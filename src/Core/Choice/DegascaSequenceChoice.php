<?php
// src/Core/Choice/DegascaSequenceChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\DegascaProcessor;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class DegascaSequenceChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_degasca_sequence';
    }

    public function commandTypes(): array
    {
        return ['choose_degasca_card'];
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
        $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? 'Дегаска') : 'Дегаска';
        $stage = (string) ($pending['stage'] ?? '');

        if (($pending['owner'] ?? '') !== $playerKey) {
            return new PanelSpec(title: $sourceName . ': выбор способности', isMine: false);
        }

        $roleParam = $role === 'host' ? 'first' : 'second';
        if ($stage === 'choose_card') {
            $items = [];
            foreach ((array) ($pending['candidate_ids'] ?? []) as $cardId) {
                $card = $state->getCard((int) $cardId);
                if (!$card) continue;
                $name = $cardsInfo[$card->ukid]['name'] ?? $card->ukid;
                $items[] = [
                    'value' => $card->instanceId,
                    'label' => $name . ' — изучить способность',
                ];
            }
            return new PanelSpec(
                title: $sourceName . ': выберите карту в колоде',
                form: [
                    'type' => 'radio',
                    'name' => 'card_id',
                    'items' => $items,
                    'hidden' => [$roleParam => '', 'game' => $state->gameId, 'cmd' => 'choose_degasca_card'],
                    'submit' => 'Изучить',
                ],
            );
        }

        return new PanelSpec(title: $sourceName . ': разрешение способности');
    }

    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    {
        $processor = new DegascaProcessor($state, $engine);
        return match ($cmd->type) {
            'choose_degasca_card' => $processor->chooseCard($playerKey, $cmd),
            default => Result::error('Неизвестный выбор Дегаски'),
        };
    }
}
