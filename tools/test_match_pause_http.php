<?php
declare(strict_types=1);
// Reuses mandatory disposable source, datadir, loopback HTTP and InnoDB checks.
require __DIR__.'/test_turn_timer_sync.php';
use Berserk\Core\{Engine,Command,GameState,TurnTimer,CardInstance,MatchPause as MP,AutomaticMatchPause,StaleStateException,InformationalWaitManager as IW,InformationalAutoAck,AutomaticTurnEnd};
$checks=0;
function mhAssert(bool $ok,string $message):void {global $checks;++$checks;tsAssert($ok,$message);}
function mhFixture(int $now):GameState {
    global $repo;
    $s=$repo->create(1,2);$s->status='battle';$s->players['host']->side=1;$s->players['player']->side=2;
    $s->battle=['active'=>'host','turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
    foreach([1=>'host',2=>'player'] as $id=>$owner)$s->addCard(new CardInstance(instanceId:$id,ukid:'pause_fixture_'.$id,owner:$owner,zone:CardInstance::ZONE_FIELD,row:$id===1?3:4,col:3,hp:20,hpMax:20));
    TurnTimer::prepare($s,'host');TurnTimer::resume($s,1,'start_phase',$now);$repo->save($s);return $s;
}
function mhPost(GameState $s,string $actor,string $cmd,array $payload=[],?string $id=null):array {
    return tsRequest(($actor==='host'?'first':'second').'&game='.$s->gameId.'&ajax=battle_command',
        ['cmd'=>$cmd,'expected_version'=>$s->persistenceVersion,'command_id'=>$id??('pause-'.bin2hex(random_bytes(8)))]+$payload);
}
function mhLocked(GameState $s,string $actor,string $type,int $now,string $id,array $payload=[]):array {
    global $db,$repo,$journal;
    $payload+=$type==='request_pause'?['duration_seconds'=>60]:['request_id'=>$s->battle['match_pause']['request_id']??'missing'];
    $a=$journal->registerProcessing($s->gameId,$actor,$id,$type,$payload,$s->persistenceVersion);
    if($a['is_new'])try{
        $e=new Engine($db,static fn():int=>$now);
        $repo->executeLockedCommand($s,$journal,(int)$a['id'],static fn(GameState $locked)=>$e->apply($locked,$actor,new Command($type,$payload)));
    }catch(StaleStateException){$journal->markConflict((int)$a['id'],$repo->findById($s->gameId)->persistenceVersion,'stale_state');}
    return [$repo->findById($s->gameId),$journal->findById((int)$a['id'])];
}
function mhRace(GameState $s,array $commands,int $now):GameState {
    global $repo;
    $barrier=sys_get_temp_dir().'/pause-race-'.bin2hex(random_bytes(8));$children=[];
    foreach($commands as $slot=>[$actor,$type]){
        $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/test_match_pause_race.php',(string)$s->gameId,$actor,$type,(string)$now,$barrier,(string)$slot],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Cannot start race child');fclose($pipes[0]);$children[]=[$proc,$pipes];
    }
    try{
        $until=microtime(true)+20;
        foreach(array_keys($commands) as $slot)while(!file_exists($barrier.'.'.$slot)){if(microtime(true)>$until)throw new RuntimeException('Race readiness timeout');usleep(1000);}
        file_put_contents($barrier.'.go','go');
        foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);mhAssert(proc_close($proc)===0,'Race process: '.$out.$err);}
    }finally{foreach(glob($barrier.'.*')?:[] as $f)unlink($f);}
    return $repo->findById($s->gameId);
}
// Real endpoint, replay, stale version, no_change, full HTTP projection and legacy GET.
$s=mhFixture(time());$before=$s;$v=$s->persistenceVersion;
$a=mhPost($s,'player','request_pause',['duration_seconds'=>120],'pause-http-req');
mhAssert($a['ok'] && $a['command_status']==='committed' && $a['match_pause']['mode']==='requested','HTTP request by non-owner');
$again=mhPost($before,'player','request_pause',['duration_seconds'=>120],'pause-http-req');
mhAssert($again['command_status']==='committed' && $again['sync_version']===$a['sync_version'],'Same ID preserves committed result');
$s=$repo->findById($s->gameId);$id=$s->battle['match_pause']['request_id'];
$poll=tsRequest('first&game='.$s->gameId.'&ajax=battle_sync&since_version='.$s->persistenceVersion);
mhAssert($poll['type']==='no_change' && $poll['match_pause']['allowed_commands']===['accept_pause','reject_pause'],'no_change public projection');
$full=file_get_contents($url.'?first&game='.$s->gameId);
preg_match('~<script type="application/json" id="match-pause-state">(.*?)</script>~s',$full,$m);
$boot=json_decode($m[1]??'',true,512,JSON_THROW_ON_ERROR);
mhAssert($boot['request_id']===$id && $boot['mode']===$poll['match_pause']['mode'],'Full HTTP same pause contract');
$blocked=mhPost($s,'host','end_turn');mhAssert($blocked['command_status']==='rejected','Game command blocked');
$stale=mhPost($before,'host','accept_pause',['request_id'=>$id]);mhAssert($stale['command_status']==='conflict','Stale CAS rejected');
$a=mhPost($s,'host','accept_pause',['request_id'=>$id],'pause-http-accept');mhAssert($a['command_status']==='committed','Accept endpoint');
$again=mhPost($s,'host','accept_pause',['request_id'=>$id],'pause-http-accept');mhAssert($again['command_status']==='committed','Accept replay');
$s=$repo->findById($s->gameId);mhAssert($s->battle['match_pause']['used']['player']===1,'One acceptance charge');
$a=mhPost($s,'host','pause_ready',['request_id'=>$id]);mhAssert($a['match_pause']['ready_self'] && $a['match_pause']['mode']==='active','First ready endpoint');
$s=$repo->findById($s->gameId);$a=mhPost($s,'player','pause_ready',['request_id'=>$id]);
mhAssert($a['match_pause']['mode']==='idle' && $a['match_pause']['last_resume']['reason']==='both_ready','Second ready endpoint');

foreach(['reject_pause','cancel_pause_request','resign'] as $cmd){
    $s=mhFixture(time());mhPost($s,'host','request_pause',['duration_seconds'=>60]);$s=$repo->findById($s->gameId);$id=$s->battle['match_pause']['request_id'];
    $a=mhPost($s,$cmd==='reject_pause'?'player':'host',$cmd,['request_id'=>$id]);
    mhAssert($a['command_status']==='committed' && $a['match_pause']['mode']==='idle','Endpoint '.$cmd);
    if($cmd==='resign')mhAssert($a['status']==='game_over' && $a['match_pause']['last_resume']===null,'Terminal response no resume');
}
// Lazy expiry counts from deadline, not reconnect. No automatic turn end in this fixture yet.
$s=mhFixture(time()-100);[$s]=mhLocked($s,'host','request_pause',time()-90,'offline-request');
$deadline=$s->battle['match_pause']['request_deadline_at'];$p=tsRequest('first&game='.$s->gameId.'&ajax=battle_sync');
mhAssert($p['match_pause']['mode']==='idle' && $p['match_pause']['last_result']['at']===$deadline,'Offline request timeout on HTTP');
mhAssert(abs($p['turn_timer']['remaining_seconds']-100)<=2,'Late request timeout grants no time');
$s=mhFixture(time()-100);[$s]=mhLocked($s,'host','request_pause',time()-90,'offline-active-request');[$s]=mhLocked($s,'player','accept_pause',time()-85,'offline-accept');
$deadline=$s->battle['match_pause']['pause_deadline_at'];$p=tsRequest('second&game='.$s->gameId.'&ajax=battle_sync');
mhAssert($p['match_pause']['last_resume']['resumed_at']===$deadline && abs($p['turn_timer']['remaining_seconds']-145)<=2,'Late active timeout no refund');
// Old GET cannot bypass the server pause; following redirect returns full projection.
$s=mhFixture(time());mhPost($s,'host','request_pause',['duration_seconds'=>60]);$s=$repo->findById($s->gameId);$v=$s->persistenceVersion;
file_get_contents($url.'?first&game='.$s->gameId.'&cmd=end_turn');mhAssert($repo->findById($s->gameId)->persistenceVersion===$v,'Legacy GET does not execute');
// Late acceptance is rejected; a distinct deadline transaction is persisted in its response.
$s=mhFixture(time()-40);[$s]=mhLocked($s,'host','request_pause',time()-30,'late-req');
$p=mhPost($s,'player','accept_pause',['request_id'=>$s->battle['match_pause']['request_id']]);
mhAssert($p['command_status']==='rejected' && $p['match_pause']['mode']==='idle' && $p['match_pause']['last_result']['outcome']==='request_timeout','Late POST cannot win');
// Surrender during active pause, not merely during request.
$s=mhFixture(time());mhPost($s,'host','request_pause',['duration_seconds'=>60]);$s=$repo->findById($s->gameId);$id=$s->battle['match_pause']['request_id'];mhPost($s,'player','accept_pause',['request_id'=>$id]);$s=$repo->findById($s->gameId);
$p=mhPost($s,'player','resign');mhAssert($p['status']==='game_over' && $p['match_pause']['last_resume']===null,'Active surrender');

// Two separate DB connections start from the same version at an explicit barrier.
$s=mhFixture(1000);$s=mhRace($s,[['host','request_pause'],['player','request_pause']],1010);
mhAssert(MP::blocking($s) && $s->battle['match_pause']['generation']===1,'One simultaneous request wins');
$rows=$db->fetchAll('SELECT status FROM battle_command_journal WHERE game_id='.$s->gameId);
mhAssert(count(array_filter($rows,static fn($r)=>$r['status']==='committed'))===1 && count(array_filter($rows,static fn($r)=>$r['status']==='conflict'))===1,'CAS request race recorded');
$s=mhFixture(1000);[$s]=mhLocked($s,'host','request_pause',1010,'ready-req');[$s]=mhLocked($s,'player','accept_pause',1011,'ready-accept');
$s=mhRace($s,[['host','pause_ready'],['player','pause_ready']],1012);
$ready=$s->battle['match_pause']['ready'];$loser=$ready['host']?'player':'host';[$s]=mhLocked($s,$loser,'pause_ready',1013,'ready-retry');
mhAssert(!MP::blocking($s) && $s->battle['match_pause']['last_resume']['reason']==='both_ready','Concurrent ready then fresh CAS retry');
$s=mhFixture(1000);[$s]=mhLocked($s,'host','request_pause',1010,'deadline-req');[$s]=mhLocked($s,'player','accept_pause',1011,'deadline-accept');[$s]=mhLocked($s,'host','pause_ready',1012,'deadline-ready1');
$s=mhRace($s,[['player','pause_ready'],['host','expire_pause']],1071);
mhAssert(!MP::blocking($s) && $s->battle['match_pause']['last_resume']['reason']==='deadline','Deadline beats second ready equality');
$event=$s->battle['match_pause']['last_resume'];(new AutomaticMatchPause($db,$repo,new Engine($db,static fn():int=>2000)))->check($s,'player');
mhAssert($repo->findById($s->gameId)->battle['match_pause']['last_resume']===$event,'No double finish');
// Competing automatic HTTP producers must coalesce into one journal identity.
$s=mhFixture(1000);[$s]=mhLocked($s,'host','request_pause',1010,'auto-race-request');$v=$s->persistenceVersion;
$s=mhRace($s,[['host','expire_pause'],['player','expire_pause']],1030);
mhAssert($s->persistenceVersion===$v+1 && $s->battle['match_pause']['last_result']['outcome']==='request_timeout','One auto deadline commit');
// Resume from an actual persisted informational wait; direct auto producers also stay blocked.
$s=mhFixture(1000);$s->battle['strike']=['state'=>'results','confirmed'=>[]];IW::publish($s);IW::reconcile($s,1000);TurnTimer::reconcileWaiting($s,1000);$repo->save($s);
[$s]=mhLocked($s,'host','request_pause',1003,'info-request');$v=$s->persistenceVersion;
(new InformationalAutoAck($db,$repo,new Engine($db,static fn():int=>1015)))->check($s,'host');
(new AutomaticTurnEnd($db,$repo,new Engine($db,static fn():int=>1015)))->check($s,'host');
mhAssert($repo->findById($s->gameId)->persistenceVersion===$v,'No ACK/auto-end while requested');
[$s]=mhLocked($s,'player','reject_pause',1006,'info-reject');mhAssert(IW::record($s)['deadline_at']===1013 && $s->battle['turn_timer']['pause_reason']==='opponent_wait','Persisted ACK budget restored');

// A stale gameplay command sent before a pause may never overwrite it.
$s=mhFixture(1000);$old=$s;[$s]=mhLocked($s,'player','request_pause',1010,'stale-game-request');
[$s,$record]=mhLocked($old,'host','end_turn',1011,'stale-game-command');
mhAssert($record['status']==='conflict' && MP::blocking($s) && $s->battle['turn_timer']['period_id']===1,'Stale game command cannot overwrite pause');
// Recover only expire_pause, under the same journal/game locks and identity.
$s=mhFixture(1000);[$s]=mhLocked($s,'host','request_pause',1010,'recover-request');$v=$s->persistenceVersion;$p=$s->battle['match_pause'];
$id='mpx:'.$p['generation'].':'.$v;$attempt=$journal->registerProcessing($s->gameId,'host',$id,'expire_pause',[],$v);
$out=(new AutomaticMatchPause($db,$repo,new Engine($db,static fn():int=>1030)))->check($s,'player');
mhAssert($out->certain && !MP::blocking($out->state) && $out->state->persistenceVersion===$v+1,'Locked processing recovery completes once');
mhAssert($journal->findById((int)$attempt['id'])['status']==='committed','Recovery reuses original processing journal');
// Definitely failed attempts retain the existing new-ID retry policy.
$s=mhFixture(1000);[$s]=mhLocked($s,'host','request_pause',1010,'retry-request');$v=$s->persistenceVersion;$p=$s->battle['match_pause'];
$id='mpx:'.$p['generation'].':'.$v;$attempt=$journal->registerProcessing($s->gameId,'host',$id,'expire_pause',[],$v);
$journal->markFailed((int)$attempt['id'],$v,'injected_definite_rollback');
$out=(new AutomaticMatchPause($db,$repo,new Engine($db,static fn():int=>1031)))->check($s,'player');
mhAssert($out->certain && !MP::blocking($out->state) && $out->state->persistenceVersion===$v+1,'Definite failure retries new stable identity');
$s=mhFixture(time());mhPost($s,'host','request_pause',['duration_seconds'=>60],'status-projection-request');
$status=tsRequest('first&game='.$s->gameId.'&ajax=battle_command_status&command_id=status-projection-request');
mhAssert($status['command_status']==='committed' && $status['match_pause']['mode']==='requested','Command recovery carries pause projection');

// A processing recovery read also materializes pause expiry without guessing command outcome.
$s=mhFixture(time()-40);[$s]=mhLocked($s,'host','request_pause',time()-30,'status-timeout-request');
$journal->registerProcessing($s->gameId,'host','unknown-ready','pause_ready',['request_id'=>$s->battle['match_pause']['request_id']],$s->persistenceVersion);
$status=tsRequest('first&game='.$s->gameId.'&ajax=battle_command_status&command_id=unknown-ready');
mhAssert($status['command_status']==='processing' && $status['match_pause']['mode']==='idle','Recovery expiry retains uncertain command identity');
$s=mhFixture(time());mhPost($s,'host','request_pause',['duration_seconds'=>60]);$s=$repo->findById($s->gameId);mhPost($s,'host','resign');
$html=file_get_contents($url.'?first&game='.$s->gameId);
preg_match('~<script type="application/json" id="match-pause-state">(.*?)</script>~s',$html,$m);
$terminal=json_decode($m[1]??'',true,512,JSON_THROW_ON_ERROR);
mhAssert($terminal['mode']==='idle' && $terminal['last_result']['outcome']==='battle_finished','Full terminal projection');
// Post-pause expiry follows the ordinary timeout rules, including mandatory pending guards.
$s=mhFixture(1000);[$s]=mhLocked($s,'host','request_pause',1010,'normal-timeout-request');[$s]=mhLocked($s,'player','accept_pause',1011,'normal-timeout-accept');
$out=(new \Berserk\Core\AutomaticBattleProgress($db,$repo,new Engine($db,static fn():int=>1300)))->check($s,'host');
mhAssert($out->state->battle['turn_timer']['period_id']===2 && $out->state->battle['active']==='player','Ordinary timeout resumes after pause expiry');
echo "Match pause HTTP/InnoDB/race tests passed ($checks checks)\n";
