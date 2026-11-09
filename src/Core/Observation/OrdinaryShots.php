<?php
declare(strict_types=1);
namespace Berserk\Core\Observation;

use Berserk\Core\{CardInstance,CardStats,EffectiveFeatures,GameState};

/** A deliberately narrow, data-defined subset; no special costs or secondary effects. */
final class OrdinaryShots
{
    public static function profiles(GameState $state, CardInstance $source): array
    {
        if (!in_array($source->zone,[CardInstance::ZONE_FIELD,CardInstance::ZONE_FLYING],true) || EffectiveFeatures::suppressed($source)) return [];
        $prop=$source->effectiveProp();
        $coinShot = $prop['coins']['shot'] ?? [];
        if ($coinShot && ($source->ukid !== 's1_17' || ($coinShot['spend'] ?? null) !== 'all')) return [];
        $hidden=false;
        foreach($state->cards as $card) if(VisibilityPolicy::card($state,$card,$source->owner)==='unknown') {$hidden=true;break;}
        $out=[];$seen=[];
        foreach($prop['actions'] ?? [] as $action) {
            $key=$action['key'] ?? $action['type'] ?? '';
            if(!is_string($key) || $key==='' || isset($seen[$key])) continue;
            $seen[$key]=true; // The server resolves the first action with a given key.
            if(($action['type'] ?? null)!=='shot'
                || array_diff(array_keys($action),['key','type','name','range','value','strike','near_shot'])!==[]) continue;
            $damage=[];
            if(is_int($action['value'] ?? null) && $action['value']>=0) $damage=array_fill_keys(['weak','medium','strong'],$action['value']);
            elseif(is_array($action['strike'] ?? null)) {
                foreach(['weak','medium','strong'] as $level) {
                    $value=$action['strike'][$level] ?? 0;
                    if(!is_int($value) || $value<0) { $damage=[];break; }
                    $damage[$level]=$value;
                }
            }
            if($damage===[]) continue;
            $out[]=['action_key'=>$key,'type'=>'shot','damage_profile'=>$damage,
                'range'=>$hidden ? null : CardStats::getEffectiveRange($state,$source,$action),
                'printed_range'=>(int)($action['range'] ?? 0),'near_shot'=>!empty($action['near_shot']),
                'own_damage_bonus'=>CardStats::getShotBonus($source)+CardStats::getNextActionBonus($source,'shot') + $source->coins * (int) ($coinShot['value'] ?? 0),
                'closes_source'=>true,'contextual_effects'=>'unknown'];
            $out[array_key_last($out)]['distance_rule']='ground_pair_only';
        }
        return $out;
    }
}
