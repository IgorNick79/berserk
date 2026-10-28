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
            self::trace($state,$card,['event'=>'consume','die'=>6]);
            if (!empty($state->battle['strike'])) $state->battle['strike']['primary_rolls'][$card->instanceId] = ['die'=>6,'source'=>'prepared_six','ready_after'=>false];
            return 6;
        }
        $die = Dice::roll();
        if (!empty($card->effectiveProp()['next_six_on_enemy_death_or_ally_incarnation'])) self::trace($state,$card,['event'=>'ordinary_roll','die'=>$die]);
        if (!empty($state->battle['strike'])) $state->battle['strike']['primary_rolls'][$card->instanceId] = ['die'=>$die,'source'=>'ordinary','ready_after'=>!empty($card->flags[self::READY])];
        return $die;
    }
    public static function arm(GameState $state, CardInstance $event, bool $incarnation): void
    {
        if (!in_array($event->type,['creature','fly'],true)) return;
        foreach ($state->cards as $card) {
            if (!CardHealing::live($card) || empty($card->effectiveProp()['next_six_on_enemy_death_or_ally_incarnation'])) continue;
            if (($card->owner === $event->owner) === $incarnation) {
                $card->flags[self::READY] = true;
                self::trace($state,$card,['event'=>$incarnation?'ally_incarnation':'enemy_death','source_id'=>$event->instanceId]);
            }
        }
    }
    private static function trace(GameState $state,CardInstance $card,array $entry):void {
        $id=$card->instanceId;
        $state->battle['abyss_roll_trace'][$id][]=$entry+['turn'=>$state->battle['turn']??null,'ready'=>!empty($card->flags[self::READY])];
        $state->battle['abyss_roll_trace'][$id]=array_slice($state->battle['abyss_roll_trace'][$id],-12);
    }
}
