<?php
// src/Core/Choice/SelfWoundChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class SelfWoundChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_self_wound';
    }

    public function commandTypes(): array
    {
        return ['choose_self_wound'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $psw = $state->battle['pending_self_wound'] ?? null;
        if (!$psw) return null;

        $attCard = $state->getCard($psw['attacker_id']);
        $attName = $attCard ? ($cardsInfo[$attCard->ukid]['name'] ?? '?') : '?';

        if (!$attCard || $attCard->owner !== $playerKey) {
            return new PanelSpec(
                title: $attName . ' — таран',
                isMine: false,
            );
        }

        $max = (int) $psw['max_wounds'];

        $items = [];
        for ($i = 1; $i <= $max; $i++) {
            $dmg = max(0, $i - 1);
            $suffix = $i >= $attCard->hp
                ? ' (Центурион погибнет)'
                : ' (урон ' . $dmg . ')';

            $items[] = [
                'value'   => $i,
                'label'   => $i . ' ран' . $suffix,
                'checked' => ($i === $max),
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $attName . ' — сколько ран взять на себя?',
            form: [
                'type'   => 'radio',
                'name'   => 'amount',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_self_wound',
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
        return $ar->chooseSelfWound($playerKey, $cmd);
    }
}