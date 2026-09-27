<?php
// src/Core/Choice/DealScoutRecruitChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class DealScoutRecruitChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_deal_scout_recruit';
    }

    public function commandTypes(): array
    {
        return ['confirm_deal_scout_recruit', 'close_deal_scout_recruit', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle[$this->pendingKey()] ?? null;
        if (!$pending) return null;

        $card = $state->getCard((int) ($pending['card_id'] ?? 0));
        $cardName = $card ? ($cardsInfo[$card->ukid]['name'] ?? $card->ukid) : 'Карта';

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $cardName . ' — разведка',
                isMine: false,
            );
        }

        return match ((string) ($pending['step'] ?? 'confirm')) {
            'reveal' => $this->revealSpec($state, $pending, $cardsInfo, $baseUrl, $role, $cardName),
            default => $this->confirmSpec($state, $baseUrl, $role, $cardName),
        };
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $prepare = new PrepareProcessor($state);

        return match ($cmd->type) {
            'confirm_deal_scout_recruit' => $prepare->confirmDealScoutRecruit($playerKey),
            'close_deal_scout_recruit' => $prepare->closeDealScoutRecruit($playerKey),
            'cancel_pending' => $prepare->cancelDealScoutRecruit($playerKey),
            default => Result::error('Неверная команда разведки'),
        };
    }

    private function confirmSpec(GameState $state, string $baseUrl, string $role, string $cardName): PanelSpec
    {
        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $cardName . ' — разведка',
            text: [
                'После подтверждения Лазутчицу необходимо будет взять в отряд. Отменить действие будет невозможно.',
            ],
            form: [
                'type' => 'hidden',
                'hidden' => [
                    $roleParam => '',
                    'game' => $state->gameId,
                    'cmd' => 'confirm_deal_scout_recruit',
                ],
                'submit' => 'Подтвердить',
                'cancel' => $baseUrl . '&cmd=cancel_pending',
            ],
        );
    }

    private function revealSpec(
        GameState $state,
        array $pending,
        array $cardsInfo,
        string $baseUrl,
        string $role,
        string $cardName
    ): PanelSpec {
        $cards = [];
        foreach ((array) ($pending['revealed_ids'] ?? []) as $id) {
            $revealed = $state->getCard((int) $id);
            if (!$revealed) continue;

            $info = $cardsInfo[$revealed->ukid] ?? [];
            $name = (string) ($info['name'] ?? $revealed->ukid);
            $tier = $revealed->elite ? 'золотая' : 'серебряная';

            $cards[] = '<div class="task-card">'
                . '<b>' . htmlspecialchars($name, ENT_QUOTES) . '</b>'
                . '<span>' . htmlspecialchars($tier, ENT_QUOTES) . '</span>'
                . '</div>';
        }

        $eliteCount = max(0, (int) ($pending['elite_count'] ?? 0));
        $discount = max(0, (int) ($pending['discount'] ?? 0));
        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $cardName . ' — раскрытые карты',
            cards: $cards,
            text: [
                'Золотых карт раскрыто: <b>' . $eliteCount . '</b>.',
                'Скидка к серебряной стоимости: <b>−' . $discount . '</b>.',
            ],
            form: [
                'type' => 'hidden',
                'hidden' => [
                    $roleParam => '',
                    'game' => $state->gameId,
                    'cmd' => 'close_deal_scout_recruit',
                ],
                'submit' => 'Закрыть',
            ],
        );
    }
}
