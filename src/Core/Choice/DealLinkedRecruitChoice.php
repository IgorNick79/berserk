<?php
// src/Core/Choice/DealLinkedRecruitChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class DealLinkedRecruitChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_deal_linked_recruit';
    }

    public function commandTypes(): array
    {
        return ['choose_deal_linked_recruit', 'cancel_pending'];
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

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $sourceName . ' — совместный набор',
                isMine: false,
            );
        }

        $items = [];
        foreach ((array) ($pending['candidate_ids'] ?? []) as $id) {
            $candidate = $state->getCard((int) $id);
            if (!$candidate) continue;

            $info = $cardsInfo[$candidate->ukid] ?? [];
            $name = (string) ($info['name'] ?? $candidate->ukid);
            $items[] = [
                'value' => $candidate->instanceId,
                'label' => $name . ' — ' . $candidate->price . ' серебра вместо золота',
                'checked' => empty($items),
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $sourceName . ' — выбрать существо',
            text: [
                'Выберите одно золотое существо стоимостью не больше '
                    . (int) ($pending['max_elite_cost'] ?? 7)
                    . '. Оно будет набрано за ту же числовую стоимость серебром и станет связанным с этой картой.',
            ],
            form: [
                'type' => 'radio',
                'name' => 'companion_id',
                'items' => $items,
                'hidden' => [
                    $roleParam => '',
                    'game' => $state->gameId,
                    'cmd' => 'choose_deal_linked_recruit',
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
        $prepare = new PrepareProcessor($state);

        if ($cmd->type === 'cancel_pending') {
            return $prepare->cancelDealLinkedRecruit($playerKey);
        }

        return $prepare->chooseDealLinkedRecruit($playerKey, $cmd);
    }
}
