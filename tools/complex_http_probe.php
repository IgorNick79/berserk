<?php
declare(strict_types=1);
if(file_exists(__DIR__.'/../.git'))throw new RuntimeException('Disposable copy only');
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository,GameState,CardInstance,Engine,Command,Result,Dice,ZoneManager,ProphecyProcessor,DbQueryException};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$db=new Db(require __DIR__.'/../config/db.php');$identity=$db->fetchOne('SELECT @@port p,DATABASE() d');
if((int)$identity['p']!==33671||!preg_match('/^lobby_[0-9]+$/D',$identity['d']))throw new RuntimeException('Wrong lab');
$repo=new GameRepository($db);$journal=new CommandJournalRepository($db);$game=(int)($argv[1]??0);$s=$repo->findById($game);if(!$s||$s->status!=='battle')throw new RuntimeException('Live lab game required');
$defs=[];foreach(json_decode(file_get_contents(__DIR__.'/complex-s1-fixture.json'),true,512,JSON_THROW_ON_ERROR)as$d)$defs[$d['ukid']]=$d;
$entry=$journal->registerProcessing($game,'host','complex-http-fixture','complex_fixture',[],$s->persistenceVersion);
$apply=static function(GameState $s)use($defs){
 $s->cards=[];$s->battle=['active'=>'host','turn'=>1,'strike'=>null];
 foreach([[1,'s1_54','host',3,3],[2,'s1_25','host',4,3],[3,'s1_42','host',1,1],[4,'s1_63','host',1,2],[5,'s1_128','player',6,5],[6,'s1_1','host',1,4]]as[$id,$key,$owner,$row,$col]){
  $d=$defs[$key];$s->addCard(new CardInstance(instanceId:$id,ukid:$key,owner:$owner,zone:'field',row:$row,col:$col,hp:(int)$d['health'],hpMax:(int)$d['health'],move:(int)$d['move'],moveMax:(int)$d['move'],prop:json_decode($d['prop']??'{}',true)?:[]));
 }
 $s->cards[1]->revealed=false;$s->cards[3]->zone='graveyard';$s->cards[3]->hp=0;$s->cards[3]->row=$s->cards[3]->col=null;$s->cards[6]->zone='deck';$s->cards[6]->row=$s->cards[6]->col=null;
 $s->historyRecorder->statusBaseline($s);
 $e=new Engine();$p=new ProphecyProcessor($s,$e);$p->commit('host',$s->cards[1],$p->peek('host',1),'simple','Пророчество',[['cmd'=>'close_prophecy']]);$p->close('host');
 (new \Berserk\Core\ValhallaProcessor($s,$e))->beginExecute($s->cards[3],'host');
 $r=$e->apply($s,'host',new Command('valhalla_pick',['target_id'=>2]));if(!$r->success)return$r;
 $r=$e->apply($s,'host',new Command('cancel_pending'));if(!$r->success)return$r;
 $s->historyRecorder->commandFinished($s,$r);return$r;
};
$admin=new Db(['host'=>'127.0.0.1:33671','user'=>'root','pass'=>'','name'=>$identity['d']]);$before=$db->fetchOne('SELECT json,version FROM games WHERE ind='.$game);$count=$db->fetchOne('SELECT COUNT(*) n FROM battle_history_events WHERE game_id='.$game);$failed=false;
$admin->query("ALTER TABLE battle_history_events ADD CONSTRAINT complex_fault CHECK(event_type <> 'prophecy.viewed' OR journal_id <> ".(int)$entry['id']." )");
try{$repo->executeLockedCommand($s,$journal,(int)$entry['id'],$apply);}catch(DbQueryException){$failed=true;}finally{$admin->query('ALTER TABLE battle_history_events DROP CONSTRAINT complex_fault');}
if(!$failed||$db->fetchOne('SELECT json,version FROM games WHERE ind='.$game)!==$before||$db->fetchOne('SELECT COUNT(*) n FROM battle_history_events WHERE game_id='.$game)!==$count)throw new RuntimeException('Status rollback failed');
$repo->executeLockedCommand($s,$journal,(int)$entry['id'],$apply);
$rows=$db->fetchAll('SELECT event_type,host_json,player_json FROM battle_history_events WHERE journal_id='.(int)$entry['id'].' ORDER BY event_seq');
foreach(['prophecy.viewed'=>1,'chain.applied'=>1,'chain.stopped'=>1,'coins.changed'=>1,'combat.wounds'=>1]as$type=>$expected)if(count(array_filter($rows,fn($r)=>$r['event_type']===$type))!==$expected)throw new RuntimeException('Missing or duplicate '.$type);
$private=array_values(array_filter($rows,fn($r)=>$r['event_type']==='prophecy.viewed'))[0];if($private['player_json']!==null||json_decode($private['host_json'],true)['cards']!==['Кочевник'])throw new RuntimeException('Private prophecy leaked or lost');
$retry=$journal->registerProcessing($game,'host','complex-http-fixture','complex_fixture',[],$s->persistenceVersion);if($retry['is_new']||$retry['status']!=='committed')throw new RuntimeException('Retry not committed');
echo json_encode(['status'=>'PASS','rollback'=>true,'retry'=>true,'types'=>array_column($rows,'event_type')]);
