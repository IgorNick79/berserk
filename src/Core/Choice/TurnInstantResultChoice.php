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
            text: [$this->renderSummary($state, (array) ($result['summary'] ?? []), $cardsInfo)],
            buttons: [[
                'label' => 'OK',
                'url' => $baseUrl . '&cmd=turn_instant_result_ok',
            ]],
        );
    }

    private function renderSummary(GameState $state, array $summary, array $cardsInfo): string
    {
        $html = '<ol class="instant-resolution-list">';
        foreach ($summary as $item) {
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

            $line = htmlspecialchars($sourceName . ' — ' . $label, ENT_QUOTES);
            if ($targetName !== '') {
                $line .= ': ' . htmlspecialchars($targetName, ENT_QUOTES);
            }
            $line .= $applied
                ? ' — применено'
                : ' — без эффекта' . ($reason !== '' ? ': ' . htmlspecialchars($reason, ENT_QUOTES) : '');

            $details = $this->formatResultDetails((array) ($item['result'] ?? []));
            if ($details !== '') {
                $line .= ' <span class="muted">' . htmlspecialchars($details, ENT_QUOTES) . '</span>';
            }

            $html .= '<li>' . $line . '</li>';
        }
        $html .= '</ol>';

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
