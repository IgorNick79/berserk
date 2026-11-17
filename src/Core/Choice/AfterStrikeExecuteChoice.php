<?php
// src/Core/Choice/AfterStrikeExecuteChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\CardStats;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\Core\StrikeResolver;
use Berserk\View\Ui\{CreatureChoice, PanelSpec};

final class AfterStrikeExecuteChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_after_strike_execute';
    }

    public function commandTypes(): array
    {
        return ['choose_after_strike_execute'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle[$this->pendingKey()] ?? null;
        if (!$pending) return null;

        $source = $state->getCard((int) ($pending['source_id'] ?? 0));
        $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? $source->ukid) : 'Карта';
        $value = (int) ($pending['value'] ?? 0);
        $title = $sourceName . ': выберите цель добивания на ' . $value;

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(title: $title, isMine: false);
        }

        $links = [];
        foreach ((array) ($pending['candidate_ids'] ?? []) as $targetId) {
            $target = $state->getCard((int) $targetId);
            if (!$source || !$target || !CardStats::canExecuteTarget($state, $source, $target, $value)) continue;

            $links[] = [
                'instanceId' => $target->instanceId,
                'prefix' => ($target->owner === $playerKey ? 'Свой' : 'Чужой') . ': ',
                'url' => $baseUrl . '&cmd=choose_after_strike_execute&target_id=' . $target->instanceId,
            ];
        }
        $buttons = CreatureChoice::immediateButtons($state, $playerKey, $cardsInfo, $links);

        return new PanelSpec(title: $title, buttons: $buttons);
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        return (new StrikeResolver($state, $engine))->chooseAfterStrikeExecute($playerKey, $cmd);
    }
}
