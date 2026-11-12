<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\{CardInstance, CardStats, GameState};

/** Display projection only. Never subtract definition stats or infer provenance from prop. */
final class ActiveCardEffects
{
    public static function forCard(CardInstance $card, GameState $state): array
    {
        return array_column(self::entries($card, $state), 'short');
    }

    /** The single enumeration used by both the star and the selected-card panel. */
    public static function entries(CardInstance $card, GameState $state): array
    {
        $rows = []; $groups = []; $descriptions = [];
        foreach (['ova'=>'Опыт в атаке', 'strong'=>'Сильный удар'] as $stat => $title) {
            $bonus = \Berserk\Core\GraveRitual::bonus($state, $card, $stat);
            if (!$bonus) continue;
            $rows[] = ($stat === 'ova' ? 'ОВА' : $title) . ' +' . $bonus;
            $descriptions[count($rows) - 1] = [
                'title'=>$title, 'description'=>$title . ' увеличен на ' . $bonus . '.',
                'duration'=>'До конца хода владельца карты.',
            ];
        }
        if ($card->zone === CardInstance::ZONE_FIELD && !$card->dying && $card->hp > 0
            && count(CardStats::getLineGroup($state, $card)) > 1) {
            $rows[] = 'Строй';
        }
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
            if ($stat === 'ability_strike' && ($m['source'] ?? '') === 'prophecy_charge') {
                $rows[] = 'Удар +' . (int) $m['value'];
                $descriptions[count($rows) - 1] = [
                    'title'=>'Усиление от пророчества',
                    'description'=>'Простой удар усилен на ' . (int) $m['value'] . '.',
                    'duration'=>'Накопленное усиление снимается после удара.',
                ];
                continue;
            }
            // Only combine records whose qualifiers and lifetime are identical.
            $qualifiers = $m; unset($qualifiers['value']);
            $key = json_encode($qualifiers, JSON_UNESCAPED_UNICODE);
            if (is_numeric($m['value'] ?? null)) {
                if (!isset($groups[$key])) $groups[$key] = [$stat, 0, $qualifiers];
                $groups[$key][1] += (int) $m['value'];
            } else {
                $rows[] = CardStats::statLabel($stat);
            }
        }
        foreach ($groups as [$stat, $value, $q]) {
            // Preserve zero/net-zero records: a conditional effect may still carry meaning.
            $label=$stat==='execute'?'Добивание':CardStats::statLabel($stat);
            $description = self::modifierDescription($stat, $value, $q);
            if ($stat === 'damage_reduction' && ($q['expire'] ?? null) === 'end_of_opponent_turn') {
                $rows[] = (-$value) . ' от немагических атак';
                $descriptions[count($rows) - 1] = $description;
                continue;
            }
            $rows[] = $label . ' ' . ($value > 0 ? '+' : '') . $value;
            $descriptions[count($rows) - 1] = $description;
        }
        $names = ['stun'=>'Оглушение', 'spider_web'=>'Сеть', 'fire'=>'Огонь', 'hunt'=>'Охота',
            'tremor'=>'Дрожь', 'strangulation'=>'Удушение', 'rooted'=>'Обездвижен',
            'sand_claws'=>'Песчаные когти', 'prey'=>'Добыча'];
        foreach ($card->markers as $key => $marker) {
            if ($key === 'poison') continue;
            $label = $names[$key] ?? $key;
            if (in_array($key, ['prey', 'hunt'], true)) {
                $rows[] = $label;
                $description = $key === 'prey'
                    ? 'Владыка небес получает +3 к простому удару по этому существу и наносит безответный удар.'
                    : 'При нанесении ран летающим существом цель получает ещё '
                        . (int) (is_array($marker) ? ($marker['bonus'] ?? 2) : 2) . ' раны.';
                $descriptions[count($rows) - 1] = [
                    'title'=>'Маркер «' . $label . '»', 'description'=>$description,
                    'duration'=>$key === 'hunt' ? 'Маркер снимается после срабатывания.' : '',
                    'marker'=>$key,
                ];
                continue;
            }
            $rows[] = $label;
            if (in_array($key, ['strangulation', 'sand_claws'], true)) {
                $amount = is_array($marker) ? ($marker['value'] ?? null) : $marker;
                if (is_numeric($amount)) $rows[count($rows) - 1] .= ' ' . $amount;
            }
            $parameters = is_array($marker) ? $marker : ['value'=>$marker];
            $description = '';
            if (in_array($key, ['strangulation', 'sand_claws'], true) && is_numeric($parameters['value'] ?? null)) {
                $description = 'Количество маркеров — ' . $parameters['value'] . '.';
            }
            $descriptions[count($rows) - 1] = [
                'title'=>$label,
                'description'=>$description,
                'duration'=>self::modifierDescription($key, 0, $parameters)['duration'],
                'marker'=>$key,
            ];
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
        $entries = [];
        foreach ($rows as $index => $short) {
            $entries[] = ['short'=>$short] + ($descriptions[$index] ?? [
                'title'=>str_contains($short, ': ') ? explode(': ', $short, 2)[0] : $short,
                'description'=>str_contains($short, ': ') ? explode(': ', $short, 2)[1] : '',
                'duration'=>'',
            ]);
        }
        return $entries;
    }

    private static function modifierDescription(string $stat, int $value, array $data): array
    {
        $title = CardStats::statLabel($stat);
        $amount = ($value > 0 ? '+' : '') . $value;
        $description = $title . ' ' . $amount . '.';
        if ($stat === 'damage_reduction') {
            $title = 'Уменьшение ран';
            $description = 'Получаемые раны уменьшаются на ' . $value . '.';
        }
        $types = ['strike'=>'простой удар', 'shot'=>'выстрел', 'throw'=>'метание',
            'tap'=>'магический удар', 'uchr'=>'удар через ряд', 'execute'=>'добивание',
            'impact'=>'воздействие', 'discharge'=>'разряд', 'magic'=>'заклинание', 'cast'=>'заклинание'];
        $applies = [];
        foreach ($data['types'] ?? [] as $type) {
            if (isset($types[$type])) $applies[] = $types[$type];
        }
        if ($applies) $description .= ' Применяется к действиям — ' . implode(', ', $applies) . '.';
        $conditions = [
            'enemy_front_row_empty'=>'первый ряд противника пуст',
            'ally_opposite_no_wounds'=>'напротив находится союзник без ран',
            'more_ally_near_than_enemy'=>'рядом больше союзников, чем противников',
            'line_count_2_plus'=>'не менее двух соседей по строю',
        ];
        $condition = $data['condition'] ?? null;
        if (is_string($condition) && isset($conditions[$condition])) {
            $description .= ' Условие — ' . $conditions[$condition] . '.';
        }
        $duration = match ($data['expire'] ?? null) {
            'end_of_turn' => 'До конца текущего хода.',
            'end_of_opponent_turn' => 'До конца хода противника владельца карты.',
            default => '',
        };
        if (!empty($data['consume'])) {
            $duration = 'Расходуется при соответствующем действии.' . ($duration ? ' ' . $duration : '');
        }
        return ['title'=>$title, 'description'=>$description, 'duration'=>$duration];
    }

    public static function render(CardInstance $card, GameState $state, string $viewer, array $catalog = []): string
    {
        if ($card->owner !== $viewer && !$card->revealed) return '';
        $effects = self::entries($card, $state);
        if (!$effects) return '';
        $html = '<section class="active-card-effects" aria-label="Активные эффекты"><h3>Активные эффекты</h3>';
        foreach ($effects as $effect) {
            $html .= '<article class="active-card-effect"><h4>' . self::escape($effect['title']) . '</h4>';
            $marker = $effect['marker'] ?? null;
            $names = [];
            if ($marker === 'rooted') {
                foreach ($card->markers['rooted']['sources'] ?? [] as $id) {
                    $source = $state->getCard((int) $id);
                    if ($source && ($source->owner === $viewer || $source->revealed)
                        && isset($catalog[$source->ukid]['name'])) {
                        $names[] = $catalog[$source->ukid]['name'];
                    }
                }
            } else {
                $known = ['prey'=>'Владыка небес', 'hunt'=>'Гиррит'];
                if (isset($known[$marker])) $names[] = $known[$marker];
            }
            if ($names) {
                $html .= '<p class="active-card-effect-source">'
                    . self::escape((count($names) === 1 ? 'Маркер поставил ' : 'Маркеры поставили ') . implode(', ', array_unique($names)))
                    . '</p>';
            }
            if ($effect['description'] !== '') {
                $description = self::escape($effect['description']);
                if ($marker === 'prey') {
                    $description = str_replace('+3 к простому удару',
                        '+3 <img class="active-effect-strike-icon" src="/assets/images/card/sword@2x.png"'
                            . ' width="16" height="16" alt="к простому удару">', $description);
                }
                $class = in_array($marker, ['prey', 'hunt'], true) ? ' class="active-card-effect-source"' : '';
                $html .= '<p' . $class . '>' . $description . '</p>';
            }
            if ($effect['duration'] !== '') $html .= '<p class="active-card-effect-duration">' . self::escape($effect['duration']) . '</p>';
            $html .= '</article>';
        }
        return $html . '</section>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        if (is_array($value)) {
            return is_numeric($value['value'] ?? null) ? (string) $value['value'] : '';
        }
        return is_scalar($value) ? (string) $value : '';
    }

}
