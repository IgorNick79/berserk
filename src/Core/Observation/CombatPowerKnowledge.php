<?php
declare(strict_types=1);
namespace Berserk\Core\Observation;

use Berserk\Core\{BattleWaitResolver,CardInstance,Engine,GameState,InstantProcessor,MatchPause};
use Berserk\Core\Choice\ChoiceRegistry;

/** The audited self/set-strong contract only; never resolves combat or predicts dice. */
final class CombatPowerKnowledge
{
    public static function supportedAbility(array $ability):bool
    {
        if(array_diff(array_keys($ability),['key','name','trigger','phase','target','effect','coins','uses_per_turn'])!==[])return false;
        $effect=$ability['effect']??[];
        return is_string($ability['key']??null) && $ability['key']!==''
            && ($ability['trigger']??null)==='combat' && ($ability['phase']??null)==='power'
            && ($ability['target']??'self')==='self' && self::supportedEffect($effect)
            && is_int($ability['coins']??0) && ($ability['coins']??0)>=0
            && is_int($ability['uses_per_turn']??1) && ($ability['uses_per_turn']??1)>0;
    }

    private static function supportedEffect(mixed $effect):bool
    {
        return is_array($effect) && count($effect)===3 && ($effect['type']??null)==='strike_level'
            && ($effect['mode']??null)==='set' && ($effect['value']??null)==='strong';
    }

    private static function uniqueAbility(CardInstance $card,string $key):?array
    {
        $matches=array_values(array_filter($card->effectiveProp()['instants']??[],static fn($a)=>($a['key']??null)===$key));
        return count($matches)===1 && self::supportedAbility($matches[0]) ? $matches[0] : null;
    }

    public static function window(GameState $state,string $viewer):?array
    {
        if($state->status!=='battle' || $state->winner!==null || MatchPause::blocking($state))return null;
        $wait=BattleWaitResolver::current($state);$s=$state->battle['strike']??[];
        if(($wait['source']??null)!=='strike.waiting_instant' || ($wait['kind']??null)!=='decision'
            || ($wait['required_players']??[])!==[$viewer] || ChoiceRegistry::current($state)!==null
            || ($s['state']??null)!=='waiting_instant' || ($s['instant_phase']??null)!=='combat'
            || ($s['combat_instant_window']??null)!=='main' || ($s['instant_priority']??null)!==$viewer
            || !in_array($viewer,$s['instant_participants']??[],true) || !empty($s['instant_resolution'])
            || !empty($s['pending_wounds_resolution']) || !empty($s['damage_applied'])
            || !empty($s['matriarch_order']) || !empty($s['friendly_fire'])
            || !in_array($s['kind']??'strike',['','strike'],true))return null;
        $attacker=$state->getCard((int)($s['attacker_id']??0));
        $defender=$state->getCard((int)(($s['defender_id']??0)?:($s['target_id']??0)));
        foreach([$attacker,$defender] as $card){
            if(!$card || VisibilityPolicy::card($state,$card,$viewer)!=='known' || $card->dying || $card->hp<=0
                || !in_array($card->zone,[CardInstance::ZONE_FIELD,CardInstance::ZONE_FLYING],true))return null;
        }
        if(!empty($attacker->effectiveProp()['attack_series']) || !empty($attacker->flags['attack_series_active']))return null;
        $dice=[];
        foreach(['attack','defend'] as $side){
            if(!is_int($s[$side.'_dice']??null) || !is_int($s[$side.'_mod']??null)
                || !in_array($s['result'][$side]??null,['','weak','medium','strong'],true))return null;
            $dice[$side]=['die'=>$s[$side.'_dice'],'modifier'=>$s[$side.'_mod']];
        }
        $stack=$s['instant_stack']??null;if(!is_array($stack) || count($stack)>1)return null;
        foreach($stack as $item){
            $card=$state->getCard((int)($item['card_id']??0));
            if(!$card || VisibilityPolicy::card($state,$card,$viewer)!=='known' || !$card->revealed || $card->dying || $card->hp<=0
                || !in_array($card->zone,[CardInstance::ZONE_FIELD,CardInstance::ZONE_FLYING],true))return null;
            $key=$item['instant_key']??null;
            if(!is_string($key) || self::uniqueAbility($card,$key)===null || ($item['player']??null)!==$card->owner
                || !in_array($card->owner,$s['instant_participants'],true) || ($item['target_id']??null)!==$card->instanceId
                || ($item['phase']??null)!=='power' || !self::supportedEffect($item['effect']??null))return null;
        }
        $hidden=false;
        foreach($state->cards as $card)if(VisibilityPolicy::card($state,$card,$viewer)==='unknown'){$hidden=true;break;}
        $options=[];
        // A single published supported item establishes pass context for BOTH participants.
        // More plays are outside the first subset, even if a human can stack another source.
        if($stack===[] && !$hidden){
            foreach((new InstantProcessor($state,new Engine()))->getInstants($viewer,'combat','combat') as $entry){
                $card=$state->getCard($entry['card_id']);$ability=$entry['payload'];
                if(!$card || $card->owner!==$viewer || VisibilityPolicy::card($state,$card,$viewer)!=='known'
                    || !self::supportedAbility($ability) || self::uniqueAbility($card,$ability['key'])===null)continue;
                $options[]=['card_id'=>$card->instanceId,'instant_key'=>$ability['key'],'cost'=>max(0,(int)$entry['cost']),'level'=>'strong'];
            }
        }
        if($stack===[] && $options===[])return null;
        usort($options,static fn($a,$b)=>[$a['card_id'],$a['instant_key']]<=>[$b['card_id'],$b['instant_key']]);
        $role=$attacker->owner===$viewer?'attack':'defend';
        return ['kind'=>'self_strike_level_set_strong','priority'=>$viewer,'role'=>$role,
            'current_own_level'=>$s['result'][$role],'published_dice'=>$dice,'can_pass'=>true,'stack_count'=>count($stack),
            'options'=>$options,'final_effects'=>'unknown'];
    }
}
