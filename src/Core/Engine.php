<?php
// src/Core/Engine.php

declare(strict_types=1);

namespace Berserk\Core;

use Berserk\Core\Movement\MovementResolver;
use Berserk\Core\Prepare\DraftProcessor;

/**
 * Применяет команды к состоянию партии.
 * Единая точка входа для игровой логики.
 */
final class Engine
{
    public function __construct(private ?Db $db = null) {}

    public function apply(GameState $state, string $playerKey, Command $cmd): Result
    {
        $result = $this->doApply($state, $playerKey, $cmd);
        GameLog::append($state, $playerKey, $cmd, $result);
        return $result;
    }

    private function doApply(GameState $state, string $playerKey, Command $cmd): Result
    {

        // Реестр ChoiceHandler — новые команды
        $activeChoice = Choice\ChoiceRegistry::current($state);
        if ($activeChoice !== null) {
            if (in_array($cmd->type, $activeChoice->commandTypes(), true)) {
                return $activeChoice->apply($state, $this, $playerKey, $cmd);
            }

            return Result::error('Ожидается выбор');
        }

        $handler = Choice\ChoiceRegistry::byCommandType($cmd->type, $state);
        if ($handler !== null) {
            return $handler->apply($state, $this, $playerKey, $cmd);
        }

        $strike = new StrikeResolver($state, $this);
        $action = new ActionResolver($state, $this);
        $movement = new MovementResolver($state, $this);
        $turn   = new TurnProcessor($state, $this);
        $zone   = new ZoneManager($state);
        $turnPhase = new TurnPhaseProcessor($state, $this);
        $valhalla = new ValhallaProcessor($state, $this);

        return match ($cmd->type) {
            'choose_mode'  => $this->chooseMode($state, $playerKey, $cmd),
            'confirm_settings' => $this->confirmSettings($state, $playerKey, $cmd),
            'draft_row'    => (new DraftProcessor($state, $this->db))->pickRow($playerKey, (int) $cmd->get('row', 0)),
            'draft_col'    => (new DraftProcessor($state, $this->db))->pickCol($playerKey, (int) $cmd->get('col', 0)),
            'draft_pass'   => (new DraftProcessor($state, $this->db))->pass($playerKey),
            'finish_draft' => (new DraftProcessor($state, $this->db))->finish($playerKey),
            'select_deck'  => $this->selectDeck($state, $playerKey, $cmd),
            'confirm_view' => $this->confirmView($state, $playerKey, $cmd),
            'confirm_turn' => $this->confirmTurn($state, $playerKey, $cmd),
            'choose_side'  => $this->chooseSide($state, $playerKey, $cmd),
            'pick_card'    => $this->pickCard($state, $playerKey, $cmd),
            'unpick_card'  => $this->unpickCard($state, $playerKey, $cmd),
            'confirm_deal' => $this->confirmDeal($state, $playerKey, $cmd),
            'reshuffle'    => $this->reshuffle($state, $playerKey, $cmd),
            'place_card'   => $this->placeCard($state, $playerKey, $cmd),
            'unplace_card' => $this->unplaceCard($state, $playerKey, $cmd),
            'confirm_place'       => $this->confirmPlace($state, $playerKey, $cmd),
            'move'                => $movement->move($playerKey, $cmd),
            'jump'                => $movement->jump($playerKey, $cmd),
            'strike'              => $strike->declare($playerKey, $cmd),
            'choose_defender'     => $strike->chooseDefender($playerKey, $cmd),
            'choose_redirect'       => $strike->chooseRedirect($playerKey, $cmd),
            'confirm_strike'      => $strike->confirmStrike($playerKey, $cmd),
            'choose_strike_mode'  => $strike->chooseStrikeMode($playerKey, $cmd),
            'choose_forced_strike' => $action->chooseForcedStrike($playerKey, $cmd),
            'uchr'                => $action->uchr($playerKey, $cmd),
            'action'              => $action->handle($playerKey, $cmd),
            'end_turn'            => $turn->endTurn($playerKey, $cmd),
            'resign'              => $this->resign($state, $playerKey, $cmd),
            'gain_coin'               => $this->gainCoin($state, $playerKey, $cmd),
            'choose_death_target'     => $this->chooseDeathTarget($state, $playerKey, $cmd),
            'choose_auto_target'      => $this->chooseAutoTarget($state, $playerKey, $cmd),
            'choose_card_option'      => $turn->chooseCardOption($playerKey, $cmd),
            'choose_push_choice'      => $strike->choosePushChoice($playerKey, $cmd),
            'choose_any_death_target' => $this->chooseAnyDeathTarget($state, $playerKey, $cmd),
            'choose_transfer_donor'   => $action->chooseTransferDonor($playerKey, $cmd),
            'choose_transfer_amount'  => $action->chooseTransferAmount($playerKey, $cmd),
            'choose_incarnation_cell' => $turn->chooseIncarnationCell($playerKey, $cmd),
            'choose_self_wound'       => $action->chooseSelfWound($playerKey, $cmd),
            'choose_multi_heal'       => $action->chooseMultiHeal($playerKey, $cmd),
            'choose_multi_discharge'  => $action->chooseMultiDischarge($playerKey, $cmd),
            'choose_revive_target'    => $action->chooseReviveTarget($playerKey, $cmd),
            'choose_revive_cell'      => $action->chooseReviveCell($playerKey, $cmd),
            'cancel_pending'          => $action->cancelPending($playerKey, $cmd),
            'close_prophecy'          => $turn->closeProphecy($playerKey, $cmd),
            'transform_seeker'        => $turn->transformSeeker($playerKey, $cmd),
            'grezy_continue'          => $action->grezyContinue($playerKey, $cmd),
            'grezy_pick'              => $action->grezyPick($playerKey, $cmd),
            'choose_whip_target'      => $action->chooseWhipTarget($playerKey, $cmd),
            'choose_dive_cell'        => $action->chooseDiveCell($playerKey, $cmd),
            'turn_task'               => $turnPhase->runTask($playerKey, $cmd),
            'turn_sub'                => $turnPhase->runSub($playerKey, $cmd),
            'turn_ack'                => $turnPhase->ackPending($playerKey),
            'choose_blood_tap'        => $action->chooseBloodTap($playerKey, $cmd),
            'valhalla_pick'           => $valhalla->chooseTarget($playerKey, $cmd),
            'turn_sub_close'          => $turnPhase->closeSub($playerKey, $cmd),
            'choose_instant_pick'     => $turnPhase->chooseInstantPick($playerKey, $cmd),
            'combat_instant_play'     => $action->playCombatInstant($playerKey, $cmd),
            'combat_instant_pass'     => $strike->passCombatInstant($playerKey),
            'choose_combat_pick'      => $action->chooseCombatPick($playerKey, $cmd),
            'open_turn_instants'      => $action->openTurnInstants($playerKey, $cmd),
            'play_turn_instant'       => $action->playTurnInstant($playerKey, $cmd),
            'choose_close_or_damage'  => $strike->chooseCloseOrDamage($playerKey, $cmd),
            'choose_ally_modifier'    => $strike->chooseAllyModifier($playerKey, $cmd),
            'reorder_start'           => (new ProphecyProcessor($state, $this))->startReorder($playerKey),
            'reorder_card_up'         => (new ProphecyProcessor($state, $this))->reorderCard(
                $playerKey, (int) $cmd->get('card_id', 0), 'top'
            ),
            'reorder_card_down'       => (new ProphecyProcessor($state, $this))->reorderCard(
                $playerKey, (int) $cmd->get('card_id', 0), 'bottom'
            ),
            'summon_start'    => (new ProphecyProcessor($state, $this))->startSummon(
                $playerKey, (int) $cmd->get('card_id', 0)
            ),
            'summon_cell'     => (new ProphecyProcessor($state, $this))->summonChooseCell(
                $playerKey, (int) $cmd->get('row', 0), (int) $cmd->get('col', 0)
            ),
            'summon_creature' => (new ProphecyProcessor($state, $this))->summonChooseCreature(
                $playerKey, (int) $cmd->get('target_id', 0)
            ),
            'summon_cancel'   => (new ProphecyProcessor($state, $this))->summonCancel($playerKey),

            default                   => Result::error("Unknown command: {$cmd->type}"),
        };
    }

    private function chooseMode(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'mode') {
            return Result::error('Сейчас не стадия выбора режима');
        }
        if ($playerKey !== GameState::PLAYER_HOST) {
            return Result::error('Режим выбирает только хост');
        }
        if ($state->mode !== null) {
            return Result::error('Режим уже выбран');
        }

        $mode = (string) $cmd->get('mode', '');
        if (!in_array($mode, [GameSettings::MODE_DRAFT, GameSettings::MODE_SYSTEM, GameSettings::MODE_SEALED], true)) {
            return Result::error('Неверный режим');
        }

        $state->mode = $mode;
        $state->settings = GameSettings::defaults();
        $state->status = 'settings';

        $state->bumpVersion();
        return Result::ok([
            "mode_chosen:{$mode}",
            "stage_changed:{$state->status}",
        ]);
    }

    private function confirmSettings(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'settings') {
            return Result::error('Сейчас не стадия настроек');
        }
        if ($playerKey !== GameState::PLAYER_HOST) {
            return Result::error('Настройки подтверждает только хост');
        }
        if ($state->mode === null) {
            return Result::error('Режим не выбран');
        }

        $state->settings = $this->settingsFromCommand($state->settings, $cmd);

        switch ($state->mode) {
            case GameSettings::MODE_SYSTEM:
                if ($state->settings->systemDeckSelection() !== GameSettings::SYSTEM_DECK_SELECTION_MANUAL) {
                    return Result::error('Неподдерживаемый способ выбора деки');
                }

                $state->status = 'deck';
                $state->bumpVersion();
                return Result::ok(['settings_confirmed:system', 'stage_changed:deck']);

            case GameSettings::MODE_DRAFT:
                if ($this->db === null) {
                    return Result::error('Db недоступен для драфта');
                }

                $result = (new DraftProcessor($state, $this->db))->start($state->settings);
                if (!$result->success) {
                    return $result;
                }

                $state->status = 'draft';
                $state->bumpVersion();
                return Result::ok(array_merge(['settings_confirmed:draft', 'stage_changed:draft'], $result->events));

            case GameSettings::MODE_SEALED:
                return Result::error('Sealed пока не реализован');

            default:
                return Result::error('Неверный режим');
        }
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

        foreach (['type' => 'draft_type', 'grid_size' => 'grid_size', 'boosters' => 'boosters', 'booster_profile' => 'booster_profile'] as $settingKey => $payloadKey) {
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
    /**
     * Хост выбирает деку. Второй играет оставшейся.
     * Переход: deck → view.
     */
    private function selectDeck(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($playerKey !== GameState::PLAYER_HOST) {
            return Result::error('Деку выбирает только хост');
        }

        if ($state->status !== 'deck') {
            return Result::error('Сейчас не стадия выбора деки');
        }

        $host = $state->getPlayer('host');
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

        $player = $state->getPlayer('player');
        $player->deckId = $otherDeckId;
        $player->deckCards = $otherCards;

        $state->getPlayer('player')->deckId = $otherDeckId;

        $state->status = 'view';
        $state->getPlayer('host')->clearConfirmations();
        $state->getPlayer('player')->clearConfirmations();
        $state->bumpVersion();

        return Result::ok([
            "deck_selected:host={$deckId}",
            "deck_assigned:player={$otherDeckId}",
            'stage_changed:view',
        ]);
    }

    /**
     * Игрок подтвердил, что ознакомился со своей декой.
     * Когда оба подтвердили — бросок кубиков и переход в turn.
     */
    private function confirmView(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'view') {
            return Result::error('Сейчас не стадия просмотра деки');
        }

        $player = $state->getPlayer($playerKey);
        if ($player->isConfirmed('view')) {
            return Result::error('Уже подтверждено');
        }

        $player->confirm('view');
        $state->bumpVersion();

        $events = ["view_confirmed:{$playerKey}"];

        if ($this->bothConfirmed($state, 'view')) {
            $state->status = 'turn';
            $state->getPlayer('host')->clearConfirmations();
            $state->getPlayer('player')->clearConfirmations();

            $this->rollDice($state);

            $host   = $state->getPlayer('host');
            $player = $state->getPlayer('player');

            $events[] = 'stage_changed:turn';
            $events[] = "dice_rolled:host={$host->dice},player={$player->dice}";
        }

        return Result::ok($events);
    }

    /**
     * Игрок подтвердил результат броска.
     * Когда оба подтвердили — переход в side.
     */
    private function confirmTurn(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'turn') {
            return Result::error('Сейчас не стадия кубика');
        }

        $player = $state->getPlayer($playerKey);
        if ($player->isConfirmed('turn')) {
            return Result::error('Уже подтверждено');
        }

        $player->confirm('turn');
        $state->bumpVersion();

        $events = ["turn_confirmed:{$playerKey}"];

        if ($this->bothConfirmed($state, 'turn')) {
            $state->status = 'side';
            $state->getPlayer('host')->clearConfirmations();
            $state->getPlayer('player')->clearConfirmations();
            $events[] = 'stage_changed:side';
        }

        return Result::ok($events);
    }

    /**
     * Оба кубика генерируются сразу.
     * При равенстве — переброс, пока значения не станут разными.
     */
    private function rollDice(GameState $state): void
    {
        $host   = $state->getPlayer('host');
        $player = $state->getPlayer('player');

        do {
            $host->dice   = random_int(1, 6);
            $player->dice = random_int(1, 6);
        } while ($host->dice === $player->dice);

        $state->firstPlayer = $host->dice > $player->dice ? 'host' : 'player';
    }

    private function bothConfirmed(GameState $state, string $stage): bool
    {
        return $state->getPlayer('host')->isConfirmed($stage)
            && $state->getPlayer('player')->isConfirmed($stage);
    }

    private function chooseSide(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'side') {
            return Result::error('Сейчас не стадия выбора стороны');
        }

        if ($state->firstPlayer !== $playerKey) {
            return Result::error('Сторону выбирает победитель кубика');
        }

        $side = (int) $cmd->get('side', 0);
        if (!in_array($side, [1, 2], true)) {
            return Result::error('Неверная сторона');
        }

        $oppKey = $state->getOpponentKey($playerKey);
        $otherSide = $side === 1 ? 2 : 1;

        $state->getPlayer($playerKey)->side = $side;
        $state->getPlayer($oppKey)->side    = $otherSide;

        $state->status = 'deal';
        $state->getPlayer('host')->clearConfirmations();
        $state->getPlayer('player')->clearConfirmations();
        
        $this->dealCards($state);

        $state->bumpVersion();

        return Result::ok([
            "side_chosen:{$playerKey}={$side}",
            'stage_changed:deal',
        ]);
    }

    /**
     * Тасует deck_cards и создаёт 15 CardInstance в зоне 'hand' для каждого.
     * Остальные карты остаются в deck_cards (не создаём инстансы, пока не нужны).
     */
    private function dealCards(GameState $state): void
    {
        foreach (['host', 'player'] as $key) {
            $player = $state->getPlayer($key);

            if ($player->side === 2) {
                $player->resources = ['gold' => 25, 'silver' => 23];
            } else {
                $player->resources = ['gold' => 24, 'silver' => 22];
            }

            $this->dealHand($state, $key);
        }
    }

    private function reshuffle(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $player = $state->getPlayer($playerKey);
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

        $this->dealHand($state, $playerKey);

        $state->bumpVersion();

        return Result::ok(["reshuffled:{$playerKey}:{$player->reshuffles}"]);
    }

    /**
     * Раздаёт 15 карт игроку в руку. Все старые инстансы
     * в руке и отряде удаляются — раздача всегда "с нуля".
     */
    private function dealHand(GameState $state, string $playerKey): void
    {
        $player = $state->getPlayer($playerKey);

        // Удаляем инстансы игрока в руке и отряде
        foreach ($state->cards as $id => $card) {
            if ($card->owner === $playerKey
                && in_array($card->zone, [CardInstance::ZONE_HAND, CardInstance::ZONE_SQUAD], true)) {
                unset($state->cards[$id]);
            }
        }

        // Собираем пул из deckCards
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
            $id = $state->nextInstanceId();
            $state->addCard(new CardInstance(
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

    private function pickCard(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $player = $state->getPlayer($playerKey);
        if ($player->isConfirmed('deal')) {
            return Result::error('Отряд уже подтверждён');
        }

        $ukid = (string) $cmd->get('ukid', '');
        if ($ukid === '') {
            return Result::error('Не указана карта');
        }

        // Ищем первый инстанс в руке
        $found = null;
        foreach ($state->cards as $card) {
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

        // Проверяем ресурсы: добавляем карту "виртуально" и считаем
        $calc = ResourceCalculator::compute($state, $playerKey, adding: $found);

        if ($calc['gold_left'] < 0) {
            return Result::error('Не хватает золота');
        }

        (new ZoneManager($state))->toSquad($found);
        $state->bumpVersion();

        return Result::ok(["card_picked:{$playerKey}:{$ukid}"]);
    }

    private function unpickCard(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $player = $state->getPlayer($playerKey);
        if ($player->isConfirmed('deal')) {
            return Result::error('Отряд уже подтверждён');
        }

        $ukid = (string) $cmd->get('ukid', '');
        if ($ukid === '') {
            return Result::error('Не указана карта');
        }

        // Ищем первый инстанс этого ukid в отряде
        foreach ($state->cards as $card) {
            if ($card->owner === $playerKey
                && $card->zone === CardInstance::ZONE_SQUAD
                && $card->ukid === $ukid) {
                (new ZoneManager($state))->toHand($card);
                $state->bumpVersion();
                return Result::ok(["card_unpicked:{$playerKey}:{$ukid}"]);
            }
        }

        return Result::error('Карта не найдена в отряде');
    }

    private function confirmDeal(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'deal') {
            return Result::error('Сейчас не стадия выбора отряда');
        }

        $player = $state->getPlayer($playerKey);
        if ($player->isConfirmed('deal')) {
            return Result::error('Уже подтверждено');
        }

        // Не выбранные уходят вниз колоды
        foreach ($state->cards as $card) {
            if ($card->owner === $playerKey && $card->zone === CardInstance::ZONE_HAND) {
                (new ZoneManager($state))->toDeck($card);
            }
        }

        $player->confirm('deal');
        $state->bumpVersion();

        $events = ["deal_confirmed:{$playerKey}"];

        if ($this->bothConfirmed($state, 'deal')) {
            $state->status = 'place';
            $state->getPlayer('host')->clearConfirmations();
            $state->getPlayer('player')->clearConfirmations();
            $events[] = 'stage_changed:place';
        }

        return Result::ok($events);
    }

    private function placeCard(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'place') {
            return Result::error('Сейчас не стадия расстановки');
        }
        $player = $state->getPlayer($playerKey);
        if ($player->isConfirmed('place')) {
            return Result::error('Расстановка уже подтверждена');
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $row    = (int) $cmd->get('row', 0);
        $col    = (int) $cmd->get('col', 0);

        $card = $state->getCard($cardId);
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_SQUAD) {
            return Result::error('Карта не в отряде');
        }

        $zone = new ZoneManager($state);
        if (!$zone->isCellAllowed($playerKey, $row, $col)) {
            return Result::error('Клетка недоступна');
        }
        if ($zone->isFieldOccupied($row, $col)) {
            return Result::error('Клетка занята');
        }

        (new ZoneManager($state))->toField($card, $row, $col);
        $state->bumpVersion();

        return Result::ok(["card_placed:{$playerKey}:{$cardId}:{$row}_{$col}"]);
    }

    private function unplaceCard(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'place') {
            return Result::error('Сейчас не стадия расстановки');
        }
        $player = $state->getPlayer($playerKey);
        if ($player->isConfirmed('place')) {
            return Result::error('Расстановка уже подтверждена');
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $card = $state->getCard($cardId);

        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Карта не на поле');
        }

        (new ZoneManager($state))->toSquad($card);
        $state->bumpVersion();

        return Result::ok(["card_unplaced:{$playerKey}:{$cardId}"]);
    }

    private function confirmPlace(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'place') {
            return Result::error('Сейчас не стадия расстановки');
        }
        $player = $state->getPlayer($playerKey);
        if ($player->isConfirmed('place')) {
            return Result::error('Уже подтверждено');
        }

        foreach ($state->cards as $card) {
            if ($card->owner === $playerKey && $card->zone === CardInstance::ZONE_SQUAD) {
                return Result::error('Не все карты расставлены');
            }
        }

        $player->confirm('place');
        $state->bumpVersion();

        $events = ["place_confirmed:{$playerKey}"];

        if ($this->bothConfirmed($state, 'place')) {
            $state->status = 'battle';
            $state->getPlayer('host')->clearConfirmations();
            $state->getPlayer('player')->clearConfirmations();

            // Инициализируем бой
            $turn = new TurnProcessor($state, $this);
            $turn->startBattle($state);

            $events[] = 'stage_changed:battle';
        }

        return Result::ok($events);
    }

    public function applyCombatEffect(
        GameState $state,
        array $effect,
        ?CardInstance $source,
        string $ownerKey,
        ?string $choice = null
    ): ?string {
        $type = $effect['type'] ?? '';
        $strike = &$state->battle['strike'];

        switch ($type) {

            case 'strike_level':
                $mode  = $effect['mode'] ?? 'set';
                $value = $effect['value'] ?? null;

                // На чьей стороне владелец карты
                $attacker = $state->getCard($strike['attacker_id']);
                if (!$attacker) return 'атакующий не найден';
                $isAttackerSide = ($attacker->owner === $ownerKey);

                // Какой уровень меняем — attack или defend
                $side = $isAttackerSide ? 'attack' : 'defend';
                $current = $strike['result'][$side] ?? '';

                if ($current === '') {
                    return $isAttackerSide ? 'промах' : 'защитник промахнулся';
                }

                if ($mode === 'set') {
                    if ($current === $value) return 'уже ' . $value;
                    $strike['result'][$side] = $value;
                    if ($isAttackerSide) $strike['level_overridden'] = true;
                    return null;
                }

                if ($mode === 'reduce_one') {
                    $levels = ['strong' => 'medium', 'medium' => 'weak', 'weak' => ''];
                    $new = $levels[$current] ?? '';
                    if ($new === $current) return 'уже минимум';
                    $strike['result'][$side] = $new;
                    return null;
                }
                return 'неверный mode';

            case 'damage_cap':
                $cap = (int) ($effect['value'] ?? 0);
                $strike['damage_cap'] = $cap;

                $selfWound = (int) ($effect['self_wound'] ?? 0);
                if ($selfWound > 0 && $source) {
                    $targetId = $strike['defender_id'] ?: $strike['target_id'];
                    $target   = $state->getCard($targetId);

                    $skipWound = false;
                    $except    = $effect['except_element'] ?? null;
                    if ($except && $target && $target->element === $except) {
                        $skipWound = true;
                    }

                    if (!$skipWound) {
                        $this->applyDamage($state, $source, $selfWound, 'impact');
                    }
                }
                return null;

            case 'dice_choice':
                if ($choice === null) return 'нет выбора';

                $parts = explode(':', $choice);
                if (count($parts) !== 2) return 'неверный выбор';
                [$op, $who] = $parts;

                $attacker = $state->getCard($strike['attacker_id']);
                if (!$attacker) return 'атакующий не найден';

                $attackerKey    = $attacker->owner;
                $isAttackerSide = ($ownerKey === $attackerKey);

                if ($who === 'own') {
                    $isAttackDice = $isAttackerSide;
                } else {
                    $isAttackDice = !$isAttackerSide;
                }

                $sr = new StrikeResolver($state, $this);

                if ($op === 'reroll') {
                    $strike['attack_dice'] = random_int(1, 6);
                    $strike['defend_dice'] = random_int(1, 6);
                    $sr->recalcTable();
                    return null;
                }

                if ($op === 'plus') {
                    if ($isAttackDice) $strike['attack_dice']++;
                    else               $strike['defend_dice']++;
                    $sr->recalcTable();
                    return null;
                }

                if ($op === 'minus') {
                    if ($isAttackDice) {
                        $strike['attack_dice'] = max(1, (int) $strike['attack_dice'] - 1);
                    } else {
                        $strike['defend_dice'] = max(1, (int) $strike['defend_dice'] - 1);
                    }
                    $sr->recalcTable();
                    return null;
                }
                return 'неверная операция';

            case 'damage_on_dice':
                $diceVal = (int) ($effect['value'] ?? 0);
                $damage  = (int) ($effect['damage'] ?? 0);

                $attacker = $state->getCard($strike['attacker_id']);
                if (!$attacker) return 'атакующий не найден';

                $attackerKey    = $attacker->owner;
                $isAttackerMine = ($attackerKey === $ownerKey);

                $defenderId = $strike['defender_id'] ?: $strike['target_id'];
                $defender   = $state->getCard($defenderId);

                $hits = [];

                // Кубик атакующего — противник владельца Мэри?
                if (!$isAttackerMine && (int) $strike['attack_dice'] === $diceVal) {
                    $hits[] = $attacker;
                }

                // Кубик защитника — противник владельца Мэри?
                if ($isAttackerMine && $defender && (int) $strike['defend_dice'] === $diceVal) {
                    $hits[] = $defender;
                }

                if (empty($hits)) {
                    return 'кубик противника ≠ ' . $diceVal;
                }

                foreach ($hits as $t) {
                    $t->hp -= $damage;
                    if ($t->hp <= 0) {
                        $t->hp    = 0;
                        $t->dying = true;
                    }
                }
                return null;
        }
        return 'неизвестный эффект: ' . $type;
    }

    public function applyInstantEffect(GameState $state, array $effect, ?CardInstance $source, CardInstance $target, string $ownerKey): void
    {
        $type = $effect['type'] ?? '';

        switch ($type) {
            case 'damage':
                $value = (int) ($effect['value'] ?? 1);
                $this->applyDamage($state, $target, $value, 'impact', $source);
                break;
            case 'heal_turn_wounds':
                $wounds = (int) ($target->flags['damage_taken_this_turn'] ?? 0);
                if ($wounds > 0) {
                    $target->hp += $wounds;
                    if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                }
                break;
            case 'heal':
                $value = (int) ($effect['value'] ?? 1);
                $target->hp += $value;
                if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                break;
            case 'close_target':
                $target->closed = true;
                break;
            case 'marker':
                $this->applyMarker($target, $effect['marker'] ?? [], $ownerKey);
                break;
        }
    }

    public function applyDamage(
        GameState $state,
        CardInstance $target,
        int $val,
        string $actionType = 'strike',
        ?CardInstance $attacker = null,
        bool $skipHunt = false
    ): void {
        if ($val <= 0) return;

        // Щит света — блокирует весь немагический урон
        foreach ($target->modifiers as $m) {
            if (($m['stat'] ?? '') === 'shield_light') {
                $magicTypes = ['magic', 'cast', 'discharge', 'poison'];
                if (!in_array($actionType, $magicTypes, true)) {
                    return;
                }
                break;
            }
        }

        // Броня
        $armorIgnores = ['cast', 'magic', 'discharge', 'poison', 'heal'];
        if (!in_array($actionType, $armorIgnores, true)) {
            if ($target->armor > 0) {
                if ($val <= $target->armor) {
                    $target->armor -= $val;
                    $val = 0;
                } else {
                    $val -= $target->armor;
                    $target->armor = 0;
                }
            }
        }

        if ($val <= 0) return;

        $hpBefore = $target->hp;
        $target->hp -= $val;

        $realDamage = $hpBefore - $target->hp;   // фактически списанное HP
        if ($realDamage > 0) {
            $target->flags['damage_taken_this_strike'] =
                ((int) ($target->flags['damage_taken_this_strike'] ?? 0)) + $realDamage;
        }
        $target->flags['damage_taken_this_turn'] =
            ((int) ($target->flags['damage_taken_this_turn'] ?? 0)) + $realDamage;


        // Счётчик попаданий от выстрелов/метаний в этот ход (Мира)
        if ($attacker
            && $attacker->instanceId !== $target->instanceId
            && in_array($actionType, ['shot', 'throw', 'uchr'], true)) {
            $target->flags['ranged_hits_this_turn'] =
                ((int) ($target->flags['ranged_hits_this_turn'] ?? 0)) + 1;
        }

        // Вампиризм — атакующий восстанавливает HP на величину нанесённого урона
        $vampireTypes = ['strike', 'tap', 'magic', 'execute'];

        if ($attacker
            && !empty($attacker->prop['vampire'])
            && in_array($actionType, $vampireTypes, true)
            && $attacker->instanceId !== $target->instanceId
            && $attacker->hp > 0) {

            $offset = (int) ($attacker->prop['vampire_offset'] ?? 0);
            $healAmount = $val + $offset;
            if ($healAmount < 0) $healAmount = 0;

            $maxHp = (int) ($attacker->prop['hp_max_override'] ?? $attacker->hpMax);
            $heal = min($healAmount, $maxHp - $attacker->hp);

            if ($heal > 0) {
                $attacker->hp += $heal;

                if (!empty($state->battle['strike'])) {
                    $state->battle['strike']['vampire_heal'][] = [
                        'instance_id' => $attacker->instanceId,
                        'ukid'        => $attacker->ukid,
                        'heal'        => $heal,
                    ];
                }
            }
        }

        // Hunt-эффект — при любом ударе летуна по цели с маркером
        if ($attacker 
            && $attacker->type === 'fly' 
            && isset($target->markers['hunt'])
            && !$skipHunt) {

            $huntBonus = (int) ($target->markers['hunt']['bonus'] ?? 2);
            unset($target->markers['hunt']);

            if (!empty($state->battle['strike'])) {
                $state->battle['strike']['hunt_trigger'][] = [
                    'target_id'   => $target->instanceId,
                    'attacker_id' => $attacker->instanceId,
                    'bonus'       => $huntBonus,
                ];
            }

            $target->hp -= $huntBonus;
        }

        if ($target->hp <= 0) {
            $target->hp = 0;
            $target->dying = true;

            (new ValhallaProcessor($state, $this))->markPending($target, $actionType);

            $this->refreshArmor($state);
            $this->clearRootedBySource($state, $target->instanceId);

            // Трупоедство
            if ($attacker
                && !empty($attacker->prop['deadeat'])
                && CardStats::isMeleeAction($actionType)
                && $attacker->hp > 0) {

                if (!empty($state->battle['strike'])) {
                    // Откладываем — сражение ещё не закончилось
                    $state->battle['strike']['deadeat_queue'][] = [
                        'instance_id' => $attacker->instanceId,
                    ];
                } else {
                    // Вне сражения — сразу
                    if (!empty($attacker->prop['deadeat_hp_bonus'])) {
                        $attacker->hpMax += (int) $attacker->prop['deadeat_hp_bonus'];
                    }
                    $attacker->hp = $attacker->hpMax;
                }
            }

            $deathByAttack = in_array($actionType, 
                ['strike', 'uchr', 'shot', 'throw', 'tap', 'discharge', 'answer'], 
                true
            );
            $this->triggerOnAnyDeath($state, $target, $actionType);
            if ($deathByAttack) {
                $this->triggerOnDeath($state, $target);
            }

            if (empty($state->battle['strike'])) {
                (new ZoneManager($state))->toGraveyard($target);
            }
        }

        $this->checkGameOver($state);
    }

    private function diceToLevel(int $dice): string
    {
        if ($dice <= 3) return 'weak';
        if ($dice <= 5) return 'medium';
        return 'strong';
    }

    public function hasDefense(GameState $state, CardInstance $target, string $actionType, ?CardInstance $attacker = null): bool
    {
        return CardStats::hasDefense($state, $target, $actionType, $attacker);
    }

    private function resign(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'battle') {
            return Result::error('Сдаться можно только в бою');
        }
        if ($state->winner !== null) {
            return Result::error('Игра уже завершена');
        }

        $state->winner = $state->getOpponentKey($playerKey);
        $state->status = 'game_over';
        $state->bumpVersion();

        return Result::ok(["resigned:{$playerKey}", "winner:{$state->winner}"]);
    }

    public function checkGameOver(GameState $state): void
    {
        if ($state->winner !== null) return;

        foreach (['host', 'player'] as $key) {
            if (!$this->hasCreaturesOnField($state, $key)) {
                $state->winner = $state->getOpponentKey($key);
                $state->status = 'game_over';
                return;
            }
        }
    }

    private function hasCreaturesOnField(GameState $state, string $playerKey): bool
    {
        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey) continue;
            if ($card->dying) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD 
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->type !== 'creature' && $card->type !== 'fly') continue;
            return true;
        }
        return false;
    }

    private function gainCoin(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }
        if ($state->battle['active'] !== $playerKey) {
            return Result::error('Сейчас не ваш ход');
        }
        if (!empty($state->battle['strike'])) {
            return Result::error('Идёт сражение');
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $card   = $state->getCard($cardId);

        if (!$card
            || $card->owner !== $playerKey
            || ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING)) {
            return Result::error('Карта не на поле');
        }
        if ($card->closed) {
            return Result::error('Карта закрыта');
        }
        if (empty($card->prop['save_coins'])) {
            return Result::error('Карта не умеет копить монеты');
        }

        $max = (int) ($card->prop['coins']['max_value'] ?? 0);
        if ($max > 0 && $card->coins >= $max) {
            return Result::error('Максимум монет');
        }

        $card->coins++;
        $card->closed = true;
        $this->syncCoinBonus($card);
        $state->bumpVersion();

        return Result::ok(["coin_gained:{$playerKey}:{$cardId}:total={$card->coins}"]);
    }

    public function applyMarker(CardInstance $target, array $marker, string $sourceKey): void
    {
        $type   = $marker['type'];
        $timing = $marker['timing'] ?? 'source_turn';

        if (!isset($target->markers[$type])) {
            $target->markers[$type] = [
                'value'  => 1,
                'expire' => $marker['expire'] ?? null,
                'source' => $sourceKey,
                'timing' => $timing,
            ];

            if (!empty($marker['skip_first_tick'])) {
                $target->markers[$type]['skip_first_tick'] = true;
            }
        } else {
            $target->markers[$type]['value']++;
            if (isset($marker['expire'])) {
                $target->markers[$type]['expire'] = $marker['expire'];
            }
            if (!empty($marker['skip_first_tick'])) {
                $target->markers[$type]['skip_first_tick'] = true;
            }
        }
    }

    public function applyPoison(CardInstance $target, int $value, string $sourceKey): void
    {
        if ($value <= 0) return;

        // zoo — защита от отравлений
        if (!empty($target->prop['zoo'])) {
            return;
        }

        if (!isset($target->markers['poison'])) {
            $target->markers['poison'] = [
                'value'  => $value,
                'source' => $sourceKey,
                'timing' => 'permanent',
            ];
        } else {
            // Замещение: ставим большее
            if ($value > $target->markers['poison']['value']) {
                $target->markers['poison']['value'] = $value;
            }
        }
    }

    public function applyRegeneration(CardInstance $card): void
    {
        $regen = $card->prop['regeneration'] ?? 0;
        if (is_array($regen)) $regen = (int) ($regen['value'] ?? 0);
        else $regen = (int) $regen;

        // Модификаторы (на будущее)
        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') === 'regeneration') {
                $regen += (int) ($m['value'] ?? 0);
            }
        }

        if ($regen <= 0) return;
        if ($card->hp >= $card->hpMax) return; // уже целая

        $card->hp += $regen;
        if ($card->hp > $card->hpMax) {
            $card->hp = $card->hpMax;
        }
    }

    public function refreshArmor(GameState $state): void
    {
        foreach ($state->cards as $card) {
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;

            $newMax = CardStats::computeArmor($state, $card);

            // Если максимум изменился — броня считается неиспользованной
            if ($newMax !== $card->armorMax) {
                $card->armorMax = $newMax;
                $card->armor    = $newMax;
            }
        }
    }

    public function triggerOnDeath(GameState $state, CardInstance $died): void
    {
        if (empty($died->prop['on_death'])) return;

        foreach ($died->prop['on_death'] as $effect) {
            $this->applyDeathEffect($state, $died, $effect);
        }
    }

    private function applyDeathEffect(GameState $state, CardInstance $died, array $effect): void
    {
        $targetType = $effect['target'] ?? 'near';

        // Отложенный выбор цели — игрок решит, кого бить
        if ($targetType === 'choice') {
            $candidates = $this->findDeathTargets($state, $died, $effect);
            if (empty($candidates)) return;

            $state->battle['strike']['pending_choice'] = [
                'source'     => 'on_death',
                'died_ukid'  => $died->ukid,
                'died_id'    => $died->instanceId,
                'effect'     => $effect,
                'candidates' => array_map(fn ($c) => $c->instanceId, $candidates),
            ];
            return;
        }

        // Автоматический эффект (near/killer) — как было
        $type  = $effect['type'] ?? '';
        $value = (int) ($effect['value'] ?? 0);

        $targets = $this->findDeathTargets($state, $died, $effect);
        if (empty($targets)) return;

        $applied = [];

        foreach ($targets as $target) {
            $hpBefore = $target->hp;

            switch ($type) {
                case 'tap':
                case 'damage':
                    $this->applyDamage($state, $target, $value, 'tap');
                    break;
                case 'heal':
                    $target->hp += $value;
                    if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                    break;
                case 'poison':
                    $this->applyPoison($target, $value, $died->owner);
                    break;
            }

            $applied[] = [
                'target_id' => $target->instanceId,
                'damage'    => max(0, $hpBefore - $target->hp),
                'heal'      => max(0, $target->hp - $hpBefore),
            ];
        }

        if (!empty($state->battle['strike'])) {
            $state->battle['strike']['death_triggers'][] = [
                'died_ukid' => $died->ukid,
                'died_id'   => $died->instanceId,
                'effect'    => $type,
                'value'     => $value,
                'targets'   => $applied,
            ];
        }
    }

    private function findDeathTargets(GameState $state, CardInstance $died, array $effect): array
    {
        $targetType = $effect['target'] ?? 'near';
        $filter     = $effect['filter'] ?? null;

        $result = [];

        foreach ($state->cards as $card) {
            if ($card->instanceId === $died->instanceId) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->owner === $died->owner) continue;

            if ($filter && !$this->matchFilter($card, $filter)) continue;

            // Для 'near' — проверяем близость, для 'choice' — нет
            if ($targetType === 'near') {
                if ($died->zone !== CardInstance::ZONE_FIELD) continue;
                if ($card->zone !== CardInstance::ZONE_FIELD) continue;

                $dr = abs($card->row - $died->row);
                $dc = abs($card->col - $died->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;
            }

            $result[] = $card;
        }

        return $result;
    }

    private function matchFilter(CardInstance $card, string $filter): bool
    {
        return match ($filter) {
            'enemy_creature_not_flying' => $card->type === 'creature',
            'own_creature'              => true,
            'own_yordling'              => $card->class === 'Йордлинг',
            default                     => true,
        };
    }

    private function chooseDeathTarget(GameState $state, string $playerKey, Command $cmd): Result
    {
        $strike = $state->battle['strike'] ?? null;
        if (!$strike || empty($strike['pending_choice'])) {
            return Result::error('Нет ожидающего выбора');
        }

        $pc = $strike['pending_choice'];
        $candidates = $pc['candidates'] ?? [];

        // Выбирает владелец умершей карты
        $died = $state->getCard($pc['died_id']);
        if (!$died || $playerKey !== $died->owner) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);
        if (!in_array($targetId, $candidates, true)) {
            return Result::error('Неверная цель');
        }

        $target = $state->getCard($targetId);
        $effect = $pc['effect'];
        $value  = (int) ($effect['value'] ?? 0);
        $type   = $effect['type'] ?? '';

        // Применяем эффект
        $hpBefore = $target->hp;
        switch ($type) {
            case 'tap':
            case 'damage':
                $this->applyDamage($state, $target, $value, 'tap');
                break;
            case 'heal':
                $target->hp += $value;
                if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                break;
            case 'poison':
                $this->applyPoison($target, $value, $died->owner);
                break;
        }

        // Пишем результат
        $state->battle['strike']['death_triggers'][] = [
            'died_ukid' => $pc['died_ukid'],
            'died_id'   => $pc['died_id'],
            'effect'    => $type,
            'value'     => $value,
            'targets'   => [[
                'target_id' => $target->instanceId,
                'damage'    => max(0, $hpBefore - $target->hp),
                'heal'      => max(0, $target->hp - $hpBefore),
            ]],
        ];

        unset($state->battle['strike']['pending_choice']);

        $state->bumpVersion();
        return Result::ok(["death_choice:{$targetId}"]);
    }

    public function revealCard(GameState $state, CardInstance $card): void
    {
        if ($card->revealed) return;

        $card->revealed = true;

        if ($card->type === 'fly' && $card->zone === CardInstance::ZONE_FIELD) {
            $this->moveToFlyingZone($state, $card);
        }
    }

    public function moveToFlyingZone(GameState $state, CardInstance $card): void
    {
        (new ZoneManager($state))->toFlying($card);
    }

    public function applyStrikeEffects(
        CardInstance $attacker,
        CardInstance $target,
        string $level,
        ?array $effects = null
    ): void {
        $effects = $effects ?? ($attacker->prop['strike_effects'] ?? []);
        if (!is_array($effects)) return;

        foreach ($effects as $effect) {
            $levels = $effect['levels'] ?? null;
            if ($levels !== null && !in_array($level, $levels, true)) continue;

            if (!empty($effect['poison'])) {
                $this->applyPoison($target, (int) $effect['poison'], $attacker->owner);
            }

            if (!empty($effect['close'])) {
                $target->closed = true;
            }

            if (!empty($effect['marker']['type'])) {
                $this->applyMarker($target, $effect['marker'], $attacker->owner);
            }

            if (!empty($effect['rooted'])) {
                if (!isset($target->markers['rooted'])) {
                    $target->markers['rooted'] = ['sources' => []];
                }
                if (!in_array($attacker->instanceId, $target->markers['rooted']['sources'], true)) {
                    $target->markers['rooted']['sources'][] = $attacker->instanceId;
                }
            }
        }
    }

    public function flushDeadeatQueue(GameState $state): void
    {
        if (empty($state->battle['strike']['deadeat_queue'])) return;

        $queue = $state->battle['strike']['deadeat_queue'];

        foreach ($queue as $item) {
            $card = $state->getCard($item['instance_id']);
            if (!$card) continue;
            if ($card->hp <= 0) continue;

            if (!empty($card->prop['deadeat_hp_bonus'])) {
                $card->hpMax += (int) $card->prop['deadeat_hp_bonus'];
            }

            $hpBefore = $card->hp;
            $card->hp = $card->hpMax;

            if ($card->hp > $hpBefore) {
                $state->battle['strike']['deadeat'][] = [
                    'instance_id' => $card->instanceId,
                    'ukid'        => $card->ukid,
                    'heal'        => $card->hp - $hpBefore,
                ];
            }
        }
        unset($state->battle['strike']['deadeat_queue']);
    }

    public function applyAnswer(
        GameState $state,
        CardInstance $defender,
        CardInstance $attacker,
        string $actionType
    ): void {
        $answer = $defender->prop['answer'] ?? null;
        if (!$answer || !is_array($answer)) return;

        // Не срабатывает, если защитник уже мёртв
        if ($defender->hp <= 0) return;

        // Тип атаки должен подходить
        $types = $answer['types'] ?? null;
        if ($types !== null && !in_array($actionType, $types, true)) return;

        // Наложение маркера на атакующего (Уриил)
        if (!empty($answer['marker']['type'])) {
            $this->applyMarker($attacker, $answer['marker'], $defender->owner);
        }

        // Урон
        $val = (int) ($answer['value'] ?? 0);
        if ($val > 0) {
            $this->applyDamage($state, $attacker, $val, 'answer');

            if (!empty($state->battle['strike'])) {
                $state->battle['strike']['answer_damage'][] = [
                    'defender_id' => $defender->instanceId,
                    'attacker_id' => $attacker->instanceId,
                    'value'       => $val,
                ];
            }
        }
    }

    public function openAutoChoice(GameState $state, CardInstance $attacker): bool
    {
        $auto = $attacker->prop['auto'] ?? null;
        if (!$auto || !is_array($auto)) return false;

        foreach ($auto as $effect) {
            $trigger = $effect['trigger'] ?? 'always';
            if ($trigger === 'strike_hit') {
                if (empty($state->battle['strike']['strike_hit'])) continue;
            }

            // Перехват Резчика (только для shot)
            $interceptors = [];
            if (($effect['type'] ?? '') === 'shot') {
                $interceptors = CardStats::getRangedInterceptors(
                    $state, $attacker->owner, 'shot'
                );
            }
            $interceptorIds = array_map(fn($p) => $p->instanceId, $interceptors);

            $candidates = [];
            foreach ($state->cards as $target) {
                if ($target->zone !== CardInstance::ZONE_FIELD
                    && $target->zone !== CardInstance::ZONE_FLYING) continue;
                if ($target->owner === $attacker->owner) continue;

                // Резчик: если есть перехватчики — только они
                if (!empty($interceptorIds) && !in_array($target->instanceId, $interceptorIds, true)) {
                    continue;
                }

                // zov / defense
                if (!empty($effect['type']) && in_array($effect['type'], ['shot', 'throw', 'discharge'], true)) {
                    if (CardStats::hasDefense($state, $target, $effect['type'], $attacker)) {
                        continue;
                    }
                }

                $range = (int) ($effect['range'] ?? 0);

                $attackerIsFlying = ($attacker->zone === CardInstance::ZONE_FLYING);
                $targetIsFlying   = ($target->zone === CardInstance::ZONE_FLYING);

                if (!$attackerIsFlying && !$targetIsFlying) {
                    $dr = abs($target->row - $attacker->row);
                    $dc = abs($target->col - $attacker->col);
                    $maxd = max($dr, $dc);
                    $dist = $dr + $dc;

                    // Соседняя клетка запрещена
                    if ($maxd <= 1) continue;
                    // range = 0 → без ограничения дальности
                    if ($range > 0 && $dist > $range) continue;
                }

                $candidates[] = $target->instanceId;
            }

            if (empty($candidates)) continue;
            
            $state->battle['strike']['pending_auto'] = [
                'source_id'  => $attacker->instanceId,
                'effect'     => $effect,
                'candidates' => $candidates,
            ];
            $state->battle['strike']['state'] = 'waiting_auto_target';
            $state->battle['strike']['confirmed'] = [];  // сбрасываем
            return true;
        }
        return false;
    }

    private function chooseAutoTarget(GameState $state, string $playerKey, Command $cmd): Result
    {
        $strike = $state->battle['strike'] ?? null;
        if (!$strike || $strike['state'] !== 'waiting_auto_target') {
            return Result::error('Сейчас не выбор цели auto');
        }

        $pa = $strike['pending_auto'];
        $source = $state->getCard($pa['source_id']);
        if (!$source || $source->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);

        // Пропуск (если игрок не хочет)
        if ($targetId === 0) {
            unset($state->battle['strike']['pending_auto']);

            // Переносим dying + закрываем
            (new ZoneManager($state))->flushDying();

            $state->battle['strike'] = null;
            $this->refreshArmor($state);

            $state->bumpVersion();
            return Result::ok(['auto_skipped']);
        }

        if (!in_array($targetId, $pa['candidates'], true)) {
            return Result::error('Неверная цель');
        }

        $target = $state->getCard($targetId);
        if (!$target) {
            return Result::error('Цель не найдена');
        }

        $effect = $pa['effect'];
        $val = (int) ($effect['value'] ?? 0);

        // Применяем урон
        $hpBefore = $target->hp;
        $this->applyDamage($state, $target, $val, 'shot', $source);

        // Записываем результат
        $state->battle['strike']['auto_effects'][] = [
            'name'      => $effect['name'] ?? 'Auto',
            'source_id' => $source->instanceId,
            'target_id' => $target->instanceId,
            'damage'    => max(0, $hpBefore - $target->hp),
        ];

        unset($state->battle['strike']['pending_auto']);

        // Переносим dying
        (new ZoneManager($state))->flushDying();

        // Закрываем сражение
        $state->battle['strike'] = null;
        $this->refreshArmor($state);

        $state->bumpVersion();
        return Result::ok(["auto_target:{$targetId}"]);
    }

    public function clearRootedBySource(GameState $state, int $sourceId): void
    {
        foreach ($state->cards as $card) {
            if (!isset($card->markers['rooted'])) continue;

            $sources = $card->markers['rooted']['sources'] ?? [];
            $sources = array_values(array_filter($sources, fn($id) => $id !== $sourceId));

            if (empty($sources)) {
                unset($card->markers['rooted']);
            } else {
                $card->markers['rooted']['sources'] = $sources;
            }
        }
    }

    public function getRegenerationAmount(CardInstance $card): int
    {
        $regen = $card->prop['regeneration'] ?? 0;
        if (is_array($regen)) $regen = (int) ($regen['value'] ?? 0);
        else $regen = (int) $regen;

        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') === 'regeneration') {
                $regen += (int) ($m['value'] ?? 0);
            }
        }
        return $regen;
    }

    public function triggerOnAnyDeath(GameState $state, CardInstance $died, string $cause = 'any'): void
    {
        $poisonValue = (int) ($died->markers['poison']['value'] ?? 0);

        foreach ($state->cards as $seeder) {
            if ($seeder->zone !== CardInstance::ZONE_FIELD
                && $seeder->zone !== CardInstance::ZONE_FLYING) continue;
            if ($seeder->dying) continue;
            if ($seeder->instanceId === $died->instanceId) continue;

            $config = $seeder->prop['on_any_death'] ?? null;
            if (!$config || !is_array($config)) continue;

            $sideFilter = $config['side'] ?? 'enemy';
            if ($sideFilter === 'enemy' && $seeder->owner === $died->owner) continue;

            // Проверка причины смерти
            $configCause = $config['cause'] ?? 'any';
            if ($configCause === 'poison' && $cause !== 'poison') continue;
            if ($configCause !== 'any' && $configCause !== 'poison' && $configCause !== $cause) continue;

            if (!empty($seeder->flags['any_death_used_this_turn'])
                && !empty($config['once_per_turn'])) continue;

            // Эффект «получить монету» — сразу, без pending
            $effect = $config['effect'] ?? null;
            if ($effect === 'get_coin') {
                $value = (int) ($config['value'] ?? 1);

                $filter = $config['filter'] ?? 'any';
                if ($filter === 'not_flying' && $died->type === 'fly') continue;

                $max = (int) ($seeder->prop['coins']['max_value'] ?? 0);
                $before = $seeder->coins;

                $seeder->coins += $value;
                if ($max > 0 && $seeder->coins > $max) $seeder->coins = $max;

                if ($seeder->coins > $before) {
                    $this->syncCoinBonus($seeder);
                }

                if (!empty($config['once_per_turn'])) {
                    $seeder->flags['any_death_used_this_turn'] = true;
                }
                continue;
            }

            // Кандидаты (Сеятель хвори)
            $candidates = [];
            foreach ($state->cards as $t) {
                if ($t->zone !== CardInstance::ZONE_FIELD) continue;
                if ($t->instanceId === $died->instanceId) continue;
                if ($t->dying) continue;
                if ($t->owner === $seeder->owner) continue;

                $dr = abs($t->row - $died->row);
                $dc = abs($t->col - $died->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

                $filter = $config['filter'] ?? 'any';
                if ($filter === 'not_flying' && $t->type === 'fly') continue;

                $candidates[] = $t->instanceId;
            }

            if (empty($candidates)) continue;

            if (!isset($state->battle['pending_any_death'])) {
                $state->battle['pending_any_death'] = [];
            }

            $state->battle['pending_any_death'][] = [
                'source_id'    => $seeder->instanceId,
                'died_id'      => $died->instanceId,
                'died_ukid'    => $died->ukid,
                'candidates'   => $candidates,
                'poison_value' => $poisonValue,
            ];

            if (!empty($config['once_per_turn'])) {
                $seeder->flags['any_death_used_this_turn'] = true;
            }
        }
    }

    public function chooseAnyDeathTarget(GameState $state, string $playerKey, Command $cmd): Result
    {
        $queue = $state->battle['pending_any_death'] ?? [];
        if (empty($queue)) {
            return Result::error('Нет ожидающего выбора');
        }

        $item = $queue[0];
        $sourceCard = $state->getCard($item['source_id']);
        if (!$sourceCard || $sourceCard->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);

        // Пропуск
        if ($targetId === 0) {
            array_shift($state->battle['pending_any_death']);
            $state->bumpVersion();
            return Result::ok(['any_death_skipped']);
        }

        if (!in_array($targetId, $item['candidates'], true)) {
            return Result::error('Неверная цель');
        }

        $target = $state->getCard($targetId);
        if (!$target) {
            return Result::error('Цель не найдена');
        }

        $poisonValue = (int) $item['poison_value'];
        $this->applyPoison($target, $poisonValue, $sourceCard->owner);

        array_shift($state->battle['pending_any_death']);

        $state->bumpVersion();
        return Result::ok(["any_death_target:{$targetId}"]);
    }

    public function tryProphecyBlock(
        GameState $state,
        CardInstance $attacker,
        CardInstance $target,
        string $actionType
    ): bool {
        $config = $target->prop['prophecy_block'] ?? null;
        if (!$config) return false;

        $attackTypes = ['strike', 'tap', 'uchr', 'shot', 'throw'];
        if (!in_array($actionType, $attackTypes, true)) return false;

        $pp     = new ProphecyProcessor($state, $this);
        $peeked = $pp->peek($target->owner, 1);
        if ($peeked === null) return false;

        $isOdd = (bool) $peeked['meta']['is_odd'];

        $alreadyBlocked = !empty($target->flags['attack_block_used_this_turn']);
        $willBlock      = $isOdd && !$alreadyBlocked;

        if ($willBlock) {
            $target->flags['attack_block_used_this_turn'] = true;
        }

        $title = match (true) {
            $willBlock => 'Нечётная цена — атака заблокирована',
            $isOdd     => 'Нечётная цена, но блок уже использован',
            default    => 'Чётная цена — атака проходит',
        };

        $actions = [['label' => 'Закрыть', 'cmd' => 'close_prophecy', 'class' => 'skip']];

        $pp->commit($target->owner, $target, $peeked, 'block', $title, $actions);

        return $willBlock;
    }

    public function syncCoinBonus(CardInstance $card): void
    {
        if (empty($card->prop['coin_strike_bonus'])) return;

        // Убираем старый модификатор
        $card->modifiers = array_values(array_filter(
            $card->modifiers,
            fn($m) => ($m['stat'] ?? '') !== 'coin_strike_bonus'
        ));

        if ($card->coins <= 0) return;

        $card->modifiers[] = [
            'stat'   => 'coin_strike_bonus',
            'value'  => (int) $card->coins,
            'expire' => 'permanent',
        ];
    }


}
