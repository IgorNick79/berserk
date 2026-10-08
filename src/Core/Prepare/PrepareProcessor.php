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
        private mixed $draftAutoPickerOverride = null,
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
        $timerError = $this->state->settings->validateDraftTimer();
        if ($timerError !== null) {
            return Result::error($timerError);
        }

        switch ($this->state->mode) {
            case GameSettings::MODE_SYSTEM:
                if ($this->state->settings->systemDeckSelection() !== GameSettings::SYSTEM_DECK_SELECTION_MANUAL) {
                    return Result::error('Неподдерживаемый способ выбора деки');
                }

                $this->state->status = 'deck';
                $this->state->bumpVersion();
                return Result::ok(['settings_confirmed:system', 'stage_changed:deck']);

            case GameSettings::MODE_DRAFT:
                $draftSettingsError = $this->state->settings->validateDraftSettings();
                if ($draftSettingsError !== null) {
                    return Result::error($draftSettingsError);
                }

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
                        DraftTimer::initialize($this->state, $this->state->settings, time());
                        $this->state->bumpVersion();
                        return Result::ok(array_merge(['settings_confirmed:draft', 'stage_changed:draft'], $result->events));

                    case GameSettings::DRAFT_PICK_MODE_RANDOM:
                        if ($this->state->settings->draftAutoSide() !== GameSettings::DRAFT_AUTO_SIDE_BOTH) {
                            $result = (new DraftProcessor($this->state, $this->db))->start($this->state->settings, true);
                            if (!$result->success) {
                                return $result;
                            }

                            $this->state->status = 'draft';
                            DraftTimer::initialize($this->state, $this->state->settings, time());
                            $autoResult = $this->runDraftAutomaticProgress(time());
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
        if ($this->db === null && $this->draftAutoPickerOverride === null) {
            return Result::error('Db недоступен для драфта');
        }

        $timeoutResult = $this->resolveDraftTimeouts();
        if (!$timeoutResult->success) {
            return $timeoutResult;
        }
        if (!empty(array_diff($timeoutResult->events, ['draft_runtime_initialized']))) {
            return Result::ok(array_merge($timeoutResult->events, ['manual_draft_action_expired']));
        }

        if ($this->db === null) {
            return Result::error('Db недоступен для драфта');
        }

        $result = (new DraftProcessor($this->state, $this->db))->finish($playerKey);
        if ($result->success) {
            $this->state->bumpVersion();
        }
        return $result;
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
        if ($this->isDraftDeck($playerKey)) {
            $deckCount = $this->zoneCount($playerKey, CardInstance::ZONE_DECK);
            if ($deckCount < GameSettings::MIN_DECK_SIZE) {
                return Result::error('Недостаточно карт в колоде');
            }
            if ($deckCount > GameSettings::MAX_DECK_SIZE) {
                return Result::error('Слишком много карт в колоде');
            }
            $copyViolation = $this->draftDeckCopyLimitViolation($playerKey);
            if ($copyViolation !== null) {
                return Result::error(DraftCopyRules::violationMessage($copyViolation));
            }
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

    public function moveViewCardToSideboard(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'view') {
            return Result::error('Сейчас не стадия просмотра деки');
        }
        if (!$this->isDraftDeck($playerKey)) {
            return Result::error('Сайдборд доступен только для драфта');
        }

        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('view')) {
            return Result::error('Дека уже подтверждена');
        }
        if ($this->zoneCount($playerKey, CardInstance::ZONE_DECK) <= GameSettings::MIN_DECK_SIZE) {
            return Result::error('Нельзя уменьшить колоду ниже минимума');
        }

        $card = $this->state->getCard((int) $cmd->get('card_id', 0));
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_DECK) {
            return Result::error('Карта не найдена в колоде');
        }

        (new ZoneManager($this->state))->toSideboard($card);
        $this->state->bumpVersion();

        return Result::ok(["view_card_sideboarded:{$playerKey}:{$card->instanceId}"]);
    }

    public function moveViewCardToDeck(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'view') {
            return Result::error('Сейчас не стадия просмотра деки');
        }
        if (!$this->isDraftDeck($playerKey)) {
            return Result::error('Сайдборд доступен только для драфта');
        }

        $player = $this->state->getPlayer($playerKey);
        if ($player->isConfirmed('view')) {
            return Result::error('Дека уже подтверждена');
        }

        $card = $this->state->getCard((int) $cmd->get('card_id', 0));
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_SIDEBOARD) {
            return Result::error('Карта не найдена в сайдборде');
        }

        (new ZoneManager($this->state))->toDeck($card);
        $this->state->bumpVersion();

        return Result::ok(["view_card_decked:{$playerKey}:{$card->instanceId}"]);
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

        if ($this->hasDealScoutRecruitLock($playerKey)) {
            return Result::error('Лазутчица уже обязана остаться в отряде');
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

        if ($this->dealLinkedRecruitConfig($found) !== null
            && empty($found->flags['deal_linked_recruit'])) {
            $beginningError = $this->validateDealLinkedRecruitBeginning($playerKey, $found);
            if ($beginningError !== null) {
                return Result::error($beginningError);
            }

            $pending = $this->dealLinkedRecruitPending($playerKey, $found);
            if (empty($pending['candidate_ids'])) {
                return Result::error('Нет подходящего существа для совместного набора');
            }

            $this->state->battle['pending_deal_linked_recruit'] = $pending;
            $this->state->bumpVersion();
            return Result::ok(["deal_linked_recruit_pending:{$playerKey}:{$ukid}"]);
        }

        if ($this->dealScoutRecruitConfig($found) !== null
            && empty($found->flags['deal_scout_recruit'])) {
            $beginningError = $this->validateDealScoutBeginning($playerKey, $found);
            if ($beginningError !== null) {
                return Result::error($beginningError);
            }

            $this->state->battle['pending_deal_scout_recruit'] = $this->dealScoutRecruitPending($playerKey, $found);
            $this->state->bumpVersion();
            return Result::ok(["deal_scout_recruit_pending:{$playerKey}:{$ukid}"]);
        }

        if ($this->dealVariableRecruitConfig($found) !== null
            && empty($found->flags['deal_variable_recruit'])) {
            $this->state->battle['pending_deal_variable_recruit'] = $this->dealVariableRecruitPending($playerKey, $found);
            $this->state->bumpVersion();
            return Result::ok(["deal_variable_recruit_pending:{$playerKey}:{$ukid}"]);
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
                if (($card->flags['deal_linked_recruit']['role'] ?? null) === 'companion') {
                    return Result::error('Связанное существо нельзя вернуть отдельно');
                }

                if (($card->flags['deal_linked_recruit']['role'] ?? null) === 'source') {
                    return $this->returnDealLinkedRecruitSource($playerKey, $card);
                }

                if (!empty($card->flags['deal_scout_recruit']['return_locked'])) {
                    return Result::error('Лазутчицу нельзя вернуть после разведки');
                }

                (new ZoneManager($this->state))->toHand($card);
                $this->resetDealVariableRecruit($card);
                $this->state->bumpVersion();
                return Result::ok(["card_unpicked:{$playerKey}:{$ukid}"]);
            }
        }

        return Result::error('Карта не найдена в отряде');
    }

    public function chooseDealLinkedRecruit(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $pending = $this->state->battle['pending_deal_linked_recruit'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет ожидающего совместного набора');
        }

        $source = $this->state->getCard((int) ($pending['source_id'] ?? 0));
        if (!$source || $source->owner !== $playerKey || $source->zone !== CardInstance::ZONE_HAND) {
            unset($this->state->battle['pending_deal_linked_recruit']);
            return Result::error('Карта не найдена в раздаче');
        }

        $config = $this->dealLinkedRecruitConfig($source);
        if ($config === null) {
            unset($this->state->battle['pending_deal_linked_recruit']);
            return Result::error('Совместный набор недоступен');
        }

        $beginningError = $this->validateDealLinkedRecruitBeginning($playerKey, $source);
        if ($beginningError !== null) {
            return Result::error($beginningError);
        }

        $companionId = (int) $cmd->get('companion_id', 0);
        $companion = $this->state->getCard($companionId);
        if (!$companion) {
            return Result::error('Существо не найдено');
        }

        $candidateError = $this->validateDealLinkedRecruitCompanion($playerKey, $source, $companion, $config);
        if ($candidateError !== null) {
            return Result::error($candidateError);
        }

        $this->applyDealLinkedRecruit($source, $companion, $config);

        $constraintError = $this->validateDealSquadConstraintsForAdditions($playerKey, [$source, $companion]);
        if ($constraintError !== null) {
            $this->resetDealLinkedRecruitPair($source, $companion);
            return Result::error($constraintError);
        }

        $calc = ResourceCalculator::compute($this->state, $playerKey, additions: [$source, $companion]);
        if ($calc['gold_left'] < 0) {
            $this->resetDealLinkedRecruitPair($source, $companion);
            return Result::error('Не хватает золота');
        }

        (new ZoneManager($this->state))->toSquad($source);
        (new ZoneManager($this->state))->toSquad($companion);
        unset($this->state->battle['pending_deal_linked_recruit']);
        $this->state->bumpVersion();

        return Result::ok([
            "card_picked:{$playerKey}:{$source->ukid}",
            "card_picked:{$playerKey}:{$companion->ukid}",
            "deal_linked_recruit:{$source->instanceId}:{$companion->instanceId}",
        ]);
    }

    public function cancelDealLinkedRecruit(string $playerKey): Result
    {
        $pending = $this->state->battle['pending_deal_linked_recruit'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет ожидающего совместного набора');
        }

        unset($this->state->battle['pending_deal_linked_recruit']);
        $this->state->bumpVersion();

        return Result::ok(['deal_linked_recruit_cancelled']);
    }

    public function confirmDealScoutRecruit(string $playerKey): Result
    {
        if ($this->state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $pending = $this->state->battle['pending_deal_scout_recruit'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет ожидающей разведки');
        }
        if (($pending['step'] ?? 'confirm') !== 'confirm') {
            return Result::error('Разведка уже подтверждена');
        }

        $card = $this->state->getCard((int) ($pending['card_id'] ?? 0));
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_HAND) {
            unset($this->state->battle['pending_deal_scout_recruit']);
            return Result::error('Карта не найдена в раздаче');
        }

        $config = $this->dealScoutRecruitConfig($card);
        if ($config === null) {
            unset($this->state->battle['pending_deal_scout_recruit']);
            return Result::error('Разведка недоступна');
        }

        $beginningError = $this->validateDealScoutBeginning($playerKey, $card);
        if ($beginningError !== null) {
            return Result::error($beginningError);
        }

        $opponentKey = $this->state->getOpponentKey($playerKey);
        $opponentHand = [];
        foreach ($this->state->cards as $otherCard) {
            if ($otherCard->owner === $opponentKey && $otherCard->zone === CardInstance::ZONE_HAND) {
                $opponentHand[] = $otherCard;
            }
        }

        $revealCount = (int) $config['reveal_count'];
        if (count($opponentHand) < $revealCount) {
            return Result::error('Недостаточно карт у оппонента для разведки');
        }

        shuffle($opponentHand);
        $revealed = array_slice($opponentHand, 0, $revealCount);
        $revealedIds = array_map(fn(CardInstance $revealedCard) => $revealedCard->instanceId, $revealed);
        $eliteCount = 0;
        foreach ($revealed as $revealedCard) {
            if ($revealedCard->elite) {
                $eliteCount++;
            }
        }

        $discount = $eliteCount * (int) $config['discount_per_elite'];

        $this->state->battle['pending_deal_scout_recruit'] = array_merge($pending, [
            'step' => 'reveal',
            'revealed_ids' => $revealedIds,
            'elite_count' => $eliteCount,
            'discount' => $discount,
            'discount_resource' => (string) $config['discount_resource'],
            'lock_return_after_recruit' => (bool) $config['lock_return_after_recruit'],
        ]);
        $this->state->bumpVersion();

        return Result::ok(["deal_scout_recruit_revealed:{$playerKey}:{$card->ukid}:elite={$eliteCount}:discount={$discount}"]);
    }

    public function closeDealScoutRecruit(string $playerKey): Result
    {
        if ($this->state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $pending = $this->state->battle['pending_deal_scout_recruit'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет ожидающей разведки');
        }
        if (($pending['step'] ?? 'confirm') !== 'reveal') {
            return Result::error('Сначала подтвердите разведку');
        }

        $card = $this->state->getCard((int) ($pending['card_id'] ?? 0));
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_HAND) {
            return Result::error('Лазутчица должна быть в руке');
        }

        $config = $this->dealScoutRecruitConfig($card);
        if ($config === null) {
            return Result::error('Разведка недоступна');
        }

        $this->applyDealScoutRecruit($card, $pending);

        $constraintError = $this->validateDealSquadConstraints($playerKey, $card);
        if ($constraintError !== null) {
            $this->resetDealScoutRecruit($card);
            return Result::error($constraintError);
        }

        $calc = ResourceCalculator::compute($this->state, $playerKey, adding: $card);
        if ($calc['gold_left'] < 0) {
            $this->resetDealScoutRecruit($card);
            return Result::error('Не хватает золота');
        }

        (new ZoneManager($this->state))->toSquad($card);
        unset($this->state->battle['pending_deal_scout_recruit']);
        $this->state->bumpVersion();

        return Result::ok([
            "card_picked:{$playerKey}:{$card->ukid}",
            "deal_scout_recruit:elite=" . (int) ($pending['elite_count'] ?? 0),
        ]);
    }

    public function cancelDealScoutRecruit(string $playerKey): Result
    {
        $pending = $this->state->battle['pending_deal_scout_recruit'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет ожидающей разведки');
        }
        if (($pending['step'] ?? 'confirm') !== 'confirm') {
            return Result::error('Разведку уже нельзя отменить');
        }

        unset($this->state->battle['pending_deal_scout_recruit']);
        $this->state->bumpVersion();

        return Result::ok(['deal_scout_recruit_cancelled']);
    }

    public function chooseDealVariableRecruit(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $pending = $this->state->battle['pending_deal_variable_recruit'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет ожидающего выбора');
        }

        $card = $this->state->getCard((int) ($pending['card_id'] ?? 0));
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_HAND) {
            unset($this->state->battle['pending_deal_variable_recruit']);
            return Result::error('Карта не найдена в раздаче');
        }

        $config = $this->dealVariableRecruitConfig($card);
        if ($config === null) {
            unset($this->state->battle['pending_deal_variable_recruit']);
            return Result::error('Выбор недоступен');
        }

        $x = (int) $cmd->get('x', -1);
        $minX = (int) $config['min_x'];
        $maxX = (int) $config['max_x'];
        if ($x < $minX || $x > $maxX) {
            return Result::error('Недопустимое значение X');
        }
        if (!in_array(($config['resource'] ?? 'elite_gold'), ['elite_gold', 'silver'], true)) {
            return Result::error('Неподдерживаемый ресурс выбора');
        }

        $this->applyDealVariableRecruit($card, $x, $config);

        $constraintError = $this->validateDealSquadConstraints($playerKey, $card);
        if ($constraintError !== null) {
            $this->resetDealVariableRecruit($card);
            return Result::error($constraintError);
        }

        $calc = ResourceCalculator::compute($this->state, $playerKey, adding: $card);
        if (!$calc['silver_extra_affordable']) {
            $this->resetDealVariableRecruit($card);
            return Result::error('Не хватает серебра');
        }
        if ($calc['gold_left'] < 0) {
            $this->resetDealVariableRecruit($card);
            return Result::error('Не хватает золота');
        }

        (new ZoneManager($this->state))->toSquad($card);
        unset($this->state->battle['pending_deal_variable_recruit']);
        $this->state->bumpVersion();

        return Result::ok(["card_picked:{$playerKey}:{$card->ukid}", "deal_variable_recruit:{$x}"]);
    }

    public function cancelDealVariableRecruit(string $playerKey): Result
    {
        $pending = $this->state->battle['pending_deal_variable_recruit'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет ожидающего выбора');
        }

        unset($this->state->battle['pending_deal_variable_recruit']);
        $this->state->bumpVersion();

        return Result::ok(['deal_variable_recruit_cancelled']);
    }

    private function validateDealSquadConstraints(string $playerKey, CardInstance $adding): ?string
    {
        return $this->validateDealSquadConstraintsForAdditions($playerKey, [$adding]);
    }

    /**
     * @param CardInstance[] $additions
     */
    private function validateDealSquadConstraintsForAdditions(string $playerKey, array $additions): ?string
    {
        $globalError = SquadRules::validate($this->state, $playerKey, $additions);
        if ($globalError !== null) {
            return $globalError;
        }

        $resultingSquad = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $playerKey) continue;
            if ($card->zone !== CardInstance::ZONE_SQUAD) continue;

            $resultingSquad[] = $card;
        }
        foreach ($additions as $adding) {
            $resultingSquad[] = $adding;
        }

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

    private function dealLinkedRecruitConfig(CardInstance $card): ?array
    {
        $config = $card->prop['deal']['linked_recruit'] ?? null;
        if (!is_array($config)) {
            return null;
        }

        $paymentResource = (string) ($config['companion_payment_resource'] ?? 'silver');
        if ($paymentResource !== 'silver') {
            return null;
        }

        return [
            'max_elite_cost' => max(0, (int) ($config['max_elite_cost'] ?? 7)),
            'companion_payment_resource' => $paymentResource,
            'requires_empty_squad' => !empty($config['requires_empty_squad']),
        ];
    }

    private function validateDealLinkedRecruitBeginning(string $playerKey, CardInstance $source): ?string
    {
        $config = $this->dealLinkedRecruitConfig($source);
        if ($config === null || !$config['requires_empty_squad']) {
            return null;
        }

        foreach ($this->state->cards as $card) {
            if ($card->owner === $playerKey && $card->zone === CardInstance::ZONE_SQUAD) {
                return 'Совместный набор доступен только в начале набора';
            }
        }

        return null;
    }

    private function dealLinkedRecruitPending(string $playerKey, CardInstance $source): array
    {
        $config = $this->dealLinkedRecruitConfig($source) ?? [];
        $candidates = [];
        foreach ($this->state->cards as $card) {
            if ($this->validateDealLinkedRecruitCompanion($playerKey, $source, $card, $config) !== null) {
                continue;
            }
            if ($this->validateDealSquadConstraintsForAdditions($playerKey, [$source, $card]) !== null) {
                continue;
            }
            $candidates[] = $card->instanceId;
        }

        return [
            'owner' => $playerKey,
            'source_id' => $source->instanceId,
            'ukid' => $source->ukid,
            'candidate_ids' => $candidates,
            'max_elite_cost' => (int) ($config['max_elite_cost'] ?? 7),
            'companion_payment_resource' => (string) ($config['companion_payment_resource'] ?? 'silver'),
            'requires_empty_squad' => !empty($config['requires_empty_squad']),
        ];
    }

    private function validateDealLinkedRecruitCompanion(
        string $playerKey,
        CardInstance $source,
        CardInstance $candidate,
        array $config,
    ): ?string {
        if ($candidate->instanceId === $source->instanceId) {
            return 'Нельзя выбрать саму карту';
        }
        if ($candidate->owner !== $playerKey) {
            return 'Существо должно принадлежать игроку';
        }
        if ($candidate->zone !== CardInstance::ZONE_HAND) {
            return 'Существо должно быть в раздаче';
        }
        if (!in_array($candidate->type, ['creature', 'fly'], true)) {
            return 'Можно выбрать только существо';
        }
        if (!$candidate->elite) {
            return 'Можно выбрать только золотое существо';
        }
        if ($candidate->price > (int) ($config['max_elite_cost'] ?? 7)) {
            return 'Стоимость существа слишком велика';
        }

        return null;
    }

    private function applyDealLinkedRecruit(CardInstance $source, CardInstance $companion, array $config): void
    {
        $this->resetDealLinkedRecruitPair($source, $companion);

        $source->flags['deal_linked_recruit'] = [
            'role' => 'source',
            'linked_instance_id' => $companion->instanceId,
        ];

        $companion->flags['deal_linked_recruit'] = [
            'role' => 'companion',
            'linked_instance_id' => $source->instanceId,
            'payment_resource' => (string) ($config['companion_payment_resource'] ?? 'silver'),
            'converted_cost' => max(0, $companion->price),
        ];
    }

    private function resetDealLinkedRecruitPair(CardInstance $source, CardInstance $companion): void
    {
        unset($source->flags['deal_linked_recruit']);
        unset($companion->flags['deal_linked_recruit']);
    }

    private function returnDealLinkedRecruitSource(string $playerKey, CardInstance $source): Result
    {
        $linkedId = (int) ($source->flags['deal_linked_recruit']['linked_instance_id'] ?? 0);
        $companion = $this->state->getCard($linkedId);
        if (!$companion
            || $companion->owner !== $playerKey
            || $companion->zone !== CardInstance::ZONE_SQUAD
            || ($companion->flags['deal_linked_recruit']['role'] ?? null) !== 'companion'
            || (int) ($companion->flags['deal_linked_recruit']['linked_instance_id'] ?? 0) !== $source->instanceId) {
            return Result::error('Связанное существо не найдено в отряде');
        }

        $this->resetDealLinkedRecruitPair($source, $companion);
        $zone = new ZoneManager($this->state);
        $zone->toHand($source);
        $zone->toHand($companion);
        $this->state->bumpVersion();

        return Result::ok([
            "card_unpicked:{$playerKey}:{$source->ukid}",
            "card_unpicked:{$playerKey}:{$companion->ukid}",
            "deal_linked_recruit_returned:{$source->instanceId}:{$companion->instanceId}",
        ]);
    }

    private function dealScoutRecruitConfig(CardInstance $card): ?array
    {
        $config = $card->prop['deal']['scout_recruit'] ?? null;
        if (!is_array($config)) {
            return null;
        }

        $revealCount = max(1, (int) ($config['reveal_count'] ?? 2));
        $discountPerElite = max(0, (int) ($config['discount_per_elite'] ?? 1));
        $discountResource = (string) ($config['discount_resource'] ?? 'silver');
        if ($discountResource !== 'silver') {
            return null;
        }

        return [
            'reveal_count' => $revealCount,
            'discount_per_elite' => $discountPerElite,
            'discount_resource' => $discountResource,
            'requires_empty_squad' => !empty($config['requires_empty_squad']),
            'lock_return_after_recruit' => !empty($config['lock_return_after_recruit']),
        ];
    }

    private function validateDealScoutBeginning(string $playerKey, CardInstance $card): ?string
    {
        $config = $this->dealScoutRecruitConfig($card);
        if ($config === null || !$config['requires_empty_squad']) {
            return null;
        }

        foreach ($this->state->cards as $otherCard) {
            if ($otherCard->owner === $playerKey && $otherCard->zone === CardInstance::ZONE_SQUAD) {
                return 'Разведка доступна только в начале набора';
            }
        }

        return null;
    }

    private function dealScoutRecruitPending(string $playerKey, CardInstance $card): array
    {
        $config = $this->dealScoutRecruitConfig($card) ?? [];

        return [
            'owner' => $playerKey,
            'step' => 'confirm',
            'card_id' => $card->instanceId,
            'ukid' => $card->ukid,
            'reveal_count' => (int) ($config['reveal_count'] ?? 2),
            'discount_per_elite' => (int) ($config['discount_per_elite'] ?? 1),
            'discount_resource' => (string) ($config['discount_resource'] ?? 'silver'),
            'requires_empty_squad' => !empty($config['requires_empty_squad']),
            'lock_return_after_recruit' => !empty($config['lock_return_after_recruit']),
        ];
    }

    private function applyDealScoutRecruit(CardInstance $card, array $pending): void
    {
        $card->flags['deal_scout_recruit'] = [
            'confirmed' => true,
            'return_locked' => !empty($pending['lock_return_after_recruit']),
            'revealed_ids' => array_map('intval', (array) ($pending['revealed_ids'] ?? [])),
            'elite_count' => max(0, (int) ($pending['elite_count'] ?? 0)),
            'discount_resource' => (string) ($pending['discount_resource'] ?? 'silver'),
            'silver_discount' => max(0, (int) ($pending['discount'] ?? 0)),
        ];
    }

    private function resetDealScoutRecruit(CardInstance $card): void
    {
        unset($card->flags['deal_scout_recruit']);
    }

    private function hasDealScoutRecruitLock(string $playerKey): bool
    {
        foreach ($this->state->cards as $card) {
            if ($card->owner === $playerKey
                && $card->zone === CardInstance::ZONE_SQUAD
                && !empty($card->flags['deal_scout_recruit']['return_locked'])) {
                return true;
            }
        }

        return false;
    }

    private function dealVariableRecruitConfig(CardInstance $card): ?array
    {
        $config = $card->prop['deal']['recruit_choice'] ?? null;
        if (!is_array($config)) {
            return null;
        }

        $extraCost = $config['extra_cost'] ?? null;
        $instanceBuff = $config['instance_buff'] ?? null;
        if (!is_array($extraCost) || !is_array($instanceBuff)) {
            return null;
        }

        $minX = max(0, (int) ($extraCost['min'] ?? 0));
        $maxX = max($minX, (int) ($extraCost['max'] ?? $minX));

        return [
            'min_x' => $minX,
            'max_x' => $maxX,
            'resource' => (string) ($extraCost['resource'] ?? 'elite_gold'),
            'attack_per_x' => max(0, (int) ($instanceBuff['attack_per_x'] ?? 0)),
            'health' => max(0, (int) ($instanceBuff['health'] ?? 0)),
        ];
    }

    private function dealVariableRecruitPending(string $playerKey, CardInstance $card): array
    {
        $config = $this->dealVariableRecruitConfig($card) ?? [];

        return [
            'owner' => $playerKey,
            'card_id' => $card->instanceId,
            'ukid' => $card->ukid,
            'min_x' => (int) ($config['min_x'] ?? 0),
            'max_x' => (int) ($config['max_x'] ?? 0),
            'resource' => (string) ($config['resource'] ?? 'elite_gold'),
            'attack_per_x' => (int) ($config['attack_per_x'] ?? 0),
            'health' => (int) ($config['health'] ?? 0),
        ];
    }

    private function applyDealVariableRecruit(CardInstance $card, int $x, array $config): void
    {
        $this->resetDealVariableRecruit($card);

        $extraCost = $x;
        $strikeBonus = $x * (int) $config['attack_per_x'];
        $hpBonus = (int) $config['health'];

        $card->flags['deal_variable_recruit'] = [
            'x' => $x,
            'extra_cost' => $extraCost,
            'resource' => (string) ($config['resource'] ?? 'elite_gold'),
            'strike_bonus' => $strikeBonus,
            'hp_bonus' => $hpBonus,
        ];

        if ($strikeBonus > 0) {
            $card->modifiers[] = [
                'stat' => 'ability_strike',
                'value' => $strikeBonus,
                'expire' => 'permanent',
                'source' => 'deal_variable_recruit',
            ];
        }

        if ($hpBonus > 0) {
            $card->hpMax += $hpBonus;
            $card->hp += $hpBonus;
        }
    }

    private function resetDealVariableRecruit(CardInstance $card): void
    {
        $applied = $card->flags['deal_variable_recruit'] ?? null;
        if (!is_array($applied)) {
            return;
        }

        $hpBonus = (int) ($applied['hp_bonus'] ?? 0);
        if ($hpBonus > 0) {
            $card->hpMax = max(0, $card->hpMax - $hpBonus);
            $card->hp = min($card->hp, $card->hpMax);
        }

        $card->modifiers = array_values(array_filter(
            $card->modifiers,
            fn($modifier) => ($modifier['source'] ?? null) !== 'deal_variable_recruit'
        ));

        unset($card->flags['deal_variable_recruit']);
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
            'grid_mode' => 'draft_grid_mode',
            'auto_side' => 'draft_auto_side',
            'grid_size' => 'grid_size',
            'boosters' => 'boosters',
            'booster_profile' => 'booster_profile',
            'timer_mode' => 'draft_timer_mode',
            'timer_total' => 'draft_timer_total',
            'timer_action' => 'draft_timer_action',
        ] as $settingKey => $payloadKey) {
            if ($cmd->get($payloadKey) !== null) {
                $value = $cmd->get($payloadKey);
                if ($settingKey === 'timer_total') {
                    $value = (int) $value * 60;
                }
                $data['draft'][$settingKey] = $value;
            }
        }

        foreach (['boosters' => 'sealed_boosters', 'booster_profile' => 'sealed_booster_profile'] as $settingKey => $payloadKey) {
            if ($cmd->get($payloadKey) !== null) {
                $data['sealed'][$settingKey] = $cmd->get($payloadKey);
            }
        }

        $settings = GameSettings::fromArray($data);
        return $settings;
    }

    private function draftManualCommand(string $playerKey, callable $apply): Result
    {
        if ($this->db === null && $this->draftAutoPickerOverride === null) {
            return Result::error('Db недоступен для драфта');
        }

        $timeoutResult = $this->resolveDraftTimeouts();
        if (!$timeoutResult->success) {
            return $timeoutResult;
        }
        if (!empty(array_diff($timeoutResult->events, ['draft_runtime_initialized']))) {
            return Result::ok(array_merge($timeoutResult->events, ['manual_draft_action_expired']));
        }

        if ($this->isDraftAutoControlled($playerKey)) {
            return Result::error('Эта сторона выбирает автоматически');
        }

        $now = time();
        $hadTimer = isset($this->state->draft['timer']);
        $timerSnapshot = $hadTimer ? $this->state->draft['timer'] : null;
        DraftTimer::settleAction($this->state, $playerKey, $now);
        $processor = $this->draftProcessor();
        $result = $apply($processor);
        if (!$result->success) {
            if ($this->state->draft !== null) {
                if ($hadTimer) {
                    $this->state->draft['timer'] = $timerSnapshot;
                } else {
                    unset($this->state->draft['timer']);
                }
            }
            return $result;
        }

        if ($this->state->status === 'draft' && $this->state->draft !== null) {
            DraftTimer::startAction($this->state, $now);
        }

        $progressResult = $this->runDraftAutomaticProgress($now);
        if (!$progressResult->success) {
            return $progressResult;
        }

        $postTimeoutResult = $this->resolveDraftTimeouts($now);
        if (!$postTimeoutResult->success) {
            return $postTimeoutResult;
        }

        if (empty($postTimeoutResult->events)) {
            $this->state->bumpVersion();
        }
        return Result::ok(array_merge($result->events, $progressResult->events, $postTimeoutResult->events));
    }

    public function resolveDraftTimeouts(?int $now = null): Result
    {
        if ($this->db === null && $this->draftAutoPickerOverride === null) {
            return Result::error('Db недоступен для драфта');
        }
        if ($this->state->status !== 'draft' || $this->state->draft === null) {
            return Result::ok([]);
        }

        $now ??= time();
        $needsRuntimeSave = !isset($this->state->draft['history'])
            || ($this->state->settings->draftTimerMode() !== GameSettings::DRAFT_TIMER_UNLIMITED
                && !isset($this->state->draft['timer']));
        DraftTimer::ensureRuntime($this->state, $now);
        $events = [];
        if ($needsRuntimeSave) {
            $events[] = 'draft_runtime_initialized';
        }

        $progressResult = $this->runDraftAutomaticProgress($now);
        if (!$progressResult->success) {
            return $progressResult;
        }
        $events = array_merge($events, $progressResult->events);

        if (!DraftTimer::hasTimer($this->state)) {
            if (!empty($events)) {
                $this->state->bumpVersion();
            }
            return Result::ok($events);
        }

        if (!DraftTimer::isExpired($this->state, $now)) {
            if (!empty($events)) {
                $this->state->bumpVersion();
            }
            return Result::ok($events);
        }

        $processor = $this->draftProcessor();
        $autoPicker = $this->draftAutoPicker();
        $guard = 0;

        while ($this->state->status === 'draft'
            && $this->state->draft !== null
            && DraftTimer::hasTimer($this->state)
            && DraftTimer::isExpired($this->state, $now)) {
            if (++$guard > 500) {
                return Result::error('Таймаут драфта не смог выбрать допустимое действие');
            }

            $turn = (string) ($this->state->draft['turn'] ?? '');
            if ($turn === '') break;

            $chargeAt = DraftTimer::chargeLimitAt($this->state) ?? $now;
            DraftTimer::settleAction($this->state, $turn, $chargeAt);

            $validSelections = $processor->validSelections();
            $selection = $autoPicker->pick(
                $validSelections,
                $this->state->draft['picked'][$turn] ?? []
            );
            if ($selection === null) {
                if (($this->state->draft['grid_mode'] ?? $this->state->settings->draftGridMode()) === GameSettings::DRAFT_GRID_MODE_DISCRETE) {
                    if (!empty($validSelections)) {
                        return Result::error('Автодрафт не выбрал допустимый вариант');
                    }
                    $result = $processor->skipNoViableSelection($turn, 'timeout_forced_skip');
                    if (!$result->success) {
                        return $result;
                    }
                    $events = array_merge($events, $result->events);

                    if ($this->state->status !== 'draft' || $this->state->draft === null) {
                        break;
                    }

                    DraftTimer::startAction($this->state, $chargeAt);
                    $autoResult = $this->runDraftAutoTurns($chargeAt);
                    if (!$autoResult->success) {
                        return $autoResult;
                    }
                    $events = array_merge($events, $autoResult->events);
                    continue;
                }

                $result = $processor->pass($turn);
                if (!$result->success) {
                    return $result;
                }
                $events = array_merge($events, $result->events);

                if ($this->state->status !== 'draft' || $this->state->draft === null) {
                    break;
                }

                DraftTimer::startAction($this->state, $chargeAt);
                $autoResult = $this->runDraftAutoTurns($chargeAt);
                if (!$autoResult->success) {
                    return $autoResult;
                }
                $events = array_merge($events, $autoResult->events);
                continue;
            }

            $result = $processor->pickSelection($turn, $selection, 'timeout_picked');
            if (!$result->success) {
                return $result;
            }
            $events = array_merge($events, $result->events);

            if ($this->state->status !== 'draft' || $this->state->draft === null) {
                break;
            }

            DraftTimer::startAction($this->state, $chargeAt);
            $progressResult = $this->runDraftAutomaticProgress($chargeAt);
            if (!$progressResult->success) {
                return $progressResult;
            }
            $events = array_merge($events, $progressResult->events);
        }

        if (!empty($events)) {
            $this->state->bumpVersion();
        }

        return Result::ok($events);
    }

    private function runDraftAutomaticProgress(?int $now = null): Result
    {
        $now ??= time();
        $events = [];
        $guard = 0;

        do {
            if (++$guard > 500) {
                return Result::error('Автопродвижение драфта зациклилось');
            }

            $before = count($events);

            $skipResult = $this->runDiscreteForcedSkips($now);
            if (!$skipResult->success) {
                return $skipResult;
            }
            $events = array_merge($events, $skipResult->events);

            $autoResult = $this->runDraftAutoTurns($now);
            if (!$autoResult->success) {
                return $autoResult;
            }
            $events = array_merge($events, $autoResult->events);
        } while ($this->state->status === 'draft'
            && $this->state->draft !== null
            && count($events) > $before);

        return Result::ok($events);
    }

    private function runDiscreteForcedSkips(?int $now = null): Result
    {
        if ($this->db === null && $this->draftAutoPickerOverride === null) {
            return Result::error('Db недоступен для драфта');
        }

        $now ??= time();
        $events = [];
        $processor = $this->draftProcessor();
        $guard = 0;

        while ($this->state->status === 'draft'
            && $this->state->draft !== null
            && (($this->state->draft['grid_mode'] ?? $this->state->settings->draftGridMode()) === GameSettings::DRAFT_GRID_MODE_DISCRETE)
            && empty($processor->validSelections())) {
            if (++$guard > 500) {
                return Result::error('Автопропуск дискретного драфта зациклился');
            }

            $turn = (string) ($this->state->draft['turn'] ?? '');
            if ($turn === '') break;

            $result = $processor->skipNoViableSelection($turn, 'forced_skip');
            if (!$result->success) {
                return $result;
            }

            $events = array_merge($events, $result->events);
            if ($this->state->status === 'draft' && $this->state->draft !== null) {
                DraftTimer::startAction($this->state, $now);
            }
        }

        return Result::ok($events);
    }

    private function runDraftAutoTurns(?int $now = null): Result
    {
        if ($this->db === null && $this->draftAutoPickerOverride === null) {
            return Result::error('Db недоступен для драфта');
        }

        $now ??= time();
        $events = [];
        $processor = $this->draftProcessor();
        $autoPicker = $this->draftAutoPicker();
        $guard = 0;

        while ($this->state->status === 'draft' && $this->state->draft !== null) {
            if (++$guard > 500) {
                return Result::error('Автодрафт не смог выбрать допустимые карты');
            }
            $turn = (string) ($this->state->draft['turn'] ?? '');
            if (!$this->isDraftAutoControlled($turn)) break;

            $selection = $autoPicker->pick(
                $processor->validSelections(),
                $this->state->draft['picked'][$turn] ?? []
            );
            if ($selection === null) {
                if (($this->state->draft['grid_mode'] ?? $this->state->settings->draftGridMode()) !== GameSettings::DRAFT_GRID_MODE_DISCRETE) {
                    $result = $processor->pass($turn);
                    if (!$result->success) {
                        return $result;
                    }
                    $events = array_merge($events, $result->events);
                    if ($this->state->status === 'draft' && $this->state->draft !== null) {
                        DraftTimer::startAction($this->state, $now);
                    }
                    continue;
                }
                if (empty($processor->validSelections())) {
                    $result = $processor->skipNoViableSelection($turn, 'auto_forced_skip');
                    if (!$result->success) {
                        return $result;
                    }
                    $events = array_merge($events, $result->events);
                    if ($this->state->status === 'draft' && $this->state->draft !== null) {
                        DraftTimer::startAction($this->state, $now);
                    }
                    continue;
                }
                break;
            }

            $result = $processor->pickSelection($turn, $selection, 'auto_picked');
            if (!$result->success) {
                return $result;
            }
            $events = array_merge($events, $result->events);
            if ($this->state->status === 'draft' && $this->state->draft !== null) {
                DraftTimer::startAction($this->state, $now);
            }
        }

        return Result::ok($events);
    }

    private function draftAutoPicker(): mixed
    {
        if ($this->draftAutoPickerOverride !== null) {
            return $this->draftAutoPickerOverride;
        }

        $ukids = [];
        if ($this->state->draft !== null) {
            foreach ($this->state->draft['grid'] ?? [] as $ukid) {
                if ($ukid !== null) $ukids[] = (string) $ukid;
            }
            foreach ($this->state->draft['pool'] ?? [] as $ukid) {
                if ($ukid !== null) $ukids[] = (string) $ukid;
            }
            foreach ($this->state->draft['recycle'] ?? [] as $ukid) {
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

    private function draftProcessor(): DraftProcessor
    {
        if ($this->db !== null) {
            return new DraftProcessor($this->state, $this->db);
        }

        $ref = new \ReflectionClass(DraftProcessor::class);
        $processor = $ref->newInstanceWithoutConstructor();
        $stateProp = $ref->getProperty('state');
        $stateProp->setAccessible(true);
        $stateProp->setValue($processor, $this->state);
        return $processor;
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

        if ($this->isDraftDeck($playerKey)) {
            $zone = new ZoneManager($this->state);
            foreach ($this->state->cards as $card) {
                if ($card->owner === $playerKey
                    && in_array($card->zone, [CardInstance::ZONE_HAND, CardInstance::ZONE_SQUAD], true)) {
                    $this->resetDealState($card);
                    $zone->toDeck($card);
                }
            }

            $pool = $this->state->getCardsInZone($playerKey, CardInstance::ZONE_DECK);
            shuffle($pool);
            foreach (array_slice($pool, 0, 15) as $card) {
                $zone->toHand($card);
            }
            return;
        }

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
                    'single'  => (bool) ($item['single'] ?? false),
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
                single:       $item['single'],
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

    private function isDraftDeck(string $playerKey): bool
    {
        return $this->state->getPlayer($playerKey)->deckId === 0;
    }

    private function zoneCount(string $playerKey, string $zone): int
    {
        return count($this->state->getCardsInZone($playerKey, $zone));
    }

    private function draftDeckCopyLimitViolation(string $playerKey): ?array
    {
        $deckCards = $this->state->getCardsInZone($playerKey, CardInstance::ZONE_DECK);
        $ukids = [];
        $cardsByUkid = [];
        foreach ($deckCards as $card) {
            $ukids[] = $card->ukid;
            $cardsByUkid[$card->ukid] ??= $card;
        }

        return DraftCopyRules::firstDeckCountViolation($ukids, $cardsByUkid);
    }

    private function resetDealState(CardInstance $card): void
    {
        unset($card->flags['deal_linked_recruit']);
        $this->resetDealScoutRecruit($card);
        $this->resetDealVariableRecruit($card);
    }
}
