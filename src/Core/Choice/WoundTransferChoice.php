<?php
// src/Core/Choice/WoundTransferChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\Core\WoundTransferProcessor;
use Berserk\View\Ui\PanelSpec;

final class WoundTransferChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_wound_transfer';
    }

    public function commandTypes(): array
    {
        return ['wt_source', 'wt_amount', 'wt_target', 'wt_target_amount', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pw = $state->battle['pending_wound_transfer'] ?? null;
        if (!$pw) return null;

        $source = $state->getCard($pw['source_id']);
        $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? '?') : '?';

        if ($pw['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $sourceName . ' — перераспределение ран',
                isMine: false,
            );
        }

        $kind  = $pw['kind'] ?? 'hermit';
        $title = $sourceName . ($kind === 'volkhv'
            ? ' — Волхвование при луне'
            : ' — Перераспределение ран');

        return match ($pw['step'] ?? 'source') {
            'source'        => $this->sourceSpec($state, $pw, $title, $baseUrl, $role, $cardsInfo),
            'amount'        => $this->amountSpec($state, $pw, $title, $baseUrl, $role, $cardsInfo),
            'target'        => $this->targetSpec($state, $pw, $title, $baseUrl, $role, $cardsInfo),
            'target_amount' => $this->targetAmountSpec($state, $pw, $title, $baseUrl, $role, $cardsInfo),
            default         => null,
        };
    }

    private function sourceSpec(GameState $state, array $pw, string $title, string $baseUrl, string $role, array $cardsInfo): PanelSpec
    {
        $isStrike = (($pw['options']['donor_filter'] ?? 'wounded') === 'damaged_this_strike');

        $buttons = [];
        foreach ($pw['donors'] as $id => $max) {
            $c = $state->getCard((int) $id);
            if (!$c) continue;
            $name = $cardsInfo[$c->ukid]['name'] ?? '?';
            $hint = $isStrike
                ? ' (получил ' . $max . ' урона)'
                : ' (ран: ' . ($c->hpMax - $c->hp) . ', макс ' . $max . ')';

            $buttons[] = [
                'label' => $name . $hint,
                'url'   => "{$baseUrl}&cmd=wt_source&donor_id={$id}",
            ];
        }
        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $title . ' — с кого снять раны?',
            buttons: $buttons,
        );
    }

    private function amountSpec(GameState $state, array $pw, string $title, string $baseUrl, string $role, array $cardsInfo): PanelSpec
    {
        $donor     = $state->getCard($pw['donor_id']);
        $donorName = $donor ? ($cardsInfo[$donor->ukid]['name'] ?? '?') : '?';
        $max       = (int) $pw['donors'][$pw['donor_id']];

        $items = [];
        for ($i = 1; $i <= $max; $i++) {
            $items[] = [
                'value'   => $i,
                'label'   => $i . ' HP',
                'checked' => ($i === $max),
            ];
        }

        return new PanelSpec(
            title: $title . ' — сколько HP снять с ' . $donorName . '?',
            form: [
                'type'   => 'radio',
                'name'   => 'amount',
                'items'  => $items,
                'hidden' => [
                    ($role === 'host' ? 'first' : 'second') => '',
                    'game' => $state->gameId,
                    'cmd'  => 'wt_amount',
                ],
                'submit' => 'Подтвердить',
                'cancel' => $baseUrl . '&cmd=cancel_pending',
            ],
        );
    }

    private function targetSpec(GameState $state, array $pw, string $title, string $baseUrl, string $role, array $cardsInfo): PanelSpec
    {
        $donor     = $state->getCard($pw['donor_id']);
        $donorName = $donor ? ($cardsInfo[$donor->ukid]['name'] ?? '?') : '?';
        $remaining = (int) $pw['remaining'];
        $filter    = $pw['options']['target_filter'] ?? 'own';
        $owner     = $pw['owner'];

        $buttons = [];
        foreach ($state->cards as $c) {
            if ($c->instanceId === $pw['donor_id']) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($filter === 'own'   && $c->owner !== $owner) continue;
            if ($filter === 'enemy' && $c->owner === $owner) continue;

            $name = $cardsInfo[$c->ukid]['name'] ?? '?';
            $buttons[] = [
                'label' => $name . ' (' . $c->hp . '/' . $c->hpMax . ')',
                'url'   => "{$baseUrl}&cmd=wt_target&target_id={$c->instanceId}",
            ];
        }

        if ($remaining > 0) {
            $buttons[] = [
                'label' => 'Готово (осталось ' . $remaining . ')',
                'url'   => "{$baseUrl}&cmd=wt_target&target_id=0",
                'class' => 'skip',
            ];
        }

        return new PanelSpec(
            title: $title . ' — переложи ' . $remaining . ' HP с ' . $donorName,
            text: [
                'Распределить осталось: <b>' . $remaining . ' HP</b>',
            ],
            buttons: $buttons,
        );
    }

    private function targetAmountSpec(GameState $state, array $pw, string $title, string $baseUrl, string $role, array $cardsInfo): PanelSpec
    {
        $target     = $state->getCard($pw['target_id']);
        $targetName = $target ? ($cardsInfo[$target->ukid]['name'] ?? '?') : '?';
        $remaining  = (int) $pw['remaining'];

        $items = [];
        for ($i = 1; $i <= $remaining; $i++) {
            $items[] = [
                'value'   => $i,
                'label'   => $i . ' HP',
                'checked' => ($i === $remaining),
            ];
        }

        return new PanelSpec(
            title: $title . ' — сколько передать ' . $targetName . '?',
            form: [
                'type'   => 'radio',
                'name'   => 'amount',
                'items'  => $items,
                'hidden' => [
                    ($role === 'host' ? 'first' : 'second') => '',
                    'game' => $state->gameId,
                    'cmd'  => 'wt_target_amount',
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
        if ($cmd->type === 'cancel_pending') {
            return (new \Berserk\Core\ActionResolver($state, $engine))
                ->cancelPending($playerKey, $cmd);
        }
        
        $proc = new WoundTransferProcessor($state, $engine);
        return match ($cmd->type) {
            'wt_source'        => $proc->chooseSource($playerKey, $cmd),
            'wt_amount'        => $proc->chooseAmount($playerKey, $cmd),
            'wt_target'        => $proc->chooseTarget($playerKey, $cmd),
            'wt_target_amount' => $proc->chooseTargetAmount($playerKey, $cmd),
            default            => Result::error('Неизвестная команда'),
        };
    }
}