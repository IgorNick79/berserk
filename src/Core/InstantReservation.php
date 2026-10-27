<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Deferred admission to the native turn stack. Call mutations under the game lock. */
final class InstantReservation
{
    public const KEY = 'instant_reservation';
    public const COMMANDS = ['reserve_instant', 'cancel_instant_reservation', 'decline_instant_priority'];
    private const ORDINARY = ['move', 'jump', 'gain_coin', 'action', 'uchr', 'strike', 'end_turn', 'open_turn_instants'];

    private static function initial(GameState $s): array
    {
        $config = require __DIR__ . '/../../config/instant-reservations.php';
        $limit = $config['paid_limit'] ?? null;
        if (!is_int($limit) || $limit < 0) throw new \LogicException('Invalid paid instant reservation limit');
        return ['period_id' => (int) ($s->battle['turn_timer']['period_id'] ?? 1),
            'turn_owner' => $s->battle['active'] ?? null, 'limit' => $limit, 'used' => 0,
            'generation' => 0, 'status' => 'idle', 'request' => null, 'ended' => false];
    }

    /** Pure projection also supports legacy games before their first reservation. */
    public static function snapshot(GameState $s): array
    {
        $r = $s->battle[self::KEY] ?? self::initial($s);
        return $r + ['remaining' => max(0, $r['limit'] - $r['used'])];
    }

    /** Public AJAX projection. Pure: no allocation of identities, timer settlement or pending changes. */
    public static function controls(GameState $s, string $actor, int $now): array
    {
        $r = self::snapshot($s);
        $live = self::livePeriod($s, $r) && !MatchPause::blocking($s);
        $clock = TurnTimer::snapshot($s, $now);
        $passive = $live && in_array($actor, ['host', 'player'], true) && $actor !== $r['turn_owner']
            && (!isset($s->battle['turn_timer']) || $clock !== null)
            && ($clock['status'] ?? null) !== 'expired';
        $origin = $passive ? FreeInstantWindows::current($s, $actor, $now) : null;
        $mode = $passive ? ($origin !== null ? 'free' : (self::safeBoundary($s) ? 'paid' : null)) : null;
        $has = $mode !== null && !empty((new InstantProcessor($s, new Engine()))->getInstants($actor, $origin['instant_phase'] ?? 'turn', 'turn'));
        $idle = $r['status'] === 'idle';
        $free = $idle && $mode === 'free' && $has;
        $paid = $idle && $mode === 'paid' && $has && $r['used'] < $r['limit'];
        $wait = BattleWaitResolver::current($s);
        $required = $wait['required_players'] ?? [];
        $priority = $wait === null ? ($s->status === 'battle' && $s->winner === null ? ($s->battle['active'] ?? null) : null)
            : (count($required) === 1 ? $required[0] : null);
        $mine = ($r['request']['owner'] ?? null) === $actor;
        return ['period_id' => $r['period_id'], 'limit' => $r['limit'], 'used' => $r['used'], 'remaining' => $r['remaining'],
            'mode' => $mine ? $r['request']['reason'] : $mode, 'can_free' => $free, 'can_paid' => $paid,
            'can_reserve' => $free || $paid, 'status' => $r['status'], 'requested' => $r['status'] === 'requested',
            'request_owner' => $r['request']['owner'] ?? null, 'request_reason' => $r['request']['reason'] ?? null,
            'request_id' => $r['request']['id'] ?? null, 'can_cancel' => $live && $mine && $r['status'] === 'requested',
            'can_decline' => $live && $mine && self::declinable($s, $r, $actor),
            'priority_owner' => $priority, 'required_players' => $required,
            'origin_choice_id' => $origin['choice_id'] ?? null];
    }

    public static function beginPeriod(GameState $s, string $owner): void
    {
        $old = $s->battle[self::KEY] ?? null;
        if ($old !== null && !$old['ended'] && $old['turn_owner'] === $owner) return;
        $r = self::initial($s);
        if ($old !== null) {
            $r['period_id'] = $old['period_id'] + 1;
            $r['generation'] = $old['generation'];
        }
        $r['turn_owner'] = $owner;
        $s->battle[self::KEY] = $r;
    }

    public static function endPeriod(GameState $s): void
    {
        if (!isset($s->battle[self::KEY])) return;
        $s->battle[self::KEY]['status'] = 'idle';
        $s->battle[self::KEY]['request'] = null;
        $s->battle[self::KEY]['ended'] = true;
    }

    public static function blocks(GameState $s, string $type): bool
    {
        $r = $s->battle[self::KEY] ?? [];
        $held = ($r['status'] ?? null) === 'granted'
            || (($r['status'] ?? null) === 'requested' && ($r['request']['reason'] ?? null) === 'free');
        return $held && in_array($type, self::ORDINARY, true);
    }

    public static function apply(GameState $s, Engine $engine, string $actor, Command $cmd): Result
    {
        if ($cmd->type === 'reserve_instant') return self::request($s, $engine, $actor, $cmd);
        $r = self::snapshot($s);
        if (!self::livePeriod($s, $r) || ($r['request']['owner'] ?? null) !== $actor
            || !is_string($cmd->get('request_id')) || $cmd->get('request_id') !== ($r['request']['id'] ?? null)) {
            return Result::error('instant_reservation_stale_request');
        }
        if ($cmd->type === 'cancel_instant_reservation') {
            if ($r['status'] !== 'requested') return Result::error('instant_reservation_already_granted');
        } elseif ($cmd->type === 'decline_instant_priority') {
            if (!self::declinable($s, $r, $actor)) {
                return Result::error('instant_reservation_cannot_decline');
            }
            unset($s->battle['turn_instant_stack']); // Empty native stack: no effects or parent to resume.
        } else return Result::error('instant_reservation_unknown_command');
        self::clear($s);
        $s->bumpVersion();
        return Result::ok([$cmd->type]);
    }

    /**
     * Stage 3 server adapter seam. HTTP never supplies $freeOrigin.
     * Bind to the current owner/source, without suspending or consuming the choice.
     */
    public static function request(GameState $s, Engine $engine, string $actor, Command $cmd, ?array $freeOrigin = null): Result
    {
        $r = self::snapshot($s);
        if (!in_array($actor, ['host', 'player'], true) || !self::livePeriod($s, $r)
            || $actor === $r['turn_owner'] || MatchPause::blocking($s)) return Result::error('instant_reservation_not_passive_turn');
        $period = $cmd->get('period_id');
        if ((!is_int($period) && (!is_string($period) || !ctype_digit($period)))
            || (string) $period !== (string) $r['period_id']) return Result::error('instant_reservation_stale_period');
        if ($r['status'] === 'requested') return Result::ok(['instant_reservation_already_requested']);
        if ($r['status'] !== 'idle') return Result::error('instant_reservation_already_granted');
        $current = FreeInstantWindows::current($s, $actor, $engine->serverNow());
        if ($freeOrigin !== null && ($current === null || ($freeOrigin['source'] ?? null) !== $current['source'])) {
            return Result::error('instant_reservation_invalid_free_origin');
        }
        $freeOrigin = $current; // Mode and identity are derived exclusively from the locked server state.
        if ($freeOrigin === null && !self::safeBoundary($s)) {
            return Result::error('instant_reservation_pending_not_connected');
        }
        if ($cmd->get('origin_choice_id') !== null && ($freeOrigin === null
            || $cmd->get('origin_choice_id') !== $freeOrigin['choice_id'])) return Result::error('instant_reservation_stale_origin');
        $clock = TurnTimer::snapshot($s, $engine->serverNow());
        if (isset($s->battle['turn_timer']) && $clock === null) return Result::error('instant_reservation_invalid_timer');
        if (($clock['status'] ?? null) === 'expired') return Result::error('instant_reservation_turn_expired');
        if ($freeOrigin === null && $r['used'] >= $r['limit']) return Result::error('instant_reservation_limit');
        if (!(new InstantProcessor($s, $engine))->getInstants($actor, $freeOrigin['instant_phase'] ?? 'turn', 'turn')) return Result::error('instant_reservation_no_instants');
        unset($r['remaining']);
        ++$r['generation'];
        $r['request'] = ['id' => 'ir:' . $s->gameId . ':' . $r['generation'], 'owner' => $actor,
            'reason' => $freeOrigin === null ? 'paid' : 'free', 'origin' => $freeOrigin];
        $r['status'] = 'requested';
        $s->battle[self::KEY] = $r;
        $s->bumpVersion();
        return Result::ok(['instant_reservation_requested']);
    }

    private static function livePeriod(GameState $s, array $r): bool
    {
        return $s->status === 'battle' && $s->winner === null && !$r['ended']
            && in_array($r['turn_owner'], ['host', 'player'], true)
            && ($s->battle['active'] ?? null) === $r['turn_owner']
            && (!isset($s->battle['turn_timer']) || ($s->battle['turn_timer']['owner'] ?? null) === $r['turn_owner']);
    }

    /** True only after the entire action and its mandatory continuations have finished. */
    public static function safeBoundary(GameState $s): bool
    {
        if ($s->status !== 'battle' || $s->winner !== null || MatchPause::blocking($s)
            || BattleWaitResolver::current($s) !== null || CombatContinuation::active($s)) return false;
        foreach (['strike', 'turn_phase', 'turn_instant_stack', 'turn_instant_result', 'matriarch_order',
            'coin_post_combat_queue', 'coin_post_combat_continuation', 'informational_wait', 'informational_wait_pending'] as $key) {
            if (!empty($s->battle[$key])) return false;
        }
        foreach ($s->battle as $key => $value) if (str_starts_with((string) $key, 'pending_') && !empty($value)) return false;
        return true;
    }

    /** Run once after successful gameplay and native continuations, before timer reconciliation. */
    public static function afterCommand(GameState $s, Engine $engine, Command $cmd): void
    {
        if (!isset($s->battle[self::KEY])) return;
        $r = $s->battle[self::KEY];
        if (!self::livePeriod($s, $r)) { self::clear($s); return; }
        if (MatchPause::blocking($s)) return;
        if ($r['status'] === 'granted') {
            if (self::safeBoundary($s)) self::clear($s);
            return;
        }
        if ($r['status'] !== 'requested' || in_array($cmd->type, [...self::COMMANDS, 'set_lucktest_debug'], true)) return;
        if ($r['request']['reason'] === 'free') {
            $origin = $r['request']['origin'];
            if (empty($origin['binding']['key'])) { self::clear($s); return; }
            if (!FreeInstantWindows::validAnchor($s, $origin)) { self::clear($s); return; }
            if (empty($origin['released'])) {
                if (FreeInstantWindows::waiting($s, $origin)) return;
                $s->battle[self::KEY]['request']['origin']['released'] = true;
            }
            $clock = TurnTimer::snapshot($s, $engine->serverNow());
            if (($clock['status'] ?? null) === 'expired') { self::clear($s); return; }
            if (FreeInstantWindows::nativeWindow($s, $r['request']['owner'], $origin)) {
                $stack = $s->battle['turn_instant_stack'];
                if (!(new InstantProcessor($s, $engine))->getInstants($r['request']['owner'], $stack['phase'], 'turn')) {
                    self::clear($s); return;
                }
                $s->battle[self::KEY]['status'] = 'granted';
                $s->battle[self::KEY]['request']['grant_kind'] = 'native';
                $s->bumpVersion();
                return;
            }
        }
        if (!self::safeBoundary($s)) return;
        $clock = TurnTimer::snapshot($s, $engine->serverNow());
        if (($clock['status'] ?? null) === 'expired') { self::clear($s); return; }
        $ip = new InstantProcessor($s, $engine);
        $owner = $r['request']['owner'];
        if (!$ip->getInstants($owner, 'turn', 'turn')
            || ($r['request']['reason'] === 'paid' && $r['used'] >= $r['limit'])) { self::clear($s); return; }
        if (!$ip->openTurnStackWindow($owner, ['type' => 'instant_reservation', 'request_id' => $r['request']['id']], 'turn')) return;
        // Opt into the native nested-combat lifecycle even though this root has no strike.
        // A forced-strike instant must suspend remaining entries until its child is done.
        CombatContinuation::blocked($s, ['kind' => 'instant_reservation', 'request_id' => $r['request']['id']]);
        if ($r['request']['reason'] === 'paid') ++$s->battle[self::KEY]['used'];
        $s->battle[self::KEY]['status'] = 'granted';
        $s->bumpVersion();
    }

    private static function ownsStack(array $r, array $stack): bool
    {
        return ($stack['continuation']['type'] ?? null) === 'instant_reservation'
            && ($stack['continuation']['request_id'] ?? null) === ($r['request']['id'] ?? null);
    }

    private static function declinable(GameState $s, array $r, string $actor): bool
    {
        $stack = $s->battle['turn_instant_stack'] ?? [];
        return $r['status'] === 'granted' && self::ownsStack($r, $stack)
            && ($stack['state'] ?? null) === 'ordering' && ($stack['priority'] ?? null) === $actor && empty($stack['stack'])
            && empty($s->battle['pending_instant_pick']) && empty($s->battle['pending_forced_strike_adjacent']);
    }

    private static function clear(GameState $s): void
    {
        $s->battle[self::KEY]['status'] = 'idle';
        $s->battle[self::KEY]['request'] = null;
    }
}
