<?php
declare(strict_types=1);
// Includes exact disposable datadir/source/loopback/InnoDB guards before any writes.
require __DIR__.'/test_match_player_settings_http.php';
use Berserk\Core\{GameState,Engine,Command,InformationalWaitManager as IW,TurnTimer};
$start=$n;
foreach(['requested','active','ack','end'] as $due)foreach(['own','opponent','other_type','missing'] as $identity){
 $now=time();$s=mhFixture($now-($due==='ack'?30:300));
 if(in_array($due,['requested','active'],true)){
  [$s]=mhLocked($s,'host','request_pause',$now-290,'progress-request');
  if($due==='active')[$s]=mhLocked($s,'player','accept_pause',$now-289,'progress-accept');
 }elseif($due==='ack'){
  $s->getCard(1)->prop['actions']=[['key'=>'marker','type'=>'place_cell_marker','marker'=>'bonfire']];
  $engine=new Engine($db,static fn():int=>$now-30);
  sp($engine->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'marker']))->success,'Publish ACK action');
  sp($engine->apply($s,'host',new Command('choose_cell_marker',['row'=>3,'col'=>2]))->success,'Publish ACK result');
  $repo->save($s);
 }
 $actor=$identity==='opponent'?'player':'host';$id='historical-setting';
 if($identity==='other_type'){
  $record=$journal->registerProcessing($s->gameId,'host',$id,'end_turn',[],$s->persistenceVersion);
  $journal->markRejected((int)$record['id'],$s->persistenceVersion,'command_rejected','Test old different command');
 }elseif($identity!=='missing'){
  $reply=mhPost($s,$actor,'set_sound_enabled',['sound_enabled'=>'0'],$id);
  sp($reply['command_status']==='committed','Historical setting persisted');$s=$repo->findById($s->gameId);
 }
 // Make the identity older than the current persistence version as well.
 $repo->save($s);
 $event=null;
 for($repeat=0;$repeat<3;$repeat++){
  $reply=tsRequest('first&game='.$s->gameId.'&ajax=battle_sync&force_snapshot=1&settings_sync_id='.$id);
  $fresh=$repo->findById($s->gameId);
  if($due==='requested')sp($reply['match_pause']['mode']==='idle' && $reply['match_pause']['last_result']['outcome']==='request_timeout','Old ID cannot suppress request expiry '.$identity);
  elseif($due==='active'){
   $next=$reply['match_pause']['last_resume']['resume_event_id']??null;
   sp($reply['match_pause']['mode']==='idle' && $next!==null && ($event===null || $event===$next),'Old ID cannot suppress or repeat resume '.$identity);$event=$next;
  }elseif($due==='ack')sp(in_array('player',$fresh->battle['strike']['confirmed']??[],true),'Old ID cannot suppress auto-ACK '.$identity);
  else sp(($fresh->battle['turn_timer']['period_id']??0)>=2,'Old ID cannot suppress auto-end '.$identity);
 }
}
// CAS conflict is pure; its ordinary sync still performs due progress despite old ID.
$s=mhFixture(time()-300);$stale=clone $s;$repo->save($s);
$reply=mhPost($stale,'host','set_sound_enabled',['sound_enabled'=>'0'],'conflicted-preference');
sp($reply['command_status']==='conflict' && $repo->findById($s->gameId)->battle['turn_timer']['period_id']===1,'CAS response is pure');
$reply=tsRequest('first&game='.$s->gameId.'&ajax=battle_sync&settings_sync_id=conflicted-preference');
sp($reply['turn_timer']['period_id']>=2 && $reply['player_settings']['sound_enabled']===true,'Normal sync after CAS handles auto-end without changing preference');
echo 'Settings sync bypass regression: '.($n-$start)." checks passed\n";
