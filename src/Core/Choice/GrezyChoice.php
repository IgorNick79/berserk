<?php
// src/Core/Choice/GrezyChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class GrezyChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_grezy';
    }

    public function commandTypes(): array
    {
        return ['grezy_pick'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pg = $state->battle['pending_grezy'] ?? null;
        if (!$pg) return null;

        $attacker = $state->getCard($pg['attacker_id']);
        $attName  = $attacker ? ($cardsInfo[$attacker->ukid]['name'] ?? '?') : '?';

        if (!$attacker || $attacker->owner !== $playerKey) {
            return new PanelSpec(
                title: $attName . ' — Грезы Архааля',
                isMine: false,
            );
        }

        $shownName = $cardsInfo[$pg['shown_ukid']]['name'] ?? '?';
        $price     = (int) $pg['price'];

        $groups = [];

        if (!empty($pg['enemies'])) {
            $items = [];
            foreach ($pg['enemies'] as $tid) {
                $tc = $state->getCard($tid);
                if (!$tc) continue;
                $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
                $items[] = [
                    'value'   => $tid,
                    'label'   => $tn,
                    'checked' => count($pg['enemies']) === 1,
                ];
            }
            $groups[] = [
                'name'  => 'enemy_id',
                'label' => 'Враг для яда 1',
                'items' => $items,
            ];
        }

        if (!empty($pg['allies'])) {
            $items = [];
            foreach ($pg['allies'] as $tid) {
                $tc = $state->getCard($tid);
                if (!$tc) continue;
                $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
                $items[] = [
                    'value'   => $tid,
                    'label'   => $tn,
                    'checked' => count($pg['allies']) === 1,
                ];
            }
            $groups[] = [
                'name'  => 'own_id',
                'label' => 'Своё — защита от немагических атак',
                'items' => $items,
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $attName . ': «' . $shownName . '» (цена ' . $price . ')',
            form: [
                'type'   => 'multi_radio',
                'groups' => $groups,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'grezy_pick',
                ],
                'submit' => 'Подтвердить',
                'cancel' => $baseUrl . '&cmd=cancel_pending',
            ],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        return $ar->grezyPick($playerKey, $cmd);
    }
}