<?php
declare(strict_types=1);
require __DIR__.'/test_instant_reservation_sql.php';
define('FREE_INSTANT_FIXTURES_ONLY',true);
require __DIR__.'/test_free_instant_reservation.php';
use Berserk\Core\{GameState,InstantReservation as IR};
function fsSeed(string $active='host'):GameState {
    global $raw,$repo;
    $base=irSqlSeed();$s=fState($active);fRevive($s);
    $s->gameId=$base->gameId;$s->hostId=$base->hostId;$s->playerId=$base->playerId;$s->journalSchema=1;
    foreach(['host','player']as$p)$s->players[$p]->userId=$base->players[$p]->userId;
    $json=$raw->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));
    $raw->query("UPDATE games SET json='$json' WHERE ind=$s->gameId");
    return $repo->findById($s->gameId);
}
$freeEvidence=[];
foreach(['host','player']as$active)foreach([true,false]as$reserveFirst){
    $s=fsSeed($active);$p=$s->getOpponentKey($active);
    $reserve=irJob($s,$p,'reserve_instant',['period_id'=>1],$reserveFirst);
    $action=irJob($s,$active,'gain_coin',['card_id'=>$active==='host'?1:2],!$reserveFirst);
    $a=irWorker($reserveFirst?$reserve:$action);irGo($a);irCheck(trim(fgets($a[1][1])?:'')==='LOCKED','Two-player free race lock');
    $b=irWorker($reserveFirst?$action:$reserve);irGo($b);irGo($a);$ar=irFinish($a);$br=irFinish($b);
    $s=$repo->findById($s->gameId);
    irCheck(($reserveFirst?$ar:$br)['status']==='committed'&&($reserveFirst?$br:$ar)['status']===($reserveFirst?'conflict':'rejected'),'Active AJAX cannot win an independent action during mandatory choice');
    irCheck(IR::snapshot($s)['request']['reason']==='free'&&IR::snapshot($s)['used']===0&&isset($s->battle['pending_revive']),'Two-player race preserves mandatory choice and free price');
}
foreach(['host','player']as$active)foreach(['reserve_first','finish_first','cancel_first','grant_first']as$order){
    $s=fsSeed($active);$p=$s->getOpponentKey($active);
    $s=irSqlCommand($s,$p,'choose_revive_target',['target_id'=>5]);
    $origin=IR::controls($s,$p,1000)['origin_choice_id'];
    if(in_array($order,['cancel_first','grant_first'],true))$s=irSqlCommand($s,$p,'reserve_instant',['period_id'=>1,'origin_choice_id'=>$origin]);
    $finish=irJob($s,$p,'choose_revive_cell',['row'=>3,'col'=>4],in_array($order,['finish_first','grant_first'],true));
    $other=in_array($order,['cancel_first','grant_first'],true)
        ?irJob($s,$p,'cancel_instant_reservation',['request_id'=>IR::snapshot($s)['request']['id']],$order==='cancel_first')
        :irJob($s,$p,'reserve_instant',['period_id'=>1,'origin_choice_id'=>$origin],$order==='reserve_first');
    $first=in_array($order,['finish_first','grant_first'],true)?$finish:$other;
    $second=in_array($order,['finish_first','grant_first'],true)?$other:$finish;
    $a=irWorker($first);irGo($a);irCheck(trim(fgets($a[1][1])?:'')==='LOCKED','Free race holds game lock');
    $b=irWorker($second);irGo($b);irGo($a);$ar=irFinish($a);$br=irFinish($b);
    irCheck($ar['status']==='committed'&&$br['status']==='conflict'&&$br['calls']===0,'Free race serializes before Engine');
    $s=$repo->findById($s->gameId);$r=IR::snapshot($s);
    irCheck($r['used']===0,'Free race never consumes paid quota');
    if($order==='reserve_first'){
        irCheck($r['status']==='requested'&&isset($s->battle['pending_revive']),'Reservation preserves mandatory cell');
        $s=irSqlCommand($s,$p,'choose_revive_cell',['row'=>3,'col'=>4]);
        irCheck(IR::snapshot($s)['status']==='granted'&&$s->getCard(5)->hp>0,'Completion and grant commit together');
    }elseif($order==='grant_first')irCheck($r['status']==='granted'&&!isset($s->battle['pending_revive']),'Grant wins cancellation race');
    elseif($order==='finish_first'){
        irCheck($r['status']==='idle'&&!isset($s->battle['pending_revive']),'Completion wins registration race');
        $job=irJob($s,$p,'reserve_instant',['period_id'=>1,'origin_choice_id'=>$origin]);$w=irWorker($job);irGo($w);$out=irFinish($w);
        irCheck($out['status']==='rejected'&&IR::snapshot($repo->findById($s->gameId))['used']===0,'Stale origin never becomes paid reservation');
    }else irCheck($r['status']==='idle'&&isset($s->battle['pending_revive']),'Cancel wins without completing choice');
    $freeEvidence[]=['active'=>$active,'order'=>$order,'first'=>$ar,'second'=>$br];
}
foreach(['host','player']as$active)foreach(['results','waiting_push_ack']as$stage){
    $s=fsSeed($active);unset($s->battle['pending_revive']);fResult($s,$stage);$p=$s->getOpponentKey($active);
    $record=$s->battle['informational_wait'];$json=$raw->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));
    $raw->query("UPDATE games SET json='$json' WHERE ind=$s->gameId");$s=$repo->findById($s->gameId);
    $s=irSqlCommand($s,$p,'reserve_instant',['period_id'=>1]);
    irCheck($s->battle['informational_wait']===$record,'Persisted reserve preserves auto-ACK deadline');
    $s=irSqlCommand($s,$active,'confirm_strike',['wait_id'=>(string)$record['wait_id']]);
    $auto=new \Berserk\Core\InformationalAutoAck($db,$repo,irEngine(1010));
    $out=$auto->checkOutcome($s,$active);$s=$out->state;
    irCheck(IR::snapshot($s)['status']==='granted'&&IR::snapshot($s)['used']===0,'Real journaled automatic ACK grants free priority');
    $v=$s->persistenceVersion;$again=$auto->checkOutcome($s,$active);
    irCheck($again->state->persistenceVersion===$v,'Repeated auto-ACK read does not grant twice');
}
// Inject failure after the final choice and grant; none of choice/stack/history may escape rollback.
$s=fsSeed();$s=irSqlCommand($s,'player','reserve_instant',['period_id'=>1]);$s=irSqlCommand($s,'player','choose_revive_target',['target_id'=>5]);
$before=$db->fetchOne('SELECT json,version FROM games WHERE ind='.$s->gameId);
$batches=$db->fetchOne('SELECT COUNT(*) n FROM battle_history_batches WHERE game_id='.$s->gameId);
$job=irJob($s,'player','choose_revive_cell',['row'=>3,'col'=>4]);$entry=$journal->registerProcessing($s->gameId,'player',$job['id'],$job['type'],$job['payload'],$s->persistenceVersion);
try{$repo->executeLockedCommand($s,$journal,(int)$entry['id'],function($w){$r=irEngine()->apply($w,'player',new \Berserk\Core\Command('choose_revive_cell',['row'=>3,'col'=>4]));$w->battle['invalid_json']=NAN;return$r;});throw new RuntimeException('Expected write failure');}
catch(TypeError $e){irCheck(str_contains($e->getMessage(),'Db::escape'),'Expected free write failure');}
irCheck($db->fetchOne('SELECT json,version FROM games WHERE ind='.$s->gameId)===$before&&$db->fetchOne('SELECT COUNT(*) n FROM battle_history_batches WHERE game_id='.$s->gameId)===$batches&&$journal->findById((int)$entry['id'])['status']==='processing','Free grant/choice/history/journal rollback atomically');
$repo->executeLockedCommand($s,$journal,(int)$entry['id'],fn($w)=>irEngine()->apply($w,'player',new \Berserk\Core\Command('choose_revive_cell',['row'=>3,'col'=>4])));
$s=$repo->findById($s->gameId);irCheck(IR::snapshot($s)['status']==='granted'&&IR::snapshot($s)['used']===0&&$s->getCard(5)->hp>0,'Retry grants free priority exactly once');
file_put_contents($root.'/free-sql-evidence.json',json_encode(['checks'=>$irChecks,'races'=>$freeEvidence,'auto_ack_cases'=>4,'atomic_free_rollback'=>true],JSON_PRETTY_PRINT));
echo "Free isolated InnoDB tests: $irChecks checks passed\n";
