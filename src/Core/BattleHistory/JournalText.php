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
        $side=static fn(mixed $key): string => $key==='host'?'Игрок 1':($key==='player'?'Игрок 2':'Ничья');
        $level=static fn(mixed $key): string => ['weak'=>'слабый удар','medium'=>'средний удар','strong'=>'сильный удар',''=>'промах'][$key]??'результат не отображается';
        switch ($type) {
            case 'turn.started': return 'Ход '.(int)$p['turn'].'. '.$side($p['active']).'.';
            case 'turn.ended': return $side($p['ending']).' завершает ход '.(int)$p['turn'].'.';
            case 'move.started':
                return ($n=$name($p['card_id']??null))===null?null:$n.' перемещается: '.\Berserk\View\Ui\BattlefieldPosition::label((int)$p['from'][0],(int)$p['from'][1],$viewer).' → '.\Berserk\View\Ui\BattlefieldPosition::label((int)$p['to'][0],(int)$p['to'][1],$viewer).'.';
            case 'combat.started':
                $a=$name($p['attacker_id']??null);$t=$name($p['target_id']??null);
                return $a===null||$t===null?null:$a.' атакует '.$t.'.';
            case 'combat.dice': case 'combat.dice_changed':
                if (count($names)<1) return null;
                return ($type==='combat.dice'?'Бросок':'Изменение кубиков').': атакующий '.(int)$p['attack']
                    .', защитник '.((int)$p['defend']===0?'без броска':(string)(int)$p['defend']).'.';
            case 'combat.result':
                return 'Итог атаки: '.$level($p['attack']).'; ответ: '.$level($p['defend']).'.';
            case 'combat.wounds':
                if (($n=$name($p['target_id']??null))===null) return null;
                return (int)$p['wounds']>0?$n.' получает '.(int)$p['wounds'].' '.\Berserk\View\Ui\RussianNumber::form((int)$p['wounds'],'рану','раны','ран').'.':
                    (((int)$p['armor_before']>(int)$p['armor_after'])?$n.': броня поглотила удар.':null);
            case 'combat.death': return ($n=$name($p['card_id']??null))===null?null:$n.' погибает.';
            case 'card.healed': return ($n=$name($p['target_id']??null))===null?null:$n.' восстанавливает '.(int)$p['amount'].' HP.';
            case 'card.status': return ($n=$name($p['target_id']??null))===null?null:$n.($p['effect']==='marker'?': применён маркер.':($p['closed']?' закрывается.':' открывается.'));
            case 'ability.applied': return ($n=$name($p['source_id']??null))===null?null:$n.' применяет способность «'.($p['ability']??'Способность').'».';
            case 'series_end.started': return ($n=$name($p['card_id']??null))===null?null:$n.' завершает серию атак.';
            case 'match_terminal.settled': return 'Бой завершён. '.($p['winner']===null||$p['winner']==='draw'?'Ничья.':'Победил '.$side($p['winner']).'.');
        }
        return null;
    }
}
