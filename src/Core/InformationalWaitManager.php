<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Identity/deadline only. Gameplay continues exclusively through Engine commands. */
final class InformationalWaitManager
{
    public const LIMIT_SECONDS = 10;
    private const SOURCES = ['strike.results', 'strike.waiting_push_ack'];

    /** Called at logical result publication, not on a partial ACK or a snapshot. */
    public static function publish(GameState $state): void
    {
        unset($state->battle['informational_wait']);
        $state->battle['informational_wait_pending'] = true;
    }

    private static function projection(GameState $state): ?array
    {
        $wait = BattleWaitResolver::current($state);
        return ($wait['kind'] ?? null) === 'informational' && in_array($wait['source'], self::SOURCES, true)
            ? $wait : null;
    }

    private static function timer(GameState $state): ?array
    {
        $timer = $state->battle['turn_timer'] ?? null;
        return $state->status === 'battle' && $state->winner === null && is_array($timer)
            && in_array($timer['owner'] ?? null, ['host', 'player'], true)
            && is_int($timer['period_id'] ?? null) && $timer['period_id'] > 0
            && in_array($timer['status'] ?? null, ['running', 'paused', 'expired'], true) ? $timer : null;
    }

    /** Read-only record validation, including its current lifecycle/period binding. */
    public static function record(GameState $state): ?array
    {
        $record = self::boundRecord($state);
        $projection = self::projection($state);
        return $record !== null && $projection !== null && $record['source'] === $projection['source'] ? $record : null;
    }

    private static function boundRecord(GameState $state): ?array
    {
        $record = $state->battle['informational_wait'] ?? null;
        $timer = self::timer($state);
        if (!is_array($record) || $timer === null) return null;
        foreach (['wait_id', 'period_id', 'started_at', 'deadline_at'] as $field) {
            if (!is_int($record[$field] ?? null) || $record[$field] < 1) return null;
        }
        $generation = $state->battle['informational_wait_generation'] ?? null;
        if (!is_int($generation) || $record['wait_id'] !== $generation
            || $record['period_id'] !== $timer['period_id']
            || !in_array($record['source'] ?? null, self::SOURCES, true)
            || ($record['required_player'] ?? null) !== $state->getOpponentKey($timer['owner'])
            || $record['deadline_at'] - $record['started_at'] !== self::LIMIT_SECONDS) return null;
        return $record;
    }

    /** Run only after a successful command and all its existing continuations. */
    public static function reconcile(GameState $state, int $now): void
    {
        if (self::timer($state) === null || empty($state->battle['strike'])) {
            unset($state->battle['informational_wait'], $state->battle['informational_wait_pending']);
            return;
        }
        if (self::projection($state) === null) {
            // A suspended valid stage gets a fresh identity when it becomes actionable again.
            if (self::boundRecord($state) !== null) {
                $state->battle['informational_wait_pending'] = true;
                unset($state->battle['informational_wait']);
            }
            return;
        }
        if (self::projection($state)['required_players'] === []) {
            unset($state->battle['informational_wait'], $state->battle['informational_wait_pending']);
            return;
        }
        if (array_key_exists('informational_wait', $state->battle)) {
            // Corrupt/mismatched metadata is never repaired into an automatic authorization.
            return;
        }
        if (($state->battle['informational_wait_pending'] ?? false) !== true) return; // Legacy window.
        $generation = $state->battle['informational_wait_generation'] ?? 0;
        if (!is_int($generation) || $generation < 0 || $generation === PHP_INT_MAX || $now < 1 || $now > PHP_INT_MAX - 10) return;
        $timer = self::timer($state);
        $projection = self::projection($state);
        $state->battle['informational_wait_generation'] = ++$generation;
        $state->battle['informational_wait'] = [
            'wait_id' => $generation, 'source' => $projection['source'],
            'required_player' => $state->getOpponentKey($timer['owner']), 'period_id' => $timer['period_id'],
            'started_at' => $now, 'deadline_at' => $now + self::LIMIT_SECONDS,
        ];
        unset($state->battle['informational_wait_pending']);
    }

    /** Render/transport token. Legacy tokens bind only to an exact saved state version. */
    public static function token(GameState $state): ?string
    {
        if (self::projection($state) === null) return null;
        $record = self::record($state);
        if ($record !== null) return (string) $record['wait_id'];
        if (array_key_exists('informational_wait', $state->battle)
            || array_key_exists('informational_wait_generation', $state->battle)
            || !empty($state->battle['informational_wait_pending'])) return null;
        return $state->persistenceVersion !== null
            ? 'legacy:' . $state->persistenceVersion : 'legacy-state:' . $state->version;
    }

    /** Must run before settle(), temporary-message cleanup or any other mutation. */
    public static function validateAck(GameState $state, string $actor, mixed $token): ?string
    {
        if (!is_string($token) || $token === '') return 'missing_or_invalid_wait_id';
        $current = self::token($state);
        if ($current === null || !hash_equals($current, $token)) return 'stale_wait_id';
        $projection = self::projection($state);
        if (!in_array($actor, $projection['required_players'], true)) return 'already_confirmed_or_not_participant';
        return null;
    }

    public static function due(GameState $state, int $now): ?array
    {
        $record = self::record($state);
        if ($record === null || $now < $record['deadline_at']) return null;
        return in_array($record['required_player'], self::projection($state)['required_players'], true) ? $record : null;
    }
}
