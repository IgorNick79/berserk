<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,Engine,GameState,TurnTimer,CardInstance,Command,AutomaticMatchPause};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$config=require __DIR__.'/../config/db.php';$expected=realpath(getenv('TURN_TIMER_TEST_DATADIR')?:'/nonexistent');
if($expected===false || $config['name']!=='berserk_turn_timer_t2_test' || file_exists(__DIR__.'/../.git'))throw new RuntimeException('Disposable source tree required');
$db=new Db($config);$meta=$db->fetchOne('SELECT @@datadir AS dir,DATABASE() AS db');
if(realpath($meta['dir'])!==$expected || $meta['db']!==$config['name'])throw new RuntimeException('Wrong test DB');
foreach($db->fetchAll('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $t)if($t['ENGINE']!=='InnoDB')throw new RuntimeException('InnoDB required');
$mode=$argv[1]??'create';
if($mode==='verify'){echo json_encode($meta,JSON_THROW_ON_ERROR);exit;}
$repo=new GameRepository($db);
if($mode==='create'){
    $s=$repo->create(1,2);$s->status='battle';$s->players['host']->side=1;$s->players['player']->side=2;
    $s->battle=['active'=>'host','turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
    foreach([1=>'host',2=>'player'] as $id=>$owner)$s->addCard(new CardInstance(instanceId:$id,ukid:'pause_fixture_'.$id,owner:$owner,zone:CardInstance::ZONE_FIELD,row:$id===1?3:4,col:3,hp:20,hpMax:20));
    TurnTimer::prepare($s,'host');TurnTimer::resume($s,1,'start_phase',time());$repo->save($s);
}elseif($mode==='expire'){
    $s=$repo->findById((int)$argv[2]);$p=$s->battle['match_pause'];$at=$p['mode']==='requested'?$p['request_deadline_at']:$p['pause_deadline_at'];
    // Controlled server clock in the disposable fixture only; no runtime shortcuts.
    $s=(new AutomaticMatchPause($db,$repo,new Engine($db,static fn():int=>$at)))->check($s,'host')->state;
}else $s=$repo->findById((int)$argv[2]);
if($mode==='seed_resign_status'){
    // Presentation fixtures, after all disposable DB/InnoDB guards above.
    $status=$argv[3];$id=$argv[4];
    if(!in_array($status,['processing','rejected','conflict','failed','committed_no_result','committed_nonterminal_result','committed_wrong_winner'],true))throw new RuntimeException('Unsupported fixture status');
    $journal=new \Berserk\Core\CommandJournalRepository($db);
    $record=$journal->registerProcessing($s->gameId,'host',$id,'resign',['html_resign'=>'1'],$s->persistenceVersion);
    if(in_array($status,['committed_nonterminal_result','committed_wrong_winner'],true)){
        $before=$s->persistenceVersion;
        if($status==='committed_wrong_winner'){$s->status='game_over';$s->winner='host';}
        $repo->save($s); // Deliberately inconsistent fixture: matching version, no successful host resign.
        $journal->markCommitted((int)$record['id'],$before,$s->persistenceVersion);
    }
    if($status==='committed_no_result')$journal->markCommitted((int)$record['id'],$s->persistenceVersion,$s->persistenceVersion+1);
    if($status==='rejected')$journal->markRejected((int)$record['id'],$s->persistenceVersion,'command_rejected','Fixture rejection');
    if($status==='conflict')$journal->markConflict((int)$record['id'],$s->persistenceVersion,'stale_version','Fixture conflict');
    if($status==='failed')$journal->markFailed((int)$record['id'],$s->persistenceVersion,'persistence_failed');
}
echo json_encode(['game_id'=>$s->gameId,'state'=>$s->toArray(),'version'=>$s->persistenceVersion],JSON_THROW_ON_ERROR);
