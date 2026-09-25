<?php
// src/Core/Choice/TransferChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class TransferChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_transfer';
    }

    public function commandTypes(): array
    {
        return ['choose_transfer_donor', 'choose_transfer_amount'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pt = $state->battle['pending_transfer'] ?? null;
        if (!$pt) return null;

        $attCard = $state->getCard($pt['attacker_id']);
        $attName = $attCard ? ($cardsInfo[$attCard->ukid]['name'] ?? '?') : '?';

        if (!$attCard || $attCard->owner !== $playerKey) {
            return new PanelSpec(
                title: $attName . ' — перераспределение ран',
                isMine: false,
            );
        }

        // Шаг 1: выбор донора
        if (empty($pt['donor_id'])) {
            $buttons = [];
            foreach ($pt['candidates'] as $tid) {
                $tc = $state->getCard($tid);
                if (!$tc) continue;
                $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
                $wounds = $tc->hpMax - $tc->hp;

                $buttons[] = [
                    'label' => $tn . ' (ран: ' . $wounds . ')',
                    'url'   => "{$baseUrl}&cmd=choose_transfer_donor&donor_id={$tid}",
                ];
            }
            $buttons[] = [
                'label' => 'Отмена',
                'url'   => $baseUrl . '&cmd=cancel_pending',
                'class' => 'skip',
            ];

            return new PanelSpec(
                title: $attName . ': выбери цель для снятия ран',
                buttons: $buttons,
            );
        }

        // Шаг 2: выбор количества
        $donor     = $state->getCard($pt['donor_id']);
        $donorName = $donor ? ($cardsInfo[$donor->ukid]['name'] ?? '?') : '?';
        $maxAmount = (int) $pt['wounds_available'];

        $items = [];
        for ($i = 1; $i <= $maxAmount; $i++) {
            $items[] = [
                'value'   => $i,
                'label'   => $i . ' ран',
                'checked' => ($i === $maxAmount),
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $attName . ' снимает раны с ' . $donorName,
            form: [
                'type'   => 'radio',
                'name'   => 'amount',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_transfer_amount',
                ],
                'submit' => 'Подтвердить',
                'cancel' => $baseUrl . '&cmd=cancel_pending',
            ],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        return match ($cmd->type) {
            'choose_transfer_donor'  => $ar->chooseTransferDonor($playerKey, $cmd),
            'choose_transfer_amount' => $ar->chooseTransferAmount($playerKey, $cmd),
            default                  => Result::error('Неизвестная команда'),
        };
    }
}