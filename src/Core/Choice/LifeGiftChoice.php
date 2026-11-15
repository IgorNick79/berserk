<?php
// src/Core/Choice/LifeGiftChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\{CardSelectionGroup, CardSelectionSpec, CreatureChoice, PanelSpec};

final class LifeGiftChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_life_gift'; }
    public function commandTypes(): array { return ['choose_life_gift', 'cancel_pending']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_life_gift'] ?? null;
        if (!$p) return null;

        $src = $state->getCard((int) $p['card_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(title: $srcName . ' — предсмертный дар', isMine: false);
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        $step = $p['step'] ?? 'amount';
        $title = $srcName . ' — предсмертный дар';
        if ($step === 'amount') {
            $buttons = [];
            for ($i = (int) $p['min_x']; $i <= min((int) $p['max_x'], $src?->coins ?? 0); $i++) {
                $buttons[] = ['label' => (string) $i,
                    'url' => $baseUrl . '&cmd=choose_life_gift&amount=' . $i];
            }
            $buttons[] = ['label'=>'Отмена', 'url'=>$baseUrl . '&cmd=cancel_pending', 'class'=>'skip'];
            return new PanelSpec(title: $title . ': сколько монет потратить?', buttons: $buttons);
        }

        $current = (new \Berserk\Core\ActionResolver($state, new Engine()))->lifeGiftTargets($playerKey);
        $enemy = $step === 'enemy';
        $key = $enemy ? 'enemies' : 'allies';
        $ids = array_values(array_intersect($p[$key], $current[$key]));
        $items = CreatureChoice::candidates($state, $ids, $playerKey, $cardsInfo, CardSelectionSpec::DEFAULT_MODE);
        return new PanelSpec(
            title: $title . ($enemy ? ': выберите существо противника' : ': выберите своё существо'),
            form: [
                'hidden' => [$roleParam=>'', 'game'=>$state->gameId, 'cmd'=>'choose_life_gift'],
                'submit' => $enemy ? 'Далее' : 'Применить',
                'cancel' => $baseUrl . '&cmd=cancel_pending',
            ],
            cardSelection: new CardSelectionSpec(
                context: implode(':', [$state->gameId, $playerKey, 'life-gift', $p['card_id'],
                    $p['opened_version'] ?? 'legacy-' . $state->version, $step]),
                battlefieldOwner: $playerKey,
                groups: [new CardSelectionGroup($step, $enemy ? 'enemy_id' : 'ally_id', $items,
                    $enemy ? 'Кого ранить' : 'Кого излечить')],
            ),
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
        return (new \Berserk\Core\ActionResolver($state, $engine))
            ->chooseLifeGift($playerKey, $cmd);
    }
}