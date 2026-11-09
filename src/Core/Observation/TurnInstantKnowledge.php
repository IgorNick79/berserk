<?php
declare(strict_types=1);
namespace Berserk\Core\Observation;
use Berserk\Core\{GameState,CardInstance,Engine,InstantProcessor,BattleWaitResolver,Command,CombatContinuation,ExpiredCommandGuard,InstantReservation,MatchPause,TurnInstantResult};
use Berserk\Core\Choice\{ChoiceRegistry,TurnSubChoice,InstantPickChoice,TurnInstantStackChoice,TurnInstantResultChoice};
/** Read-only, owner-filtered contract for the two audited turn effects. */
final class TurnInstantKnowledge
{
 public static function supported(array $a):bool {
  if(array_diff(array_keys($a),['key','name','trigger','target','effect','uses_per_turn','coins'])!==[])return false;
  $e=$a['effect']??null;if(!is_array($e))return false;
  if(($a['trigger']??null)!=='turn'||!is_string($a['key']??null)||$a['key']===''||!is_int($a['coins']??0)||($a['coins']??0)<0||!is_int($a['uses_per_turn']??1)||($a['uses_per_turn']??1)<1)return false;
  return (($a['target']??null)==='ally'&&count($e)===3&&($e['type']??null)==='open'&&($e['condition']??null)==='target_closed'&&is_int($e['damage']??null)&&$e['damage']>=0)
   ||(($a['target']??null)==='enemy'&&count($e)===2&&($e['type']??null)==='close_target'&&($e['condition']??null)==='target_not_moved');
 }
 private static function ability(CardInstance $c,string $key):?array {
  $as=array_values(array_filter($c->effectiveProp()['instants']??[],static fn($a)=>is_array($a)&&($a['key']??null)===$key));
  return count($as)===1&&self::supported($as[0])?$as[0]:null;
 }
 private static function known(GameState $s,CardInstance $c,string $v):bool{return VisibilityPolicy::card($s,$c,$v)==='known';}
 private static function targets(GameState $s,string $v,CardInstance $source,array $a,InstantProcessor $ip):array {
  $out=[];foreach($s->cards as$c){if(!self::known($s,$c,$v)||!$ip->isLegalInstantTarget($source,$c,$a['target'],$a['effect']['condition']))continue;
   $out[]=['target_id'=>$c->instanceId,'owner'=>$c->owner,'closed'=>$c->closed,'hp'=>$c->hp,'damage'=>$a['effect']['damage']??0];}
  usort($out,static fn($a,$b)=>$a['target_id']<=>$b['target_id']);return $out;
 }
 private static function continuation(array $stack):bool{return ($stack['phase']??null)==='turn'&&($stack['continuation']??null)===['type'=>'turn_phase'];}
 private static function stackSafe(GameState $s,array $stack,string $v):bool {
  if(!self::continuation($stack))return false;
  foreach($stack['stack']??[]as$item){$c=$s->getCard((int)($item['card_id']??0));$t=$s->getCard((int)($item['target_id']??0));
   if(!$c||!$t||!self::known($s,$c,$v)||!self::known($s,$t,$v)||!$c->revealed||($item['player']??null)!==$c->owner)return false;
   $a=self::ability($c,(string)($item['instant_key']??''));if($a===null||($item['effect']??null)!==$a['effect'])return false;}
  return true;
 }
 private static function canCancel(GameState $s):bool {
  if(!empty($s->battle['turn_phase']['pending_ack']))return false;
  foreach($s->battle as$key=>$value)if(str_starts_with((string)$key,'pending_')&&$key!=='pending_instant_pick'&&!empty($value))return false;
  return true;
 }
 private static function terminalBeforePass(GameState $s,string $side,array $wait): ?array
 {
     if (!in_array($side,['host','player'],true)) return null;
     $frame=$s->battle['turn_instant_stack']??[];
     $strike=$s->battle['strike']??[];
     $other=$s->getOpponentKey($side);
     $participants=$frame['participants']??null;
     if (($wait['source']??null)!=='turn_instant_stack' || ($wait['kind']??null)!=='decision'
         || $wait['required_players']!==[$side]
         || ($frame['state']??null)!=='ordering' || ($frame['phase']??null)!=='before'
         || ($frame['continuation']??null)!==['type'=>'strike_before']
         || ($frame['priority']??null)!==$side || ($frame['passed']??null)!==[$other]
         || !in_array($participants,[['host','player'],['player','host']],true)
         || ($frame['stack']??null)!==[] || ($frame['summary']??null)!==[] || ($frame['next_sequence']??null)!==0
         || isset($frame['remaining']) || isset($frame['resolved_summary'])
         || !empty($s->battle['turn_phase']) || !empty($s->battle['turn_instant_result'])
         || ($strike['state']??null)!=='waiting_defender' || !empty($strike['friendly_fire'])
         || !array_key_exists('attack_dice',$strike) || $strike['attack_dice']!==null
         || !array_key_exists('defend_dice',$strike) || $strike['defend_dice']!==null
         || !empty($strike['continuation_frame']) || !empty($strike['damage_applied'])
         || !empty($strike['result']) || !empty($strike['instant_phase'])) return null;
     foreach ([$s->battle, $strike] as $context) {
         foreach ($context as $key=>$value) {
             if (is_string($key) && str_starts_with($key,'pending_') && !empty($value)) return null;
         }
     }
     foreach(['attacker_id','target_id'] as $key) {
         $card=$s->getCard((int)($strike[$key]??0));
         if($card===null || $card->dying || $card->hp<=0
             || VisibilityPolicy::card($s,$card,$side)!=='known')return null;
     }
     $a=new Command('pass_turn_instant',[]);
     if(InstantReservation::blocks($s,$a->type)
         || ExpiredCommandGuard::error($s,$side,$a,time())!==null)return null;
     return ['kind'=>'terminal_pass','owner'=>$side,'can_pass'=>true];
 }
 private static function terminalAfterPass(GameState $s,string $side,array $wait): ?array
 {
     if (!in_array($side,['host','player'],true)) return null;
     $frame=$s->battle['turn_instant_stack']??[];
     $other=$s->getOpponentKey($side);
     $participants=$frame['participants']??null;
     if (($wait['source']??null)!=='turn_instant_stack' || ($wait['kind']??null)!=='decision'
         || $wait['required_players']!==[$side]
         || ($frame['state']??null)!=='ordering' || ($frame['phase']??null)!=='after'
         || ($frame['continuation']['type']??null)!=='strike_after'
         || ($frame['priority']??null)!==$side || ($frame['passed']??null)!==[$other]
         || !in_array($participants,[['host','player'],['player','host']],true)
         || ($frame['stack']??null)!==[] || ($frame['summary']??null)!==[] || ($frame['next_sequence']??null)!==0
         || array_key_exists('remaining',$frame) || array_key_exists('resolved_summary',$frame)
         || !empty($s->battle['turn_phase']) || !empty($s->battle['turn_instant_result'])
         || !array_key_exists('strike',$s->battle) || $s->battle['strike']!==null
         || CombatContinuation::active($s) || !empty($s->battle['instant_result'])
         || !empty($s->battle['informational_wait']) || !empty($s->battle['informational_wait_pending'])
         || !empty($s->battle['coin_post_combat_queue']) || !empty($s->battle['coin_post_combat_continuation'])) return null;
     foreach($s->battle as $key=>$value)if(is_string($key) && str_starts_with($key,'pending_') && !empty($value))return null;
     if ($frame['continuation'] === ['type'=>'strike_after']) {
         $resolution=$s->battle['action_resolution']??[];
         $id=$resolution['next_id']??null;
         if (!is_int($id) || !is_array($resolution['tokens']??null)) return null;
         $token=$resolution['tokens'][$id]??[];
         if (($token['action_id']??null)!==$id || ($token['parent_id']??null)!==null) return null;
         if (($s->battle['active']??null)!==$other || ($token['owner']??null)!==$other
             || ($token['type']??null)!=='strike' || ($token['origin']??null)!=='ordinary'
             || ($token['delivered']??null)!==true || !empty($resolution['notifications'])) return null;
     } else {
         $origin=$frame['continuation']['coin_origin_strike']??null;
         if (!is_array($origin) || array_diff(array_keys($frame['continuation']), ['type','coin_origin_strike'])
             || ($origin['kind']??null)!=='uchr' || ($origin['state']??null)!=='results'
             || count(array_intersect([$side,$other], $origin['confirmed']??[]))!==2
             || empty($origin['coin_post_combat_effects']) || ($s->battle['active']??null)!==$other
             || $s->getCard((int)($origin['attacker_id']??0))?->owner!==$other) return null;
     }
     // Availability of a voluntary, unannounced instant does not change the saved ordering state.
     $a=new Command('pass_turn_instant',[]);
     if(InstantReservation::blocks($s,$a->type)
         || ExpiredCommandGuard::error($s,$side,$a,time())!==null)return null;
     return ['kind'=>'after_terminal_pass','owner'=>$side,'can_pass'=>true];
 }
 public static function build(GameState $s,string $v):?array {
  if($s->status!=='battle'||$s->winner!==null||MatchPause::blocking($s))return null;
  $w=BattleWaitResolver::current($s);if(($w['required_players']??[])!==[$v])return null;$h=ChoiceRegistry::current($s);
  if($h instanceof TurnInstantResultChoice){$r=$s->battle['turn_instant_result'];$cur=TurnInstantResult::current($r);
   if($cur===null||$cur['owner']!==$v||($w['source']??null)!=='turn_instant_result'||!self::continuation($r))return null;
   return ['kind'=>'result','owner'=>$v,'ack_id'=>$cur['ack_id']];}
  if($h instanceof TurnInstantStackChoice && ($terminal=self::terminalBeforePass($s,$v,$w))!==null)return $terminal;
  if($h instanceof TurnInstantStackChoice && ($terminal=self::terminalAfterPass($s,$v,$w))!==null)return $terminal;
  if(!empty($s->battle['strike']))return null;
  $tp=$s->battle['turn_phase']??[];$sub=$tp['sub']??[];
  if(!in_array($tp['phase']??null,['start','end'],true)||!in_array($tp['side']??null,['active','passive'],true)
   ||($sub['parent_type']??null)!=='instants'||!is_array($sub['remaining']??null))return null;
  $owner=$tp[$tp['side'].'_key']??null;
  if($h instanceof TurnInstantStackChoice){$stack=$s->battle['turn_instant_stack'];
   if(($w['source']??null)!=='turn_instant_stack'||($stack['priority']??null)!==$v||!in_array($v,$stack['participants']??[],true)||!self::stackSafe($s,$stack,$v))return null;
   return ['kind'=>'stack','owner'=>$v,'can_pass'=>true];}
  if($owner!==$v)return null;
  $ip=new InstantProcessor($s,new Engine());
  if($h instanceof InstantPickChoice){$pick=$s->battle['pending_instant_pick'];$stack=$s->battle['turn_instant_stack']??[];
   if(($pick['owner']??null)!==$v||($pick['source']??null)!=='turn_stack'||($stack['state']??null)!=='ordering'||($stack['priority']??null)!==$v||!self::stackSafe($s,$stack,$v))return null;
   $pending=$sub['pending_id']??null;$found=null;foreach($sub['remaining']as$item)if(($item['id']??null)===$pending)$found=$item;
   $c=$s->getCard((int)($pick['card_id']??0));if(!$c||$c->owner!==$v||!self::known($s,$c,$v)||($found['card_id']??null)!==$c->instanceId)return null;
   $a=self::ability($c,(string)($pick['list_key']??''));if($a===null||($pick['effect']??null)!==$a['effect']||($pick['target']??null)!==$a['target'])return null;
   $available=false;foreach($ip->getSourceInstants($c,'turn','turn','pending_instant_pick')as$x)if($x===$a)$available=true;
   if(!$available)return null;
   return ['kind'=>'target','owner'=>$v,'can_cancel'=>self::canCancel($s),'effect'=>$a['effect']['type'],'cost'=>$a['coins']??0,'targets'=>self::targets($s,$v,$c,$a,$ip)];
  }
  if(!($h instanceof TurnSubChoice)||($w['source']??null)!=='turn_phase.sub')return null;
  $options=[];
  foreach($sub['remaining']as$item){$c=$s->getCard((int)($item['card_id']??0));$key=$item['payload']['key']??null;
   if(!$c||$c->owner!==$v||!self::known($s,$c,$v)||!is_string($key)||!is_string($item['id']??null))continue;
   $a=self::ability($c,$key);if($a===null)continue;
   $available=false;foreach($ip->getSourceInstants($c,'turn','turn')as$x)if($x===$a)$available=true;
   if(!$available)continue;$targets=self::targets($s,$v,$c,$a,$ip);if($targets===[])continue;
   $options[]=['sub_id'=>$item['id'],'source_id'=>$c->instanceId,'effect'=>$a['effect']['type'],'cost'=>$a['coins']??0,'coins'=>$c->coins,'uses_left'=>($a['uses_per_turn']??1)-($c->flags['instant_uses_this_turn'][$key]??0),'targets'=>$targets];
  }
  usort($options,static fn($a,$b)=>strcmp($a['sub_id'],$b['sub_id']));
  return ['kind'=>'sub','owner'=>$v,'can_close'=>($sub['can_close']??false)===true,'options'=>$options];
 }
}
