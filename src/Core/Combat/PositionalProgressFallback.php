<?php
declare(strict_types=1);
namespace Berserk\Core\Combat;

/** A weak geometric hint over safe Observation and current certified moves, never a future command. */
final class PositionalProgressFallback
{
    public static function consider(array $o,array $l,array $visited,array $baseline):array
    {
        if (($baseline['action']['type'] ?? null)!=='end_turn') return $baseline;
        $best=$baseline;
        foreach (self::evaluate($o,$l,$visited)['candidates'] as $candidate) {
            $score=$candidate['score'];$action=$candidate['command'];
            if ($score<=0 || ($best['action']['type']==='move' && ($score<$best['score']
                || ($score===$best['score'] && serialize($action)>=serialize($best['action']))))) continue;
            $best=['action'=>$action,'reason'=>'positional_progress_fallback','score'=>$score,
                'factors'=>$candidate['factors']];
        }
        return $best;
    }

    /** Exposed for reproducible diagnostics. No state access, rolls, Engine calls, SQL or cross-version cache. */
    public static function evaluate(array $o,array $l,array $visited=[]):array
    {
        $out=['sources'=>[],'candidates'=>[],'stats'=>['moves'=>0,'sources'=>0,'known_targets'=>0,'ring_checks'=>0,'distance_checks'=>0]];
        if (($o['stage']??null)!=='battle' || ($l['stage']??null)!=='battle'
            || ($o['control']['status']??null)!=='own_turn' || ($l['decision']??null)!=='own_turn'
            || !isset($o['version'],$l['version']) || $o['version']!==$l['version']
            || ($o['viewer']??null)!==($l['viewer']??null) || ($o['active']??null)!==($o['viewer']??null)
            || !empty($o['finished']) || !empty($o['winner'])) return $out;
        foreach ($o['forced_strikes']??[] as $required) if ($required['status']!=='not_required') return $out;
        $cards=array_column($o['cards'],null,'id');$occupied=[];$targets=[];$allies=[];
        foreach ($cards as $card) {
            if (!in_array($card['zone'],['field','flying'],true)) continue;
            if ($card['identity']!=='known') return $out;
            if ($card['zone']!=='field') continue;
            if (!self::cell($card['row'],$card['col'])) return $out;
            $occupied[$card['row'].':'.$card['col']]=$card['id'];
            if ($card['owner']===$o['viewer']) $allies[]=$card;
            elseif ($card['hp']>0) $targets[]=$card;
        }
        $out['stats']['known_targets']=count($targets);
        $moves=array_values(array_filter($l['actions'],static fn($a)=>$a['type']==='move'));
        $out['stats']['moves']=count($moves);
        foreach ($moves as $action) {
            $p=$action['payload'];$source=$cards[$p['card_id']]??null;
            if ($source===null || $source['owner']!==$o['viewer'] || $source['zone']!=='field'
                || $source['type']==='fly' || $source['closed'] || $source['hp']<=0 || $source['move']<=0
                || !self::cell($p['row'],$p['col']) || isset($visited[$source['id']][$p['row'].':'.$p['col']])) continue;
            $id=$source['id'];
            if (!isset($out['sources'][$id])) {
                $channels=[];
                if (($source['basic_ground_strike']['supported_source']??false)===true
                    && ($source['basic_ground_strike']['distance_rule']??null)==='adjacent_ground') $channels[]='strike';
                foreach ($source['basic_magic_profiles']??[] as $profile)
                    if (($profile['type']??null)==='basic_magic' && ($profile['distance_rule']??null)==='adjacent_ground'
                        && ($profile['resource_cost']??null)===0 && ($profile['value']??0)>0
                        && ($profile['positional_context_supported']??false)===true) {$channels[]='magic';break;}
                $goals=[];$byChannel=[];
                foreach ($channels as $channel) foreach ($targets as $target) {
                    if (!self::potentialTarget($source,$target,$channel)) continue;
                    for ($dr=-1;$dr<=1;$dr++) for ($dc=-1;$dc<=1;$dc++) {
                        if ($dr===0 && $dc===0) continue;
                        $out['stats']['ring_checks']++;
                        $row=$target['row']+$dr;$col=$target['col']+$dc;$key=$row.':'.$col;
                        if (!self::cell($row,$col) || (isset($occupied[$key]) && $occupied[$key]!==$id)) continue;
                        $goals[$key]=['row'=>$row,'col'=>$col];
                        $byChannel[$channel][$target['id']][$key]=['row'=>$row,'col'=>$col,'effect_outcome'=>'unknown'];
                    }
                }
                ksort($goals);$out['sources'][$id]=['goals'=>array_values($goals),'channels'=>$byChannel];
                $out['stats']['sources']++;
            }
            $goals=$out['sources'][$id]['goals'];
            if ($goals===[]) continue;
            $before=self::phi($source,$goals);$after=self::phi($p,$goals);
            $out['stats']['distance_checks']+=2*count($goals);
            if ($after>=$before) continue;
            $supported=false;
            foreach ($allies as $ally) if ($ally['id']!==$id
                && max(abs($p['row']-$ally['row']),abs($p['col']-$ally['col']))<=1) {$supported=true;break;}
            $factors=['positional_progress'=>3*min(1,$before-$after),'isolated'=>$supported?0:-2,
                'own_fragility'=>$source['hp']<=3?-8:0];
            $out['candidates'][]=['command'=>$action,'phi_before'=>$before,'phi_after'=>$after,
                'score'=>array_sum($factors),'factors'=>$factors];
        }
        return $out;
    }

    private static function cell(mixed $row,mixed $col):bool
    {
        return is_int($row)&&is_int($col)&&$row>=1&&$row<=6&&$col>=1&&$col<=5;
    }
    private static function phi(array $position,array $goals):int
    {
        $distance=PHP_INT_MAX;
        foreach ($goals as $goal) $distance=min($distance,abs($position['row']-$goal['row'])+abs($position['col']-$goal['col']));
        return $distance;
    }
    private static function potentialTarget(array $source,array $target,string $channel):bool
    {
        $keys=['zoa'];
        if ($source['type']==='creature') $keys[]='zoan';
        if ($source['type']==='fly') $keys[]='zoal';
        if ($channel==='magic') $keys[]='zom';
        foreach ($keys as $key) {
            $fact=$target['properties']['defenses'][$key]['direct']??'unknown';
            // Do not route toward a known block or resolve an uncertain conditional defense ourselves.
            if ($fact==='present'||$fact==='conditional') return false;
        }
        // Missing/local facts do not prove absence of contextual defenses: every goal retains unknown outcome.
        return true;
    }
}
