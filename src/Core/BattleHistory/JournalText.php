<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;

/** Formats only a persisted historical projection. No current cards/catalog/Engine. */
final class JournalText
{
    public static function format(string $type, array $p, string $viewer = 'host'): ?string
    {
        if ($type==='recording_gap.started') return 'Часть игровых событий не записана. История неполная.';
        if (!isset($p['_journal']) || isset($p['redacted'])) return null;
        $cards=$p['_journal']['cards']??[];
        $name=static fn(mixed $id): ?string => $cards[(string)$id]['name']??null;
        $names=array_column(array_values($cards),'name');
        $cause=isset($p['_journal']['cause_name'])?' Источник: '.$p['_journal']['cause_name'].'.':'';
        $side=static fn(mixed $key): string => $key==='host'?'Игрок 1':($key==='player'?'Игрок 2':'Ничья');
        $level=static fn(mixed $key): string => ['weak'=>'слабый удар','medium'=>'средний удар','strong'=>'сильный удар',''=>'промах'][$key]??'результат не отображается';
        switch ($type) {
            case 'health.bonus': return ($n=$name($p['target_id']??null))===null?null:$n.': здоровье '.(int)$p['hp_before'].' → '.(int)$p['hp_after'].', максимум HP '.(int)$p['maximum_before'].' → '.(int)$p['maximum_after'].'.';
            case 'rule.class_bonus': return $side($p['owner']).': '.($p['class']??'Класс').($p['active']?' — усиление до конца хода: сильный удар +'.(int)$p['strong'].', ОВА +'.(int)$p['ova']:' — усиление класса завершено').'.';
            case 'rule.movement_limit': return $side($p['owner']).': '.($p['limit']===null?'ограничение числа перемещений снято':'число перемещений ограничено: '.(int)$p['limit']).'.';
            case 'prophecy.viewed': return 'Пророчество: просмотрены карты — '.implode(', ',$p['cards']??[]).'.';
            case 'prophecy.reordered': return 'Пророчество: '.implode(', ',$p['cards']??[]).(($p['destination']??'')==='top'?' положена наверх колоды.':' положена вниз колоды.');
            case 'chain.started': case 'chain.combat': case 'chain.finished': case 'chain.stopped': case 'chain.applied':
                $ability=$p['ability']??'Способность';$source=$name($p['source_id']??null);$target=$name($p['target_id']??null);
                if($type==='chain.stopped')return ($source!==null?$source.': ':'').$ability.' — дальнейшие применения прекращены.';
                if($type==='chain.finished')return ($source!==null?$source.': ':'').$ability.' — серия завершена.';
                if($type==='chain.combat')return $target===null?null:$target.': сражение по '.($ability==='Приказ матриарха'?'приказу матриарха':'способности').'.';
                return ($source!==null?$source.': ':'').$ability.($type==='chain.started'?' — начало серии':' — применено').($target!==null?' → '.$target:'').'.';
            case 'trait.changed':
                if(($n=$name($p['target_id']??null))===null)return null;
                $labels=['zoal'=>'Защита от атак летающих','zoa'=>'Защита от простых ударов','zov'=>'Защита от выстрелов','zot'=>'Защита от метания','zor'=>'Защита от особых ударов','zoz'=>'Защита от разрядов','zom'=>'Защита от заклинаний','direct'=>'Направленный удар','unanswer'=>'Безответный удар','coins_receive_deny'=>'Запрет получения монет'];
                return $n.': '.($p['active']?'получено':'снято').' свойство «'.($labels[$p['trait']]??'Свойство').'».';
            case 'capability.changed':
                return ($n=$name($p['target_id']??null))===null?null:$n.': '.((int)$p['after']>(int)$p['before']?'получено':'утрачено').' дополнительное действие «'.(['strike'=>'Простой удар','execute'=>'Добивание','shot'=>'Выстрел','throw'=>'Метание','discharge'=>'Разряд','heal'=>'Лечение','jump'=>'Прыжок'][$p['action_type']]??'Действие').'».';
            case 'incarnation.ready': return ($n=$name($p['target_id']??null))===null?null:$n.': готова к инкарнации.';
            case 'capability.learned': return ($n=$name($p['target_id']??null))===null?null:$n.': изучена новая способность.';
            case 'capability.ready_six': return ($n=$name($p['target_id']??null))===null?null:$n.': подготовлена шестёрка для следующего броска.';
            case 'coins.changed':
                if(($p['unit']??'coins')==='miss_counter')return ($n=$name($p['target_id']??null))===null?null:$n.': счётчик промахов '.(int)$p['before'].' → '.(int)$p['after'].'.';
                return ($n=$name($p['target_id']??null))===null?null:$n.((int)$p['delta']>0?' получает ':(($p['reason']??'')==='cost'?' тратит ':' теряет ')).abs((int)$p['delta']).' '.\Berserk\View\Ui\RussianNumber::form(abs((int)$p['delta']),'монету','монеты','монет').' ('.(int)$p['before'].' → '.(int)$p['after'].').'.$cause;
            case 'coins.limit':
                return ($n=$name($p['target_id']??null))===null?null:$n.': предел монет '.((int)$p['after']>0?(string)(int)$p['after']:'без ограничения').'.';
            case 'coins.prevented': return ($n=$name($p['target_id']??null))===null?null:$n.': получение монет заблокировано.';
            case 'status.condition': return ($n=$name($p['target_id']??null))===null?null:$n.': '.($p['active']?'получено':'снято').' состояние «'.(['memory_loss'=>'Потеря памяти','unique_loss'=>'Потеря уникальных особенностей'][$p['condition']]??'Состояние').'».';
            case 'status.changed': return ($n=$name($p['target_id']??null))===null?null:$n.($p['closed']?' закрывается.':' открывается.');
            case 'characteristic.changed': case 'modifier.changed':
                if(($n=$name($p['target_id']??null))===null)return null;
                $stats=['cannot_attack'=>'запрет атак','next_strike_bonus'=>'бонус следующего удара','health'=>'здоровье','regeneration'=>'регенерация','execute'=>'добивание','strike'=>'удар','zoa'=>'защита от простых ударов','zoal'=>'защита от атак летающих','zov'=>'защита от выстрелов','zor'=>'защита от особых ударов','zom'=>'защита от заклинаний','zoo'=>'защитное свойство','zoz'=>'защита от разрядов','ova'=>'ОВА','ovz'=>'ОВЗ','ability_strike'=>'бонус удара','ability_discharge'=>'бонус разряда','armor_max'=>'максимум брони','armor'=>'броня','move'=>'движение','move_max'=>'максимум движения','strike_weak'=>'слабый удар','strike_medium'=>'средний удар','strike_strong'=>'сильный удар','direct'=>'направленный удар','shield_light'=>'поглощение ран','action_range'=>'дальность действия','next_action_bonus'=>'бонус следующего действия','damage_reduction'=>'снижение урона','clumsy'=>'неповоротливость','unanswer'=>'безответный удар'];
                $label=$stats[$p['stat']]??'характеристика';
                if($type==='characteristic.changed')return $n.': '.$label.' '.(int)$p['before'].' → '.(int)$p['after'].'.';
                $duration=($p['expire']??null)==='end_of_battle'?'до конца боя':(($p['expire']??null)==='permanent'?'постоянно':(($p['expire']??null)==='end_of_turn'?'до конца хода':(($p['expire']??null)==='end_of_opponent_turn'?'до конца хода противника':(is_numeric($p['expire']??null)?'срок: '.(int)$p['expire']:'срок по условию эффекта'))));
                return $n.': '.($p['operation']==='added'?'получает эффект':(($p['reason']??'')==='expired'?'истёк эффект':'снят эффект')).' «'.$label.' '.((int)$p['value']>=0?'+':'').(int)$p['value'].'»'.($p['operation']==='added'?' ('.$duration.')':'').'.'.$cause;
            case 'marker.changed': case 'cell_marker.changed':
                $labels=['poison'=>'Яд','stun'=>'Оглушение','rooted'=>'Обездвиживание','spider_web'=>'Паутина','hunt'=>'Охота','prey'=>'Добыча','sand_claws'=>'Песчаные когти','strangulation'=>'Удушение','incarnation'=>'Инкарнация','valhalla'=>'Вальхалла','bomb'=>'Бомба','bonfire'=>'Костёр','gate'=>'Врата','tremor'=>'Дрожь','reduce_attack'=>'Уменьшение атаки','row_spell'=>'Цветущие руны','lantern_light'=>'Свет фонаря'];
                $label=$labels[$p['marker']]??'Маркер';
                $n=$type==='marker.changed'?$name($p['target_id']??null):'Клетка '.\Berserk\View\Ui\BattlefieldPosition::label((int)$p['cell'][0],(int)$p['cell'][1],$viewer);
                if($n===null)return null;
                return $n.': '.$label.' '.($p['after']===null||$p['after']===0?(($p['reason']??'')==='expired'?'— срок истёк':'снят'):($p['before']===null||$p['before']===0?'добавлен ('.(int)$p['after'].')':(int)$p['before'].' → '.(int)$p['after'])).'.'.$cause;
            case 'instant.result':
                if(($n=$name($p['target_id']??null))===null)return null;
                return $n.': '.match($p['effect']){'forced_strike_adjacent'=>'выполняет вынужденный удар','strike_level'=>'сила удара '.($p['before']===''?'промах':(['weak'=>'слабый','medium'=>'средний','strong'=>'сильный'][$p['before']]??'уровень')).' → '.($p['after']===''?'промах':(['weak'=>'слабый','medium'=>'средний','strong'=>'сильный'][$p['after']]??'уровень')),'strike_value'=>'величина удара '.(int)$p['before'].' → '.(int)$p['after'],'damage_cap'=>'урон ограничен: '.(int)$p['after'],'redirect_strike'=>'атака перенаправлена на эту карту',default=>'результат изменён'}.'.'.(($source=$name($p['source_id']??null))!==null?' Источник: '.$source.' — '.($p['ability']??'Инстант').'.':'');
            case 'instant.inactive': return ($n=$name($p['source_id']??null))===null?null:$n.': '.(['miss'=>'инстант не применён: промах.','protected'=>'инстант не применён: цель защищена.','target_unavailable'=>'инстант не применён: цель недоступна.'][$p['reason']??'']??'инстант не изменил результат.');
            case 'turn.started': return 'Ход '.(int)$p['turn'].'. '.$side($p['active']).'.';
            case 'turn.ended': return $side($p['ending']).' завершает ход '.(int)$p['turn'].'.';
            case 'movement.completed':
                if(($n=$name($p['card_id']??null))===null)return null;
                $verb=['move'=>'перемещается','jump'=>'прыгает','teleport'=>'телепортируется','forced_move'=>'принудительно перемещается'][$p['display_kind']??$p['movement_type']]??'перемещается';
                return $n.' '.$verb.': '.\Berserk\View\Ui\BattlefieldPosition::label((int)$p['from'][0],(int)$p['from'][1],$viewer).' → '.\Berserk\View\Ui\BattlefieldPosition::label((int)$p['to'][0],(int)$p['to'][1],$viewer).'.';
            case 'zone.changed':
                if(($n=$name($p['card_id']??null))===null)return null;
                if($p['to_zone']==='exile')return $n.' изгоняется.';
                $zones=['field'=>'поле','flying'=>'зона летающих','graveyard'=>'кладбище','deck'=>'колода','hand'=>'рука','squad'=>'отряд','exile'=>'изгнание'];
                $verb=$p['from_zone']==='graveyard'?'возвращается с кладбища':(($p['reason']??'')==='summon'?'призывается':'переходит');
                return $n.' '.$verb.': '.($zones[$p['to_zone']]??'игровая зона').(is_array($p['position']??null)?' ('.\Berserk\View\Ui\BattlefieldPosition::label((int)$p['position'][0],(int)$p['position'][1],$viewer).')':'').'.';
            case 'card.transformed':
                if(($n=$name($p['card_id']??null))===null)return null;
                if(($p['reason']??'')==='gain_flight')return $n.' получает полёт.';
                $forms=['creature'=>'существо','fly'=>'летающее существо','artifact'=>'артефакт','companion'=>'симбиот','parasite'=>'паразит'];
                return $n.': трансформация — '.(isset($p['before']['type'])?($forms[$p['before']['type']]??'форма').' → ':'').($forms[$p['after']['type']]??'новая форма').'.';
            case 'move.started':
                if (!empty($p['text_recorded'])) return null;
                return ($n=$name($p['card_id']??null))===null?null:$n.' перемещается: '.\Berserk\View\Ui\BattlefieldPosition::label((int)$p['from'][0],(int)$p['from'][1],$viewer).' → '.\Berserk\View\Ui\BattlefieldPosition::label((int)$p['to'][0],(int)$p['to'][1],$viewer).'.';
            case 'attack.declared': case 'attack.result':
                $t=$name($p['target_id']??null);if($t===null)return null;
                $a=$name($p['source_id']??null);
                $kind=['execute'=>'Добивание','shot'=>'Выстрел','throw'=>'Метание','uchr'=>'Удар через ряд','discharge'=>'Разряд','tap'=>'Особый удар','magic'=>'Магический удар','bomb_shot'=>'Выстрел бомбой','dive'=>'Пикирование','row_spell'=>'Цветущие руны','damage_ranged'=>'Заклинание'][$p['attack_type']??'']??'Атака';
                if($type==='attack.declared')return ($a===null?'Атака':$a.': '.$kind).' → '.$t.'.';
                $mods=[];foreach (['reduction'=>'снижение','ability'=>'способность','shot'=>'бонус выстрела','next_action'=>'бонус действия','coins'=>'монеты','rows'=>'ряды','target'=>'цель','flying'=>'полёт'] as $key=>$label) if (!empty($p['modifiers'][$key])) $mods[]=$label.' '.(int)$p['modifiers'][$key];
                return $t.': '.($a===null?'итог атаки':$kind.' — итог').': '.(['hit'=>isset($p['value'])?'величина '.(int)$p['value']:'атака разрешена','blocked'=>'атака предотвращена','miss'=>'промах','no_effect'=>'нулевая величина атаки','cancelled'=>'атака отменена','executed'=>'добивание выполнено'][$p['outcome']]??'завершена').($mods?' ('.implode(', ',$mods).')':'').'.';
            case 'dice.rolled': case 'dice.assigned': case 'dice.changed': case 'dice.final':
                if(($n=$name($p['card_id']??null))===null)return null;
                $label=['dice.rolled'=>'Бросок','dice.assigned'=>'Установленное значение','dice.final'=>'Итог кубика','dice.changed'=>($p['operation']??'')==='reroll'?'Переброс':'Изменение кубика'][$type];
                return $n.': '.$label.': '.(isset($p['before'])?(int)$p['before'].' → ':'').(int)$p['value'].($type==='dice.final'&&isset($p['original'])?' (исходное '.(int)$p['original'].', модификатор '.(int)$p['modifier'].')':'').'.';
            case 'combat.started':
                $a=$name($p['attacker_id']??null);$t=$name($p['target_id']??null);
                return $a===null||$t===null?null:$a.' атакует '.$t.'.';
            case 'combat.dice': case 'combat.dice_changed':
                if (count($names)<1) return null;
                $die=static fn(string $k):string => (int)$p[$k].(!empty($p[$k.'_mod'])?' (модификатор '.(int)$p[$k.'_mod'].', итог '.((int)$p[$k]+(int)$p[$k.'_mod']).')':'');
                return ($type==='combat.dice'?(in_array('prepared_six',[$p['attack_origin']??null,$p['defend_origin']??null],true)?'Значения кубиков':'Бросок'):(($p['operation']??'')==='reroll'?'Переброс':'Изменение кубиков')).': атакующий '.$die('attack')
                    .', защитник '.((int)$p['defend']===0?'без броска':$die('defend')).'.';
            case 'combat.result':
                return 'Итог атаки: '.$level($p['attack']).'; ответ: '.$level($p['defend']).'.';
            case 'combat.wounds':
                if (($n=$name($p['target_id']??null))===null) return null;
                return (int)$p['wounds']>0?$n.' получает '.(int)$p['wounds'].' '.\Berserk\View\Ui\RussianNumber::form((int)$p['wounds'],'рану','раны','ран').'.'.(!empty($p['cell_bonus'])?' Свет фонаря: +'.(int)$p['cell_bonus'].' к ранам.':''):
                    (((int)$p['armor_before']>(int)$p['armor_after'] && empty($p['armor_reported']))?$n.': броня поглотила удар.':null);
            case 'combat.death': return ($n=$name($p['card_id']??null))===null?null:$n.' погибает.';
            case 'health.prevented': return ($n=$name($p['target_id']??null))===null?null:$n.': предотвращено ран — '.(int)$p['amount'].'.';
            case 'health.armor': return ($n=$name($p['target_id']??null))===null?null:$n.': броня поглотила '.(int)$p['amount'].' ед. урона.';
            case 'health.maximum': return ($n=$name($p['target_id']??null))===null?null:$n.': Трупоедство — максимум HP '.(int)$p['before'].' → '.(int)$p['after'].'.';
            case 'health.replaced': return ($n=$name($p['target_id']??null))===null?null:$n.': '.(['opened'=>'вместо лечения карта открывается.','wounds'=>'лечение заменено нанесением ран.','prevented'=>'лечение предотвращено.'][$p['result']]??'');
            case 'health.transfer_out': return ($n=$name($p['target_id']??null))===null||!(int)$p['amount']?null:$n.' передаёт раны'.(!empty($p['recipients'])?' → '.implode(', ',$p['recipients']):'').': восстановлено '.(int)$p['amount'].' HP.';
            case 'card.healed': return ($n=$name($p['target_id']??null))===null?null:$n.' восстанавливает '.(int)$p['amount'].' HP.'.(['regeneration'=>' Регенерация.','deadeat'=>' Трупоедство.','vampire'=>' Вампиризм.'][$p['ability']??'']??'');
            case 'card.status': return ($n=$name($p['target_id']??null))===null?null:$n.($p['effect']==='marker'?': применён маркер.':($p['closed']?' закрывается.':' открывается.').(!empty($p['reason'])?' Причина: '.$p['reason'].'.':''));
            case 'ability.applied': return ($n=$name($p['source_id']??null))===null?null:$n.' применяет способность «'.($p['ability']??'Способность').'».';
            case 'series_end.started': return ($n=$name($p['card_id']??null))===null?null:$n.' завершает серию атак.';
            case 'match_terminal.settled': return 'Бой завершён. '.($p['winner']===null||$p['winner']==='draw'?'Ничья.':'Победил '.$side($p['winner']).'.');
        }
        return null;
    }
}
