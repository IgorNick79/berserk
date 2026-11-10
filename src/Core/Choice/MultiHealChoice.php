<?php
// src/Core/Choice/MultiHealChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;
use Berserk\Core\ActionResolution;
use Berserk\View\Ui\{CardSelectionSpec, CreatureChoice};

final class MultiHealChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_multi_heal';
    }

    public function commandTypes(): array
    {
        return ['choose_multi_heal', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pmh = $state->battle['pending_multi_heal'] ?? null;
        if (!$pmh) return null;

        $attCard = $state->getCard($pmh['attacker_id']);
        $attName = $attCard ? ($cardsInfo[$attCard->ukid]['name'] ?? '?') : '?';

        if (!$attCard || $attCard->owner !== $playerKey) {
            return new PanelSpec(
                title: $attName . ' — излечение',
                isMine: false,
            );
        }

        $max   = (int) $pmh['max_targets'];
        $value = (int) $pmh['value'];

        $items = CreatureChoice::candidates($state, $pmh['candidates'], $playerKey, $cardsInfo, CardSelectionSpec::DEFAULT_MODE);
        $submitOrder = array_flip($pmh['candidates']);
        foreach ($items as &$item) {
            $item['submitOrder'] = $submitOrder[$item['instanceId']];
        }
        unset($item);

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $attName . ' — выбери до ' . $max . ' целей (по +' . $value . ' HP):',
            form: [
                'type'   => 'checkbox',
                'name'   => 'target_ids[]',
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_multi_heal',
                ],
                'submit' => 'Подтвердить',
                'cancel' => $baseUrl . '&cmd=cancel_pending',
            ],
            cardSelection: new CardSelectionSpec(
                context: implode(':', [$state->gameId, $playerKey, 'multi-heal',
                    $pmh['action'][ActionResolution::ID] ?? 'legacy-' . $state->version]),
                candidates: $items,
                maxSelect: $max,
                battlefieldOwner: $playerKey,
            ),
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        if ($cmd->type === 'cancel_pending') {
            return $ar->cancelPending($playerKey, $cmd);
        }
        return $ar->chooseMultiHeal($playerKey, $cmd);
    }
}