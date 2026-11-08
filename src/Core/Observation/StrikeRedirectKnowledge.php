<?php
declare(strict_types=1);
namespace Berserk\Core\Observation;

use Berserk\Core\{BattleWaitResolver,CardInstance,CardStats,GameState,MatchPause,ZoneManager};
use Berserk\Core\Choice\ChoiceRegistry;

/** Narrow projection of the existing legacy pre-dice redirect, never a resolver. */
final class StrikeRedirectKnowledge
{
    public static function choice(GameState $state,string $viewer):?array
    {
        if($state->status!=='battle' || $state->winner!==null || MatchPause::blocking($state))return null;
        $wait=BattleWaitResolver::current($state);
        if(($wait['source']??null)!=='strike.waiting_redirect' || ($wait['kind']??null)!=='decision'
            || ($wait['required_players']??[])!==[$viewer] || ChoiceRegistry::current($state)!==null)return null;
        $s=$state->battle['strike']??[];
        if(($s['state']??null)!=='waiting_redirect' || ($s['defender_id']??null)!==null
            || ($s['attack_dice']??null)!==null || ($s['defend_dice']??null)!==null || ($s['result']??null)!==null
            || !empty($s['redirect_used']) || !empty($s['mage_redirect']) || !empty($s['matriarch_order'])
            || !empty($s['friendly_fire']) || !empty($s['damage_applied'])
            || (isset($s['kind']) && $s['kind']!=='strike'))return null;
        $attacker=$state->getCard((int)($s['attacker_id']??0));
        $original=$state->getCard((int)($s['original_target_id']??0));
        if(!$attacker || !$original || ($s['target_id']??null)!==$original->instanceId
            || $attacker->owner===$viewer || $original->owner!==$viewer)return null;
        foreach([$attacker,$original] as $card){
            if(VisibilityPolicy::card($state,$card,$viewer)!=='known' || $card->zone!==CardInstance::ZONE_FIELD
                || $card->dying || $card->hp<=0)return null;
        }
        // Only inspect the attacker's effective properties after its identity is known.
        $prop=$attacker->effectiveProp();
        if($attacker->closed || ($prop['strike']['redirect']??null)!==true || !empty($prop['strike']['opposite']) || !empty($prop['attack_series'])
            || !empty($attacker->flags['attack_series_active']))return null;
        return ['kind'=>'attacker_strike_redirect_before_dice','owner'=>$viewer,
            'attacker_id'=>$attacker->instanceId,'original_target_id'=>$original->instanceId,'can_keep_original'=>true];
    }

    /** Native membership plus current public/own checks. Not ordinary-strike targeting rules. */
    public static function alternatives(GameState $state,string $viewer):array
    {
        $choice=self::choice($state,$viewer);if($choice===null)return [];
        foreach($state->cards as $card)if(VisibilityPolicy::card($state,$card,$viewer)==='unknown')return [];
        $attacker=$state->getCard($choice['attacker_id']);$ids=[];
        foreach($state->battle['strike']['redirect_candidates']??[] as $id){
            if(!is_int($id) || $id<=0 || $id===$choice['original_target_id'])continue;
            $card=$state->getCard($id);
            if(!$card || $card->owner!==$viewer || VisibilityPolicy::card($state,$card,$viewer)!=='known'
                || $card->zone!==CardInstance::ZONE_FIELD || $card->dying || $card->hp<=0
                || !ZoneManager::areAdjacentFieldCards($attacker,$card))continue;
            // Immunity redirect is native-accepted but outside the audited first subset.
            if(CardStats::hasDefense($state,$card,'strike',$attacker))continue;
            $ids[]=$id;
        }
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);return $ids;
    }
}
