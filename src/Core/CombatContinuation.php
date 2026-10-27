<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Opt-in ownership only: native resolvers retain responsibility for combat effects. */
final class CombatContinuation
{
    public static function active(GameState $state): bool
    {
        return !empty($state->battle['combat_continuation']);
    }

    public static function blocked(GameState $state, array $returnTo): void
    {
        unset($state->battle['combat_completion']);
        $state->battle['combat_continuation'] = ['return_to'=>$returnTo, 'frames'=>[1=>'done'], 'next_id'=>1, 'stacks'=>[]];
    }

    public static function begin(GameState $state, array $returnTo = []): void
    {
        if (!empty($state->battle['strike']['continuation_frame'])) return;
        if (!$returnTo && !self::active($state)) return;
        if (!self::active($state)) {
            unset($state->battle['combat_completion']);
            $state->battle['combat_continuation'] = [
                'return_to' => $returnTo, 'frames' => [], 'next_id' => 0, 'stacks' => [],
            ];
        }
        $context = &$state->battle['combat_continuation'];
        $id = ++$context['next_id'];
        $context['frames'][$id] = 'active';
        $state->battle['strike']['continuation_frame'] = $id;
    }

    public static function suspend(GameState $state, array $strike): void
    {
        $id = $strike['continuation_frame'] ?? null;
        if ($id !== null) $state->battle['combat_continuation']['frames'][$id] = 'suspended';
    }

    public static function resume(GameState $state, array $strike): void
    {
        $id = $strike['continuation_frame'] ?? null;
        if ($id === null || !isset($state->battle['combat_continuation']['frames'][$id])) {
            throw new \LogicException('Unknown suspended combat frame');
        }
        $state->battle['combat_continuation']['frames'][$id] = 'active';
        $state->battle['strike'] = $strike;
    }

    public static function localDone(GameState $state, array $strike): void
    {
        $id = $strike['continuation_frame'] ?? null;
        if ($id !== null && ($state->battle['combat_continuation']['frames'][$id] ?? null) === 'active') {
            $state->battle['combat_continuation']['frames'][$id] = 'done';
        }
    }

    public static function busy(GameState $state): bool
    {
        if (!empty($state->battle['strike']) || Choice\ChoiceRegistry::current($state) !== null) return true;
        foreach ($state->battle as $key => $value) {
            if (str_starts_with((string) $key, 'pending_') && !empty($value)) return true;
        }
        foreach (['turn_instant_stack', 'turn_instant_result', 'coin_post_combat_queue', 'coin_post_combat_continuation'] as $key) {
            if (!empty($state->battle[$key])) return true;
        }
        return false;
    }

    public static function settle(GameState $state, Engine $engine): void
    {
        while (self::active($state)) {
            if ($state->status !== 'battle' || $state->winner !== null) {
                unset($state->battle['combat_continuation']);
                return;
            }
            if (self::busy($state)) return;
            $context = &$state->battle['combat_continuation'];
            if ($context['stacks']) {
                // A suspended parent may resume only after every newer combat frame is done.
                $stack = $context['stacks'][array_key_last($context['stacks'])];
                foreach ($context['frames'] as $id => $stage) {
                    if ($id > $stack['frame_watermark'] && $stage !== 'done') return;
                }
                array_pop($context['stacks']);
                $state->battle['turn_instant_stack'] = $stack;
                (new InstantProcessor($state, $engine))->resumeContinuationStack();
                // Native re-entry may release coin effects. Do not leave an invisible queue
                // waiting for a user command when there is no actionable panel yet.
                (new CoinAbilities($state, $engine))->advance();
                continue;
            }
            if (!$context['frames']) return;
            foreach ($context['frames'] as $stage) if ($stage !== 'done') return;
            // A reservation root owns nested frames but is not itself a combat.
            if (($context['return_to']['kind'] ?? null) !== 'instant_reservation' || $context['next_id'] > 1) {
                $state->historyRecorder?->combatFinished($state);
            }
            $state->battle['combat_completion'] = $context['return_to'];
            unset($state->battle['combat_continuation']);
            return;
        }
    }
}
