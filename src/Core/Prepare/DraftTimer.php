<?php
// src/Core/Prepare/DraftTimer.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\GameSettings;
use Berserk\Core\GameState;

final class DraftTimer
{
    public const TRANSPORT_GRACE_SECONDS = 2;

    public static function initialize(GameState $state, GameSettings $settings, int $now): void
    {
        if ($state->draft === null) return;

        $state->draft['history'] = [];

        if ($settings->draftTimerMode() === GameSettings::DRAFT_TIMER_UNLIMITED) {
            unset($state->draft['timer']);
            return;
        }

        $state->draft['timer'] = [
            'mode' => $settings->draftTimerMode(),
            'total_limit' => $settings->draftTimerTotalSeconds(),
            'action_limit' => $settings->draftTimerActionSeconds(),
            'elapsed' => [
                GameState::PLAYER_HOST => 0,
                GameState::PLAYER_PLAYER => 0,
            ],
            'action_started_at' => $now,
        ];
    }

    public static function ensureRuntime(GameState $state, int $now): void
    {
        if ($state->draft === null) return;

        if (!isset($state->draft['history']) || !is_array($state->draft['history'])) {
            $state->draft['history'] = [];
        }

        if ($state->settings->draftTimerMode() === GameSettings::DRAFT_TIMER_UNLIMITED) {
            unset($state->draft['timer']);
            return;
        }

        if (!isset($state->draft['timer']) || !is_array($state->draft['timer'])) {
            self::initialize($state, $state->settings, $now);
            return;
        }

        $timer = &$state->draft['timer'];
        $timer['mode'] ??= $state->settings->draftTimerMode();
        $timer['total_limit'] = max(1, (int) ($timer['total_limit'] ?? $state->settings->draftTimerTotalSeconds()));
        $timer['action_limit'] = max(1, (int) ($timer['action_limit'] ?? $state->settings->draftTimerActionSeconds()));
        $timer['elapsed'] = is_array($timer['elapsed'] ?? null) ? $timer['elapsed'] : [];
        $timer['elapsed'][GameState::PLAYER_HOST] = max(0, (int) ($timer['elapsed'][GameState::PLAYER_HOST] ?? 0));
        $timer['elapsed'][GameState::PLAYER_PLAYER] = max(0, (int) ($timer['elapsed'][GameState::PLAYER_PLAYER] ?? 0));
        $timer['action_started_at'] = (int) ($timer['action_started_at'] ?? $now);
    }

    public static function hasTimer(GameState $state): bool
    {
        return $state->draft !== null && isset($state->draft['timer']) && is_array($state->draft['timer']);
    }

    public static function startAction(GameState $state, int $now): void
    {
        self::ensureRuntime($state, $now);
        if (!self::hasTimer($state)) return;

        $state->draft['timer']['action_started_at'] = $now;
    }

    public static function settleAction(GameState $state, string $playerKey, int $now): int
    {
        $charge = self::pendingActionCharge($state, $playerKey, $now);
        self::applyActionCharge($state, $playerKey, $charge);

        return $charge;
    }

    public static function pendingActionCharge(GameState $state, string $playerKey, int $now): int
    {
        if (!self::hasTimer($state)) return 0;

        $timer = $state->draft['timer'];
        $elapsed = max(0, $now - (int) $timer['action_started_at']);
        $before = self::remainingTotalBeforeActive($state, $playerKey);

        return min($elapsed, (int) $timer['action_limit'], $before);
    }

    public static function applyActionCharge(GameState $state, string $playerKey, int $charge): void
    {
        if ($charge <= 0 || !self::hasTimer($state)) return;

        $state->draft['timer']['elapsed'][$playerKey] = (int) ($state->draft['timer']['elapsed'][$playerKey] ?? 0) + $charge;
    }

    public static function isExpired(GameState $state, int $now): bool
    {
        return self::deadlineAt($state) !== null && $now >= self::deadlineAt($state);
    }

    public static function deadlineAt(GameState $state): ?int
    {
        $chargeAt = self::chargeLimitAt($state);
        return $chargeAt === null ? null : $chargeAt + self::TRANSPORT_GRACE_SECONDS;
    }

    public static function chargeLimitAt(GameState $state): ?int
    {
        if (!self::hasTimer($state) || $state->draft === null) return null;

        $playerKey = (string) ($state->draft['turn'] ?? '');
        if ($playerKey === '') return null;

        $timer = $state->draft['timer'];
        $remainingBefore = self::remainingTotalBeforeActive($state, $playerKey);
        $limit = min((int) $timer['action_limit'], $remainingBefore);
        if ($limit <= 0) return (int) $timer['action_started_at'];

        return (int) $timer['action_started_at'] + $limit;
    }

    public static function remainingTotal(GameState $state, string $playerKey, int $now): ?int
    {
        if (!self::hasTimer($state)) return null;

        $remaining = self::remainingTotalBeforeActive($state, $playerKey);
        if (($state->draft['turn'] ?? null) === $playerKey) {
            $remaining -= max(0, $now - (int) $state->draft['timer']['action_started_at']);
        }

        return max(0, $remaining);
    }

    public static function remainingAction(GameState $state, int $now): ?int
    {
        $deadline = self::deadlineAt($state);
        return $deadline === null ? null : max(0, $deadline - $now);
    }

    public static function snapshot(GameState $state, string $viewerKey, int $now): array
    {
        self::ensureRuntime($state, $now);
        if (!self::hasTimer($state)) {
            return ['mode' => GameSettings::DRAFT_TIMER_UNLIMITED];
        }

        $turn = (string) ($state->draft['turn'] ?? '');
        return [
            'mode' => (string) ($state->draft['timer']['mode'] ?? GameSettings::DRAFT_TIMER_DEFAULT),
            'turn' => $turn,
            'viewer' => $viewerKey,
            'server_now' => $now,
            'deadline_at' => self::deadlineAt($state),
            'remaining_action' => self::remainingAction($state, $now),
            'remaining_total' => [
                GameState::PLAYER_HOST => self::remainingTotal($state, GameState::PLAYER_HOST, $now),
                GameState::PLAYER_PLAYER => self::remainingTotal($state, GameState::PLAYER_PLAYER, $now),
            ],
            'viewer_remaining_total' => self::remainingTotal($state, $viewerKey, $now),
        ];
    }

    private static function remainingTotalBeforeActive(GameState $state, string $playerKey): int
    {
        if (!self::hasTimer($state)) return PHP_INT_MAX;

        $timer = $state->draft['timer'];
        $limit = (int) ($timer['total_limit'] ?? 0);
        $elapsed = (int) ($timer['elapsed'][$playerKey] ?? 0);
        return max(0, $limit - $elapsed);
    }
}
