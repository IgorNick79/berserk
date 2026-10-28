<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Primary card-owned rolls only. Combat instant rerolls deliberately bypass this class. */
final class CardRoll
{
    public const READY = 'abyss_next_six_ready';
    public static function roll(GameState $state, CardInstance $card): int
    {
        if (!empty($card->effectiveProp()['next_six_on_enemy_death_or_ally_incarnation']) && !empty($card->flags[self::READY])) {
            $card->flags[self::READY] = false;
            return 6;
        }
        return Dice::roll();
    }
    public static function arm(GameState $state, CardInstance $event, bool $incarnation): void
    {
        if (!in_array($event->type,['creature','fly'],true)) return;
        foreach ($state->cards as $card) {
            if (!CardHealing::live($card) || empty($card->effectiveProp()['next_six_on_enemy_death_or_ally_incarnation'])) continue;
            if (($card->owner === $event->owner) === $incarnation) $card->flags[self::READY] = true;
        }
    }
}
