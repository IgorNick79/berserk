<?php
// src/Core/Prepare/PrepareProcessor.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Db;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\ResourceCalculator;
use Berserk\Core\Result;
use Berserk\Core\ZoneManager;

final class PrepareProcessor
{
    public function __construct(
        private GameState $state,
        private ?Db $db = null,
    ) {}

    public function chooseMode(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'mode') {
            return Result::error('Сейчас не стадия выбора режима');
        }
        if ($playerKey !== GameState::PLAYER_HOST) {
            return Result::error('Режим выбирает только хост');
        }
        if ($this->state->mode !== null) {
            return Result::error('Режим уже выбран');
        }

        $mode = (string) $cmd->get('mode', '');
        if (!in_array($mode, [GameSettings::MODE_DRAFT, GameSettings::MODE_SYSTEM, GameSettings::MODE_SEALED], true)) {
            return Result::error('Неверный режим');
        }

        $this->state->mode = $mode;
        $this->state->settings = GameSettings::defaults();
        $this->state->status = 'settings';

        $this->state->bumpVersion();
        return Result::ok([
            "mode_chosen:{$mode}",
            "stage_changed:{$this->state->status}",
        ]);
    }

    public function confirmSettings(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'settings') {
            return Result::error('Сейчас не стадия настроек');
        }
        if ($playerKey !== GameState::PLAYER_HOST) {
            return Result::error('Настройки подтверждает только хост');
        }
        if ($this->state->mode === null) {
            return Result::error('Режим не выбран');
        }

        $this->state->settings = $this->settingsFromCommand($this->state->settings, $cmd);

        switch ($this->state->mode) {
            case GameSettings::MODE_SYSTEM:
                if ($this->state->settings->systemDeckSelection() !== GameSettings::SYSTEM_DECK_SELECTION_MANUAL) {
                    return Result::error('Неподдерживаемый способ выбора деки');
                }

                $this->state->status = 'deck';
                $this->state->bumpVersion();
                return Result::ok(['settings_confirmed:system', 'stage_changed:deck']);

            case GameSettings::MODE_DRAFT:
                if (!in_array($this->state->settings->draftPickMode(), [
                    GameSettings::DRAFT_PICK_MODE_MANUAL,
                    GameSettings::DRAFT_PICK_MODE_RANDOM,
                ], true)) {
                    return Result::error('Неподдерживаемый способ драфта');
                }
                if (!in_array($this->state->settings->draftAutoSide(), [
                    GameSettings::DRAFT_AUTO_SIDE_BOTH,
                    GameSettings::DRAFT_AUTO_SIDE_HOST,
                    GameSettings::DRAFT_AUTO_SIDE_PLAYER,
                ], true)) {
                    return Result::error('Неподдерживаемая сторона автовыбора');
                }

                if ($this->db === null) {
                    return Result::error('Db недоступен для драфта');
                }

                switch ($this->state->settings->draftPickMode()) {
                    case GameSettings::DRAFT_PICK_MODE_MANUAL:
                        $result = (new DraftProcessor($this->state, $this->db))->start($this->state->settings);
                        if (!$result->success) {
                            return $result;
                        }

                        $this->state->status = 'draft';
                        $this->state->bumpVersion();
                        return Result::ok(array_merge(['settings_confirmed:draft', 'stage_changed:draft'], $result->events));

                    case GameSettings::DRAFT_PICK_MODE_RANDOM:
                        if ($this->state->settings->draftAutoSide() !== GameSettings::DRAFT_AUTO_SIDE_BOTH) {
                            $result = (new DraftProcessor($this->state, $this->db))->start($this->state->settings, true);
                            if (!$result->success) {
                                return $result;
                            }

                            $this->state->status = 'draft';
                            $autoResult = $this->runDraftAutoTurns();
                            if (!$autoResult->success) {
                                return $autoResult;
                            }

                            $this->state->bumpVersion();
                            return Result::ok(array_merge(
                                ['settings_confirmed:draft', 'stage_changed:draft'],
                                $result->events,
                                $autoResult->events
                            ));
                        }

                        $result = (new RandomDraftProcessor($this->state, $this->db))->start($this->state->settings);
                        if (!$result->success) {
                            return $result;
                        }

                        $this->state->bumpVersion();
                        return Result::ok(array_merge(['settings_confirmed:draft'], $result->events));

                    default:
                        return Result::error('Неподдерживаемый способ драфта');
                }

            case GameSettings::MODE_SEALED:
                return Result::error('Sealed пока не реализован');

            default:
                return Result::error('Неверный режим');
        }
    }

    public function draftRow(string $playerKey, Command $cmd): Result
    {
        return $this->draftManualCommand($playerKey, fn(DraftProcessor $p) => $p->pickRow($playerKey, (int) $cmd->get('row', 0)));
    }

    public function draftCol(string $playerKey, Command $cmd): Result
    {
        return $this->draftManualCommand($playerKey, fn(DraftProcessor $p) => $p->pickCol($playerKey, (int) $cmd->get('col', 0)));
    }

    public function draftPass(string $playerKey): Result
    {
        return $this->draftManualCommand($playerKey, fn(DraftProcessor $p) => $p->pass($playerKey));
    }

    public function finishDraft(string $playerKey): Result
    {
        if ($this->db === null) {
            return Result::error('Db недоступен для драфта');
        }

        return (new DraftProcessor($this->state, $this->db))->finish($playerKey);
    }

    public function selectDeck(string $playerKey, Command $cmd): Result
    {
        if ($playerKey !== GameState::PLAYER_HOST) {
            return Result::error('Деку выбирает только хост');
        }

        if ($this->state->status !== 'deck') {
            return Result::error('Сейчас не стадия выбора деки');
        }

        $host = $this->state->getPlayer('host');
        if ($host->deckId !== null) {
            return Result::error('Дека уже выбрана');
        }

        $deckId      = (int) $cmd->get('deck_id', 0);
        $otherDeckId = (int) $cmd->get('other_deck_id', 0);
        $deckCards   = (array) $cmd->get('deck_cards', []);
        $otherCards  = (array) $cmd->get('other_cards', []);
        $validIds    = (array) $cmd->get('valid_deck_ids', []);

        if ($deckId <= 0 || !in_array($deckId, $validIds, true)) {
            return Result::error('Неверная дека');
        }
        if ($otherDeckId <= 0 || !in_array($otherDeckId, $validIds, true)) {
            return Result::error('Нет второй деки для оппонента');
        }
        if ($deckId === $otherDeckId) {
            return Result::error('Деки не могут совпадать');
        }

        $host->deckId = $deckId;
        $host->deckCards = $deckCards;

        $player = $this->state->getPlayer('player');
        $player->deckId = $otherDeckId;
        $player->deckCards = $otherCards;

        $this->state->getPlayer('player')->deckId = $otherDeckId;

        $this->state->status = 'view';
        $this->state->getPlayer('host')->clearConfirmations();
        $this->state->getPlayer('player')->clearConfirmations();
        $this->state->bumpVersion();

        return Result::ok([
            "deck_selected:host={$deckId}",
            "deck_assigned:player={$otherDeckId}",
            'stage_changed:view',
        ]);
    }

    public function confirmView(string $playerKey): Result
    {
        if ($this->state->status !== 'view') {
            return Result::error('Сейчас не стадия просмотра деки');
        }

        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('view')) {
            return Result::error('Уже подтверждено');
        }

        $player->confirm('view');
        $this->state->bumpVersion();

        $events = ["view_confirmed:{$playerKey}"];

        if ($this->bothConfirmed('view')) {
            $this->state->status = 'turn';
            $this->state->getPlayer('host')->clearConfirmations();
            $this->state->getPlayer('player')->clearConfirmations();

            $this->rollDice();

            $host   = $this->state->getPlayer('host');
            $player = $this->state->getPlayer('player');

            $events[] = 'stage_changed:turn';
            $events[] = "dice_rolled:host={$host->dice},player={$player->dice}";
        }

        return Result::ok($events);
    }

    public function confirmTurn(string $playerKey): Result
    {
        if ($this->state->status !== 'turn') {
            return Result::error('Сейчас не стадия кубика');
        }

        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('turn')) {
            return Result::error('Уже подтверждено');
        }

        $player->confirm('turn');
        $this->state->bumpVersion();

        $events = ["turn_confirmed:{$playerKey}"];

        if ($this->bothConfirmed('turn')) {
            $this->state->status = 'side';
            $this->state->getPlayer('host')->clearConfirmations();
            $this->state->getPlayer('player')->clearConfirmations();
            $events[] = 'stage_changed:side';
        }

        return Result::ok($events);
    }

    public function chooseSide(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'side') {
            return Result::error('Сейчас не стадия выбора стороны');
        }

        if ($this->state->firstPlayer !== $playerKey) {
            return Result::error('Сторону выбирает победитель кубика');
        }

        $side = (int) $cmd->get('side', 0);
        if (!in_array($side, [1, 2], true)) {
            return Result::error('Неверная сторона');
        }

        $oppKey = $this->state->getOpponentKey($playerKey);
        $otherSide = $side === 1 ? 2 : 1;

        $this->state->getPlayer($playerKey)->side = $side;
        $this->state->getPlayer($oppKey)->side    = $otherSide;

        $this->state->status = 'deal';
        $this->state->getPlayer('host')->clearConfirmations();
        $this->state->getPlayer('player')->clearConfirmations();

        $this->dealCards();

        $this->state->bumpVersion();

        return Result::ok([
            "side_chosen:{$playerKey}={$side}",
            'stage_changed:deal',
        ]);
    }

    public function reshuffle(string $playerKey): Result
    {
        if ($this->state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('deal')) {
            return Result::error('Отряд уже подтверждён');
        }

        if ($player->reshuffles >= 3) {
            return Result::error('Лимит пересдач исчерпан');
        }

        if (($player->resources['gold'] ?? 0) < 1) {
            return Result::error('Не хватает золота на пересдачу');
        }

        $player->resources['gold']--;
        $player->reshuffles++;

        $this->dealHand($playerKey);

        $this->state->bumpVersion();

        return Result::ok(["reshuffled:{$playerKey}:{$player->reshuffles}"]);
    }

    public function pickCard(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('deal')) {
            return Result::error('Отряд уже подтверждён');
        }

        $ukid = (string) $cmd->get('ukid', '');
        if ($ukid === '') {
            return Result::error('Не указана карта');
        }

        $found = null;
        foreach ($this->state->cards as $card) {
            if ($card->owner === $playerKey
                && $card->zone === CardInstance::ZONE_HAND
                && $card->ukid === $ukid) {
                $found = $card;
                break;
            }
        }

        if (!$found) {
            return Result::error('Карта не найдена в раздаче');
        }

        $constraintError = $this->validateDealSquadConstraints($playerKey, $found);
        if ($constraintError !== null) {
            return Result::error($constraintError);
        }

        $calc = ResourceCalculator::compute($this->state, $playerKey, adding: $found);

        if ($calc['gold_left'] < 0) {
            return Result::error('Не хватает золота');
        }

        (new ZoneManager($this->state))->toSquad($found);
        $this->state->bumpVersion();

        return Result::ok(["card_picked:{$playerKey}:{$ukid}"]);
    }

    public function unpickCard(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('deal')) {
            return Result::error('Отряд уже подтверждён');
        }

        $ukid = (string) $cmd->get('ukid', '');
        if ($ukid === '') {
            return Result::error('Не указана карта');
        }

        foreach ($this->state->cards as $card) {
            if ($card->owner === $playerKey
                && $card->zone === CardInstance::ZONE_SQUAD
                && $card->ukid === $ukid) {
                (new ZoneManager($this->state))->toHand($card);
                $this->state->bumpVersion();
                return Result::ok(["card_unpicked:{$playerKey}:{$ukid}"]);
            }
        }

        return Result::error('Карта не найдена в отряде');
    }

    private function validateDealSquadConstraints(string $playerKey, CardInstance $adding): ?string
    {
        $resultingSquad = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $playerKey) continue;
            if ($card->zone !== CardInstance::ZONE_SQUAD) continue;

            $resultingSquad[] = $card;
        }
        $resultingSquad[] = $adding;

        foreach ($resultingSquad as $card) {
            $constraint = $card->prop['deal']['squad_constraint'] ?? [];
            if (!is_array($constraint)) {
                continue;
            }

            $elementalCards = 0;
            foreach ($resultingSquad as $otherCard) {
                if ($otherCard->instanceId === $card->instanceId) continue;
                if ($otherCard->element !== 'neutral' && $otherCard->element !== '') {
                    $elementalCards++;
                }
            }

            if (isset($constraint['max_elemental_cards'])
                && $elementalCards > (int) $constraint['max_elemental_cards']) {
                return 'Нарушено ограничение состава отряда';
            }
        }

        return null;
    }

    public function confirmDeal(string $playerKey): Result
    {
        if ($this->state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('deal')) {
            return Result::error('Уже подтверждено');
        }

        foreach ($this->state->cards as $card) {
            if ($card->owner === $playerKey && $card->zone === CardInstance::ZONE_HAND) {
                (new ZoneManager($this->state))->toDeck($card);
            }
        }

        $player->confirm('deal');
        $this->state->bumpVersion();

        $events = ["deal_confirmed:{$playerKey}"];

        if ($this->bothConfirmed('deal')) {
            $this->state->status = 'place';
            $this->state->getPlayer('host')->clearConfirmations();
            $this->state->getPlayer('player')->clearConfirmations();
            $events[] = 'stage_changed:place';
        }

        return Result::ok($events);
    }

    public function placeCard(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'place') {
            return Result::error('Сейчас не стадия расстановки');
        }
        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('place')) {
            return Result::error('Расстановка уже подтверждена');
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $row    = (int) $cmd->get('row', 0);
        $col    = (int) $cmd->get('col', 0);

        $card = $this->state->getCard($cardId);
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_SQUAD) {
            return Result::error('Карта не в отряде');
        }

        $zone = new ZoneManager($this->state);
        if (!$zone->isCellAllowed($playerKey, $row, $col)) {
            return Result::error('Клетка недоступна');
        }
        if ($zone->isFieldOccupied($row, $col)) {
            return Result::error('Клетка занята');
        }

        (new ZoneManager($this->state))->toField($card, $row, $col);
        $this->state->bumpVersion();

        return Result::ok(["card_placed:{$playerKey}:{$cardId}:{$row}_{$col}"]);
    }

    public function unplaceCard(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'place') {
            return Result::error('Сейчас не стадия расстановки');
        }
        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('place')) {
            return Result::error('Расстановка уже подтверждена');
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $card = $this->state->getCard($cardId);

        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Карта не на поле');
        }

        (new ZoneManager($this->state))->toSquad($card);
        $this->state->bumpVersion();

        return Result::ok(["card_unplaced:{$playerKey}:{$cardId}"]);
    }

    public function confirmPlace(string $playerKey): Result
    {
        if ($this->state->status !== 'place') {
            return Result::error('Сейчас не стадия расстановки');
        }
        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('place')) {
            return Result::error('Уже подтверждено');
        }

        foreach ($this->state->cards as $card) {
            if ($card->owner === $playerKey && $card->zone === CardInstance::ZONE_SQUAD) {
                return Result::error('Не все карты расставлены');
            }
        }

        $player->confirm('place');
        $this->state->bumpVersion();

        $events = ["place_confirmed:{$playerKey}"];

        if ($this->bothConfirmed('place')) {
            $this->state->status = 'battle';
            $this->state->getPlayer('host')->clearConfirmations();
            $this->state->getPlayer('player')->clearConfirmations();

            $events[] = 'stage_changed:battle';
        }

        return Result::ok($events);
    }

    private function settingsFromCommand(GameSettings $settings, Command $cmd): GameSettings
    {
        $data = $settings->toArray();
        $payloadSettings = $cmd->get('settings');

        if (is_array($payloadSettings)) {
            $data = array_merge($data, $payloadSettings);
        }

        foreach (['deck_selection'] as $key) {
            if ($cmd->get($key) !== null) {
                $data['system'][$key] = $cmd->get($key);
            }
        }

        foreach ([
            'type' => 'draft_type',
            'pick_mode' => 'draft_pick_mode',
            'auto_side' => 'draft_auto_side',
            'grid_size' => 'grid_size',
            'boosters' => 'boosters',
            'booster_profile' => 'booster_profile',
        ] as $settingKey => $payloadKey) {
            if ($cmd->get($payloadKey) !== null) {
                $data['draft'][$settingKey] = $cmd->get($payloadKey);
            }
        }

        foreach (['boosters' => 'sealed_boosters', 'booster_profile' => 'sealed_booster_profile'] as $settingKey => $payloadKey) {
            if ($cmd->get($payloadKey) !== null) {
                $data['sealed'][$settingKey] = $cmd->get($payloadKey);
            }
        }

        return GameSettings::fromArray($data);
    }

    private function draftManualCommand(string $playerKey, callable $apply): Result
    {
        if ($this->db === null) {
            return Result::error('Db недоступен для драфта');
        }
        if ($this->isDraftAutoControlled($playerKey)) {
            return Result::error('Эта сторона выбирает автоматически');
        }

        $processor = new DraftProcessor($this->state, $this->db);
        $result = $apply($processor);
        if (!$result->success) {
            return $result;
        }

        $autoResult = $this->runDraftAutoTurns();
        if (!$autoResult->success) {
            return $autoResult;
        }

        $this->state->bumpVersion();
        return Result::ok(array_merge($result->events, $autoResult->events));
    }

    private function runDraftAutoTurns(): Result
    {
        if ($this->db === null) {
            return Result::error('Db недоступен для драфта');
        }

        $events = [];
        $processor = new DraftProcessor($this->state, $this->db);
        $autoPicker = $this->draftAutoPicker();

        while ($this->state->status === 'draft' && $this->state->draft !== null) {
            $turn = (string) ($this->state->draft['turn'] ?? '');
            if (!$this->isDraftAutoControlled($turn)) break;

            $selection = $autoPicker->pick(
                $processor->validSelections(),
                $this->state->draft['picked'][$turn] ?? []
            );
            if ($selection === null) break;

            $result = $processor->pickSelection($turn, $selection, 'auto_picked');
            if (!$result->success) {
                return $result;
            }
            $events = array_merge($events, $result->events);
        }

        return Result::ok($events);
    }

    private function draftAutoPicker(): DraftAutoPicker
    {
        $ukids = [];
        if ($this->state->draft !== null) {
            foreach ($this->state->draft['grid'] ?? [] as $ukid) {
                if ($ukid !== null) $ukids[] = (string) $ukid;
            }
            foreach ($this->state->draft['pool'] ?? [] as $ukid) {
                if ($ukid !== null) $ukids[] = (string) $ukid;
            }
            foreach ($this->state->draft['picked']['host'] ?? [] as $ukid) {
                $ukids[] = (string) $ukid;
            }
            foreach ($this->state->draft['picked']['player'] ?? [] as $ukid) {
                $ukids[] = (string) $ukid;
            }
        }

        $dataProvider = new DraftSelectionDataProvider($this->db);
        $cardsByUkid = $dataProvider->loadCardsByUkid($ukids);

        return new DraftAutoPicker(new DraftSelectionEvaluator(
            $cardsByUkid,
            $dataProvider->loadSynergyByPair($cardsByUkid),
        ));
    }

    private function isDraftAutoControlled(string $playerKey): bool
    {
        if ($this->state->settings->draftPickMode() !== GameSettings::DRAFT_PICK_MODE_RANDOM) {
            return false;
        }

        return match ($this->state->settings->draftAutoSide()) {
            GameSettings::DRAFT_AUTO_SIDE_BOTH => true,
            GameSettings::DRAFT_AUTO_SIDE_HOST => $playerKey === GameState::PLAYER_HOST,
            GameSettings::DRAFT_AUTO_SIDE_PLAYER => $playerKey === GameState::PLAYER_PLAYER,
            default => false,
        };
    }

    private function rollDice(): void
    {
        $host   = $this->state->getPlayer('host');
        $player = $this->state->getPlayer('player');

        do {
            $host->dice   = random_int(1, 6);
            $player->dice = random_int(1, 6);
        } while ($host->dice === $player->dice);

        $this->state->firstPlayer = $host->dice > $player->dice ? 'host' : 'player';
    }

    private function bothConfirmed(string $stage): bool
    {
        return $this->state->getPlayer('host')->isConfirmed($stage)
            && $this->state->getPlayer('player')->isConfirmed($stage);
    }

    private function dealCards(): void
    {
        foreach (['host', 'player'] as $key) {
            $player = $this->state->getPlayer($key);

            if ($player->side === 2) {
                $player->resources = ['gold' => 25, 'silver' => 23];
            } else {
                $player->resources = ['gold' => 24, 'silver' => 22];
            }

            $this->dealHand($key);
        }
    }

    private function dealHand(string $playerKey): void
    {
        $player = $this->state->getPlayer($playerKey);

        foreach ($this->state->cards as $id => $card) {
            if ($card->owner === $playerKey
                && in_array($card->zone, [CardInstance::ZONE_HAND, CardInstance::ZONE_SQUAD], true)) {
                unset($this->state->cards[$id]);
            }
        }

        $pool = [];
        foreach ($player->deckCards as $item) {
            $count = (int) ($item['count'] ?? 1);
            for ($i = 0; $i < $count; $i++) {
                $pool[] = [
                    'ukid'    => $item['ukid'],
                    'price'   => (int) ($item['price'] ?? 0),
                    'elite'   => (bool) ($item['elite'] ?? false),
                    'element' => (string) ($item['element'] ?? 'neutral'),
                    'health'  => (int) ($item['health'] ?? 0),
                    'move'    => (int) ($item['move'] ?? 0),
                    'sw'      => (int) ($item['strike_weak'] ?? 0),
                    'sm'      => (int) ($item['strike_medium'] ?? 0),
                    'ss'      => (int) ($item['strike_strong'] ?? 0),
                    'prop'    => (array) ($item['prop'] ?? []),
                    'type'    => (string) ($item['type'] ?? 'creature'),
                    'class'   => (string) ($item['class'] ?? ''),
                ];
            }
        }

        shuffle($pool);
        $hand = array_slice($pool, 0, 15);

        foreach ($hand as $item) {
            $id = $this->state->nextInstanceId();
            $this->state->addCard(new CardInstance(
                instanceId:   $id,
                ukid:         $item['ukid'],
                owner:        $playerKey,
                zone:         CardInstance::ZONE_HAND,
                hp:           $item['health'],
                hpMax:        $item['health'],
                price:        $item['price'],
                elite:        $item['elite'],
                element:      $item['element'],
                move:         $item['move'],
                moveMax:      $item['move'],
                strikeWeak:   $item['sw'],
                strikeMedium: $item['sm'],
                strikeStrong: $item['ss'],
                prop:         $item['prop'],
                type:         $item['type'],
                class:        $item['class'],
            ));
        }
    }
}
