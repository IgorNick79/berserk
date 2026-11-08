<?php
declare(strict_types=1);
namespace Berserk\Core\Observation;
use Berserk\Core\{CardInstance,CardStats,GameState};

/** Knowledge bounds for the existing predicate; unknown enemy traits are never inspected. */
final class ForcedStrikeKnowledge
{
    public static function build(GameState $state,string $viewer):array
    {
        if($state->status!=='battle' || ($state->battle['active'] ?? null)!==$viewer)return [];
        $sources=array_values(array_filter($state->getCardsInZone($viewer,CardInstance::ZONE_FIELD),
            static fn($card)=>!empty($card->effectiveProp()['forced_strike'])));
        if($sources===[])return [];
        $known=clone $state;$possible=clone $state;$known->cards=[];$possible->cards=[];
        foreach($state->cards as $card){
            if(VisibilityPolicy::card($state,$card,$viewer)!=='unknown'){
                $known->cards[$card->instanceId]=$possible->cards[$card->instanceId]=$card;
            }elseif($card->zone===CardInstance::ZONE_FIELD){
                // Closed is a hypothetical upper bound, NOT a disclosed fact or a certified target.
                $possible->cards[$card->instanceId]=new CardInstance($card->instanceId,'unknown',$card->owner,$card->zone,
                    row:$card->row,col:$card->col,closed:true);
            }
        }
        $out=[];
        foreach($sources as $source){
            $status=CardStats::getForcedStrikeTarget($known,$source)!==null ? 'required'
                : (CardStats::getForcedStrikeTarget($possible,$source)!==null ? 'unknown' : 'not_required');
            $out[]=['source_id'=>$source->instanceId,'status'=>$status,'condition'=>'adjacent_closed_enemy'];
        }
        return $out;
    }
}
