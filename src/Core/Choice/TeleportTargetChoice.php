<?php
// src/Core/Choice/TeleportTargetChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class TeleportTargetChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_teleport_target'; }

    public function commandTypes(): array
    {
        return ['choose_teleport_cell', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pt = $state->battle['pending_teleport_target'] ?? null;
        if (!is_array($pt) || empty($pt['cells'])) {
            return null;
        }

        $source = $state->getCard((int) ($pt['attacker_id'] ?? 0));
        $target = $state->getCard((int) ($pt['target_id'] ?? 0));

        $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? '?') : '?';
        $targetName = $target ? ($cardsInfo[$target->ukid]['name'] ?? '?') : '?';

        // Не наш выбор — просто ждём
        if (($pt['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $sourceName . ': дверь измерений',
                isMine: false,
            );
        }

        $items = [];
        $first = true;
        foreach ($pt['cells'] as $c) {
            $row = (int) $c['row'];
            $col = (int) $c['col'];
            $items[] = [
                'value'   => $row . '_' . $col,
                'label'   => '(' . $row . ';' . $col . ')',
                'checked' => $first,
            ];
            $first = false;
        }

        if (empty($items)) {
            return new PanelSpec(
                title: $sourceName . ': нет свободных клеток',
                buttons: [[
                    'label' => 'Отмена',
                    'url'   => $baseUrl . '&cmd=cancel_pending',
                    'class' => 'skip',
                ]],
            );
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $sourceName . ': дверь измерений — куда переместить ' . $targetName . '?',
            form: [
                'type'   => 'radio',
                'name'   => 'cell',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_teleport_cell',
                ],
                'submit' => 'Переместить',
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

        $cell = (string) $cmd->get('cell', '');
        if ($cell === '') {
            return Result::error('Не выбрана клетка');
        }

        $parts = explode('_', $cell, 2);
        if (count($parts) !== 2) {
            return Result::error('Неверная клетка');
        }

        // ActionResolver::chooseTeleportTargetCell читает row/col —
        // переупаковываем 'r_c' из формы в отдельные поля.
        $innerCmd = new Command('choose_teleport_cell', [
            'row' => (int) $parts[0],
            'col' => (int) $parts[1],
        ]);

        return (new \Berserk\Core\ActionResolver($state, $engine))
            ->chooseTeleportTargetCell($playerKey, $innerCmd);
    }
}