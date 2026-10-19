<?php
declare(strict_types=1);
// Runs the full Stage 1 HTTP suite first, including its disposable MySQL guards.
require __DIR__.'/test_match_pause_http.php';
use Berserk\Core\{GameState,Engine,AutomaticMatchPause,InformationalWaitManager as IW,TurnTimer};
$stage11Start=$checks;
function mfChild(GameState $s,string $mode):string {
    $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/test_match_pause_fault.php',(string)$s->gameId,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Cannot start fault child');fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    mhAssert(proc_close($proc)===0,'Fault child: '.$err.$out);return $out;
}
// The exact namespace attack from review must fail before any journal registration.
$s=mhFixture(time());mhPost($s,'host','request_pause',['duration_seconds'=>60]);$s=$repo->findById($s->gameId);
$p=$s->battle['match_pause'];$id='mpx:'.$p['generation'].':'.$s->persistenceVersion;
foreach([$id,strtoupper($id),'p2a:reserved','t3e:reserved'] as $reserved) {
    $reply=mhPost($s,'host','end_turn',[],$reserved);
    mhAssert(($reply['error']??null)==='invalid_command_id' && $journal->find($s->gameId,'host',$reserved)===null,'Reserved ID rejected before registration: '.$reserved);
}
$reply=tsRequest('first&game='.$s->gameId.'&ajax=battle_command_status&command_id='.$id);
mhAssert(($reply['error']??null)==='invalid_command_id','Recovery input also reserves internal ID');
$out=(new AutomaticMatchPause($db,$repo,new Engine($db,static fn():int=>$p['request_deadline_at'])))->check($s,'host');
mhAssert($out->certain && $out->state->battle['match_pause']['mode']==='idle' && $journal->find($s->gameId,'host',$id)['status']==='committed','Attack cannot prevent deadline commit');

// Public manual error classification, including a normal invalid game command without pause.
$s=mhFixture(time());$reply=mhPost($s,'player','end_turn',[],'manual-invalid-end');
$status=tsRequest('second&game='.$s->gameId.'&ajax=battle_command_status&command_id=manual-invalid-end');
mhAssert($reply['command_status']==='rejected' && $status['error_code']==='command_rejected','Ordinary manual refusal preserves API contract');
mhPost($s,'host','request_pause',['duration_seconds'=>60]);$s=$repo->findById($s->gameId);$reply=mhPost($s,'host','end_turn',[],'manual-paused-end');
$status=tsRequest('first&game='.$s->gameId.'&ajax=battle_command_status&command_id=manual-paused-end');
mhAssert($reply['command_status']==='rejected' && $status['error_code']==='command_rejected','Paused manual refusal preserves API contract');
$a=$journal->registerProcessing($s->gameId,'host','auto-rejection-probe','expire_pause',[],$s->persistenceVersion);
$repo->executeLockedCommand($s,$journal,(int)$a['id'],static fn(GameState $locked)=>\Berserk\Core\Result::error('automatic_probe'));
mhAssert($journal->findById((int)$a['id'])['error_code']==='automatic_command_rejected','Automatic refusal keeps existing classification');

// Lost COMMIT acknowledgement: full sync invokes the wrapper again while rendering;
// recovery/status also goes through the SAME request-scoped certainty gate.
foreach(['sync-commit-unknown','status-commit-unknown','status-ack-commit-unknown','sync-rollback-unknown'] as $mode) {
    $now=time();$s=mhFixture($now-300);
    if($mode==='status-ack-commit-unknown') {
        $s->battle['strike']=['state'=>'results','confirmed'=>[]];IW::publish($s);IW::reconcile($s,$now-300);TurnTimer::reconcileWaiting($s,$now-300);$repo->save($s);
    }
    [$s]=mhLocked($s,'host','request_pause',$now-297,'fault-request');[$s]=mhLocked($s,'player','accept_pause',$now-295,'fault-accept');
    $v=$s->persistenceVersion;$deadline=$s->battle['match_pause']['pause_deadline_at'];
    // command_status must look up the accept command as its actual recipient.
    // The fault child uses host, so create a terminal host record for that recovery identity.
    if(str_starts_with($mode,'status-')) {
        $a=$journal->registerProcessing($s->gameId,'host','fault-accept','pause_ready',['request_id'=>$s->battle['match_pause']['request_id']],$v);
        $journal->markRejected((int)$a['id'],$v,'command_rejected','test recovery identity');
    }
    $reply=json_decode(mfChild($s,$mode),true,512,JSON_THROW_ON_ERROR);$fresh=$repo->findById($s->gameId);
    $rows=$db->fetchAll('SELECT command_id,status FROM battle_command_journal WHERE game_id='.$s->gameId." AND command_type IN ('expire_pause','confirm_strike','end_turn')");
    mhAssert(count($rows)===1,'No competing command or continuation after unknown outcome: '.$mode);
    if($mode==='sync-rollback-unknown') {
        mhAssert($rows[0]['status']==='processing' && $fresh->persistenceVersion===$v && $fresh->battle['match_pause']['mode']==='active','Same HTTP must not recover its own unknown rollback');
        $out=(new AutomaticMatchPause($db,$repo,new Engine($db,static fn():int=>$now)))->check($fresh,'host');$fresh=$out->state;
        mhAssert($out->certain && $fresh->persistenceVersion===$v+1 && $journal->find($s->gameId,'host',$rows[0]['command_id'])['status']==='committed','Next request safely recovers same processing identity');
    } else {
        mhAssert($rows[0]['status']==='committed' && $fresh->persistenceVersion===$v+1 && $fresh->battle['turn_timer']['period_id']===1,'Unknown commit does not chain auto-end');
        mhAssert($reply['match_pause']['mode']==='idle','Repeated snapshot may show idle without reopening progress gate');
        if($mode==='status-ack-commit-unknown')mhAssert($fresh->battle['strike']['confirmed']===[],'Unknown commit does not chain auto-ACK');
    }
    mhAssert($fresh->battle['match_pause']['last_resume']['resumed_at']===$deadline,'Recovery preserves logical deadline');
}

// A real process exits immediately after registering expire_pause, before BEGIN.
$s=mhFixture(1000);[$s]=mhLocked($s,'host','request_pause',1010,'crash-request');[$s]=mhLocked($s,'player','accept_pause',1011,'crash-accept');$v=$s->persistenceVersion;
mfChild($s,'crash-after-register');$row=$db->fetchOne('SELECT id,status FROM battle_command_journal WHERE game_id='.$s->gameId." AND command_type='expire_pause'");
mhAssert($row['status']==='processing' && $repo->findById($s->gameId)->persistenceVersion===$v,'Crash after registration has no game mutation');
// Two independent recovery connections compete for exactly the same orphan row.
$fresh=mhRace($s,[['host','expire_pause'],['player','expire_pause']],1100);
$rows=$db->fetchAll('SELECT id,status FROM battle_command_journal WHERE game_id='.$s->gameId." AND command_type='expire_pause'");
mhAssert(count($rows)===1 && $rows[0]['id']===$row['id'] && $rows[0]['status']==='committed','Concurrent recovery reuses one row');
mhAssert($fresh->persistenceVersion===$v+1 && $fresh->battle['match_pause']['last_resume']['resumed_at']===1071 && $fresh->battle['turn_timer']['remaining_seconds']===141,'One event, one version, no recovered time refund');
$event=$fresh->battle['match_pause']['last_resume'];
$out=(new AutomaticMatchPause($db,$repo,new Engine($db,static fn():int=>1200)))->check($s,'host');
mhAssert($out->state->battle['match_pause']['last_resume']===$event && $out->state->persistenceVersion===$v+1,'Stale recovery replay never emits a second resume');

// A recovery contender must wait for a LIVE executor's journal lock, not infer a crash.
$s=mhFixture(1000);[$s]=mhLocked($s,'host','request_pause',1010,'live-request');[$s]=mhLocked($s,'player','accept_pause',1011,'live-accept');$v=$s->persistenceVersion;
$barrier=sys_get_temp_dir().'/pause-live-'.bin2hex(random_bytes(8));$children=[];
try {
    $pipes=[];$hold=proc_open([PHP_BINARY,__DIR__.'/test_match_pause_fault.php',(string)$s->gameId,'hold-executor',$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$hold,$pipes];
    $until=microtime(true)+10;while(!file_exists($barrier.'.held')){if(microtime(true)>$until)throw new RuntimeException('Executor lock timeout');usleep(1000);}
    $pipes=[];$recover=proc_open([PHP_BINARY,__DIR__.'/test_match_pause_race.php',(string)$s->gameId,'player','expire_pause','1100',$barrier,'recover'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$recover,$pipes];
    $until=microtime(true)+10;while(!file_exists($barrier.'.recover')){if(microtime(true)>$until)throw new RuntimeException('Recovery ready timeout');usleep(1000);}
    file_put_contents($barrier.'.go','go');
    $until=microtime(true)+10;
    do {$waiting=(int)$db->fetchOne("SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND DB=DATABASE() AND INFO LIKE 'SELECT * FROM battle_command_journal WHERE id = % FOR UPDATE'")['n'];if(microtime(true)>$until)throw new RuntimeException('Recovery did not reach the held journal locking read');if(!$waiting)usleep(10000);}while(!$waiting);
    mhAssert(proc_get_status($recover)['running'],'Recovery waits while original transaction is alive');
    file_put_contents($barrier.'.release','release');
    foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);mhAssert(proc_close($proc)===0,'Live/recovery child: '.$out.$err);}$children=[];
    $fresh=$repo->findById($s->gameId);$rows=$db->fetchAll('SELECT status FROM battle_command_journal WHERE game_id='.$s->gameId." AND command_type='expire_pause'");
    mhAssert(count($rows)===1 && $rows[0]['status']==='committed' && $fresh->persistenceVersion===$v+1,'Live executor and recovery produce one commit');
    mhAssert($fresh->battle['match_pause']['last_resume']['resumed_at']===1071 && $fresh->battle['turn_timer']['remaining_seconds']===141,'Waiting recovery cannot refund time or re-emit event');
} finally {
    foreach($children as [$proc,$pipes]){proc_terminate($proc);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($proc);}
    foreach(glob($barrier.'.*')?:[] as $f)unlink($f);
}
echo 'Match pause Stage 1.1 tests passed ('.($checks-$stage11Start)." additional checks)\n";
