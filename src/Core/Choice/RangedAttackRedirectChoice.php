<?php
// src/Core/Choice/RangedAttackRedirectChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\ActionResolver;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class RangedAttackRedirectChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_ranged_attack_redirect'; }
    public function commandTypes(): array { return ['choose_ranged_redirect']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle['pending_ranged_attack_redirect'] ?? null;
        if (!$pending) return null;

        $original = $state->getCard((int) ($pending['original_target_id'] ?? 0));
        $originalName = $original ? ($cardsInfo[$original->ukid]['name'] ?? $original->ukid) : '?';

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: 'Перенаправление дальней атаки',
                isMine: false,
            );
        }

        $items = [];
        foreach ((array) ($pending['options'] ?? []) as $option) {
            $target = $state->getCard((int) ($option['target_id'] ?? 0));
            if (!$target) continue;
            $source = $state->getCard((int) ($option['source_id'] ?? 0));
            $targetName = $cardsInfo[$target->ukid]['name'] ?? $target->ukid;
            $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? $source->ukid) : '?';
            $coords = $target->row !== null && $target->col !== null
                ? ' (' . $target->row . ',' . $target->col . ')'
                : '';
            $items[] = [
                'value' => (string) ($option['option_id'] ?? ''),
                'label' => 'Перенаправить на ' . $targetName . $coords . ' — ' . $sourceName,
            ];
        }
        $items[] = [
            'value' => '0',
            'label' => 'Оставить исходную цель',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: 'Перенаправить дальнюю атаку по существу "' . $originalName . '"?',
            form: [
                'type' => 'radio',
                'name' => 'redirect_option',
                'items' => $items,
                'hidden' => ['cmd' => 'choose_ranged_redirect'],
                'submit' => 'Подтвердить',
            ],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        return (new ActionResolver($state, $engine))->chooseRangedRedirect($playerKey, $cmd);
    }
}
