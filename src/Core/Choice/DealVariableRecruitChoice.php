<?php
// src/Core/Choice/DealVariableRecruitChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class DealVariableRecruitChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_deal_variable_recruit';
    }

    public function commandTypes(): array
    {
        return ['choose_deal_variable_recruit', 'cancel_pending'];
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

        $card = $state->getCard((int) ($pending['card_id'] ?? 0));
        $cardName = $card ? ($cardsInfo[$card->ukid]['name'] ?? $card->ukid) : 'Карта';

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $cardName . ' — набор',
                isMine: false,
            );
        }

        $minX = (int) ($pending['min_x'] ?? 0);
        $maxX = (int) ($pending['max_x'] ?? $minX);
        $health = (int) ($pending['health'] ?? 0);
        $attackPerX = (int) ($pending['attack_per_x'] ?? 0);
        $resourceLabel = ($pending['resource'] ?? 'elite_gold') === 'silver' ? 'серебра' : 'золота';
        $items = [];
        for ($x = $minX; $x <= $maxX; $x++) {
            $attackBonus = $x * $attackPerX;
            $items[] = [
                'value' => $x,
                'label' => $x === 0
                    ? 'X = 0: без доплаты, +' . $health . ' HP'
                    : 'X = ' . $x . ': +' . $x . ' ' . $resourceLabel . ', +' . $attackBonus . ' к удару, +' . $health . ' HP',
                'checked' => $x === $minX,
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $cardName . ' — выбрать X',
            form: [
                'type' => 'radio',
                'name' => 'x',
                'items' => $items,
                'hidden' => [
                    $roleParam => '',
                    'game' => $state->gameId,
                    'cmd' => 'choose_deal_variable_recruit',
                ],
                'submit' => 'В отряд',
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
            return $prepare->cancelDealVariableRecruit($playerKey);
        }

        return $prepare->chooseDealVariableRecruit($playerKey, $cmd);
    }
}
