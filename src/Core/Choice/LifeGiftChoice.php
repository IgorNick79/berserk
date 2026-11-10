<?php
// src/Core/Choice/LifeGiftChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\{CardSelectionGroup, CardSelectionSpec, CreatureChoice, PanelSpec};

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
            ];
        }

        $items = CreatureChoice::candidates($state, array_merge($p['enemies'], $p['allies']),
            $playerKey, $cardsInfo, CardSelectionSpec::DEFAULT_MODE);
        $enemyItems = array_values(array_filter($items, static fn(array $item): bool => in_array($item['instanceId'], $p['enemies'], true)));
        $allyItems = array_values(array_filter($items, static fn(array $item): bool => in_array($item['instanceId'], $p['allies'], true)));

        return new PanelSpec(
            title: $srcName . ': предсмертный дар',
            form: [
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_life_gift',
                ],
                'submit' => 'Применить',
                'cancel' => $baseUrl . '&cmd=cancel_pending',
            ],
            cardSelection: new CardSelectionSpec(
                context: implode(':', [$state->gameId, $playerKey, 'life-gift', $p['card_id'],
                    $p['opened_version'] ?? 'legacy-' . $state->version]),
                battlefieldOwner: $playerKey,
                groups: [
                    new CardSelectionGroup('amount', 'amount', $amountItems, 'Сколько монет потратить', selected: [(int) $p['max_x']]),
                    new CardSelectionGroup('enemy', 'enemy_id', $enemyItems, 'Кого ранить'),
                    new CardSelectionGroup('ally', 'ally_id', $allyItems, 'Кого излечить'),
                ],
            ),
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