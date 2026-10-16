<?php
declare(strict_types=1);
// P2 suite also enforces disposable source tree, exact datadir, loopback HTTP and InnoDB.
require __DIR__.'/test_informational_wait_http.php';
use Berserk\Core\{GameState,CardInstance,TurnTimer,Engine,Command,AutomaticTurnEnd,AutomaticBattleProgress,InformationalWaitManager as IW};

function teFixture(int $now, string $owner='host'): GameState {
    global $repo;
    $s=$repo->create(1,2);$s->status='battle';
    $s->players['host']->side=1;$s->players['player']->side=2;
    $s->battle=['active'=>$owner,'turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
    foreach([1=>$owner,2=>$s->getOpponentKey($owner)] as $id=>$key)$s->addCard(new CardInstance(
        instanceId:$id,ukid:'t3_'.$id,owner:$key,zone:CardInstance::ZONE_FIELD,row:$id===1?3:4,col:3,hp:20,hpMax:20,
        strikeWeak:1,strikeMedium:2,strikeStrong:3,
        prop:$id===1?['actions'=>[['key'=>'marker','type'=>'place_cell_marker','marker'=>'bonfire']]]:[]));
    TurnTimer::prepare($s,$owner);TurnTimer::resume($s,1,'start_phase',$now);$repo->save($s);return $s;
}
function teAuto(GameState $s,int $now): \Berserk\Core\AutomaticCommandResult {
    global $db,$repo;return (new AutomaticTurnEnd($db,$repo,new Engine($db,static fn():int=>$now)))->check($s,'player');
}
function teOpenExpiredResult(): GameState {
    global $db,$repo;
    $s=teFixture(1000);$now=1000;$e=new Engine($db,static function()use(&$now):int{return $now;});
    tsAssert($e->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'marker']))->success,'Open own decision');
    $now=1180;tsAssert($e->apply($s,'host',new Command('choose_cell_marker',['row'=>3,'col'=>2]))->success,'Finish after zero');
    tsAssert($e->apply($s,'host',new Command('confirm_strike',['wait_id'=>IW::token($s)]))->success,'Owner ACK');
    $repo->save($s);return $s;
}
foreach(['host','player'] as $owner){
    $s=teFixture(1000,$owner);$v=$s->persistenceVersion;$before=$s->persistenceJson;
    tsAssert(teAuto($s,1179)->state->persistenceJson===$before && iaRows($s)===[],'Before deadline no writes');
    $out=teAuto($s,1200);$fresh=$out->state;
    tsAssert($out->certain && $fresh->battle['active']===$s->getOpponentKey($owner)
        && $fresh->battle['turn_timer']['period_id']===2 && $fresh->persistenceVersion===$v+1,'Auto uses timer owner and ordinary lifecycle');
    teAuto($s,1200);teAuto($fresh,1200);
    tsAssert(count(iaRows($s))===1 && iaRows($s)[0]['status']==='committed','Replay/lost response ends only one period');
}

// Processing blocks all versions of the same period; terminal conflict permits fresh eligibility.
$s=teFixture(1000);$id='t3e:1:host:'.$s->persistenceVersion;
$a=$journal->registerProcessing($s->gameId,'host',$id,'end_turn',['period_id'=>1],$s->persistenceVersion);
$repo->save($s);$v=$s->persistenceVersion;
$out=teAuto($s,1200);tsAssert(!$out->certain && $out->reason==='processing' && count(iaRows($s))===1 && $out->state->persistenceVersion===$v,'Orphan processing blocks competing version');
$journal->markConflict((int)$a['id'],$v,'stale_state');
$out=teAuto($repo->findById($s->gameId),1200);tsAssert($out->state->battle['turn_timer']['period_id']===2 && count(iaRows($s))===2,'Terminal conflict + fresh read can retry');

$s=teFixture(1000);$new=$repo->findById($s->gameId);$repo->save($new);
$out=teAuto($s,1200);tsAssert($out->reason==='conflict' && iaRows($s)[0]['status']==='conflict','Stale version rejected before Engine');
$out=teAuto($out->state,1200);tsAssert($out->state->battle['turn_timer']['period_id']===2,'Fresh retry after CAS conflict');

// Cached candidate cannot hide a persisted workflow; locked callback revalidates actual state.
$s=teOpenExpiredResult();$before=$s->persistenceJson;$cached=clone $s;
unset($cached->battle['strike'],$cached->battle['informational_wait']);
$out=teAuto($cached,1200);tsAssert($out->state->persistenceJson===$before && iaRows($s)[0]['status']==='rejected','Locked pending revalidation rejects without game write');
$s=teFixture(1000);$cached=clone $s;$cached->battle['turn_timer']['period_id']=2;
$out=teAuto($cached,1200);tsAssert($out->state->persistenceVersion===$s->persistenceVersion && iaRows($s)[0]['status']==='rejected','Period binding revalidated under lock');

// Expiry is latched by an actual own decision; P2 closes the last result, then T3 runs.
$s=teOpenExpiredResult();$v=$s->persistenceVersion;
$out=(new AutomaticBattleProgress($db,$repo,new Engine($db,static fn():int=>1190)))->check($s,'host');
$rows=iaRows($s);tsAssert(count($rows)===2 && str_starts_with($rows[0]['command_id'],'p2a:')
    && str_starts_with($rows[1]['command_id'],'t3e:') && $out->state->persistenceVersion===$v+2,'P2 then fresh T3, separate atomic commands');

$s=teFixture(1000);$s->getCard(1)->prop=['forced_strike'=>true,'strike_effects'=>[['push'=>2]]];$s->getCard(2)->closed=true;
$s->addCard(new CardInstance(instanceId:3,ukid:'push_blocker',owner:'player',zone:CardInstance::ZONE_FIELD,row:5,col:3,hp:20,hpMax:20));
$clock=1200;$engine=new Engine($db,static function()use(&$clock):int{return $clock;});
$_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
tsAssert($engine->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>2]))->success,'Expired forced strike with push');
tsAssert($engine->apply($s,'host',new Command('confirm_strike',['wait_id'=>IW::token($s)]))->success,'Initial owner ACK');$repo->save($s);
$progress=new AutomaticBattleProgress($db,$repo,new Engine($db,static fn():int=>1210));$out=$progress->check($s,'host');
tsAssert($out->state->battle['strike']['state']==='waiting_push_ack' && IW::token($out->state)==='2'
    && $out->state->battle['turn_timer']['period_id']===1 && count(iaRows($s))===1,'P2 new window blocks T3 and gets a fresh deadline');
unset($_SESSION['debug_roll'],$_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();

// HTTP: read-only sync, expiry between requests, blocked new action and user metadata.
$s=teFixture(time());$q='game='.$s->gameId;$before=$s->persistenceJson;
$r=tsRequest('first&'.$q.'&ajax=battle_sync&since_version='.$s->persistenceVersion);
tsAssert($r['type']==='no_change' && iaRows($s)===[] && $repo->findById($s->gameId)->persistenceJson===$before,'Early HTTP sync read-only');
foreach(['missing','malformed','paused','unknown','finished'] as $mode){
    $s=teFixture(time()-181);
    if($mode==='missing')unset($s->battle['turn_timer']);
    if($mode==='malformed')$s->battle['turn_timer']['anchor_at']='broken';
    if($mode==='paused'){$s->battle['turn_timer']['status']='paused';$s->battle['turn_timer']['anchor_at']=null;$s->battle['turn_timer']['pause_reason']='opponent_wait';}
    if($mode==='unknown')$s->battle['pending_future']=['owner'=>'host'];
    if($mode==='finished'){$s->status='game_over';$s->winner='host';}
    $repo->save($s);$before=$s->persistenceJson;
    $r=tsRequest('first&game='.$s->gameId.'&ajax=battle_sync&force_snapshot=1');
    tsAssert($repo->findById($s->gameId)->persistenceJson===$before && iaRows($s)===[],'HTTP blocked state has no auto effect: '.$mode);
    if(in_array($mode,['missing','malformed'],true))tsAssert($r['turn_timer']===null,'Malformed/legacy timer has no unsafe projection');
}
$s=teFixture(time()-181);$q='game='.$s->gameId;$v=$s->persistenceVersion;
$r=tsRequest('second&'.$q.'&ajax=battle_sync&since_version='.$v);
tsAssert($r['type']==='snapshot' && $r['turn_timer']['period_id']===2 && $r['turn_timer']['owner']==='player','Opponent poll can complete offline owner turn');
$r2=tsRequest('first&'.$q.'&ajax=battle_sync&force_snapshot=1');
tsAssert($r2['sync_version']===$r['sync_version'] && count(iaRows($s))===1,'Reload sees same next period');

$s=teFixture(time()-181);$q='game='.$s->gameId;$v=$s->persistenceVersion;
$payload=['cmd'=>'action','card_id'=>1,'action_key'=>'marker','command_id'=>'expired-action','expected_version'=>$v];
$r=tsRequest('first&'.$q.'&ajax=battle_command',$payload);
tsAssert($r['command_status']==='rejected' && $r['command_id']==='expired-action'
    && $r['sync_version']===$v+1 && $r['turn_timer']['period_id']===2,'Rejected root action then T3 preserves user metadata');
$user=$journal->find($s->gameId,'host','expired-action');
tsAssert($user['status']==='rejected' && (int)$user['before_version']===$v && (int)$user['after_version']===$v && count(iaRows($s))===2,'Refusal never becomes committed journal result');
$status=tsRequest('first&'.$q.'&ajax=battle_command_status&command_id=expired-action');
tsAssert($status['command_status']==='rejected' && $status['sync_version']===$v+1,'Lost rejection response recovers with authoritative period');

$s=teOpenExpiredResult();$q='game='.$s->gameId;$v=$s->persistenceVersion;
$payload=['cmd'=>'confirm_strike','wait_id'=>IW::token($s),'command_id'=>'last-manual-ack','expected_version'=>$v];
$r=tsRequest('second&'.$q.'&ajax=battle_command',$payload);
tsAssert($r['command_status']==='committed' && $r['command_id']==='last-manual-ack'
    && $r['before_version']===$v && $r['after_version']===$v+1 && $r['sync_version']===$v+2
    && $r['turn_timer']['period_id']===2 && count(iaRows($s))===2,'Manual continuation commits first; T3 keeps its exact user metadata');
$replay=tsRequest('second&'.$q.'&ajax=battle_command',$payload);
tsAssert($replay['command_status']==='committed' && $replay['after_version']===$v+1 && count(iaRows($s))===2,'Lost manual response/replay cannot end another turn');
$s=teOpenExpiredResult();$q='game='.$s->gameId;$v=$s->persistenceVersion;
$r=tsRequest('first&'.$q.'&ajax=battle_sync&since_version='.$v);
tsAssert($r['sync_version']===$v+2 && $r['turn_timer']['period_id']===2 && count(iaRows($s))===2,'HTTP P2 -> T3 snapshot advances after final blocker');

// Definite manual continuation commits at v+1, subsequent auto-end at v+2.
$s=teFixture(time()-181);$s->getCard(1)->prop=['forced_strike'=>true];$s->getCard(2)->closed=true;$repo->save($s);$q='game='.$s->gameId;
$r=tsRequest('first&'.$q.'&ajax=battle_sync&force_snapshot=1');
tsAssert($r['turn_timer']['remaining_seconds']===0 && iaRows($s)===[],'Forced obligation remains available at zero');
$s->getCard(1)->prop=['turn_end'=>[['type'=>'heal','value'=>1]]];$repo->save($s);$v=$s->persistenceVersion;
$r=tsRequest('first&'.$q.'&ajax=battle_sync&force_snapshot=1');
$fresh=$repo->findById($s->gameId);
tsAssert(!empty($fresh->battle['turn_phase']) && $fresh->battle['turn_timer']['period_id']===1 && $fresh->battle['active']==='player','HTTP auto-end may leave end phase pending');
tsRequest('second&'.$q.'&ajax=battle_sync&force_snapshot=1');tsAssert(count(iaRows($s))===1,'Pending end phase not launched twice');
$r=tsRequest('first&'.$q.'&ajax=battle_command',['cmd'=>'turn_ack','command_id'=>'phase-ack','expected_version'=>$fresh->persistenceVersion]);
tsAssert($r['command_status']==='committed' && $r['turn_timer']['period_id']===2,'Manual phase continuation starts next timer');

foreach(['unknown','processing'] as $mode){
    $s=teFixture(time()-181);$q='game='.$s->gameId;$before=$s->persistenceJson;
    if($mode==='processing')$journal->registerProcessing($s->gameId,'host','uncertain-turn','end_turn',[],$s->persistenceVersion);
    $r=tsRequest('first&'.$q.'&ajax=battle_command_status&command_id=uncertain-turn');
    tsAssert($r['command_status']===$mode && $repo->findById($s->gameId)->persistenceJson===$before,'Unknown user recovery never auto-ends');
}
$s=teFixture(time());$q='game='.$s->gameId;$before=$s->persistenceJson;
file_get_contents($url.'?first&'.$q.'&cmd=end_turn',false,stream_context_create(['http'=>['follow_location'=>0,'ignore_errors'=>true]]));
tsAssert($repo->findById($s->gameId)->persistenceJson===$before && iaRows($s)===[],'GET end_turn not executed');
$r=tsRequest('first&'.$q.'&ajax=battle_command',['cmd'=>'end_turn','command_id'=>'t3e:1:host:999','expected_version'=>$s->persistenceVersion]);
tsAssert(($r['ok']??true)===false && iaRows($s)===[],'Client cannot claim T3 namespace');
$r=tsRequest('first&'.$q.'&ajax=battle_command',['cmd'=>'end_turn','command_id'=>'T3E:1:HOST:999','expected_version'=>$s->persistenceVersion]);
tsAssert(($r['ok']??true)===false && iaRows($s)===[],'Case-insensitive DB collation cannot bypass reserved namespace');

// Orphan P2 blocks orchestration; do not bypass it into T3.
$s=teOpenExpiredResult();$journal->registerProcessing($s->gameId,'player','p2a:1:'.$s->persistenceVersion,'confirm_strike',['wait_id'=>'1'],$s->persistenceVersion);
$out=(new AutomaticBattleProgress($db,$repo,new Engine($db,static fn():int=>1190)))->check($s,'host');
tsAssert(!$out->certain && count(iaRows($s))===1 && $out->state->battle['active']==='host','Processing P2 stops ordered pass');

// Faults and races use real independent connections in a bounded child process.
function teChild(GameState $s,string $mode,string $barrier='unused',string $slot='unused'): array {
    $pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/test_turn_timeout_race.php',(string)$s->gameId,$mode,$barrier,$slot],
        [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    tsAssert(is_resource($p),'Start T3 child');fclose($pipes[0]);return [$p,$pipes];
}
function teJoin(array $child): void {
    [$p,$pipes]=$child;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    tsAssert(proc_close($p)===0,'T3 child: '.$out.$err);
}
foreach(['commit-unknown','rollback-unknown','save-failed','p2-commit-unknown','p2-commit-unknown-http'] as $mode){
    $s=str_starts_with($mode,'p2-')?teOpenExpiredResult():teFixture(1000);$v=$s->persistenceVersion;
    teJoin(teChild($s,$mode));$fresh=$repo->findById($s->gameId);$rows=iaRows($s);
    tsAssert(count($rows)===1,'Unknown transaction never launches a competing operation');
    if($mode==='commit-unknown')tsAssert($rows[0]['status']==='committed' && $fresh->battle['turn_timer']['period_id']===2,'Lost T3 COMMIT ack remains atomically committed');
    elseif($mode==='rollback-unknown')tsAssert($rows[0]['status']==='processing' && $fresh->persistenceJson===$s->persistenceJson,'Unknown rollback remains processing without state write');
    elseif($mode==='save-failed'){
        tsAssert($rows[0]['status']==='failed' && $fresh->persistenceJson===$s->persistenceJson,'Acknowledged rollback after SQL failure becomes failed, never committed');
        $retry=teAuto($fresh,1200);
        tsAssert($retry->state->battle['turn_timer']['period_id']===2 && count(iaRows($s))===2,'Fresh request retries a definite transient failure without a game-version write');
    }
    else tsAssert($rows[0]['status']==='committed' && str_starts_with($rows[0]['command_id'],'p2a:')
        && $fresh->battle['active']==='host' && empty($fresh->battle['strike']),'Unknown P2 commit does not chain T3 even after closing last blocker');
}
foreach([['auto','auto'],['manual','auto']] as $modes){
    $s=teFixture(1000);$v=$s->persistenceVersion;$barrier=tempnam(sys_get_temp_dir(),'t3race');$children=[];
    try{
        foreach($modes as $slot=>$mode)$children[]=teChild($s,$mode,$barrier,(string)$slot);
        $until=microtime(true)+10;
        while(!file_exists($barrier.'.0') || !file_exists($barrier.'.1')){tsAssert(microtime(true)<$until,'T3 race ready');usleep(1000);}
        file_put_contents($barrier.'.go','go');foreach($children as $child)teJoin($child);$children=[];
        $fresh=$repo->findById($s->gameId);
        tsAssert($fresh->persistenceVersion===$v+1 && $fresh->battle['turn_timer']['period_id']===2,'Concurrent end-turn applies once');
        tsAssert(count(array_filter(iaRows($s),static fn(array $r):bool=>$r['status']==='committed'))===1,'One committed end-turn journal result');
    }finally{
        foreach($children as [$p,$pipes]){proc_terminate($p);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}
        foreach(['','.0','.1','.go'] as $suffix)if(file_exists($barrier.$suffix))unlink($barrier.$suffix);
    }
}
echo "T3 Journal/CAS/HTTP/P2 ordering/concurrency/unknown outcome tests passed\n";
