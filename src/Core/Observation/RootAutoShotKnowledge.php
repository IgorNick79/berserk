<?php
declare(strict_types=1);
namespace Berserk\Core\Observation;
use Berserk\Core\{GameState,CardInstance,CardStats,BattleWaitResolver,MatchPause,CombatContinuation};
use Berserk\Core\Choice\ChoiceRegistry;
/** Only the audited root after-hit shot. No execution or hidden-target inference. */
final class RootAutoShotKnowledge
{
 public static function supportedEffect(mixed $effect):bool {
  return is_array($effect)&&array_diff(array_keys($effect),['name','type','range','value','trigger'])===[]
   &&($effect['type']??null)==='shot'&&($effect['trigger']??null)==='strike_hit'
   &&is_int($effect['value']??null)&&$effect['value']>=0
   &&is_int($effect['range']??0)&&($effect['range']??0)>=0;
 }
 public static function choice(GameState $state,string $viewer):?array {
  if($state->status!=='battle'||$state->winner!==null||MatchPause::blocking($state)||CombatContinuation::active($state)||ChoiceRegistry::current($state)!==null)return null;
  $s=$state->battle['strike']??null;$w=BattleWaitResolver::current($state);
  if(!is_array($s)||($s['state']??null)!=='waiting_auto_target'||($s['kind']??'strike')!=='strike'
   ||($w['source']??null)!=='strike.pending_auto'||($w['kind']??null)!=='decision'||($w['required_players']??[])!==[$viewer]
   ||empty($s['strike_hit'])||empty($s['damage_applied'])||empty($s['wounds_window_done'])
   ||!empty($s['continuation_frame'])||!empty($s['friendly_fire'])||!empty($s['matriarch_order'])||!empty($s['instant_resolution'])
   ||!empty($s['pending_wounds_resolution'])||!empty($s['auto_effects']))return null;
  foreach($state->battle as$key=>$value)if(str_starts_with((string)$key,'pending_')&&!empty($value))return null;
  foreach($s as$key=>$value)if(str_starts_with((string)$key,'pending_')&&$key!=='pending_auto'&&!empty($value))return null;
  $p=$s['pending_auto']??null;
  if(!is_array($p)||!is_int($p['source_id']??null)||$p['source_id']!==($s['attacker_id']??null)||!is_array($p['candidates']??null)||!self::supportedEffect($p['effect']??null))return null;
  $source=$state->getCard($p['source_id']);
  if(!$source||$source->owner!==$viewer||VisibilityPolicy::card($state,$source,$viewer)!=='known'||!in_array($source->zone,['field','flying'],true))return null;
  if(!empty($source->effectiveProp()['attack_series'])||!empty($source->flags['attack_series_active']))return null;
  $matches=0;foreach($source->effectiveProp()['auto']??[]as$effect)if($effect===$p['effect'])$matches++;
  if($matches!==1)return null;
  return ['kind'=>'root_after_hit_shot','owner'=>$source->owner,'source_id'=>$source->instanceId,'can_skip'=>true,'damage'=>$p['effect']['value']];
 }
 public static function alternatives(GameState $state,string $viewer):array {
  $choice=self::choice($state,$viewer);if($choice===null)return [];
  // Interception and inherited defenses may depend on other battlefield cards.
  foreach($state->cards as$c)if(in_array($c->zone,['field','flying'],true)&&VisibilityPolicy::card($state,$c,$viewer)!=='known')return [];
  $source=$state->getCard($choice['source_id']);$pending=$state->battle['strike']['pending_auto'];$range=$pending['effect']['range']??0;
  $interceptors=array_map(static fn($c)=>$c->instanceId,CardStats::getRangedInterceptors($state,$viewer,'shot'));$out=[];
  foreach($pending['candidates']as$id){
   if(!is_int($id)||$id<=0)continue;$target=$state->getCard($id);
   if(!$target||$target->owner===$viewer||VisibilityPolicy::card($state,$target,$viewer)!=='known'||!in_array($target->zone,['field','flying'],true)||$target->dying||$target->hp<=0)continue;
   if($interceptors!==[]&&!in_array($id,$interceptors,true))continue;
   if(CardStats::hasDefense($state,$target,'shot',$source))continue;
   if($source->zone==='field'&&$target->zone==='field'){
    if(!is_int($source->row)||!is_int($source->col)||!is_int($target->row)||!is_int($target->col))continue;
    $dr=abs($target->row-$source->row);$dc=abs($target->col-$source->col);
    if(max($dr,$dc)<=1||($range>0&&$dr+$dc>$range))continue;
   }
   $out[]=$id;
  }
  $out=array_values(array_unique($out));sort($out,SORT_NUMERIC);return $out;
 }
}
