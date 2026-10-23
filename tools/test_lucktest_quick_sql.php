<?php
declare(strict_types=1);
// Explicit disposable-server-only integration test. Never loads application DB credentials.
if (PHP_SAPI!=='cli' || !in_array('--isolated',$argv,true)) {fwrite(STDERR,"Use --isolated with LUCKTEST_SQL_PASSWORD on the disposable port 33987\n");exit(2);}
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,Command,CommandJournalRepository,Dice,Engine,GameRepository,GameState,StaleStateException};
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\Core\BattleHistory\{HistoryRuntime};
use Berserk\Lobby\{MatchCreator,RoomSetup,RoomTransaction};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$raw=new mysqli('127.0.0.1','root',getenv('LUCKTEST_SQL_PASSWORD')?:'', '',33987);
$identity=$raw->query('SELECT @@port AS port,@@datadir AS dir')->fetch_assoc();
if ((int)$identity['port']!==33987 || !str_ends_with(strtolower(str_replace('\\','/',$identity['dir'])),'/lucktest-stage3/sql-data/'))throw new RuntimeException('Not the disposable Stage 3 server');
$schema='berserk_lucktest_stage3_'.bin2hex(random_bytes(5));$raw->query("CREATE DATABASE $schema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$raw->select_db($schema);$raw->set_charset('utf8mb4');
function sqlBatch(mysqli $raw,string $sql):void{$raw->multi_query($sql);do{if($r=$raw->store_result())$r->free();if(!$raw->more_results())break;}while($raw->next_result());}
sqlBatch($raw,"CREATE TABLE games(ind INT NOT NULL AUTO_INCREMENT PRIMARY KEY,host_id INT NOT NULL,player_id INT NOT NULL,status VARCHAR(32) NOT NULL,json LONGTEXT NOT NULL,version INT NOT NULL DEFAULT 0,date DATETIME,active INT) ENGINE=InnoDB;
CREATE TABLE accounts(id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB; INSERT INTO accounts VALUES(11),(22);
CREATE TABLE elements(ind INT PRIMARY KEY,code VARCHAR(32));INSERT INTO elements VALUES(1,'forests');
CREATE TABLE cards(ukid VARCHAR(40) PRIMARY KEY,price INT,health INT,move INT,elite INT,single INT,type VARCHAR(20),class VARCHAR(40),strike_weak INT,strike_medium INT,strike_strong INT,element_id INT,prop LONGTEXT);
INSERT INTO cards VALUES('army',7,8,2,1,1,'creature','Воин',1,3,5,1,'{}');");
foreach(['20261021_command_journal.sql','20261020_battle_history.sql','20261021_lobby.sql','20261021_lobby_setup.sql'] as $migration)sqlBatch($raw,file_get_contents(__DIR__.'/migrations/'.$migration));
ini_set('mysqli.default_port','33987');$db=new Db(['host'=>'127.0.0.1','user'=>'root','pass'=>getenv('LUCKTEST_SQL_PASSWORD')?:'','name'=>$schema]);
$checks=0;function verify(bool $ok,string $why):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($why);}
$catalog=['army'=>['revision'=>'test','name'=>'Army','image'=>'test/army.jpg','description'=>'fixture','type'=>'creature','element'=>'forests','classes'=>['Воин'],'price'=>7]];
function rolls():void{$_SESSION['debug_roll']='4,4,6,1';unset($_SESSION['debug_roll_state']);Dice::init();}
function seed(Db $db):GameState{
    $repo=new GameRepository($db);$s=$repo->create(11,22);$j=new CommandJournalRepository($db);
    $cmd=new Command('choose_mode',['mode'=>'lucktest']);$entry=$j->registerProcessing($s->gameId,'host','seed-mode',$cmd->type,$cmd->payload,$s->persistenceVersion);
    $repo->executeLockedCommand($s,$j,(int)$entry['id'],fn($w)=>(new Engine())->apply($w,'host',$cmd));
    return $repo->findById($s->gameId);
}
// Lab-only position setup for the existing final-placement boundary test.
function fixtureSave(Db $db,GameState $s):void{$db->query("UPDATE games SET json='".$db->escape(json_encode($s->toArray(),JSON_THROW_ON_ERROR))."',status='".$db->escape($s->status)."',version=version+1 WHERE ind=".$s->gameId);$fresh=(new GameRepository($db))->findById($s->gameId);$s->persistenceJson=$fresh->persistenceJson;$s->persistenceVersion=$fresh->persistenceVersion;}
function recording(Db $db,int $id):GameRepository{global $catalog;return new GameRepository($db,$catalog);}
function register(CommandJournalRepository $j,GameState $s,string $key):array{return $j->registerProcessing($s->gameId,'host',$key,'confirm_settings',[],$s->persistenceVersion);}
function initialCount(Db $db,int $id):int{return (int)$db->fetchOne("SELECT COUNT(*) n FROM battle_history_steps WHERE game_id=$id AND is_initial=1")['n'];}
function runQuick(GameRepository $repo,Db $db,CommandJournalRepository $j,GameState $s,int $jid,?Closure $random=null):void{rolls();$repo->executeLockedCommand($s,$j,$jid,fn($locked)=>(new Engine($db,null,$random??fn($min,$max)=>$min))->apply($locked,'host',new Command('confirm_settings')));}
$j=new CommandJournalRepository($db);$s=seed($db);$repo=recording($db,$s->gameId);$entry=register($j,$s,'success');$stale=$repo->findById($s->gameId);
runQuick($repo,$db,$j,$s,(int)$entry['id']);$saved=$repo->findById($s->gameId);
verify($saved->status==='battle' && count($saved->cards)===60 && $saved->persistenceVersion===$s->persistenceVersion+1,'durable complete quick Battle');
verify($j->findById((int)$entry['id'])['status']==='committed' && initialCount($db,$s->gameId)===1,'journal and initial committed');
$again=register($j,$s,'success');verify(!$again['is_new'] && $again['status']==='committed','duplicate command id idempotent');
$entry=register($j,$stale,'stale');try{runQuick($repo,$db,$j,$stale,(int)$entry['id']);verify(false,'stale accepted');}catch(StaleStateException){verify(initialCount($db,$s->gameId)===1,'stale cannot create new initial');}
$before=$saved->toArray();verify(!(new Engine($db))->apply($saved,'host',new Command('confirm_settings'))->success && $saved->toArray()['cards']===$before['cards'] && $saved->status==='battle' && $saved->battle['turn']===1,'refresh/repeated launch preserves battle');
// Existing manual final-Place journal boundary remains valid on an owned transaction.
$manual=seed($db);$plain=new GameRepository($db);rolls();
verify((new Engine($db))->apply($manual,'host',new Command('confirm_settings',['lucktest_quick_game'=>'0']))->success && $manual->status==='turn','manual stays in Turn');
$processor=new PrepareProcessor($manual,$db);$processor->confirmTurn('host');$processor->confirmTurn('player');$processor->chooseSide($manual->firstPlayer,new Command('choose_side',['side'=>1]));
foreach(['host','player'] as $owner)foreach($manual->getCardsInZone($owner,'squad') as $card)foreach(array_merge(...\Berserk\Core\ZoneManager::cellLevels($owner)) as $cell){[$row,$col]=array_map('intval',explode('_',$cell));$zone=new \Berserk\Core\ZoneManager($manual);if($zone->isCellAllowed($owner,$row,$col) && !$zone->isFieldOccupied($row,$col)){verify($processor->placeCard($owner,new Command('place_card',['card_id'=>$card->instanceId,'row'=>$row,'col'=>$col]))->success,'manual place');break;}}
$processor->confirmPlace('host');fixtureSave($db,$manual);$repo=recording($db,$manual->gameId);$entry=$j->registerProcessing($manual->gameId,'player','manual-final','confirm_place',[],$manual->persistenceVersion);
$repo->executeLockedCommand($manual,$j,(int)$entry['id'],fn($locked)=>(new Engine($db))->apply($locked,'player',new Command('confirm_place')));
verify($repo->findById($manual->gameId)->status==='battle' && initialCount($db,$manual->gameId)===1 && $j->findById((int)$entry['id'])['status']==='committed','manual final Place still uses native history transaction');
// Fail the history append after the game UPDATE: whole existing transaction rolls back.
$s=seed($db);$repo=recording($db,$s->gameId);$entry=register($j,$s,'append-failure');$before=$s->persistenceJson;
$raw->query("CREATE TRIGGER fail_history BEFORE INSERT ON battle_history_steps FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected history failure'");
try{runQuick($repo,$db,$j,$s,(int)$entry['id']);verify(false,'append failure accepted');}catch(\Berserk\Core\DbQueryException){$loaded=$repo->findById($s->gameId);verify($loaded->persistenceJson===$before && $loaded->status==='settings' && $loaded->cards===[],'game UPDATE rolled back');verify($db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id='.$s->gameId)!==null && initialCount($db,$s->gameId)===0,'history metadata and captures rolled back');verify($j->findById((int)$entry['id'])['status']==='processing','journal not falsely committed');}finally{$raw->query('DROP TRIGGER fail_history');}
runQuick($repo,$db,$j,$s,(int)$entry['id']);verify(initialCount($db,$s->gameId)===1,'retry commits exactly one initial');
// A failed game UPDATE must not commit a history header or journal status either.
$s=seed($db);$repo=recording($db,$s->gameId);$entry=register($j,$s,'write-failure');$before=$s->persistenceJson;
$raw->query("CREATE TRIGGER fail_game BEFORE UPDATE ON games FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected save failure'");
try{runQuick($repo,$db,$j,$s,(int)$entry['id']);verify(false,'write failure accepted');}
catch(\Berserk\Core\DbQueryException){verify($repo->findById($s->gameId)->persistenceJson===$before,'save failure retains durable original');verify($db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id='.$s->gameId)!==null && initialCount($db,$s->gameId)===0,'save failure rolls back history header');verify($j->findById((int)$entry['id'])['status']==='processing','save failure cannot mark committed');}
finally{$raw->query('DROP TRIGGER fail_game');}
// Controlled failure during placement and after startBattle has made a transient initial.
foreach(['placement','start','invalid_catalog'] as $failure){$s=seed($db);$repo=recording($db,$s->gameId);$entry=register($j,$s,'reject-'.$failure);
if($failure==='invalid_catalog')$raw->query("UPDATE cards SET prop='invalid-json'");
if($failure==='start')$raw->query("UPDATE cards SET prop='".$raw->real_escape_string(json_encode(['start'=>['type'=>'get_hp','hp'=>PHP_INT_MAX]]))."'");
try{runQuick($repo,$db,$j,$s,(int)$entry['id'],$failure==='placement'?fn()=>throw new RuntimeException('injected placement fault'):null);$loaded=$repo->findById($s->gameId);verify($loaded->status==='settings' && $loaded->cards===[] && $loaded->persistenceVersion===$s->persistenceVersion,'controlled failure leaves durable Settings');verify($j->findById((int)$entry['id'])['status']==='rejected' && initialCount($db,$s->gameId)===0,'failed initial discarded and journal rejected');}finally{$raw->query("UPDATE cards SET prop='{}'");}}
// Scoped adapter may query but cannot commit or outlive the caller's transaction.
$raw->begin_transaction();$escaped=null;Db::withTransaction($raw,function(Db $borrowed)use(&$escaped){$escaped=$borrowed;verify($borrowed->inTransaction() && $borrowed->isBorrowedTransaction(),'borrowed scope active');foreach(['beginTransaction','commit','rollback'] as $method){try{$borrowed->$method();verify(false,'borrowed transaction ownership bypass');}catch(LogicException){verify(true,'caller retains transaction ownership');}}});$raw->rollback();try{$escaped->query('SELECT 1');verify(false,'escaped scope');}catch(LogicException){verify(true,'expired adapter cannot query');}
// Actual MatchCreator + RoomTransaction + history path, including rollback after its successful inner work.
$configDir=__DIR__.'/../config';if(!is_dir($configDir))mkdir($configDir);$historyConfig=$configDir.'/battle_history.local.php';if(is_file($historyConfig))throw new RuntimeException('Refuse to overwrite history config');
try{foreach(['commit','outer-failure','history-failure','manual'] as $scenario){$next=(int)$db->fetchOne("SELECT AUTO_INCREMENT n FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_NAME='games'")['n'];
file_put_contents($historyConfig,'<?php return '.var_export(['catalog'=>$catalog],true).';');
$setup=RoomSetup::parse('lucktest',['lucktest_quick_game'=>$scenario==='manual'?'0':'1','lucktest_card_count'=>'10']);$json=$raw->real_escape_string(json_encode($setup));$token=str_repeat('a',64);
$raw->query("INSERT INTO lobby_rooms(creator_account_id,candidate_account_id,candidate_request_id,state,created_at,updated_at,game_type,setup_json) VALUES(11,22,'$token','awaiting_confirmation',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'lucktest','$json')");$room=(int)$raw->insert_id;
if($scenario==='history-failure')$raw->query("CREATE TRIGGER fail_history BEFORE INSERT ON battle_history_steps FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected lobby history failure'");
try{rolls();RoomTransaction::run($raw,function(RoomTransaction $tx)use($raw,$room,$scenario,$db,&$created){$created=MatchCreator::create($tx,$room,11,22,fn()=>$db);verify($db->fetchOne('SELECT * FROM games WHERE ind='.$created)===null,'no game visible before outer commit');verify($db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id='.$created)===null,'no history visible before outer commit');if($scenario==='outer-failure')throw new RuntimeException('injected outer failure');$raw->query("UPDATE lobby_rooms SET state='started',candidate_account_id=NULL,candidate_request_id=NULL,accepted_account_id=22,game_id=$created WHERE id=$room");});verify(in_array($scenario,['commit','manual'],true),'unexpected lobby success');$loaded=(new GameRepository($db))->findById($created);verify($loaded->status===($scenario==='manual'?'turn':'battle') && count($loaded->cards)===60,'Lobby state committed');verify(initialCount($db,$created)===($scenario==='manual'?0:1),'Lobby native initial policy');verify($db->fetchOne("SELECT game_id FROM lobby_rooms WHERE id=$room")['game_id']==$created,'room linked');}
catch(Throwable $e){if(!in_array($scenario,['outer-failure','history-failure'],true))throw $e;verify($scenario==='outer-failure' ? $e->getMessage()==='injected outer failure' : $e instanceof \Berserk\Core\DbQueryException,'expected Lobby failure');verify($db->fetchOne("SELECT * FROM games WHERE ind=$next")===null,'Lobby game insert rolled back');verify($db->fetchOne("SELECT * FROM battle_history_matches WHERE game_id=$next")===null && initialCount($db,$next)===0,'Lobby history rolled back');verify($db->fetchOne("SELECT state FROM lobby_rooms WHERE id=$room")['state']==='awaiting_confirmation','room remains pending');}
finally{if($scenario==='history-failure')$raw->query('DROP TRIGGER fail_history');}}
}finally{unlink($historyConfig);}
echo "LuckTest SQL: $checks checks passed; isolated MariaDB ".$identity['port']."; schema $schema\n";
