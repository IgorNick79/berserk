<?php
// src/Core/Choice/AnyDeathChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\DamageResolver;
use Berserk\Core\Result;
use Berserk\View\Ui\{CardSelectionSpec, CreatureChoice, PanelSpec};

final class AnyDeathChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_any_death';
    }

    public function commandTypes(): array
    {
        return ['choose_any_death_target', 'cancel_pending'];
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
                title: ($item['type'] ?? 'poison_near') === 'optional_effect'
                    ? (string) ($item['title'] ?? ($srcName . ': сработала способность'))
                    : $srcName . ' — отравление',
                isMine: false,
            );
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        if (($item['type'] ?? 'poison_near') === 'optional_effect') {
            $acceptLabel = (string) ($item['accept_label'] ?? 'Применить');
            $declineLabel = (string) ($item['decline_label'] ?? 'Закрыть');

            return new PanelSpec(
                title: (string) ($item['title'] ?? ($srcName . ': сработала способность')),
                buttons: [
                    [
                        'label' => $acceptLabel,
                        'url' => $baseUrl . '&cmd=choose_any_death_target&target_id=' . (int) ($item['source_id'] ?? 0),
                    ],
                    [
                        'label' => $declineLabel,
                        'url' => $baseUrl . '&cmd=choose_any_death_target&target_id=0',
                        'class' => 'skip',
                    ],
                ],
            );
        }

        $items = [];
        $first = true;
        foreach ($item['candidates'] as $tid) {
            $tc = $state->getCard($tid);
            if (!$tc) continue;
            $items[] = ['value' => (int) $tid, 'checked' => $first];
            $first = false;
        }

        return new PanelSpec(
            title: $srcName . ': ' . $diedName . ' погиб от яда. Отравить на ' . $item['poison_value'] . '?',
            form: [
                'type'   => 'radio',
                'name'   => 'target_id',
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_any_death_target',
                ],
                'submit' => 'Отравить',
                'cancel' => $baseUrl . '&cmd=choose_any_death_target&target_id=0',
            ],
            cardSelection: CreatureChoice::confirmed($state, $playerKey, $cardsInfo, $items,
                'any-death:' . $state->version, mode: CardSelectionSpec::SIMPLE),
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
