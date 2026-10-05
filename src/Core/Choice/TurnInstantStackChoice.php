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
use Berserk\View\Ui\TaskCard;

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

        if ($priority !== $playerKey) {
            return new PanelSpec(
                title: 'Стек инстантов хода',
                isMine: false,
            );
        }

        $phase = (string) ($stack['phase'] ?? 'turn');
        $cards = [];
        $instantProcessor = new InstantProcessor($state, new Engine());
        foreach ($instantProcessor->getInstants($playerKey, $phase, 'turn') as $inst) {
            $payload = (array) ($inst['payload'] ?? []);
            $url = "{$baseUrl}&cmd=play_turn_instant&card_id={$inst['card_id']}"
                . '&instant_key=' . urlencode((string) ($payload['key'] ?? ''));
            $cards[] = TaskCard::render([
                'label' => (string) ($inst['label'] ?? ''),
                'card_ukid' => $inst['ukid'] ?? null,
                'row' => $inst['row'] ?? null,
                'col' => $inst['col'] ?? null,
                'hint' => (string) ($inst['label'] ?? ''),
                'is_instant' => true,
            ], $url, $cardsInfo);
        }

        return new PanelSpec(
            title: 'Стек инстантов хода',
            cards: $cards,
            text: $this->renderStackItems($items, $playerKey),
            buttons: [[
                'label' => 'Пас',
                'url' => $baseUrl . '&cmd=pass_turn_instant',
                'class' => 'skip',
            ]],
        );
    }

    private function renderStackItems(array $items, string $playerKey): array
    {
        if (empty($items)) return [];

        $items = array_reverse($items);
        $html = '<div class="instant-stack">'
            . '<div class="instant-stack__title">Стек (сверху разрешается первым):</div>'
            . '<ul class="instant-stack__list">';

        foreach ($items as $i => $item) {
            $isTop = ($i === 0) ? ' instant-stack__item--top' : '';
            $whoLabel = (($item['player'] ?? '') === $playerKey) ? 'Ты' : 'Оппонент';
            $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES);
            $target = htmlspecialchars((string) ($item['target'] ?? ''), ENT_QUOTES);
            $html .= '<li class="instant-stack__item' . $isTop . '">'
                . '<span class="instant-stack__who">' . htmlspecialchars($whoLabel, ENT_QUOTES) . '</span> '
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
