<?php
// src/Core/Choice/IncarnationAckChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class IncarnationAckChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_incarnation_ack'; }
    public function commandTypes(): array { return ['incarnation_ack']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        if (empty($state->battle['pending_incarnation_ack'])) return null;

        $parts = [];
        foreach ($state->battle['any_death_messages'] ?? [] as $m) {
            if (($m['type'] ?? '') !== 'incarnation_token_wound') continue;

            $srcUkid = (string) ($m['source_ukid'] ?? '');
            $srcName = $cardsInfo[$srcUkid]['name'] ?? $srcUkid;

            $hits = [];
            foreach ((array) ($m['targets'] ?? []) as $t) {
                $tUkid = (string) ($t['target_ukid'] ?? '');
                $tName = $cardsInfo[$tUkid]['name'] ?? $tUkid;
                $dmg   = (int) ($t['damage'] ?? 0);
                $hits[] = $tName . ' -' . $dmg . 'HP';
            }

            $parts[] = $srcName . ': ' . implode(', ', $hits);
        }

        $title = empty($parts) ? 'Могильная хватка' : implode('; ', $parts);

        return new PanelSpec(
            title: $title,
            isMine: true,
            buttons: [[
                'label' => 'Продолжить',
                'url'   => $baseUrl . '&cmd=incarnation_ack',
            ]],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        if (empty($state->battle['pending_incarnation_ack'])) {
            return Result::error('Нечего подтверждать');
        }

        unset($state->battle['pending_incarnation_ack']);
        $state->bumpVersion();
        return Result::ok(['incarnation_ack']);
    }
}