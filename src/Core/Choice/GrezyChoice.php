<?php
// src/Core/Choice/GrezyChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\{CardSelectionSpec, CreatureChoice, PanelSpec};

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

        $price = (int) $pg['price'];
        $enemyStep = !empty($pg['enemies']);
        $ids = $enemyStep ? $pg['enemies'] : $pg['allies'];
        $field = $enemyStep ? 'enemy_id' : 'own_id';
        $mode = CardSelectionSpec::SIMPLE;

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $enemyStep
                ? 'Грезы Архаля (цена ' . $price . ') : выберите карту противника для отравления на 1'
                : '−1 от немагических атак',
            form: [
                'name'   => $field,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'grezy_pick',
                ],
                'submit' => $enemyStep ? 'Отравить' : 'Усилить',
            ],
            cardSelection: new CardSelectionSpec(
                context: implode(':', [$state->gameId, $playerKey, 'grezy', $pg['attacker_id'], $field,
                    $pg['opened_version'] ?? 'legacy-' . $state->version]),
                candidates: CreatureChoice::candidates($state, $ids, $playerKey, $cardsInfo, $mode),
                mode: $mode,
                selected: count($ids) === 1 ? array_values($ids) : [],
                battlefieldOwner: $playerKey,
            ),
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