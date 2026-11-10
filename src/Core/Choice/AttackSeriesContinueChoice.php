<?php
// src/Core/Choice/AttackSeriesContinueChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class AttackSeriesContinueChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_attack_series_choice'; }

    public function commandTypes(): array
    {
        return ['attack_series_next', 'attack_series_end', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_attack_series_choice'] ?? null;
        if (!$p) return null;

        $hydra = $state->getCard((int) $p['source_id']);
        $hName = $hydra ? ($cardsInfo[$hydra->ukid]['name'] ?? '?') : '?';

        if (($p['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $hName . ' — продолжение серии',
                isMine: false,
            );
        }

        $items = [];
        foreach ($p['candidates'] as $tid) {
            $target = $state->getCard((int) $tid);
            if (!$target || $target->dying || $target->hp <= 0) continue;
            $name = $target->revealed || $target->owner === $playerKey
                ? ($cardsInfo[$target->ukid]['name'] ?? '?') : '???';
            $items[] = ['value' => $target->instanceId, 'label' => $name];
        }
        $endUrl = $baseUrl . '&cmd=attack_series_end';

        return new PanelSpec(
            title: $hName . ': продолжить серию атак?',
            text: ['Атакуй следующего соседа или закончи серию — тогда Гидра закроется.'],
            form: $items ? [
                'type' => 'radio',
                'name' => 'target_id',
                'items' => $items,
                'hidden' => \Berserk\View\Ui\Form::battleHidden($role, $state->gameId, 'attack_series_next'),
                'submit' => 'Выбрать',
                'cancel' => $endUrl,
                'cancel_label' => 'Закончить серию',
            ] : null,
            buttons: $items ? [] : [['label'=>'Закончить серию', 'url'=>$endUrl, 'class'=>'skip']],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);

        if ($cmd->type === 'attack_series_end' || $cmd->type === 'cancel_pending') {
            return $ar->chooseAttackSeriesEnd($playerKey, $cmd);
        }

        return $ar->chooseAttackSeriesNext($playerKey, $cmd);
    }
}