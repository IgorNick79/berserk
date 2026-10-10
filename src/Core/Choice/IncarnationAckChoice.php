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
        $ack = $state->battle['pending_incarnation_ack'] ?? null;
        if (!$ack) return null;

        if (($ack['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: 'Могильная хватка',
                isMine: false,
            );
        }

        $text = $this->formatEntries($ack['entries'] ?? [], $cardsInfo);

        return new PanelSpec(
            title: $text . '. Готов к инкарнации.',
            isMine: true,
            buttons: [[
                'label' => 'Продолжить',
                'url'   => $baseUrl . '&cmd=incarnation_ack',
            ]],
        );
    }

    /**
     * @param array<int, array{source_ukid:string,targets:array}> $entries
     */
    private function formatEntries(array $entries, array $cardsInfo): string
    {
        if (empty($entries)) {
            return 'Могильная хватка сработала';
        }

        $parts = [];
        foreach ($entries as $entry) {
            $srcUkid = (string) ($entry['source_ukid'] ?? '');
            $srcName = $srcUkid !== '' && isset($cardsInfo[$srcUkid])
                ? $cardsInfo[$srcUkid]['name']
                : $srcUkid;

            $hits = [];
            foreach ($entry['targets'] as $t) {
                $tUkid = (string) ($t['target_ukid'] ?? '');
                $tName = $tUkid !== '' && isset($cardsInfo[$tUkid])
                    ? $cardsInfo[$tUkid]['name']
                    : $tUkid;
                $dmg = (int) ($t['damage'] ?? 0);
                $hits[] = $tName . ' -' . $dmg . 'HP';
            }

            $parts[] = $srcName . ': ' . implode(', ', $hits);
        }

        return implode('; ', $parts);
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ack = $state->battle['pending_incarnation_ack'] ?? null;
        if (!$ack || ($ack['owner'] ?? null) !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        unset($state->battle['pending_incarnation_ack']);
        $state->bumpVersion();
        return Result::ok(['incarnation_ack']);
    }
}