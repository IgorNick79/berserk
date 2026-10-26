<?php
declare(strict_types=1);
namespace Berserk\Core\Prepare;

use Berserk\Core\{CardInstance,GameState,ZoneManager};

/** Input constraints only. Catalog stats, generation and geometry remain owned by existing components. */
final class LuckTestScenario
{
    public static function parse(mixed $raw, int $count, bool $deferDeckCapacity = false): array
    {
        if (!is_string($raw) || strlen($raw)>4096) throw new \DomainException('Сценарий LuckTest: допустимо не более 4096 байт JSON');
        if (trim($raw)==='') return [];
        try { $root=json_decode($raw,false,8,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \DomainException('Сценарий LuckTest: неверный JSON или превышена глубина'); }
        if (!$root instanceof \stdClass || array_diff(array_keys(get_object_vars($root)),['cards','deck'])
            || (!isset($root->cards) && !isset($root->deck))) throw new \DomainException('Сценарий LuckTest: ожидаются массивы cards и/или deck');
        $entries=[]; $counts=['cards'=>['host'=>0,'player'=>0],'deck'=>['host'=>0,'player'=>0]];
        $cells=[]; $required=['host'=>[],'player'=>[]];
        foreach (['cards','deck'] as $zone) {
            $list=property_exists($root,$zone)?$root->$zone:[];
            if (!is_array($list) || !array_is_list($list)) throw new \DomainException('Сценарий LuckTest: ожидается массив '.$zone);
            foreach ($list as $entry) {
                if (!$entry instanceof \stdClass) throw new \DomainException('Сценарий LuckTest: запись карты должна быть объектом');
                $e=get_object_vars($entry);
                $allowed=$zone==='cards'?['owner','ukid','row','col','hp']:['owner','ukid'];
                if (array_diff(array_keys($e),$allowed) || !in_array($e['owner']??null,['host','player'],true)
                    || !is_string($e['ukid']??null) || $e['ukid']==='' || strlen($e['ukid'])>128) throw new \DomainException('Сценарий LuckTest: неверные поля карты');
                if (array_key_exists('hp', $e) && (!is_int($e['hp']) || $e['hp'] < 1)) {
                    throw new \DomainException('Сценарий LuckTest: hp должно быть положительным целым числом');
                }
                $owner=$e['owner'];
                $limit=$zone==='cards'?$count:($deferDeckCapacity?\Berserk\Core\GameSettings::MIN_DECK_SIZE:\Berserk\Core\GameSettings::MIN_DECK_SIZE-$count);
                if (++$counts[$zone][$owner]>$limit) throw new \DomainException('Сценарий LuckTest: обязательных карт больше доступных мест в '.$zone);
                if ($zone==='deck') { $e['zone']='deck'; $entries[]=$e; continue; }
                $hasRow=array_key_exists('row',$e); $hasCol=array_key_exists('col',$e);
                if ($hasRow!==$hasCol) throw new \DomainException('Сценарий LuckTest: row и col задаются вместе');
                if ($hasRow) {
                    if (!is_int($e['row']) || !is_int($e['col'])) throw new \DomainException('Сценарий LuckTest: координаты должны быть целыми числами');
                    $cell=$e['row'].'_'.$e['col']; $found=false;
                    foreach (ZoneManager::cellLevels($owner) as $level) {
                        if (in_array($cell,$level,true)) { $found=true; break; }
                        foreach ($level as $previous) $required[$owner][$previous]=true;
                    }
                    if (!$found || isset($cells[$cell])) throw new \DomainException('Сценарий LuckTest: недопустимая или повторная клетка');
                    $cells[$cell]=true; $required[$owner][$cell]=true;
                }
                $entries[]=$e;
            }
        }
        if (!$entries) throw new \DomainException('Сценарий LuckTest: нет обязательных карт');
        foreach ($required as $needed) if (count($needed)>$count) throw new \DomainException('Сценарий LuckTest: недостаточно K карт для заполнения предыдущих уровней');
        return $entries;
    }

    /** Recreate public JSON without exposing the internal deck zone tag as a cards field. */
    public static function root(array $entries): array
    {
        $root=['cards'=>[]];
        foreach ($entries as $entry) {
            $zone=($entry['zone']??null)==='deck'?'deck':'cards'; unset($entry['zone']);
            $root[$zone][]=$entry;
        }
        return $root;
    }

    public static function fixed(array $entries, array $catalog, string $zone = 'cards'): array
    {
        $byId = array_column($catalog, null, 'ukid'); $fixed = ['host'=>[], 'player'=>[]];
        foreach ($entries as $entry) {
            if (!isset($byId[$entry['ukid']])) throw new \DomainException('Сценарий LuckTest: карта не найдена в каталоге');
            if ((($entry['zone'] ?? null) === 'deck' ? 'deck' : 'cards') === $zone) $fixed[$entry['owner']][] = $byId[$entry['ukid']];
        }
        return $fixed;
    }

    public static function bind(GameState $state): void
    {
        $used = []; $bindings = [];
        foreach ($state->lucktestDebug['scenario'] ?? [] as $entry) {
            $found = null;
            foreach ($state->getCardsInZone($entry['owner'], ($entry['zone'] ?? null) === 'deck' ? CardInstance::ZONE_DECK : CardInstance::ZONE_SQUAD) as $card) {
                if ($card->ukid === $entry['ukid'] && !isset($used[$card->instanceId])) { $found = $card; break; }
            }
            if ($found === null) throw new \DomainException('Сценарий LuckTest: обязательная карта отсутствует в отряде');
            $used[$found->instanceId] = true; $entry['instance_id'] = $found->instanceId; $bindings[] = $entry;
        }
        if ($bindings !== []) $state->lucktestDebug['bindings'] = $bindings;
    }

    /** Applied only at the validated Quick Game preparation boundary, before the initial history snapshot. */
    public static function applyInitialHealth(GameState $state): void
    {
        foreach ($state->lucktestDebug['bindings'] ?? [] as $entry) {
            if (isset($entry['hp'])) $state->getCard($entry['instance_id'])->hp = $entry['hp'];
        }
    }

    public static function verify(GameState $state): void
    {
        foreach ($state->lucktestDebug['bindings'] ?? [] as $entry) {
            $card = $state->getCard($entry['instance_id']);
            if ($card === null || $card->owner !== $entry['owner'] || $card->ukid !== $entry['ukid'] || $card->zone !== (($entry['zone'] ?? null) === 'deck' ? CardInstance::ZONE_DECK : CardInstance::ZONE_FIELD)
                || (isset($entry['row']) && ($card->row !== $entry['row'] || $card->col !== $entry['col']))) {
                throw new \DomainException('Сценарий LuckTest: обязательная расстановка не выполнена');
            }
            if (isset($entry['hp']) && $entry['hp'] > $card->hpMax) {
                throw new \DomainException('Сценарий LuckTest: hp превышает максимальное здоровье карты');
            }
        }
    }
}
