<?php
// src/Core/Prepare/RandomDraftProcessor.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\BoosterGenerator;
use Berserk\Core\Db;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\Result;

final class RandomDraftProcessor
{
    private const MIN_DRAFTED_CARDS = 30;

    public function __construct(
        private GameState $state,
        private Db $db,
    ) {}

    public function start(GameSettings $settings): Result
    {
        if ($settings->draftPickMode() !== GameSettings::DRAFT_PICK_MODE_RANDOM) {
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

        $pool = $this->generatePool($settings);
        shuffle($pool);

        $picked = ['host' => [], 'player' => []];
        foreach ($pool as $i => $ukid) {
            $picked[$i % 2 === 0 ? 'host' : 'player'][] = $ukid;
        }

        if (count($picked['host']) < self::MIN_DRAFTED_CARDS
            || count($picked['player']) < self::MIN_DRAFTED_CARDS) {
            return Result::error('Недостаточно карт для автоматического драфта');
        }

        $builder = new DraftDeckBuilder($this->db);
        $this->state->getPlayer('host')->deckCards = $builder->buildDeckCards($picked['host']);
        $this->state->getPlayer('player')->deckCards = $builder->buildDeckCards($picked['player']);
        $this->state->getPlayer('host')->deckId = 0;
        $this->state->getPlayer('player')->deckId = 0;

        $this->state->draft = null;
        $this->state->status = 'view';

        return Result::ok([
            'random_draft_started',
            'random_draft_cards:host=' . count($picked['host']) . ',player=' . count($picked['player']),
            'stage_changed:view',
        ]);
    }

    private function generatePool(GameSettings $settings): array
    {
        $gen = new BoosterGenerator($this->db);
        $pool = [];
        for ($i = 0; $i < $settings->draftBoosters(); $i++) {
            foreach ($gen->generate() as $ukid) {
                $pool[] = $ukid;
            }
        }
        return $pool;
    }
}
