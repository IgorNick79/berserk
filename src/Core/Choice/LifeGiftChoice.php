<?php
// src/Core/Choice/LifeGiftChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class LifeGiftChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_life_gift'; }
    public function commandTypes(): array { return ['choose_life_gift', 'cancel_pending']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_life_gift'] ?? null;
        if (!$p) return null;

        $src = $state->getCard((int) $p['card_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(title: $srcName . ' — предсмертный дар', isMine: false);
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        // X — сколько монет
        $amountItems = [];
        for ($i = (int) $p['min_x']; $i <= (int) $p['max_x']; $i++) {
            $amountItems[] = [
                'value'   => $i,
                'label'   => $i . ' монет',
                'checked' => ($i === (int) $p['max_x']),
            ];
        }

        // Враг — кого ранить
        $enemyItems = [];
        foreach ($p['enemies'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $enemyItems[] = [
                'value' => (int) $tid,
                'label' => $tn . ' (' . $tc->row . ';' . $tc->col . ') — ' . $tc->hp . '/' . $tc->hpMax,
            ];
        }

        // Союзник — кого лечить
        $allyItems = [];
        foreach ($p['allies'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $allyItems[] = [
                'value' => (int) $tid,
                'label' => $tn . ' (' . $tc->row . ';' . $tc->col . ') — ' . $tc->hp . '/' . $tc->hpMax,
            ];
        }

        return new PanelSpec(
            title: $srcName . ': предсмертный дар',
            form: [
                'type'   => 'multi_radio',
                'groups' => [
                    ['name' => 'amount',   'label' => 'Сколько монет потратить', 'items' => $amountItems],
                    ['name' => 'enemy_id', 'label' => 'Кого ранить',             'items' => $enemyItems],
                    ['name' => 'ally_id',  'label' => 'Кого излечить',           'items' => $allyItems],
                ],
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_life_gift',
                ],
                'submit' => 'Применить',
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
        if ($cmd->type === 'cancel_pending') {
            return (new \Berserk\Core\ActionResolver($state, $engine))
                ->cancelPending($playerKey, $cmd);
        }
        return (new \Berserk\Core\ActionResolver($state, $engine))
            ->chooseLifeGift($playerKey, $cmd);
    }
}