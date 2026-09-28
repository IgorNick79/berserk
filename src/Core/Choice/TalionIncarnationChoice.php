<?php
// src/Core/Choice/TalionIncarnationChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class TalionIncarnationChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_talion_incarnation';
    }

    public function commandTypes(): array
    {
        return ['choose_talion_incarnation'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle['pending_talion_incarnation'] ?? null;
        if (!$pending) return null;

        $source = $state->getCard((int) ($pending['source_id'] ?? 0));
        $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? '?') : '?';

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $sourceName . ': жетон инкарнации',
                isMine: false,
                waitText: 'Ожидание выбора карты на кладбище оппонентом...',
            );
        }

        $items = [];
        foreach ((array) ($pending['candidate_ids'] ?? []) as $id) {
            $card = $state->getCard((int) $id);
            if (!$card) continue;

            $name = $cardsInfo[$card->ukid]['name'] ?? $card->ukid;
            $items[] = [
                'value' => $card->instanceId,
                'label' => $name . ' #' . $card->instanceId . ' — ' . self::incarnationLabel($card),
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $sourceName . ': выберите существо на кладбище для жетона инкарнации',
            form: [
                'type' => 'radio',
                'name' => 'target_id',
                'items' => $items,
                'hidden' => [
                    $roleParam => '',
                    'game' => $state->gameId,
                    'cmd' => 'choose_talion_incarnation',
                ],
                'submit' => 'Положить жетон',
            ],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        return (new \Berserk\Core\ActionResolver($state, $engine))
            ->chooseTalionIncarnation($playerKey, $cmd);
    }

    private static function incarnationLabel(\Berserk\Core\CardInstance $card): string
    {
        $tokens = (int) ($card->markers['incarnation']['value'] ?? 0);
        $inc = $card->prop['incarnation'] ?? null;

        if ($inc === null) {
            return 'без Инкарнации — ' . $tokens . ' жетон' . self::tokenSuffix($tokens);
        }

        $threshold = is_array($inc)
            ? (int) ($inc['turns'] ?? $card->markers['incarnation']['threshold'] ?? 0)
            : (int) $inc;

        return 'Инкарнация ' . $threshold . ' — ' . $tokens . '/' . $threshold;
    }

    private static function tokenSuffix(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) return '';
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) return 'а';
        return 'ов';
    }
}
