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
            if (!empty($cards)) {
                $selections[] = ['positions' => $positions, 'cards' => $cards];
            }
        }

        for ($col = 0; $col < 3; $col++) {
            $positions = [$col, $col + 3, $col + 6];
            $cards = $this->cardsAt($grid, $positions);
            if (!empty($cards)) {
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

        $taken = [];
        foreach ($positions as $pos) {
            if (($draft['grid'][$pos] ?? null) !== null) {
                $taken[] = $draft['grid'][$pos];
            }
        }
        if (empty($taken)) return Result::error('Нечего брать');

        foreach ($taken as $ukid) {
            $this->state->draft['picked'][$playerKey][] = $ukid;
        }

        foreach ($positions as $pos) {
            $this->state->draft['grid'][$pos] = null;
        }

        foreach ($positions as $pos) {
            if (empty($this->state->draft['pool'])) break;
            $this->state->draft['grid'][$pos] = array_shift($this->state->draft['pool']);
        }

        $this->state->draft['turn']   = $this->opponent($playerKey);

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

        $this->state->draft['turn'] = $this->opponent($playerKey);

        return $this->checkEnd('passed');
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

        if ($allEmpty && empty($draft['pool'])) {
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
