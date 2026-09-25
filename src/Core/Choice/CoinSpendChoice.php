<?php
// src/Core/Choice/CoinSpendChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\View\Ui\PanelSpec;

final class CoinSpendChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_coin_spend';
    }

    public function commandTypes(): array
    {
        return ['choose_coin_spend'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pcs = $state->battle['pending_coin_spend'] ?? null;
        if (!$pcs) return null;

        $attCard = $state->getCard($pcs['attacker_id']);
        if (!$attCard) return null;

        $attName = $cardsInfo[$attCard->ukid]['name'] ?? '?';

        if ($attCard->owner !== $playerKey) {
            return new PanelSpec(
                title: $attName . ' — выбор монет',
                isMine: false,
            );
        }

        $min     = (int) ($pcs['min_coins'] ?? 0);
        $max     = (int) $pcs['max_coins'];
        $perCoin = (int) $pcs['per_coin'];
        $mode    = (string) ($pcs['mode'] ?? 'damage');

        $items = [];
        for ($i = $min; $i <= $max; $i++) {
            if ($mode === 'shield') {
                $suffix = ' (' . $i . ' ход(а) защиты)';
            } else {
                $suffix = ($i > 0 && $perCoin > 0)
                    ? ' (+' . ($i * $perCoin) . ' к урону)'
                    : '';
            }
            $items[] = [
                'value'   => $i,
                'label'   => $i . ' монет' . $suffix,
                'checked' => ($i === $max),
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $attName . ' — сколько монет потратить?',
            form: [
                'type'   => 'radio',
                'name'   => 'amount',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_coin_spend',
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
        // Делегируем в существующий ActionResolver через рефлексию Engine:
        // у Engine есть apply($state, $playerKey, $cmd), а сам он знает про ActionResolver.
        // Проще — дернуть из Engine публичный метод, если он есть, либо вернуть
        // "не поддержано в пилоте" и оставить старую ветку.

        // Пилот: используем ActionResolver напрямую.
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        return $ar->chooseCoinSpend($playerKey, $cmd);
    }
}