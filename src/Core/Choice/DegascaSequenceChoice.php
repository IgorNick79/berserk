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
        return ['choose_degasca_card', 'choose_degasca_chain', 'choose_degasca_talion'];
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
            $names = DegascaProcessor::sourceNames();
            foreach ((array) ($pending['candidate_ids'] ?? []) as $cardId) {
                $card = $state->getCard((int) $cardId);
                if (!$card) continue;
                $name = $cardsInfo[$card->ukid]['name'] ?? ($names[$card->ukid] ?? $card->ukid);
                $ability = $names[$card->ukid] ?? $name;
                $items[] = [
                    'value' => $card->instanceId,
                    'label' => $name . ' — изучить: ' . $ability,
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

        if ($stage === 'choose_chain') {
            $items = [];
            foreach ((array) ($pending['candidate_ids'] ?? []) as $targetId) {
                $target = $state->getCard((int) $targetId);
                if (!$target || $target->dying || $target->hp <= 0) continue;
                $name = $cardsInfo[$target->ukid]['name'] ?? $target->ukid;
                $coord = $target->row !== null && $target->col !== null ? ' (' . $target->row . ',' . $target->col . ')' : '';
                $items[] = ['value' => $target->instanceId, 'label' => $name . $coord . ' — ' . $target->hp . '/' . $target->hpMax];
            }
            return new PanelSpec(
                title: $sourceName . ': выберите две цели для цепей',
                form: [
                    'type' => 'checkbox',
                    'name' => 'target_ids[]',
                    'items' => $items,
                    'hidden' => [$roleParam => '', 'game' => $state->gameId, 'cmd' => 'choose_degasca_chain'],
                    'submit' => 'Подтвердить',
                ],
            );
        }

        if ($stage === 'choose_talion') {
            $items = [];
            foreach ((array) ($pending['candidate_ids'] ?? []) as $targetId) {
                $target = $state->getCard((int) $targetId);
                if (!$target) continue;
                $name = $cardsInfo[$target->ukid]['name'] ?? $target->ukid;
                $items[] = ['value' => $target->instanceId, 'label' => $name . ' #' . $target->instanceId];
            }
            return new PanelSpec(
                title: $sourceName . ': выберите существо на кладбище для жетона инкарнации',
                form: [
                    'type' => 'radio',
                    'name' => 'target_id',
                    'items' => $items,
                    'hidden' => [$roleParam => '', 'game' => $state->gameId, 'cmd' => 'choose_degasca_talion'],
                    'submit' => 'Положить жетон',
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
            'choose_degasca_chain' => $processor->chooseChain($playerKey, $cmd),
            'choose_degasca_talion' => $processor->chooseTalion($playerKey, $cmd),
            default => Result::error('Неизвестный выбор Дегаски'),
        };
    }
}
