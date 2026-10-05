<?php
// src/Core/Choice/TurnInstantResultChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class TurnInstantResultChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'turn_instant_result'; }

    public function commandTypes(): array
    {
        return ['turn_instant_result_ok'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $result = $state->battle['turn_instant_result'] ?? null;
        if (!$result) return null;

        return new PanelSpec(
            title: 'Разрешение инстантов',
            text: [$this->renderSummary($state, (array) ($result['summary'] ?? []), $cardsInfo, $playerKey)],
            buttons: [[
                'label' => 'OK',
                'url' => $baseUrl . '&cmd=turn_instant_result_ok',
            ]],
        );
    }

    private function renderSummary(GameState $state, array $summary, array $cardsInfo, string $playerKey): string
    {
        $html = '<div class="instant-stack">'
            . '<div class="instant-stack__title">Итог разрешения (сверху вниз):</div>'
            . '<ul class="instant-stack__list">';

        foreach ($summary as $i => $item) {
            $source = $state->getCard((int) ($item['card_id'] ?? 0));
            $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? $source->ukid) : '?';
            $label = (string) ($item['label'] ?? 'Инстант');
            $applied = !empty($item['applied']);
            $reason = (string) ($item['reason'] ?? '');
            $targetName = '';

            $target = $state->getCard((int) ($item['target_id'] ?? 0));
            if ($target) {
                $targetName = $cardsInfo[$target->ukid]['name'] ?? $target->ukid;
            }

            $isTop = ($i === 0) ? ' instant-stack__item--top' : '';
            $whoLabel = (($item['player'] ?? '') === $playerKey) ? 'Ты' : 'Оппонент';
            $line = '<span class="instant-stack__who">' . htmlspecialchars($whoLabel, ENT_QUOTES) . '</span> '
                . '<b>' . htmlspecialchars($sourceName, ENT_QUOTES) . '</b> — '
                . htmlspecialchars($label, ENT_QUOTES);
            if ($targetName !== '') {
                $line .= ' <span class="instant-stack__target">→ '
                    . htmlspecialchars($targetName, ENT_QUOTES)
                    . '</span>';
            }
            $line .= ' <span class="muted">'
                . ($applied
                    ? 'применено'
                    : 'без эффекта' . ($reason !== '' ? ': ' . htmlspecialchars($reason, ENT_QUOTES) : ''))
                . '</span>';

            $details = $this->formatResultDetails((array) ($item['result'] ?? []));
            if ($details !== '') {
                $line .= ' <span class="muted">' . htmlspecialchars($details, ENT_QUOTES) . '</span>';
            }

            $html .= '<li class="instant-stack__item' . $isTop . '">' . $line . '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    private function formatResultDetails(array $result): string
    {
        if (isset($result['hp_before'], $result['hp_after'])
            && (int) $result['hp_before'] !== (int) $result['hp_after']) {
            return 'HP ' . (int) $result['hp_before'] . ' -> ' . (int) $result['hp_after'];
        }

        if (isset($result['closed_before'], $result['closed_after'])
            && (bool) $result['closed_before'] !== (bool) $result['closed_after']) {
            return !empty($result['closed_after']) ? 'закрыт' : 'открыт';
        }

        return '';
    }

    public function apply(GameState $state, Engine $engine, string $playerKey, Command $cmd): Result
    {
        return (new InstantProcessor($state, $engine))->ackTurnInstantResult($playerKey);
    }
}
