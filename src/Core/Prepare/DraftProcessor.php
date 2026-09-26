<?php
// src/Core/Prepare/DraftProcessor.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\BoosterGenerator;
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

    public function start(GameSettings $settings): Result
    {
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
            'pool'         => $pool,
            'grid'         => $grid,
            'turn'         => 'host',
            'picked'       => ['host' => [], 'player' => []],
            'passed'       => ['host' => false, 'player' => false],
            'pass_blocked' => false,
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

    private function pickPositions(string $playerKey, array $positions): Result
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
        $this->state->draft['passed'] = ['host' => false, 'player' => false];

        return $this->checkEnd('picked:' . count($taken));
    }

    public function pass(string $playerKey): Result
    {
        $draft = $this->state->draft ?? null;
        if (!$draft) return Result::error('Драфт не активен');
        if ($draft['turn'] !== $playerKey) return Result::error('Не ваш ход');
        if ($draft['pass_blocked']) return Result::error('Пас больше недоступен');

        $this->state->draft['passed'][$playerKey] = true;

        if ($this->state->draft['passed']['host'] && $this->state->draft['passed']['player']) {
            $this->state->draft['pass_blocked'] = true;
            $this->state->draft['passed']       = ['host' => false, 'player' => false];
        }

        $this->state->draft['turn'] = $this->opponent($playerKey);

        return $this->checkEnd('passed');
    }

    private function checkEnd(string $event): Result
    {
        $draft = $this->state->draft;

        $allEmpty = true;
        foreach ($draft['grid'] as $ukid) {
            if ($ukid !== null) { $allEmpty = false; break; }
        }

        if ($allEmpty && empty($draft['pool'])) {
            return $this->finalize($event);
        }

        return Result::ok([$event]);
    }

    private function finalize(string $event): Result
    {
        $hostUkids   = $this->state->draft['picked']['host'];
        $playerUkids = $this->state->draft['picked']['player'];

        $this->state->getPlayer('host')->deckCards   = $this->buildDeckCards($hostUkids);
        $this->state->getPlayer('player')->deckCards = $this->buildDeckCards($playerUkids);
        $this->state->getPlayer('host')->deckId   = 0;
        $this->state->getPlayer('player')->deckId = 0;

        $this->state->draft  = null;
        $this->state->status = 'view';

        return Result::ok([$event, 'draft_finished']);
    }

    private function buildDeckCards(array $ukids): array
    {
        if (empty($ukids)) return [];

        $counts = [];
        foreach ($ukids as $ukid) {
            $counts[$ukid] = ($counts[$ukid] ?? 0) + 1;
        }

        $unique = array_keys($counts);
        $in = "'" . implode("','", array_map(fn($u) => $this->db->escape($u), $unique)) . "'";

        $elements = [];
        foreach ($this->db->fetchAll("SELECT ind, code FROM elements") as $e) {
            $elements[(int) $e['ind']] = $e['code'];
        }

        $rows = $this->db->fetchAll(
            "SELECT ukid, price, health, move, elite, type, class,
                    strike_weak, strike_medium, strike_strong, element_id, prop
             FROM cards WHERE ukid IN ($in)"
        );
        $byUkid = [];
        foreach ($rows as $r) $byUkid[$r['ukid']] = $r;

        $result = [];
        foreach ($counts as $ukid => $count) {
            $r = $byUkid[$ukid] ?? null;
            if (!$r) continue;

            $result[] = [
                'ukid'          => $ukid,
                'count'         => $count,
                'price'         => (int) $r['price'],
                'elite'         => (bool) $r['elite'],
                'element'       => $elements[(int) $r['element_id']] ?? 'neutral',
                'health'        => (int) $r['health'],
                'move'          => (int) $r['move'],
                'strike_weak'   => (int) $r['strike_weak'],
                'strike_medium' => (int) $r['strike_medium'],
                'strike_strong' => (int) $r['strike_strong'],
                'prop'          => $r['prop'] ? json_decode($r['prop'], true) : [],
                'type'          => $r['type'] ?? 'creature',
                'class'         => $r['class'] ?? '',
            ];
        }
        return $result;
    }

    private function opponent(string $key): string
    {
        return $key === 'host' ? 'player' : 'host';
    }
}
