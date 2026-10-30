<?php
declare(strict_types=1);

namespace Berserk\Core;

/** Persisted turn clock. All timestamps are server Unix seconds, never client input. */
final class TurnTimer
{
    public const LIMIT_SECONDS = 180;

    public static function enabled(GameState $state): bool
    {
        return ($state->settings->battle['turn_seconds'] ?? self::LIMIT_SECONDS) !== 0;
    }

    /** Called only by the turn lifecycle, never by load/snapshot. */
    public static function prepare(GameState $state, string $owner): void
    {
        if (!self::enabled($state)) return;
        if ($state->status !== 'battle' || $state->winner !== null) return;
        if (!in_array($owner, [GameState::PLAYER_HOST, GameState::PLAYER_PLAYER], true)) {
            throw new \InvalidArgumentException('Unknown timer owner');
        }
        $previous = $state->battle['turn_timer'] ?? null;
        // Repeated lifecycle continuation must not allocate another period.
        if ($previous !== null && $previous['owner'] === $owner) return;
        $state->battle['turn_timer'] = [
            'period_id' => ($previous['period_id'] ?? 0) + 1,
            'owner' => $owner,
            'status' => 'paused',
            'remaining_seconds' => self::LIMIT_SECONDS,
            'anchor_at' => null,
            'pause_reason' => 'start_phase',
        ];
    }

    public static function settle(GameState $state, int $now): void
    {
        if (!self::enabled($state) || !isset($state->battle['turn_timer'])) return;
        $timer = &$state->battle['turn_timer'];
        if ($timer['status'] === 'running') {
            // A backwards wall-clock adjustment cannot refund consumed time.
            $effectiveNow = max($now, $timer['anchor_at']);
            $timer['remaining_seconds'] = max(0, $timer['remaining_seconds'] - ($effectiveNow - $timer['anchor_at']));
            $timer['anchor_at'] = $effectiveNow;
            if ($timer['remaining_seconds'] === 0) {
                $timer['status'] = 'expired';
                $timer['anchor_at'] = null;
            }
        }
        if ($state->status !== 'battle' || $state->winner !== null) {
            if ($timer['status'] !== 'expired') $timer['status'] = 'paused';
            $timer['anchor_at'] = null;
            $timer['pause_reason'] = 'battle_finished';
        }
    }

    public static function pause(GameState $state, int $periodId, string $reason, int $now): void
    {
        if (!self::enabled($state) || ($state->battle['turn_timer']['period_id'] ?? null) !== $periodId) return;
        self::settle($state, $now);
        $timer = &$state->battle['turn_timer'];
        if ($timer['status'] !== 'running') return;
        $timer['status'] = 'paused';
        $timer['anchor_at'] = null;
        $timer['pause_reason'] = $reason;
    }

    public static function resume(GameState $state, int $periodId, string $reason, int $now): void
    {
        if (!self::enabled($state) || ($state->battle['turn_timer']['period_id'] ?? null) !== $periodId) return;
        self::settle($state, $now);
        $timer = &$state->battle['turn_timer'];
        if ($state->status !== 'battle' || $state->winner !== null
            || $timer['status'] !== 'paused' || $timer['pause_reason'] !== $reason) return;
        $timer['status'] = 'running';
        $timer['anchor_at'] = $now;
        $timer['pause_reason'] = null;
    }

    /** Reconcile only the pause owned by P1; unknown waits preserve the current mode. */
    public static function reconcileWaiting(GameState $state, int $now): void
    {
        if (!self::enabled($state) || MatchPause::blocking($state)) return;
        $timer = $state->battle['turn_timer'] ?? null;
        if ($timer === null || $state->status !== 'battle' || $state->winner !== null
            || $timer['status'] === 'expired'
            || ($timer['status'] === 'paused' && $timer['pause_reason'] !== 'opponent_wait')) return;
        $wait = BattleWaitResolver::current($state);
        if (($wait['kind'] ?? null) === 'unknown') return;
        $opponent = $state->getOpponentKey($timer['owner']);
        if (in_array($opponent, $wait['required_players'] ?? [], true)) {
            self::pause($state, $timer['period_id'], 'opponent_wait', $now);
        } else {
            self::resume($state, $timer['period_id'], 'opponent_wait', $now);
        }
    }

    /** Read-only projection for a future AJAX snapshot; does not allocate or persist. */
    public static function snapshot(GameState $state, int $now): ?array
    {
        if (!self::enabled($state)) return null;
        $timer = $state->battle['turn_timer'] ?? null;
        if (!is_array($timer) || !self::validMetadata($timer)) return null;
        $copy = clone $state;
        self::settle($copy, $now);
        if (!isset($copy->battle['turn_timer'])) return null;
        return $copy->battle['turn_timer'] + ['limit_seconds' => self::LIMIT_SECONDS, 'server_now' => $now];
    }

    /** Structural model validation only; no timeout command or completion policy. */
    private static function validMetadata(array $t): bool
    {
        foreach (['period_id','owner','status','remaining_seconds','anchor_at','pause_reason'] as $key) {
            if (!array_key_exists($key, $t)) return false;
        }
        if (!is_int($t['period_id']) || $t['period_id'] < 1 || !in_array($t['owner'], ['host','player'], true)
            || !is_int($t['remaining_seconds']) || $t['remaining_seconds'] < 0 || $t['remaining_seconds'] > self::LIMIT_SECONDS
            || !in_array($t['status'], ['running','paused','expired'], true)
            || ($t['pause_reason'] !== null && (!is_string($t['pause_reason']) || $t['pause_reason'] === ''))) return false;
        if ($t['status'] === 'running') return is_int($t['anchor_at']) && $t['anchor_at'] > 0 && $t['pause_reason'] === null;
        if ($t['anchor_at'] !== null) return false;
        return $t['status'] === 'expired'
            ? $t['remaining_seconds'] === 0 && in_array($t['pause_reason'], [null, 'battle_finished'], true)
            : $t['remaining_seconds'] > 0 && $t['pause_reason'] !== null;
    }
}
