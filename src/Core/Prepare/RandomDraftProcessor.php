<?php
// src/Core/Prepare/RandomDraftProcessor.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\Db;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\Result;

final class RandomDraftProcessor
{
    public function __construct(
        private GameState $state,
        private Db $db,
    ) {}

    public function start(GameSettings $settings): Result
    {
        if ($settings->draftPickMode() !== GameSettings::DRAFT_PICK_MODE_RANDOM) {
            return Result::error('Неподдерживаемый способ драфта');
        }
        if ($settings->draftAutoSide() !== GameSettings::DRAFT_AUTO_SIDE_BOTH) {
            return Result::error('Неподдерживаемая сторона автовыбора для полного автодрафта');
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

        $processor = new DraftProcessor($this->state, $this->db);
        $result = $processor->start($settings, true);
        if (!$result->success) return $result;

        $autoPicker = $this->autoPicker();
        $this->state->status = 'draft';
        $picked = ['host' => [], 'player' => []];
        $guard = 0;
        while ($this->state->draft !== null) {
            if (++$guard > 1000) {
                return Result::error('Недостаточно допустимых вариантов для автоматического драфта');
            }
            $turn = (string) ($this->state->draft['turn'] ?? '');
            $selection = $autoPicker->pick(
                $processor->validSelections(),
                $this->state->draft['picked'][$turn] ?? []
            );
            if ($selection === null) {
                if (($this->state->draft['grid_mode'] ?? $this->state->settings->draftGridMode()) !== GameSettings::DRAFT_GRID_MODE_DISCRETE) {
                    $result = $processor->pass($turn);
                    if (!$result->success) return $result;
                    continue;
                }
                if (empty($processor->validSelections())) {
                    $result = $processor->skipNoViableSelection($turn, 'auto_forced_skip');
                    if (!$result->success) return $result;
                    continue;
                }
                break;
            }

            $result = $processor->pickSelection($turn, $selection, 'auto_picked');
            if (!$result->success) return $result;
            foreach ($selection['cards'] as $ukid) {
                $picked[$turn][] = $ukid;
            }
        }

        if (count($picked['host']) < GameSettings::MIN_DECK_SIZE
            || count($picked['player']) < GameSettings::MIN_DECK_SIZE) {
            return Result::error('Недостаточно карт для автоматического драфта');
        }

        return Result::ok([
            'random_draft_started',
            'random_draft_cards:host=' . count($picked['host']) . ',player=' . count($picked['player']),
            'stage_changed:view',
        ]);
    }

    private function autoPicker(): DraftAutoPicker
    {
        $ukids = [];
        foreach ($this->state->draft['grid'] ?? [] as $ukid) {
            if ($ukid !== null) $ukids[] = (string) $ukid;
        }
        foreach ($this->state->draft['pool'] ?? [] as $ukid) {
            if ($ukid !== null) $ukids[] = (string) $ukid;
        }
        foreach ($this->state->draft['recycle'] ?? [] as $ukid) {
            if ($ukid !== null) $ukids[] = (string) $ukid;
        }

        $dataProvider = new DraftSelectionDataProvider($this->db);
        $cardsByUkid = $dataProvider->loadCardsByUkid($ukids);

        return new DraftAutoPicker(new DraftSelectionEvaluator(
            $cardsByUkid,
            $dataProvider->loadSynergyByPair($cardsByUkid),
        ));
    }
}
