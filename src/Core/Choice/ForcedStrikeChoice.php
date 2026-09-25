<?php
// src/Core/Choice/ForcedStrikeChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class ForcedStrikeChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_forced_strike'; }
    public function commandTypes(): array { return ['choose_forced_strike']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pf = $state->battle['pending_forced_strike'] ?? null;
        if (!$pf) return null;

        $attacker = $state->getCard($pf['attacker_id']);
        $attName  = $attacker ? ($cardsInfo[$attacker->ukid]['name'] ?? '?') : '?';

        if ($pf['owner'] !== $playerKey) {
            return new PanelSpec(title: $attName . ' — обязательная атака', isMine: false);
        }

        $buttons = [];
        foreach ($pf['candidates'] as $tid) {
            $tc = $state->getCard($tid);
            if (!$tc) continue;
            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $buttons[] = [
                'label' => $tn . ' (' . $tc->hp . '/' . $tc->hpMax . ')',
                'url'   => "{$baseUrl}&cmd=choose_forced_strike&target_id={$tid}",
            ];
        }

        return new PanelSpec(
            title: $attName . ' — обязан атаковать закрытое существо:',
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        return $ar->chooseForcedStrike($playerKey, $cmd);
    }
}