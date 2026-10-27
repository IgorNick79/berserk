<?php
// src/Core/Choice/TurnInstantStackChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class TurnInstantStackChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'turn_instant_stack'; }

    public function commandTypes(): array
    {
        return ['play_turn_instant', 'pass_turn_instant'];
    }

    public function isActive(GameState $state): bool
    {
        $stack = $state->battle['turn_instant_stack'] ?? null;
        return is_array($stack) && (($stack['state'] ?? '') === 'ordering');
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $stack = $state->battle['turn_instant_stack'] ?? null;
        if (!$stack || (($stack['state'] ?? '') !== 'ordering')) return null;

        $priority = (string) ($stack['priority'] ?? '');
        $items = [];
        foreach ((array) ($stack['stack'] ?? []) as $entry) {
            $card = $state->getCard((int) ($entry['card_id'] ?? 0));
            $name = $card ? ($cardsInfo[$card->ukid]['name'] ?? $card->ukid) : '?';
            $target = $state->getCard((int) ($entry['target_id'] ?? 0));
            $targetName = $target ? ($cardsInfo[$target->ukid]['name'] ?? $target->ukid) : '';
            $items[] = [
                'label' => $name . ' — ' . (string) ($entry['label'] ?? 'Инстант'),
                'player' => (string) ($entry['player'] ?? ''),
                'target' => $targetName,
            ];
        }

        $phase = (string) ($stack['phase'] ?? 'turn');
        $cards = [];
        $buttons = [];
        $text = $this->renderStackItems($items, $playerKey);

        if ($priority !== $playerKey) {
            $text[] = '<p class="wait">Ожидание выбора оппонента...</p>';
        } else {
            $text[] = '<p>Выберите инстант или пас в нижнем блоке.</p>';
        }

        return new PanelSpec(
            title: 'Стек инстантов хода',
            cards: $cards,
            text: $text,
            buttons: $buttons,
        );
    }

    private function renderStackItems(array $items, string $playerKey): array
    {
        $html = '<div class="instant-summary">'
            . '<div class="instant-summary__title">Объявленные инстанты хода:</div>';

        if (empty($items)) {
            return [$html . '<p class="instant-stack__empty">Пока нет объявленных инстантов.</p></div>'];
        }

        $html .= '<ul class="instant-summary__list instant-summary__list--plain">';

        foreach ($items as $item) {
            $isMine = (($item['player'] ?? '') === $playerKey);
            $whoLabel = $isMine ? 'Ты' : 'Соперник';
            $whoClass = $isMine ? 'instant-summary__who--mine' : 'instant-summary__who--opp';
            $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES);
            $target = htmlspecialchars((string) ($item['target'] ?? ''), ENT_QUOTES);
            $html .= '<li class="instant-summary__item">'
                . '<span class="instant-summary__who ' . $whoClass . '">' . htmlspecialchars($whoLabel, ENT_QUOTES) . '</span> '
                . $label;
            if ($target !== '') {
                $html .= ' <span class="instant-stack__target">→ ' . $target . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></div>';

        return [$html];
    }

    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    {
        $ip = new InstantProcessor($state, $engine);
        if ($cmd->type === 'pass_turn_instant') {
            return $ip->passTurnInstant($playerKey);
        }
        return $ip->playTurnInstant($playerKey, $cmd);
    }
}
