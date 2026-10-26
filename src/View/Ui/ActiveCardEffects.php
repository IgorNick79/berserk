<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\{CardInstance, CardStats, GameState};

/** Display projection only. Never subtract definition stats or infer provenance from prop. */
final class ActiveCardEffects
{
    public static function forCard(CardInstance $card, GameState $state): array
    {
        $rows = []; $groups = [];
        if (in_array($card->zone, ['field', 'flying'], true) && !empty($card->flags['stitched_unique_loss'])) {
            $rows[] = 'Модифицировано Сшивателем плоти';
        }
        if (CardStats::hasHorizontalStrikeReduction($state, $card)) {
            $rows[] = 'Защитная аура: удары по карте сводятся к слабому';
        }
        if (in_array($card->zone, ['field', 'flying'], true) && !empty($card->flags['memory_loss_sources'])) {
            $rows[] = 'Потеря памяти: уникальные особенности отключены до ухода источника с поля боя';
        }
        foreach ($card->modifiers as $m) {
            $stat = (string) ($m['stat'] ?? '');
            if ($stat === '') continue;
            // Only combine records whose qualifiers and lifetime are identical.
            $qualifiers = $m; unset($qualifiers['value'], $qualifiers['source']);
            $key = json_encode($qualifiers, JSON_UNESCAPED_UNICODE);
            if (is_numeric($m['value'] ?? null)) {
                if (!isset($groups[$key])) $groups[$key] = [$stat, 0, $qualifiers];
                $groups[$key][1] += (int) $m['value'];
            } else {
                $rows[] = CardStats::statLabel($stat) . ': ' . self::value($m['value'] ?? true) . self::details($qualifiers);
            }
        }
        foreach ($groups as [$stat, $value, $q]) {
            // Preserve zero/net-zero records: a conditional effect may still carry meaning.
            $rows[] = CardStats::statLabel($stat) . ' ' . ($value > 0 ? '+' : '') . $value . self::details($q);
        }
        $names = ['stun'=>'Оглушение', 'spider_web'=>'Сеть', 'fire'=>'Огонь', 'hunt'=>'Охота',
            'tremor'=>'Дрожь', 'strangulation'=>'Удушение', 'rooted'=>'Обездвижен',
            'sand_claws'=>'Песчаные когти', 'prey'=>'Добыча'];
        foreach ($card->markers as $key => $marker) {
            if ($key === 'poison') continue;
            $label = $names[$key] ?? $key;
            $rows[] = $label . (is_array($marker) ? self::details($marker) : ': ' . self::value($marker));
        }
        // These helpers evaluate actual line membership and source eligibility.
        foreach (['armor','zot','zoal','zov','zom','zoa','zoan','zoda','zoz','zor','ability','damage_reduction'] as $key) {
            foreach (CardStats::getInheritedLineBonusValues($state, $card, $key) as $value) {
                if (is_array($value) && !empty($value['condition']) && !CardStats::checkCondition($value['condition'], $state, $card)) continue;
                $rows[] = 'Бонус строя: ' . CardStats::statLabel($key) . ' ' . self::value($value);
            }
        }
        // Same source eligibility as CardStats::getLineStrikeAuraBonus; membership is delegated to CardStats.
        foreach ($state->cards as $source) {
            $aura = $source->prop['line_strike_aura'] ?? null;
            if (!is_array($aura) || $source->owner !== $card->owner || $source->zone !== CardInstance::ZONE_FIELD
                || $source->dying || $source->hp <= 0 || !CardStats::hasLine($source)) continue;
            if (!empty($aura['exclude_self']) && $source->instanceId === $card->instanceId) continue;
            if (!CardStats::areInSameConnectedLine($state, $source, $card)) continue;
            $value = (int) ($aura['value'] ?? 1);
            $rows[] = 'Аура строя: удар ' . ($value > 0 ? '+' : '') . $value;
        }
        // Conditional self bonuses are shown only when the existing stats helper activates them.
        $active = CardStats::getActivePropBadges($state, $card);
        foreach (['ova','ovz','direct'] as $key) {
            $prop = $card->prop[$key] ?? null;
            if (is_array($prop) && (!empty($prop['condition']) || !empty($prop['line'])) && !empty($active[$key])) {
                $rows[] = 'Условный бонус: ' . CardStats::statLabel($key) . ' +' . $active[$key];
            }
        }
        return $rows;
    }

    public static function poison(CardInstance $card): int
    {
        $value = $card->markers['poison'] ?? 0;
        return is_array($value) ? (int) ($value['value'] ?? 0) : (int) $value;
    }

    private static function value($value): string
    {
        if (is_bool($value)) return $value ? 'да' : 'нет';
        $terms = ['end_of_turn'=>'до конца хода', 'owner_turn'=>'ход владельца', 'permanent'=>'постоянно'];
        if (is_string($value) && isset($terms[$value])) return $terms[$value];
        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function details(array $data): string
    {
        $labels = ['value'=>'значение', 'expire'=>'срок', 'timing'=>'момент', 'condition'=>'условие',
            'element'=>'стихия', 'types'=>'типы', 'only'=>'действия', 'level'=>'уровень', 'sources'=>'источники'];
        $parts = [];
        foreach ($data as $key => $value) {
            if ($key === 'stat' || $key === 'owner' || str_starts_with((string) $key, 'source') && $key !== 'sources' || str_ends_with((string) $key, '_id')) continue;
            if ($key === 'sources' && is_array($value)) $value = count($value);
            $parts[] = ($labels[$key] ?? $key) . ': ' . self::value($value);
        }
        return $parts ? ' (' . implode('; ', $parts) . ')' : '';
    }
}
