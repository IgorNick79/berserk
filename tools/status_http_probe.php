<?php
declare(strict_types=1);
if(file_exists(__DIR__.'/../.git'))throw new RuntimeException('Disposable copy only');
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository,GameState,CardInstance,Engine,Command,Result,Dice,ZoneManager,ProphecyProcessor,DbQueryException};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$db=new Db(require __DIR__.'/../config/db.php');$identity=$db->fetchOne('SELECT @@port p,DATABASE() d');
if((int)$identity['p']!==33671||!preg_match('/^lobby_[0-9]+$/D',$identity['d']))throw new RuntimeException('Wrong lab');
$repo=new GameRepository($db);$journal=new CommandJournalRepository($db);$game=(int)($argv[1]??0);$s=$repo->findById($game);if(!$s||$s->status!=='battle')throw new RuntimeException('Live lab game required');
$defs=[];foreach(json_decode(file_get_contents(__DIR__.'/status-s1-fixture.json'),true,512,JSON_THROW_ON_ERROR)as$d)$defs[$d['ukid']]=$d;
$entry=$journal->registerProcessing($game,'host','status-http-fixture','status_fixture',[],$s->persistenceVersion);
$apply=static function(GameState $s)use($defs){
 $s->cards=[];$s->battle=['active'=>'host','turn'=>1,'strike'=>null];
 foreach([[1,'s1_25','host',3,3],[2,'s1_128','host',4,3],[3,'s1_89','host',1,1],[4,'s1_31','host',1,2],[5,'s1_1','player',6,5]]as[$id,$key,$owner,$row,$col]){
  $d=$defs[$key];$s->addCard(new CardInstance(instanceId:$id,ukid:$key,owner:$owner,zone:'field',row:$row,col:$col,hp:(int)$d['health'],hpMax:(int)$d['health'],move:(int)$d['move'],moveMax:(int)$d['move'],prop:json_decode($d['prop']??'{}',true)?:[]));
 }
 $s->cards[2]->closed=true;$s->cards[4]->revealed=false;
 $_SESSION=['debug_roll'=>'4,6,2'];Dice::init();$e=new Engine();
 $r=$e->apply($s,'host',new Command('gain_coin',['card_id'=>1]));if(!$r->success)return$r;
 $r=$e->apply($s,'host',new Command('action',['card_id'=>3,'target_id'=>3,'action_key'=>'empower']));if(!$r->success)return$r;
 $e->applyInstantEffect($s,$s->cards[4]->prop['instants'][0]['effect'],$s->cards[4],$s->cards[2],'host');
 $e->applyMarker($s->cards[2],['type'=>'hunt','expire'=>1],'host');
 $s->historyRecorder->commandFinished($s,$r);return$r;
};
$admin=new Db(['host'=>'127.0.0.1:33671','user'=>'root','pass'=>'','name'=>$identity['d']]);$before=$db->fetchOne('SELECT json,version FROM games WHERE ind='.$game);$count=$db->fetchOne('SELECT COUNT(*) n FROM battle_history_events WHERE game_id='.$game);$failed=false;
$admin->query("ALTER TABLE battle_history_events ADD CONSTRAINT status_fault CHECK(event_type <> 'coins.changed' OR journal_id <> ".(int)$entry['id']." )");
try{$repo->executeLockedCommand($s,$journal,(int)$entry['id'],$apply);}catch(DbQueryException){$failed=true;}finally{$admin->query('ALTER TABLE battle_history_events DROP CONSTRAINT status_fault');}
if(!$failed||$db->fetchOne('SELECT json,version FROM games WHERE ind='.$game)!==$before||$db->fetchOne('SELECT COUNT(*) n FROM battle_history_events WHERE game_id='.$game)!==$count)throw new RuntimeException('Status rollback failed');
$repo->executeLockedCommand($s,$journal,(int)$entry['id'],$apply);
$rows=$db->fetchAll('SELECT event_type,host_json,player_json FROM battle_history_events WHERE journal_id='.(int)$entry['id'].' ORDER BY event_seq');
foreach(['coins.changed'=>1,'modifier.changed'=>2,'status.changed'=>1,'marker.changed'=>1,'combat.wounds'=>1]as$type=>$expected)if(count(array_filter($rows,fn($r)=>$r['event_type']===$type))!==$expected)throw new RuntimeException('Missing or duplicate '.$type);
$status=array_values(array_filter($rows,fn($r)=>$r['event_type']==='status.changed'))[0];if($status['player_json']===null||str_contains($status['player_json'],'Ойуун')||isset(json_decode($status['player_json'],true)['source_id']))throw new RuntimeException('Hidden source suppresses or leaks public status');
$retry=$journal->registerProcessing($game,'host','status-http-fixture','status_fixture',[],$s->persistenceVersion);if($retry['is_new']||$retry['status']!=='committed')throw new RuntimeException('Retry not committed');
echo json_encode(['status'=>'PASS','rollback'=>true,'retry'=>true,'types'=>array_column($rows,'event_type')]);
