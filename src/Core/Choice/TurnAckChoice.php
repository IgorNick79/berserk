<?php
// src/Core/Choice/TurnAckChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class TurnAckChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'turn_phase'; }
    public function commandTypes(): array { return ['turn_ack']; }

    public function isActive(GameState $state): bool
    {
        $tp = $state->battle['turn_phase'] ?? null;
        return $tp !== null && !empty($tp['pending_ack']);
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $tp = $state->battle['turn_phase'] ?? null;
        if (!$tp || empty($tp['pending_ack'])) return null;

        $text = $this->formatAck($tp['pending_ack'], $state, $cardsInfo);

        return new PanelSpec(
            title: $text,
            isMine: true,
            buttons: [[
                'label' => 'Продолжить',
                'url'   => $baseUrl . '&cmd=turn_ack',
            ]],
        );
    }

    private function formatAck($ack, GameState $state, array $cardsInfo): string
    {
        if (is_array($ack)) {
            $label = (string) ($ack['label'] ?? '');

            if (!empty($ack['source_id'])) {
                $srcCard = $state->getCard((int) $ack['source_id']);
                if ($srcCard) {
                    $label = $cardsInfo[$srcCard->ukid]['name'] ?? $srcCard->ukid;
                }
            }

            $items = $ack['items'] ?? [];
            $parts = [];
            foreach ($items as $it) {
                // Строки без карты (события-сообщения, «бомба взорвалась на (3;3)»)
                if (isset($it['standalone_text'])) {
                    $parts[] = $it['standalone_text'];
                    continue;
                }

                if (!isset($it['instance_id'])) continue;

                $card = $state->getCard((int) $it['instance_id']);
                if (!$card) continue;
                $name = $cardsInfo[$card->ukid]['name'] ?? $card->ukid;

                if (isset($it['delta'])) {
                    $delta   = (int) $it['delta'];
                    $sign    = $delta >= 0 ? '+' . $delta : '−' . abs($delta);
                    $parts[] = $name . ' ' . $sign;
                } elseif (isset($it['text'])) {
                    $parts[] = $name . ' — ' . $it['text'];
                } else {
                    $ev = (string) ($it['event'] ?? '');
                    if ($ev === 'progress') {
                        $parts[] = $name . ' ' . (int) $it['value'] . '/' . (int) $it['threshold'];
                    } elseif ($ev === 'ready') {
                        $parts[] = $name . ' — готов (ждёт места)';
                    } elseif ($ev === 'returned') {
                        $parts[] = $name . ' — вернулся в бой';
                    }
                }
            }

            return $label . (empty($parts) ? '' : ': ' . implode(', ', $parts));
        }

        $text = (string) $ack;
        if ($text === 'incarnation') $text = 'Инкарнация';
        return $text;
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $tp = new \Berserk\Core\TurnPhaseProcessor($state, $engine);
        return $tp->ackPending($playerKey);
    }
}