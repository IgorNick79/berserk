<?php
// src/Core/Choice/ChoiceRegistry.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\View\Ui\PanelSpec;

final class ChoiceRegistry
{
    /** @return ChoiceHandlerInterface[] */
    public static function all(): array
    {
        static $handlers = null;
        if ($handlers === null) {
            $handlers = [
                new DealLinkedRecruitChoice(),
                new DealScoutRecruitChoice(),
                new DealVariableRecruitChoice(),
                new CoinSpendChoice(),
                new DiceChoiceChoice(),
                new AfterStrikeExecuteChoice(),
                new CellMarkerChoice(),
                new InstantPickChoice(),
                new CombatPickChoice(),
                new IncarnationChoice(),
                new ValhallaPickChoice(),
                new AnyDeathChoice(),
                new WhipChoice(),
                new KoboldHealChoice(),
                new TalionIncarnationChoice(),
                new HolvertOpenChoice(),
                new WoundTransferChoice(),
                new MultiHealChoice(),
                new MultiDischargeChoice(),
                new SelfWoundChoice(),
                new CardChoice(),
                new BloodTapChoice(),
                new ReviveChoice(),
                new GrezyChoice(),
                new ForcedDirectionalMoveChoice(),
                new ForcedStrikeChoice(),
                new RowPickChoice(),
                new RowSpellPickChoice(),
                new GatePickChoice(),
                new GreedTeleportChoice(),
                // ─── Фаза хода ───────────────────────────────────────
                new TurnAckChoice(),
                new TurnInstantsChoice(),
                new TurnSubChoice(),
                new TurnPhaseChoice(),
            ];
        }
        return $handlers;
    }

    public static function byPendingKey(string $key): ?ChoiceHandlerInterface
    {
        foreach (self::all() as $h) {
            if ($h->pendingKey() === $key) return $h;
        }
        return null;
    }

    public static function byCommandType(string $type, ?GameState $state = null): ?ChoiceHandlerInterface
    {
        if ($type === 'cancel_pending' && $state !== null) {
            foreach (self::all() as $h) {
                $key = $h->pendingKey();
                if (empty($state->battle[$key])) continue;
                if (in_array($type, $h->commandTypes(), true)) return $h;
            }
            return null;
        }

        foreach (self::all() as $h) {
            if (in_array($type, $h->commandTypes(), true)) return $h;
        }
        return null;
    }

    /** Какой pending сейчас активен (первый найденный в реестре) */
    public static function current(GameState $state): ?ChoiceHandlerInterface
    {
        foreach (self::all() as $h) {
            // Если хендлер умеет isActive() — он сам знает, когда активен
            if (method_exists($h, 'isActive')) {
                if ($h->isActive($state)) return $h;
                continue;
            }

            // Стандартная проверка по плоскому ключу
            $key = $h->pendingKey();
            if (!empty($state->battle[$key])) return $h;
        }
        return null;
    }
}
