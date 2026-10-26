<?php
declare(strict_types=1);
// Retained disposable InnoDB instance only; no application DB config and no DDL.
if(PHP_SAPI!=='cli'||!in_array('--isolated',$argv,true)){fwrite(STDERR,"Use --isolated with stage2a-final-validation/mysql-data on port 33991\n");exit(2);}
require __DIR__.'/test_lord_of_the_dead.php';
use Berserk\Core\{Command,CommandJournalRepository,Db,Engine,GameRepository,GameState,Result,StaleStateException};
$sqlStartChecks=$lordChecks;
$db=new Db(['host'=>'127.0.0.1:33991','user'=>'root','pass'=>'','name'=>'berserk_lucktest_stage3_c7a111282b']);
$proof=$db->fetchOne('SELECT @@port port,@@datadir dir,VERSION() version,DATABASE() db');
ldCheck((int)$proof['port']===33991&&str_ends_with(strtolower(str_replace('\\','/',$proof['dir'])),'/stage2a-final-validation/mysql-data/'),'Verified disposable SQL server');
function ldsSchema():array {
 global $db;$result=[];
 foreach(['games','battle_command_journal','battle_history_matches','battle_history_actions','battle_history_events','battle_history_steps','battle_history_batches']as$table){
  $status=$db->fetchOne("SHOW TABLE STATUS LIKE '$table'");ldCheck($status!==null&&strtolower($status['Engine'])==='innodb','InnoDB '.$table);
  $row=$db->fetchOne("SHOW CREATE TABLE $table");$result[$table]=hash('sha256',preg_replace('/AUTO_INCREMENT=\d+ /','',array_values($row)[1]));
 }return $result;
}
$schemaBefore=ldsSchema();$journal=new CommandJournalRepository($db);
function ldsRegister(GameState $s,string $type,array $payload=[]):array {global $journal;return $journal->registerProcessing($s->gameId,'host','lord_'.bin2hex(random_bytes(10)),$type,$payload,$s->persistenceVersion);}
function ldsExecute(GameRepository $repo,GameState &$s,string $type,array $payload=[]):array {
 global $journal;$entry=ldsRegister($s,$type,$payload);
 $repo->executeLockedCommand($s,$journal,(int)$entry['id'],static function(GameState $w)use($type,$payload):Result {
  ldCheck($w->historyRecorder!==null,'Mandatory recorder active under native lock');$r=ldCommand($w,$type,$payload);ldCheck($r->success,$type.': '.$r->error);return $r;
 });
 $s=$repo->findById($s->gameId);ldCheck($journal->findById((int)$entry['id'])['status']==='committed','Command committed '.$type);return $entry;
}
function ldsSeed():array {
 global $db,$journal;$plain=new GameRepository($db);$created=$plain->create(101,202);$fixture=ldState();
 $fixture->players['player']->confirm('place');$catalog=[];
 foreach($fixture->cards as$c)$catalog[$c->ukid]=['revision'=>'lord-sql','name'=>$c->ukid,'image'=>'','description'=>'','type'=>$c->type,'element'=>'neutral','classes'=>[],'price'=>0];
 $repo=new GameRepository($db,$catalog);$entry=ldsRegister($created,'confirm_place');
 $repo->executeLockedCommand($created,$journal,(int)$entry['id'],static function(GameState $w)use($fixture):Result {
  $w->cards=$fixture->cards;$w->players=$fixture->players;$w->nextInstanceId=$fixture->nextInstanceId;
  $w->status='place';$w->firstPlayer='host';$w->battle=$fixture->battle;
  return (new Engine())->apply($w,'host',new Command('confirm_place'));
 });
 $s=$repo->findById($created->gameId);ldCheck($s->status==='battle'&&$s->journalSchema===1,'Native battle fixture and journal persisted');return[$repo,$s];
}
function ldsSnapshot(GameState $s):array {
 global $db;$out=['game'=>$db->fetchOne('SELECT json,version FROM games WHERE ind='.$s->gameId),'meta'=>$db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id='.$s->gameId)];
 foreach(['battle_history_actions','battle_history_events','battle_history_steps','battle_history_batches']as$table)$out[$table]=$db->fetchOne('SELECT COUNT(*) n FROM '.$table.' WHERE game_id='.$s->gameId)['n'];
 return $out;
}
foreach(['save','journal']as$failure){
 [$repo,$s]=ldsSeed();
 foreach([['turn_task',['task_id'=>'instants']],['turn_sub',['sub_id'=>'instant_1_bone']],['choose_instant_pick',['target_id'=>2]],['pass_turn_instant',[]],['turn_instant_result_ok',[]]]as[$type,$payload])ldsExecute($repo,$s,$type,$payload);
 ldCheck($s->getCard(1)->closed&&$s->getCard(2)->hp===19&&!empty($s->battle['pending_incarnation']),'Bone damage/tap/continuation survive each SQL reload');
 $payload=['row'=>1,'col'=>1];$entry=ldsRegister($s,'choose_incarnation_cell',$payload);$before=ldsSnapshot($s);$source=$s->toArray();$failed=false;$calls=0;
 try{
  $repo->executeLockedCommand($s,$journal,(int)$entry['id'],static function(GameState $w)use($payload,$failure,$db,&$calls):Result {
   ++$calls;$r=ldCommand($w,'choose_incarnation_cell',$payload);ldCheck($r->success&&!$w->getCard(1)->closed&&$w->getCard(3)->effectiveMove()===3,'Effects applied before injected fault');
   if($failure==='save')$w->battle['invalid_json_fixture']=NAN;
   else $db->query("UPDATE battle_history_matches SET recording_status='disabled' WHERE game_id=".$w->gameId);
   return$r;
  });
 }catch(TypeError $e){if($failure!=='save'||!str_contains($e->getMessage(),'Db::escape'))throw$e;$failed=true;}
 catch(LogicException $e){if($failure!=='journal')throw$e;$failed=true;}
 ldCheck($failed&&$calls===1&&ldsSnapshot($s)===$before&&$s->toArray()===$source,'Rollback game, opening, movement, pending and live journal atomically: '.$failure);
 ldCheck($journal->findById((int)$entry['id'])['status']==='processing','Failed command remains retryable');
 $stale=$repo->findById($s->gameId);
 $repo->executeLockedCommand($s,$journal,(int)$entry['id'],static function(GameState $w)use($payload,&$calls):Result {++$calls;return ldCommand($w,'choose_incarnation_cell',$payload);});
 $s=$repo->findById($s->gameId);
 ldCheck($calls===2&&!$s->getCard(1)->closed&&count(ldBonuses($s->getCard(3)))===1&&$s->getCard(3)->move===3&&$s->getCard(3)->effectiveMove()===3,'Successful retry persists exactly one bonus and opening');
 ldCheck($s->getCard(2)->hp===19&&($s->getCard(1)->flags['instant_uses_this_turn']['bone']??0)===1,'Incarnation retry never replays bone');
 ldCheck(empty($s->battle['pending_incarnation'])&&empty($s->battle['turn_phase']),'Native continuation persisted complete');
 ldCheck($journal->findById((int)$entry['id'])['status']==='committed','Game and journal committed together');
 $after=ldsSnapshot($s);$duplicate=$journal->registerProcessing($s->gameId,'host',$entry['command_id'],'choose_incarnation_cell',$payload,$stale->persistenceVersion);
 ldCheck(!$duplicate['is_new']&&$duplicate['status']==='committed'&&ldsSnapshot($s)===$after,'Committed duplicate returns prior result without execution');
 $conflictEntry=ldsRegister($stale,'choose_incarnation_cell',$payload);$ran=false;$conflict=false;
 try{$repo->executeLockedCommand($stale,$journal,(int)$conflictEntry['id'],static function(GameState $w)use(&$ran):Result{$ran=true;return Result::ok();});}catch(StaleStateException $e){$conflict=true;}
 ldCheck($conflict&&!$ran&&ldsSnapshot($s)===$after,'CAS rejects before callback and preserves game/live journal');
 ldCheck((int)$after['battle_history_batches']>0&&(int)$after['battle_history_events']>0,'Native live journal batches persisted');
 echo 'SQL fixture game '.$s->gameId.': '.$failure." rollback, reload, retry and CAS passed\n";
}
ldCheck(ldsSchema()===$schemaBefore,'SQL schema unchanged');
echo 'Lord SQL: '.($lordChecks-$sqlStartChecks)." checks passed; ".$proof['version']." / port ".$proof['port']."\n";
