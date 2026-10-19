<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Personal match presentation preferences; never settles clocks or advances combat. */
final class MatchPlayerSettings
{
    public const COMMAND = 'set_sound_enabled';

    public static function snapshot(GameState $state, string $viewer): array
    {
        return ['sound_enabled' => $state->getPlayer($viewer)->soundEnabled];
    }

    public static function apply(GameState $state, string $actor, Command $command): Result
    {
        if (!in_array($actor, ['host', 'player'], true)) return Result::error('settings_not_participant');
        if ($state->status !== 'battle' || $state->winner !== null) return Result::error('battle_not_active');
        $value = $command->get('sound_enabled');
        if (in_array($value, [true, 1, '1', 'true'], true)) $enabled = true;
        elseif (in_array($value, [false, 0, '0', 'false'], true)) $enabled = false;
        else return Result::error('invalid_sound_enabled');
        // Identity comes only from the request/executor context, never payload player_key.
        $player = $state->getPlayer($actor);
        if ($player->soundEnabled !== $enabled) {
            $player->soundEnabled = $enabled;
            $state->bumpVersion();
        }
        return Result::ok(['sound_setting_updated']);
    }
}
