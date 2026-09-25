<?php
// src/Core/GameLog.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Логи партии. Пишем в storage/games/{gameId}/events.jsonl (построчно JSON).
 * Позже миграция в таблицу game_events для статистики.
 */
final class GameLog
{
    public static function append(
        GameState $state,
        string $playerKey,
        Command $cmd,
        Result $result
    ): void {
        try {
            $file = self::file($state->gameId);

            $params = $cmd->payload;
            unset($params['first'], $params['second'], $params['game'], $params['cmd'], $params['sel']);

            $entry = [
                'step'    => self::nextStep($file),
                'ts'      => time(),
                'turn'    => (int) ($state->battle['turn'] ?? 0),
                'phase'   => $state->battle['turn_phase']['phase'] ?? null,
                'player'  => $playerKey,
                'cmd'     => $cmd->type,
                'params'  => $params,
                'success' => $result->success,
                'events'  => $result->events,
                'error'   => $result->error,
            ];

            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) return;

            file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // логи не должны ронять игру
        }
    }

    /**
     * @return array<int, array>
     */
    public static function read(int $gameId): array
    {
        $file = self::file($gameId);
        if (!is_file($file)) return [];

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return [];

        $result = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) $result[] = $decoded;
        }
        return $result;
    }

    public static function clear(int $gameId): void
    {
        $file = self::file($gameId);
        if (is_file($file)) @unlink($file);
    }

    private static function file(int $gameId): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/games/' . $gameId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/events.jsonl';
    }

    private static function nextStep(string $file): int
    {
        if (!is_file($file)) return 1;
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return ($lines === false ? 0 : count($lines)) + 1;
    }
}