<?php
declare(strict_types=1);
// Reuse the disposable-tree, loopback URL, datadir and InnoDB guards, plus T2/P1 regressions.
require __DIR__.'/test_turn_timer_sync.php';
use Berserk\Core\{GameState,TurnTimer,Engine,Command,CardInstance,InformationalWaitManager as IW,InformationalAutoAck,Result,StaleStateException};

function iaFixture(int $now): GameState {
    global $repo,$db;
    $s=$repo->create(1,2);$s->status='battle';
    $s->players['host']->side=1;$s->players['player']->side=2;
    $s->battle=['active'=>'host','turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
    foreach([1=>'host',2=>'player'] as $id=>$owner)$s->addCard(new CardInstance(
        instanceId:$id,ukid:'p2_fixture',owner:$owner,zone:CardInstance::ZONE_FIELD,
        row:$id===1?3:4,col:3,hp:20,hpMax:20,
        prop:$id===1?['actions'=>[['key'=>'marker','type'=>'place_cell_marker','marker'=>'bonfire']]]:[]));
    TurnTimer::prepare($s,'host');TurnTimer::resume($s,1,'start_phase',$now);
    $e=new Engine($db,static fn():int=>$now);
    foreach([new Command('action',['card_id'=>1,'action_key'=>'marker']),new Command('choose_cell_marker',['row'=>3,'col'=>2])] as $cmd)
        tsAssert($e->apply($s,'host',$cmd)->success,'Real Engine publication');
    $repo->save($s);return $s;
}
function iaRows(GameState $s): array {
    global $db;
    return $db->fetchAll('SELECT command_id,status,before_version,after_version FROM battle_command_journal WHERE game_id='.$s->gameId.' ORDER BY id');
}
function iaAuto(GameState $s,int $now): GameState {
    global $db,$repo;
    return (new InformationalAutoAck($db,$repo,new Engine($db,static fn():int=>$now)))->check($s,'host');
}

// Exact deadline, replay/lost response, persisted clock and single journal effect.
$s=iaFixture(1000);$original=$s->persistenceJson;$v=$s->persistenceVersion;
$early=iaAuto($s,1009);tsAssert($early->persistenceJson===$original && iaRows($s)===[],'Before deadline is read-only');
$done=iaAuto($s,1040);
tsAssert($done->battle['strike']['confirmed']===['player'] && $done->persistenceVersion===$v+1,'Auto confirms opponent only once');
tsAssert($done->battle['turn_timer']['anchor_at']===1040 && $done->battle['turn_timer']['remaining_seconds']===180,'Offline time resumes at actual execution');
tsAssert($done->battle['informational_wait']===$s->battle['informational_wait'],'Owner retains same window');
iaAuto($s,1045);iaAuto($done,1045);
tsAssert(count(iaRows($s))===1 && iaRows($s)[0]['status']==='committed','Lost response/replay never re-executes');

// Unknown/processing attempt blocks competing IDs even after a persisted version change.
$s=iaFixture(1000);$id='p2a:1:'.$s->persistenceVersion;
$attempt=$journal->registerProcessing($s->gameId,'player',$id,'confirm_strike',['wait_id'=>'1'],$s->persistenceVersion);
$repo->save($s);$v=$s->persistenceVersion;
$blocked=iaAuto($s,1010);
tsAssert($blocked->persistenceVersion===$v && count(iaRows($s))===1 && $blocked->battle['strike']['confirmed']===[],'Processing blocks all competing versions');
$journal->markConflict((int)$attempt['id'],$v,'stale_state');
$done=iaAuto($repo->findById($s->gameId),1010);
tsAssert($done->persistenceVersion===$v+1 && count(iaRows($s))===2,'Terminal conflict allows a fresh read/version attempt');

// Stale cached state must be rejected under the game lock before calling Engine.
$s=iaFixture(1000);$fresh=$repo->findById($s->gameId);$repo->save($fresh);
$conflict=iaAuto($s,1010);
tsAssert($conflict->persistenceVersion===$fresh->persistenceVersion && iaRows($s)[0]['status']==='conflict','CAS refuses stale auto state');
$done=iaAuto($conflict,1011);tsAssert($done->battle['strike']['confirmed']===['player'],'Fresh retry confirms once');

// Locked callback reads DB state, never caller-mutated state; rejection writes only journal.
$s=iaFixture(1000);$before=$s->persistenceJson;$s->battle['probe']='unpersisted';
$a=$journal->registerProcessing($s->gameId,'player','p2a:1:'.$s->persistenceVersion,'confirm_strike',['wait_id'=>'1'],$s->persistenceVersion);
$repo->executeLockedCommand($s,$journal,(int)$a['id'],static function(GameState $locked)use($db):Result {
    tsAssert($db->inTransaction() && !isset($locked->battle['probe']),'Fresh state locked inside transaction');
    return Result::error('not_current');
});
tsAssert($repo->findById($s->gameId)->persistenceJson===$before && iaRows($s)[0]['status']==='rejected','Rejected validation cannot mutate game');

// Manual wins before auto; auto wins before stale manual, even with fresh version.
$s=iaFixture(1000);$manual=new Command('confirm_strike',['wait_id'=>'1']);
$a=$journal->registerProcessing($s->gameId,'player','manual-first',$manual->type,$manual->payload,$s->persistenceVersion);
tsAssert((new Engine($db,static fn():int=>1009))->apply($s,'player',$manual)->success,'Manual ACK');
$repo->saveCommittedCommand($s,$journal,(int)$a['id']);
iaAuto($s,1011);tsAssert(count(iaRows($s))===1,'Manual winner prevents auto attempt');

// HTTP paths: no-change/read-only, due snapshot, recovery metadata, stale link and GET safety.
$s=iaFixture(time());$q='game='.$s->gameId;$v=$s->persistenceVersion;
$sync=tsRequest('first&'.$q.'&ajax=battle_sync&since_version='.$v);
tsAssert($sync['type']==='no_change' && iaRows($s)===[],'Not-due sync stays read-only');
$s=iaFixture(time()-20);$q='game='.$s->gameId;$v=$s->persistenceVersion;
$sync=tsRequest('first&'.$q.'&ajax=battle_sync&since_version='.$v);
tsAssert($sync['type']==='snapshot' && $sync['sync_version']===$v+1 && count(iaRows($s))===1,'Due sync changes version atomically');
$opponent=tsRequest('second&'.$q.'&ajax=battle_sync&force_snapshot=1');
tsAssert($opponent['turn_timer']['status']==='running' && count(iaRows($s))===1,'Both viewers share auto result');
$late=tsRequest('second&'.$q.'&ajax=battle_command',['cmd'=>'confirm_strike','wait_id'=>'1','command_id'=>'late-manual','expected_version'=>$sync['sync_version']]);
tsAssert($late['command_status']==='rejected','Already-confirmed late ACK rejected with fresh version');

$s=iaFixture(time());IW::publish($s);IW::reconcile($s,time());$repo->save($s);$before=$s->persistenceJson;$q='game='.$s->gameId;
$stale=tsRequest('second&'.$q.'&ajax=battle_command',['cmd'=>'confirm_strike','wait_id'=>'1','command_id'=>'old-screen','expected_version'=>$s->persistenceVersion]);
tsAssert($stale['command_status']==='rejected' && $repo->findById($s->gameId)->persistenceJson===$before,'Old HTML cannot ACK a new window with fresh version');

$s=iaFixture(time()-20);$q='game='.$s->gameId;
$manual=tsRequest('second&'.$q.'&ajax=battle_command',['cmd'=>'confirm_strike','wait_id'=>'1','command_id'=>'opponent-post','expected_version'=>$s->persistenceVersion]);
tsAssert($manual['command_status']==='committed' && count(iaRows($s))===1,'Due auto never runs before the opponent POST');

$s=iaFixture(time()-20);$q='game='.$s->gameId;
file_get_contents($url.'?first&'.$q);
tsAssert(count(iaRows($s))===1 && $repo->findById($s->gameId)->battle['strike']['confirmed']===['player'],'Ordinary page opens run due auto');

$s=iaFixture(time()-20);$q='game='.$s->gameId;
$terminal=$journal->registerProcessing($s->gameId,'host','known-rejection','confirm_strike',['wait_id'=>'bad'],$s->persistenceVersion);
$journal->markRejected((int)$terminal['id'],$s->persistenceVersion,'command_rejected');
$status=tsRequest('first&'.$q.'&ajax=battle_command_status&command_id=known-rejection');
tsAssert($status['command_id']==='known-rejection' && $status['command_status']==='rejected' && count(iaRows($s))===2,'Terminal status can run auto without replacing user outcome');

$s=iaFixture(time()-20);$q='game='.$s->gameId;
$payload=['cmd'=>'confirm_strike','wait_id'=>'1','command_id'=>'owner-post','expected_version'=>$s->persistenceVersion];
$post=tsRequest('first&'.$q.'&ajax=battle_command',$payload);
tsAssert($post['command_id']==='owner-post' && $post['command_status']==='committed' && count(iaRows($s))===2,'User command first, separate auto; metadata preserved');
$recovery=tsRequest('first&'.$q.'&ajax=battle_command_status&command_id=owner-post');
tsAssert($recovery['command_id']==='owner-post' && $recovery['command_status']==='committed' && count(iaRows($s))===2,'Lost HTTP response recovers without second execution');

foreach(['unknown','processing'] as $mode){
    $s=iaFixture(time()-20);$q='game='.$s->gameId;
    if($mode==='processing')$journal->registerProcessing($s->gameId,'host','uncertain','confirm_strike',['wait_id'=>'1'],$s->persistenceVersion);
    $before=$repo->findById($s->gameId)->persistenceJson;
    $r=tsRequest('first&'.$q.'&ajax=battle_command_status&command_id=uncertain');
    tsAssert($r['command_status']===$mode && $repo->findById($s->gameId)->persistenceJson===$before,'Uncertain manual recovery has no auto side effect');
}
$s=iaFixture(time());$q='game='.$s->gameId;
$before=$s->persistenceJson;
file_get_contents($url.'?first&'.$q.'&cmd=confirm_strike&wait_id=1',false,stream_context_create(['http'=>['follow_location'=>0,'ignore_errors'=>true]]));
tsAssert($repo->findById($s->gameId)->persistenceJson===$before && iaRows($s)===[],'Battle GET never executes command');
$reserved=tsRequest('first&'.$q.'&ajax=battle_command',['cmd'=>'confirm_strike','wait_id'=>'1','command_id'=>'p2a:1:999','expected_version'=>$s->persistenceVersion]);
tsAssert(iaRows($s)===[] && ($reserved['ok']??true)===false,'Reserved IDs rejected before journal');

$s=iaFixture(time()-20);unset($s->battle['informational_wait'],$s->battle['informational_wait_generation']);$repo->save($s);
$before=$s->persistenceJson;$q='game='.$s->gameId;
$r=tsRequest('first&'.$q.'&ajax=battle_sync&force_snapshot=1');
tsAssert($repo->findById($s->gameId)->persistenceJson===$before && iaRows($s)===[],'Legacy poll invents no deadline');
$r=tsRequest('second&'.$q.'&ajax=battle_command',['cmd'=>'confirm_strike','wait_id'=>IW::token($s),'command_id'=>'legacy-ack','expected_version'=>$s->persistenceVersion]);
tsAssert($r['command_status']==='committed' && count(iaRows($s))===1,'Legacy exact-version manual ACK');
foreach(['commit-unknown','rollback-unknown'] as $fault){
    $s=iaFixture(1000);$v=$s->persistenceVersion;$pipes=[];
    $process=proc_open([PHP_BINARY,__DIR__.'/test_informational_wait_race.php',(string)$s->gameId,$fault,'unused','unused'],
        [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    tsAssert(is_resource($process),'Start acknowledgement fault child');fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    tsAssert(proc_close($process)===0,'Fault child: '.$out.$err);
    $fresh=$repo->findById($s->gameId);$rows=iaRows($s);
    tsAssert(count($rows)===1,'Unknown outcome never creates a competing attempt');
    if($fault==='commit-unknown')tsAssert($rows[0]['status']==='committed' && $fresh->persistenceVersion===$v+1
        && $fresh->battle['strike']['confirmed']===['player'],'Lost commit ACK recovers atomic committed result');
    else tsAssert($rows[0]['status']==='processing' && $fresh->persistenceJson===$s->persistenceJson,'Unknown rollback stays processing without game effect');
}
// Two independent mysqli connections/processes enter from the same saved version.
foreach([['auto','auto'],['manual','auto']] as $modes){
    $s=iaFixture(1000);$v=$s->persistenceVersion;
    $barrier=tempnam(sys_get_temp_dir(),'p2race');$children=[];
    try{
        foreach($modes as $slot=>$mode){
            $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/test_informational_wait_race.php',(string)$s->gameId,$mode,$barrier,(string)$slot],
                [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            tsAssert(is_resource($process),'Start race child');fclose($pipes[0]);$children[]=[$process,$pipes];
        }
        $until=microtime(true)+10;
        while(!file_exists($barrier.'.0') || !file_exists($barrier.'.1')){
            tsAssert(microtime(true)<$until,'Race children ready');usleep(1000);
        }
        file_put_contents($barrier.'.go','go');
        foreach($children as [$process,$pipes]){
            $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            tsAssert(proc_close($process)===0,'Race child: '.$out.$err);
        }
        $children=[];$fresh=$repo->findById($s->gameId);
        tsAssert($fresh->persistenceVersion===$v+1 && $fresh->battle['strike']['confirmed']===['player'],'Concurrent ACK applies once');
        tsAssert(count(array_filter(iaRows($s),static fn(array $r):bool=>$r['status']==='committed'))===1,'Only one journal commit');
    }finally{
        foreach($children as [$process,$pipes]){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
        foreach(['','.0','.1','.go'] as $suffix)if(file_exists($barrier.$suffix))unlink($barrier.$suffix);
    }
}
echo "P2 Journal/CAS/HTTP/concurrent manual-auto/auto-auto tests passed\n";
