<?php
// src/Core/Choice/AnyDeathChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\DamageResolver;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class AnyDeathChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_any_death';
    }

    public function commandTypes(): array
    {
        return ['choose_any_death_target'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $queue = $state->battle['pending_any_death'] ?? [];
        if (empty($queue)) return null;

        $item = $queue[0];
        $srcCard = $state->getCard($item['source_id']);
        $srcName = $srcCard ? ($cardsInfo[$srcCard->ukid]['name'] ?? '?') : '?';
        $diedName = $cardsInfo[$item['died_ukid']]['name'] ?? '?';

        $chooserKey = $srcCard ? $srcCard->owner : null;

        if ($playerKey !== $chooserKey) {
            return new PanelSpec(
                title: $srcName . ' — отравление',
                isMine: false,
            );
        }

        $items = [];
        $first = true;
        foreach ($item['candidates'] as $tid) {
            $tc = $state->getCard($tid);
            if (!$tc) continue;
            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';

            $label = $tn . ' (' . $tc->row . ';' . $tc->col . ')';
            if ($tc->owner === $playerKey) {
                $label .= ' — моё существо';
            }

            $items[] = [
                'value'   => (int) $tid,
                'label'   => $label,
                'checked' => $first,
            ];
            $first = false;
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $srcName . ': ' . $diedName . ' погиб от яда. Отравить на ' . $item['poison_value'] . '?',
            form: [
                'type'   => 'radio',
                'name'   => 'target_id',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_any_death_target',
                ],
                'submit' => 'Отравить',
                'cancel' => $baseUrl . '&cmd=choose_any_death_target&target_id=0',
            ],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        return (new DamageResolver($state))->chooseAnyDeathTarget($playerKey, $cmd);
    }
}
