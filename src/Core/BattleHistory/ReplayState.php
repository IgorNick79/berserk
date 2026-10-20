<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

/** Versioned display contract; deliberately has no GameState/Engine dependency. */
final class ReplayState
{
    public const VERSION = 1;

    public static function validate(array $state): void
    {
        self::keys($state, ['version','ruleset','participants','turn','active','cards','catalog','markers','finished','winner']);
        if ($state['version'] !== self::VERSION) throw new \InvalidArgumentException('Unsupported ReplayState version');
        self::text($state['ruleset']);
        if (!is_array($state['participants']) || array_keys($state['participants']) !== ['host','player']) self::bad();
        foreach ($state['participants'] as $participant) {
            if (!is_array($participant)) self::bad();
            self::keys($participant, ['label']);
            self::text($participant['label']);
        }
        self::integer($state['turn'], 0);
        if (!in_array($state['active'], ['host','player',null], true) || !is_bool($state['finished'])
            || !in_array($state['winner'], ['host','player','draw',null], true)
            || (!$state['finished'] && $state['winner'] !== null)) self::bad();
        if (!is_array($state['cards']) || !array_is_list($state['cards']) || !is_array($state['catalog'])) self::bad();
        foreach ($state['catalog'] as $ref => $entry) {
            if (!is_array($entry)) self::bad();
            self::keys($entry, ['revision','name','image','description','type','element','classes','price']);
            self::text($ref); self::text($entry['revision']); self::text($entry['name']);
            self::text($entry['image']); self::text($entry['description']);
            self::text($entry['type']); self::text($entry['element']); self::integer($entry['price'],0);
            if (!is_array($entry['classes']) || !array_is_list($entry['classes'])) self::bad();
            foreach ($entry['classes'] as $class) self::text($class);
        }
        $ids = [];
        foreach ($state['cards'] as $card) {
            if (!is_array($card)) self::bad();
            self::keys($card, ['instance_id','definition','owner','zone','order','row','col','slot','hp','hp_max',
                'move','move_max','armor','armor_max','strike','coins','closed','revealed','statuses','markers','effects']);
            self::text($card['instance_id']);
            if (isset($ids[$card['instance_id']])) self::bad();
            $ids[$card['instance_id']] = true;
            if (!in_array($card['owner'], ['host','player'], true)) self::bad();
            if (!in_array($card['zone'], ['deck','sideboard','hand','squad','field','discard','graveyard','exile','flying'], true)) self::bad();
            foreach (['order','row','col','slot','hp','hp_max','move','move_max','armor','armor_max','coins'] as $key) {
                if ($card[$key] !== null) self::integer($card[$key], $key === 'hp' ? PHP_INT_MIN : 0);
            }
            if (!is_bool($card['closed']) || !is_bool($card['revealed'])) self::bad();
            if ($card['definition'] !== null && (!is_string($card['definition']) || !array_key_exists($card['definition'], $state['catalog']))) self::bad();
            if ($card['strike'] !== null) {
                if (!is_array($card['strike'])) self::bad();
                self::keys($card['strike'], ['weak','medium','strong']);
                foreach ($card['strike'] as $value) self::integer($value, 0);
            }
            foreach (['statuses','markers','effects'] as $key) self::labels($card[$key]);
        }
        self::labels($state['markers']);
        self::encode($state); // reject resources, invalid UTF-8, non-finite numbers
    }

    /** Exact fields prevent accidentally persisting games.json/pending/flags as a public DTO. */
    private static function keys(array $value, array $keys): void
    {
        $actual = array_keys($value); sort($actual); sort($keys);
        if ($actual !== $keys) self::bad();
    }
    private static function text(mixed $value): void { if (!is_string($value) || strlen($value) > 16384) self::bad(); }
    private static function integer(mixed $value, int $min): void { if (!is_int($value) || $value < $min) self::bad(); }
    private static function labels(mixed $values): void
    {
        if (!is_array($values) || !array_is_list($values)) self::bad();
        foreach ($values as $value) {
            if (!is_array($value)) self::bad();
            self::keys($value, ['code','label','value']);
            self::text($value['code']); self::text($value['label']);
            if (!is_null($value['value']) && !is_string($value['value']) && !is_int($value['value']) && !is_bool($value['value'])) self::bad();
        }
    }
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private static function bad(): never { throw new \InvalidArgumentException('Invalid ReplayState v1'); }
}
