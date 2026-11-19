<?php
declare(strict_types=1);
if(file_exists(__DIR__.'/../.git'))throw new RuntimeException('Isolated only');
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository,Command,Result,CardInstance,CardHealing,Engine};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$db=new Db(require __DIR__.'/../config/db.php');$identity=$db->fetchOne('SELECT @@port p,DATABASE() d');
if((int)$identity['p']!==33671||!preg_match('/^lobby_[0-9]+$/D',$identity['d']))throw new RuntimeException('Wrong lab');
$repo=new GameRepository($db);$s=$repo->create(1,2);$journal=new CommandJournalRepository($db);$entry=$journal->registerProcessing($s->gameId,'host','health-atomic','health_fixture',[],$s->persistenceVersion);
$apply=static function($s){$s->historyRecorder->commandStarted($s,'host',new Command('health_fixture'));$c=new CardInstance(instanceId:1,ukid:'card_1',owner:'host',zone:'field',row:3,col:1,hp:4,hpMax:10);$s->addCard($c);(new CardHealing($s,new Engine()))->begin($c,$c,3,'heal');$result=Result::ok();$s->historyRecorder->commandFinished($s,$result);return$result;};
$admin=new Db(['host'=>'127.0.0.1:33671','user'=>'root','pass'=>'','name'=>$identity['d']]);
$before=$db->fetchOne('SELECT json,version FROM games WHERE ind='.$s->gameId);
$admin->query("ALTER TABLE battle_history_events ADD CONSTRAINT health_fault CHECK(event_type <> 'card.healed')");$failed=false;
try{$repo->executeLockedCommand($s,$journal,(int)$entry['id'],$apply);}catch(Berserk\Core\DbQueryException){$failed=true;}finally{$admin->query('ALTER TABLE battle_history_events DROP CONSTRAINT health_fault');}
if(!$failed||$db->fetchOne('SELECT json,version FROM games WHERE ind='.$s->gameId)!==$before||$db->fetchOne('SELECT event_seq FROM battle_history_events WHERE game_id='.$s->gameId)!==null)throw new RuntimeException('Rollback failed');
$repo->executeLockedCommand($s,$journal,(int)$entry['id'],$apply);$rows=$db->fetchAll("SELECT event_seq,host_json,player_json FROM battle_history_events WHERE game_id=".$s->gameId." AND event_type='card.healed'");
if(count($rows)!==1||json_decode($rows[0]['host_json'],true)['amount']!==3)throw new RuntimeException('Not exactly one heal');
$retry=$journal->registerProcessing($s->gameId,'host','health-atomic','health_fixture',[],$s->persistenceVersion);
if($retry['is_new']||$retry['status']!=='committed')throw new RuntimeException('Retry not committed');
$runtime=Berserk\Core\BattleHistory\HistoryRuntime::load($db);$live=new Berserk\Core\BattleHistory\LiveJournal($db,$runtime);
foreach([1,2] as $user){$a=$live->read($s->gameId,['berserk_authenticated_user_id'=>$user]);$b=$live->read($s->gameId,['berserk_authenticated_user_id'=>$user]);if($a!==$b||count(array_filter($a['entries'],fn($e)=>str_contains($e['text'],'восстанавливает 3 HP'))) !== 1)throw new RuntimeException('Reload or projection failed');}
echo json_encode(['status'=>'PASS','rollback'=>true,'retry'=>true,'health_events'=>1,'reload_both'=>true]);
