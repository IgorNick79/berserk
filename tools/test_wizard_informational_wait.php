<?php
declare(strict_types=1);
require __DIR__.'/test_informational_wait.php';
use Berserk\Core\{GameState,Engine,Command,InformationalWaitManager as IW,TurnTimer};
foreach ([0,180,null] as $seconds) foreach (['host','player'] as $owner) {
 $now=1000;$e=new Engine(null,static function()use(&$now):int{return $now;});
 $make=function()use($seconds,$owner):GameState{
  $s=iwState($owner);
  if($seconds===0){$s->settings->battle['turn_seconds']=0;unset($s->battle['turn_timer']);}
  if($seconds===null){$data=$s->toArray();unset($data['settings']['battle']);$s=GameState::fromArray($data);}
  return $s;
 };
 $opponent=$owner==='host'?'player':'host';
 foreach(['manual','auto'] as $method){
  $now=1000;$s=$make();iwOpen($e,$s,$owner);$record=$s->battle['informational_wait'];$token=IW::token($s);
  iwAssert($record['deadline_at']===1010 && IW::due($s,1009)===null,'same ten seconds');
  $before=serialize($s);for($i=0;$i<3;$i++){IW::token($s);IW::due($s,1010);}iwAssert(serialize($s)===$before,'repeated status is pure');
  $s=GameState::fromArray($s->toArray());iwAssert(IW::token($s)===$token,'saved wait restored');
  $now=$method==='auto'?1010:1005;$actor=$method==='auto'?IW::due($s,$now)['required_player']:$opponent;
  iwApply($e,$s,$actor,'confirm_strike',['wait_id'=>$token]);iwAssert(IW::due($s,99999)===null,'manual/auto suppresses later auto');
  $before=serialize($s);iwAssert(!$e->apply($s,$actor,new Command('confirm_strike',['wait_id'=>$token]))->success && serialize($s)===$before,'repeat ACK does not continue twice');
  iwApply($e,$s,$owner,'confirm_strike',['wait_id'=>$token]);iwAssert(empty($s->battle['strike']) && IW::due($s,99999)===null,'finished exactly once');
  iwAssert(($seconds===0)===!isset($s->battle['turn_timer']),'no fake timer');
 }
 $now=1000;$s=$make();iwOpen($e,$s,$owner);$token=IW::token($s);
 $now=1005;iwApply($e,$s,$owner,'confirm_strike',['wait_id'=>$token]);
 iwAssert(IW::due($s,1010)!==null,'owner manual ACK keeps opponent automatic obligation');
 $now=1010;iwApply($e,$s,$opponent,'confirm_strike',['wait_id'=>$token]);
 iwAssert(empty($s->battle['strike']) && IW::due($s,99999)===null,'auto after owner manual ACK finishes once');
 $now=1000;$s=$make();iwOpen($e,$s,$owner);$old=IW::token($s);
 $s->battle['strike']['pending_choice']=['died_id'=>2,'died_ukid'=>'info_2','effect'=>['type'=>'damage','value'=>1],'candidates'=>[1]];
 IW::reconcile($s,1005);iwAssert(IW::due($s,1015)===null,'nested pending blocks auto');
 $now=1020;iwApply($e,$s,$opponent,'choose_death_target',['target_id'=>1]);iwAssert(IW::token($s)!==$old && $s->battle['informational_wait']['deadline_at']===1030,'nested continuation fresh wait');
 $before=serialize($s);iwAssert(!$e->apply($s,$owner,new Command('confirm_strike',['wait_id'=>$old]))->success && serialize($s)===$before,'stale previous window');
 $bad=GameState::fromArray($s->toArray());if($seconds===0)$bad->battle['turn']++;else $bad->battle['turn_timer']['period_id']++;
 iwAssert(IW::token($bad)===null && IW::due($bad,99999)===null,'stale turn cannot authorize auto');
 $now=1000;$s=$make();iwOpen($e,$s,$owner);
 $s->battle['active']=$opponent;$s->battle['instant_reservation']=['turn_owner'=>$owner,'ended'=>true];
 IW::publish($s);IW::reconcile($s,1001);
 iwAssert(IW::due($s,1011)['required_player']===$opponent,'end phase keeps resolving owner after active switches');
 foreach(['reject','ready','deadline'] as $ending){
  $now=1000;$s=$make();iwOpen($e,$s,$owner);$token=IW::token($s);
  $now=1003;iwApply($e,$s,$owner,'request_pause',['duration_seconds'=>60]);$id=$s->battle['match_pause']['request_id'];
  iwAssert(IW::due($s,99999)===null,'requested pause blocks auto');
  if($ending==='reject'){$now=1010;iwApply($e,$s,$opponent,'reject_pause',['request_id'=>$id]);$resume=1010;}
  else{
   $now=1004;iwApply($e,$s,$opponent,'accept_pause',['request_id'=>$id]);iwAssert(IW::due($s,99999)===null,'active pause blocks auto');
   if($ending==='ready'){$now=1010;foreach([$owner,$opponent] as $actor)iwApply($e,$s,$actor,'pause_ready',['request_id'=>$id]);$resume=1010;}
   else{$now=1064;iwAssert(Berserk\Core\MatchPause::expire($s,$now)->success,'pause deadline');$resume=1064;}
  }
  iwAssert(IW::token($s)===$token && $s->battle['informational_wait']['deadline_at']===$resume+7,'pause preserves identity and remaining seconds');
  iwAssert(IW::due($s,$resume+6)===null && IW::due($s,$resume+7)!==null,'resumed exact deadline');
  iwAssert(($seconds===0)===!isset($s->battle['turn_timer']),'pause creates no fake clock');
 }
}
echo "Untimed/timed/historical informational ACK, stale state, nested pending and pause checks passed\n";
