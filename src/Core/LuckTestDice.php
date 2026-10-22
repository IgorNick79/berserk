<?php
declare(strict_types=1);
namespace Berserk\Core;

use Berserk\Auth\DebugAccess;

/** Adapts the existing Dice session interface to the transaction-local GameState candidate. */
final class LuckTestDice
{
    public static function hasQueue(GameState $state, string $actor): bool
    {
        $id = $state->players[$actor]->userId ?? 0;
        return $state->mode === GameSettings::MODE_LUCKTEST && isset($state->lucktestDebug['rolls'][(string)$id]);
    }

    public static function run(GameState $state, string $actor, \Closure $operation): Result
    {
        $id = $state->players[$actor]->userId ?? 0;
        $queue = $state->lucktestDebug['rolls'][(string)$id] ?? null;
        $eligible = $state->mode === GameSettings::MODE_LUCKTEST && DebugAccess::allowsId($id)
            && is_array($queue) && ($queue['account_id'] ?? null) === $id && ($queue['game_id'] ?? null) === $state->gameId;
        // CLI regression fixtures may still drive native Dice directly. HTTP always clears legacy input.
        if (!$eligible && !DebugAccess::authenticated() && $queue === null) return $operation();
        $saved = [];
        foreach (['debug_roll','debug_roll_state'] as $key) if (array_key_exists($key, $_SESSION ?? [])) $saved[$key] = $_SESSION[$key];
        unset($_SESSION['debug_roll'], $_SESSION['debug_roll_state']);
        if ($eligible) {
            $_SESSION['debug_roll'] = $queue['raw'];
            if ($queue['state'] !== null) $_SESSION['debug_roll_state'] = $queue['state'];
        }
        try {
            Dice::init();
            $result = $operation();
            if ($result->success && $eligible) $state->lucktestDebug['rolls'][(string)$id]['state'] = $_SESSION['debug_roll_state'] ?? null;
            return $result;
        } finally {
            unset($_SESSION['debug_roll'], $_SESSION['debug_roll_state']);
            if (!DebugAccess::authenticated()) foreach ($saved as $key=>$value) $_SESSION[$key] = $value;
            Dice::init();
        }
    }
}
