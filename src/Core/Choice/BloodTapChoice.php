<?php
// src/Core/Choice/BloodTapChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class BloodTapChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_blood_tap';
    }

    public function commandTypes(): array
    {
        return ['choose_blood_tap'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pb = $state->battle['pending_blood_tap'] ?? null;
        if (!$pb) return null;

        $attCard = $state->getCard($pb['attacker_id']);
        $attName = $attCard ? ($cardsInfo[$attCard->ukid]['name'] ?? '?') : '?';

        if (!$attCard || $attCard->owner !== $playerKey) {
            return new PanelSpec(
                title: $attName . ' — кровавый разряд',
                isMine: false,
            );
        }

        $maxX = (int) $pb['max_x'];

        $items = [];
        for ($i = 1; $i <= $maxX; $i++) {
            $items[] = [
                'value'   => $i,
                'label'   => $i . ' (снять ' . $i . ' HP, разряд на ' . $i . ')',
                'checked' => ($i === $maxX),
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $attName . ' — сколько дополнительных жизней снять?',
            form: [
                'type'   => 'radio',
                'name'   => 'amount',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_blood_tap',
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
        return $ar->chooseBloodTap($playerKey, $cmd);
    }
}