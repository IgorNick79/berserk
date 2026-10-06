<?php
// src/Core/Prepare/DraftProcessor.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\BoosterGenerator;
use Berserk\Core\CardInstance;
use Berserk\Core\Db;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\Result;

final class DraftProcessor
{
    public function __construct(
        private GameState $state,
        private Db $db,
    ) {}

    public function start(GameSettings $settings, bool $allowRandomPickMode = false): Result
    {
        if ($settings->draftPickMode() !== GameSettings::DRAFT_PICK_MODE_MANUAL
            && !($allowRandomPickMode && $settings->draftPickMode() === GameSettings::DRAFT_PICK_MODE_RANDOM)) {
            return Result::error('Неподдерживаемый способ драфта');
        }
        if ($settings->draftType() !== GameSettings::DRAFT_TYPE_GRID) {
            return Result::error('Неподдерживаемый тип драфта');
        }
        if ($settings->draftGridSize() !== 3) {
            return Result::error('Неподдерживаемый размер сетки драфта');
        }
        if ($settings->draftBoosters() <= 0) {
            return Result::error('Неверное количество бустеров');
        }
        if ($settings->draftBoosterProfile() !== GameSettings::BOOSTER_PROFILE_DEFAULT) {
            return Result::error('Неподдерживаемый профиль бустера');
        }

        $gen = new BoosterGenerator($this->db);

        $pool = [];
        for ($i = 0; $i < $settings->draftBoosters(); $i++) {
            foreach ($gen->generate() as $ukid) {
                $pool[] = $ukid;
            }
        }

        shuffle($pool);

        $grid = [];
        for ($i = 0; $i < 9; $i++) {
            $grid[] = array_shift($pool);
        }

        $this->state->draft = [
            'pool'   => $pool,
            'grid'   => $grid,
            'turn'   => 'host',
            'picked' => ['host' => [], 'player' => []],
            'history' => [],
            'recycle' => [],
            'grid_mode' => $settings->draftGridMode(),
            'consecutive_passes' => 0,
            'round_first_player' => 'host',
            'round_picks' => 0,
            'initial_count' => count($pool) + count(array_filter($grid, fn($ukid) => $ukid !== null)),
        ];

        return Result::ok([
            'draft_started',
            'draft_grid:3',
            'draft_boosters:' . $settings->draftBoosters(),
        ]);
    }

    public function pickRow(string $playerKey, int $row): Result
    {
        if ($row < 1 || $row > 3) return Result::error('Неверная строка');

        $positions = [];
        for ($i = 0; $i < 3; $i++) {
            $positions[] = ($row - 1) * 3 + $i;
        }
        return $this->pickPositions($playerKey, $positions);
    }

    public function pickCol(string $playerKey, int $col): Result
    {
        if ($col < 1 || $col > 3) return Result::error('Неверная колонка');

        $positions = [];
        for ($i = 0; $i < 3; $i++) {
            $positions[] = $i * 3 + ($col - 1);
        }
        return $this->pickPositions($playerKey, $positions);
    }

    public function pickSelection(string $playerKey, array $selection, string $eventPrefix = 'picked'): Result
    {
        $positions = array_values(array_map('intval', $selection['positions'] ?? []));
        if (!$this->isValidSelectionPositions($positions)) {
            return Result::error('Неверный вариант драфта');
        }

        return $this->pickPositions($playerKey, $positions, $eventPrefix);
    }

    /**
     * @return array<int,array{positions:int[],cards:string[]}>
     */
    public function validSelections(): array
    {
        $draft = $this->state->draft ?? null;
        if (!$draft) return [];

        $grid = $draft['grid'] ?? [];
        $selections = [];

        for ($row = 0; $row < 3; $row++) {
            $positions = [$row * 3, $row * 3 + 1, $row * 3 + 2];
            $cards = $this->cardsAt($grid, $positions);
            if (!empty($cards) && $this->isViableSelection((string) ($draft['turn'] ?? ''), $cards, $positions)) {
                $selections[] = ['positions' => $positions, 'cards' => $cards];
            }
        }

        for ($col = 0; $col < 3; $col++) {
            $positions = [$col, $col + 3, $col + 6];
            $cards = $this->cardsAt($grid, $positions);
            if (!empty($cards) && $this->isViableSelection((string) ($draft['turn'] ?? ''), $cards, $positions)) {
                $selections[] = ['positions' => $positions, 'cards' => $cards];
            }
        }

        return $selections;
    }

    private function pickPositions(string $playerKey, array $positions, string $eventPrefix = 'picked'): Result
    {
        $draft = $this->state->draft ?? null;
        if (!$draft) return Result::error('Драфт не активен');
        if ($draft['turn'] !== $playerKey) return Result::error('Не ваш ход');
        $this->ensureDraftRuntime();

        $taken = [];
        foreach ($positions as $pos) {
            if (($this->state->draft['grid'][$pos] ?? null) !== null) {
                $taken[] = $this->state->draft['grid'][$pos];
            }
        }
        if (empty($taken)) return Result::error('Нечего брать');
        if (!$this->isViableSelection($playerKey, $taken, $positions)) {
            return Result::error('Выбор лишает соперника минимальной колоды');
        }

        foreach ($taken as $ukid) {
            $this->state->draft['picked'][$playerKey][] = $ukid;
        }
        $this->state->draft['history'] ??= [];
        $this->state->draft['history'][] = [
            'player' => $playerKey,
            'cards' => array_values($taken),
        ];

        foreach ($positions as $pos) {
            $this->state->draft['grid'][$pos] = null;
        }

        $this->state->draft['consecutive_passes'] = 0;

        if ($this->isDiscreteMode()) {
            $this->state->draft['round_picks'] = (int) ($this->state->draft['round_picks'] ?? 0) + 1;
            if ($this->state->draft['round_picks'] >= 2) {
                $nextFirst = $this->opponent((string) ($this->state->draft['round_first_player'] ?? GameState::PLAYER_HOST));
                $this->recycleGridRemainder();
                $this->state->draft['grid'] = array_fill(0, 9, null);
                $this->fillPositions(range(0, 8));
                $this->state->draft['round_first_player'] = $nextFirst;
                $this->state->draft['round_picks'] = 0;
                $this->state->draft['turn'] = $nextFirst;
            } else {
                $this->state->draft['turn'] = $this->opponent($playerKey);
            }
        } else {
            $this->fillPositions($positions);
            $this->state->draft['turn'] = $this->opponent($playerKey);
        }

        return $this->checkEnd($eventPrefix . ':' . count($taken));
    }

    private function isValidSelectionPositions(array $positions): bool
    {
        foreach ($this->validSelections() as $validSelection) {
            if ($positions === $validSelection['positions']) {
                return true;
            }
        }
        return false;
    }

    public function pass(string $playerKey): Result
    {
        $draft = $this->state->draft ?? null;
        if (!$draft) return Result::error('Драфт не активен');
        if ($draft['turn'] !== $playerKey) return Result::error('Не ваш ход');
        $this->ensureDraftRuntime();
        if ($this->isDiscreteMode()) {
            return Result::error('Пас недоступен в дискретном драфте');
        }

        $this->state->draft['consecutive_passes'] = (int) ($this->state->draft['consecutive_passes'] ?? 0) + 1;
        $event = 'passed';
        if ($this->state->draft['consecutive_passes'] >= 2) {
            $this->recycleGridRemainder();
            $this->state->draft['grid'] = array_fill(0, 9, null);
            $this->fillPositions(range(0, 8));
            $this->state->draft['consecutive_passes'] = 0;
            $event = 'draft_grid_replaced';
        }
        $this->state->draft['turn'] = $this->opponent($playerKey);

        return $this->checkEnd($event);
    }

    public function skipNoViableSelection(string $playerKey, string $event = 'draft_forced_skip'): Result
    {
        $draft = $this->state->draft ?? null;
        if (!$draft) return Result::error('Драфт не активен');
        if ($draft['turn'] !== $playerKey) return Result::error('Не ваш ход');
        $this->ensureDraftRuntime();
        if (!$this->isDiscreteMode()) {
            return Result::error('Автопропуск доступен только в дискретном драфте');
        }
        if (!empty($this->validSelections())) {
            return Result::error('Есть допустимые варианты драфта');
        }

        $this->state->draft['consecutive_passes'] = 0;
        $this->state->draft['round_picks'] = (int) ($this->state->draft['round_picks'] ?? 0) + 1;

        if ($this->state->draft['round_picks'] >= 2) {
            $nextFirst = $this->opponent((string) ($this->state->draft['round_first_player'] ?? GameState::PLAYER_HOST));
            $this->recycleGridRemainder();
            $this->state->draft['grid'] = array_fill(0, 9, null);
            $this->fillPositions(range(0, 8));
            $this->state->draft['round_first_player'] = $nextFirst;
            $this->state->draft['round_picks'] = 0;
            $this->state->draft['turn'] = $nextFirst;
        } else {
            $this->state->draft['turn'] = $this->opponent($playerKey);
        }

        return $this->checkEnd($event);
    }

    public function finish(string $playerKey): Result
    {
        if ($this->state->status !== 'draft') {
            return Result::error('Сейчас не стадия драфта');
        }

        $draft = $this->state->draft ?? null;
        if (!$draft) return Result::error('Драфт не активен');
        if (($draft['turn'] ?? null) !== $playerKey) return Result::error('Не ваш ход');
        if (!$this->canFinish($draft)) {
            return Result::error('Недостаточно карт для завершения драфта');
        }

        return $this->finalize('draft_finished_manually');
    }

    private function checkEnd(string $event): Result
    {
        $draft = $this->state->draft;

        $allEmpty = true;
        foreach ($draft['grid'] as $ukid) {
            if ($ukid !== null) { $allEmpty = false; break; }
        }

        if ($allEmpty && empty($draft['pool']) && empty($draft['recycle'] ?? [])) {
            if (!$this->canFinish($draft)) {
                return Result::error('Пул драфта исчерпан, но у игроков недостаточно карт');
            }
            return $this->finalize($event);
        }

        return Result::ok([$event]);
    }

    private function cardsAt(array $grid, array $positions): array
    {
        $cards = [];
        foreach ($positions as $pos) {
            if (($grid[$pos] ?? null) !== null) {
                $cards[] = (string) $grid[$pos];
            }
        }
        return $cards;
    }

    private function canFinish(array $draft): bool
    {
        return count($draft['picked']['host'] ?? []) >= GameSettings::MIN_DECK_SIZE
            && count($draft['picked']['player'] ?? []) >= GameSettings::MIN_DECK_SIZE;
    }

    private function ensureDraftRuntime(): void
    {
        if ($this->state->draft === null) return;
        $this->state->draft['recycle'] = array_values((array) ($this->state->draft['recycle'] ?? []));
        $this->state->draft['history'] = is_array($this->state->draft['history'] ?? null)
            ? $this->state->draft['history']
            : [];
        $this->state->draft['grid_mode'] = (string) ($this->state->draft['grid_mode'] ?? $this->state->settings->draftGridMode());
        $this->state->draft['consecutive_passes'] = max(0, (int) ($this->state->draft['consecutive_passes'] ?? 0));
        $this->state->draft['round_first_player'] = (string) ($this->state->draft['round_first_player'] ?? GameState::PLAYER_HOST);
        $this->state->draft['round_picks'] = max(0, (int) ($this->state->draft['round_picks'] ?? 0));
    }

    private function isDiscreteMode(): bool
    {
        $this->ensureDraftRuntime();
        return ($this->state->draft['grid_mode'] ?? GameSettings::DRAFT_GRID_MODE_CONTINUOUS)
            === GameSettings::DRAFT_GRID_MODE_DISCRETE;
    }

    private function drawDraftCard(): ?string
    {
        if ($this->state->draft === null) return null;

        if (empty($this->state->draft['pool']) && !empty($this->state->draft['recycle'])) {
            $this->state->draft['pool'] = array_values($this->state->draft['recycle']);
            shuffle($this->state->draft['pool']);
            $this->state->draft['recycle'] = [];
        }

        if (empty($this->state->draft['pool'])) {
            return null;
        }

        return (string) array_shift($this->state->draft['pool']);
    }

    private function fillPositions(array $positions): void
    {
        foreach ($positions as $pos) {
            if (($this->state->draft['grid'][$pos] ?? null) !== null) continue;
            $card = $this->drawDraftCard();
            if ($card === null) break;
            $this->state->draft['grid'][$pos] = $card;
        }
    }

    private function recycleGridRemainder(): void
    {
        foreach ($this->state->draft['grid'] ?? [] as $pos => $ukid) {
            if ($ukid === null) continue;
            $this->state->draft['recycle'][] = (string) $ukid;
            $this->state->draft['grid'][$pos] = null;
        }
    }

    private function isViableSelection(string $playerKey, array $taken, array $positions): bool
    {
        if ($this->state->draft === null || $playerKey === '') return false;

        $hostPicked = count($this->state->draft['picked'][GameState::PLAYER_HOST] ?? []);
        $playerPicked = count($this->state->draft['picked'][GameState::PLAYER_PLAYER] ?? []);
        if ($playerKey === GameState::PLAYER_HOST) {
            $hostPicked += count($taken);
        } else {
            $playerPicked += count($taken);
        }

        $remainingGrid = 0;
        $selected = array_flip($positions);
        foreach (($this->state->draft['grid'] ?? []) as $pos => $ukid) {
            if ($ukid !== null && !isset($selected[$pos])) {
                $remainingGrid++;
            }
        }

        $available = count($this->state->draft['pool'] ?? [])
            + count($this->state->draft['recycle'] ?? [])
            + $remainingGrid;
        $hostNeed = max(0, GameSettings::MIN_DECK_SIZE - $hostPicked);
        $playerNeed = max(0, GameSettings::MIN_DECK_SIZE - $playerPicked);

        return $available >= ($hostNeed + $playerNeed);
    }

    private function finalize(string $event): Result
    {
        $hostUkids   = $this->state->draft['picked']['host'];
        $playerUkids = $this->state->draft['picked']['player'];
        $builder = new DraftDeckBuilder($this->db);

        $hostDeckCards = $builder->buildDeckCards($hostUkids);
        $playerDeckCards = $builder->buildDeckCards($playerUkids);

        $this->state->getPlayer('host')->deckCards   = $hostDeckCards;
        $this->state->getPlayer('player')->deckCards = $playerDeckCards;
        $this->state->getPlayer('host')->deckId   = 0;
        $this->state->getPlayer('player')->deckId = 0;
        $this->createDraftDeckInstances('host', $hostDeckCards);
        $this->createDraftDeckInstances('player', $playerDeckCards);

        $this->state->draft  = null;
        $this->state->status = 'view';

        return Result::ok([$event, 'draft_finished']);
    }

    private function opponent(string $key): string
    {
        return $key === 'host' ? 'player' : 'host';
    }

    private function createDraftDeckInstances(string $owner, array $deckCards): void
    {
        foreach ($this->state->cards as $id => $card) {
            if ($card->owner === $owner
                && in_array($card->zone, [CardInstance::ZONE_DECK, CardInstance::ZONE_SIDEBOARD], true)) {
                unset($this->state->cards[$id]);
            }
        }

        foreach ($deckCards as $item) {
            $count = max(0, (int) ($item['count'] ?? 1));
            for ($i = 0; $i < $count; $i++) {
                $health = (int) ($item['health'] ?? 0);
                $move = (int) ($item['move'] ?? 0);
                $id = $this->state->nextInstanceId();
                $this->state->addCard(new CardInstance(
                    instanceId: $id,
                    ukid: (string) ($item['ukid'] ?? ''),
                    owner: $owner,
                    zone: CardInstance::ZONE_DECK,
                    hp: $health,
                    hpMax: $health,
                    price: (int) ($item['price'] ?? 0),
                    elite: (bool) ($item['elite'] ?? false),
                    single: (bool) ($item['single'] ?? false),
                    type: (string) ($item['type'] ?? 'creature'),
                    element: (string) ($item['element'] ?? 'neutral'),
                    class: (string) ($item['class'] ?? ''),
                    move: $move,
                    moveMax: $move,
                    strikeWeak: (int) ($item['strike_weak'] ?? 0),
                    strikeMedium: (int) ($item['strike_medium'] ?? 0),
                    strikeStrong: (int) ($item['strike_strong'] ?? 0),
                    prop: (array) ($item['prop'] ?? []),
                ));
            }
        }
    }
}
