<?php
// src/Core/Choice/ReviveChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\View\Ui\PanelSpec;

final class ReviveChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_revive';
    }

    public function commandTypes(): array
    {
        return ['choose_revive_target', 'choose_revive_cell'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pr = $state->battle['pending_revive'] ?? null;
        if (!$pr) return null;

        $healer     = $state->getCard($pr['healer_id']);
        $healerName = $healer ? ($cardsInfo[$healer->ukid]['name'] ?? '?') : '?';

        if (!$healer || $healer->owner !== $playerKey) {
            return new PanelSpec(
                title: $healerName . ' — возрождение',
                isMine: false,
            );
        }

        $step = $pr['step'] ?? 'card';

        if ($step === 'card') {
            $buttons = [];
            foreach ($pr['candidates'] as $tid) {
                $tc = $state->getCard($tid);
                if (!$tc) continue;
                $tn   = $cardsInfo[$tc->ukid]['name'] ?? '?';
                $type = $tc->type === 'fly' ? ' (летун)' : '';

                $buttons[] = [
                    'label' => $tn . $type,
                    'url'   => "{$baseUrl}&cmd=choose_revive_target&target_id={$tid}",
                ];
            }
            $buttons[] = [
                'label' => 'Отмена',
                'url'   => $baseUrl . '&cmd=cancel_pending',
                'class' => 'skip',
            ];

            return new PanelSpec(
                title: $healerName . ' возрождает — выбери существо с кладбища:',
                buttons: $buttons,
            );
        }

        if ($step === 'cell') {
            $card = $state->getCard($pr['chosen_id']);
            if (!$card) return null;
            $cardName = $cardsInfo[$card->ukid]['name'] ?? '?';

            $cells = [];
            $dirs  = [[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]];
            foreach ($dirs as [$dr, $dc]) {
                $r = $healer->row + $dr;
                $c = $healer->col + $dc;
                if ($r < 1 || $r > 6 || $c < 1 || $c > 5) continue;

                $occupied = false;
                foreach ($state->cards as $other) {
                    if ($other->zone === CardInstance::ZONE_FIELD
                        && $other->row === $r && $other->col === $c) {
                        $occupied = true;
                        break;
                    }
                }
                if ($occupied) continue;

                $cells[] = [
                    'label' => "({$r},{$c})",
                    'url'   => "{$baseUrl}&cmd=choose_revive_cell&row={$r}&col={$c}",
                ];
            }

            if (empty($cells)) {
                return new PanelSpec(
                    title: 'Нет свободного места рядом с ' . $healerName,
                    buttons: [[
                        'label' => 'Отмена',
                        'url'   => $baseUrl . '&cmd=cancel_pending',
                        'class' => 'skip',
                    ]],
                );
            }

            $cells[] = [
                'label' => 'Отмена',
                'url'   => $baseUrl . '&cmd=cancel_pending',
                'class' => 'skip',
            ];

            return new PanelSpec(
                title: $cardName . ' возрождается рядом со Знахарем — выбери клетку:',
                buttons: $cells,
            );
        }

        return null;
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        return match ($cmd->type) {
            'choose_revive_target' => $ar->chooseReviveTarget($playerKey, $cmd),
            'choose_revive_cell'   => $ar->chooseReviveCell($playerKey, $cmd),
            default                => Result::error('Неизвестная команда'),
        };
    }
}