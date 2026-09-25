<?php
// src/Core/Choice/ValhallaPickChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\View\Ui\PanelSpec;

final class ValhallaPickChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_valhalla_pick';
    }

    public function commandTypes(): array
    {
        return ['valhalla_pick'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pv = $state->battle['pending_valhalla_pick'] ?? null;
        if (!$pv) return null;

        $card = $state->getCard($pv['card_id']);
        $name = $card ? ($cardsInfo[$card->ukid]['name'] ?? '?') : '?';

        if ($pv['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $name . ' — Вальхалла',
                isMine: false,
            );
        }

        $vp = new \Berserk\Core\ValhallaProcessor($state, $engine = new Engine());
        $candidates = $vp->collectCandidates($card, $playerKey);

        $buttons = [];
        foreach ($candidates as $tid) {
            $tc = $state->getCard($tid);
            if (!$tc) continue;
            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $coords = $tc->zone === CardInstance::ZONE_FIELD
                ? ' (' . $tc->row . ',' . $tc->col . ')' : '';

            $buttons[] = [
                'label' => $tn . $coords . ' (' . $tc->hp . '/' . $tc->hpMax . ')',
                'url'   => "{$baseUrl}&cmd=valhalla_pick&target_id={$tid}",
            ];
        }

        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $name . ' — выбери цель',
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $vp = new \Berserk\Core\ValhallaProcessor($state, $engine);
        return $vp->chooseTarget($playerKey, $cmd);
    }
}