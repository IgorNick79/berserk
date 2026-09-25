<?php
// src/Core/Choice/TurnSubChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;
use Berserk\View\Ui\TaskCard;

final class TurnSubChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'turn_phase'; }
    public function commandTypes(): array { return ['turn_sub', 'turn_sub_close']; }

    public function isActive(GameState $state): bool
    {
        $tp = $state->battle['turn_phase'] ?? null;
        if (!$tp) return false;
        if (!empty($tp['pending_ack'])) return false;
        if (empty($tp['sub'])) return false;

        // Специализированные pending перехватывают приоритет
        if (!empty($state->battle['pending_prophecy']))       return false;
        if (!empty($state->battle['pending_valhalla_pick']))  return false;
        if (!empty($state->battle['pending_instant_pick']))   return false;

        return true;
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $tp = $state->battle['turn_phase'] ?? null;
        if (!$tp || empty($tp['sub'])) return null;

        $sub      = $tp['sub'];
        $side     = $tp['side'];
        $ownerKey = $side === 'passive' ? $tp['passive_key'] : $tp['active_key'];

        if ($ownerKey !== $playerKey) {
            return new PanelSpec(
                title: 'Ожидание',
                isMine: false,
            );
        }

        $remaining = $sub['remaining'] ?? [];
        if (empty($remaining)) return null;

        $cards = [];
        foreach ($remaining as $item) {
            $url = $baseUrl . '&cmd=turn_sub&sub_id=' . urlencode($item['id']);

            $render = [
                'label'     => '',
                'card_ukid' => $item['ukid'] ?? null,
                'row'       => $item['row'] ?? null,
                'col'       => $item['col'] ?? null,
                'hint'      => '',
            ];
            $cards[] = TaskCard::render($render, $url, $cardsInfo);
        }

        $title = match ($sub['parent_type']) {
            'prophecy' => 'Пророчество — выбери карту:',
            'valhalla' => 'Вальхалла — выбери карту:',
            'instants' => 'Инстанты — выбери карту:',
            default    => 'Выбери действие:',
        };

        $buttons = [];
        if (!empty($sub['can_close'])) {
            $buttons[] = [
                'label' => 'Закрыть',
                'url'   => $baseUrl . '&cmd=turn_sub_close',
                'class' => 'skip',
            ];
        }

        return new PanelSpec(
            title: $title,
            cards: $cards,
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $tp = new \Berserk\Core\TurnPhaseProcessor($state, $engine);
        return match ($cmd->type) {
            'turn_sub'       => $tp->runSub($playerKey, $cmd),
            'turn_sub_close' => $tp->closeSub($playerKey, $cmd),
            default          => Result::error('Неизвестная команда'),
        };
    }
}