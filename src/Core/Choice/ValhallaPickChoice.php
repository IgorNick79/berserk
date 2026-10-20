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
        return ['valhalla_pick', 'cancel_pending'];
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

        $title = $name . ' — выбери цель';
        if (($pv['repeat_source'] ?? null) === 'ragnar' && (int) ($pv['repeat_index'] ?? 1) > 1) {
            $title = $name . ' — Рагнар: второй выбор цели';
        }

        return new PanelSpec(
            title: $title,
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
        if ($cmd->type === 'cancel_pending') return $vp->cancel($playerKey);
        return $vp->chooseTarget($playerKey, $cmd);
    }
}
