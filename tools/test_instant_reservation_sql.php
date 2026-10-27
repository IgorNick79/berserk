<?php
declare(strict_types=1);
// Dedicated disposable server only. Never loads application credentials.
if (PHP_SAPI !== 'cli' || !in_array('--isolated', $argv, true)) throw new RuntimeException('Use --isolated');
define('IR_FIXTURES_ONLY', true);
require __DIR__ . '/test_instant_reservation.php';
use Berserk\Core\{Db,GameRepository,CommandJournalRepository,GameState,Command,Engine,InstantReservation as IR,StaleStateException,CommandJournalException};
$data = getenv('IR_TEST_DATADIR') ?: '';
$root = getenv('IR_TEST_ROOT') ?: '';
if (!is_dir($root) || realpath($data) === false
    || !str_ends_with(str_replace('\\','/',strtolower(realpath($data))), '/instant-reservation-stage2-validation/mysql-data')) throw new RuntimeException('Disposable datadir required');
$raw = new mysqli('127.0.0.1', 'root', '', '', 33992);
$proof = $raw->query('SELECT @@datadir dir,@@port port,VERSION() version')->fetch_assoc();
irCheck(realpath($proof['dir']) === realpath($data) && (int)$proof['port'] === 33992, 'Verified isolated server');

if (($argv[1] ?? '') === '--worker') {
    $job=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);
    if (!preg_match('/^berserk_ir_[a-f0-9]+$/D',$job['schema'])) throw new RuntimeException('Invalid test schema');
    $db=new Db(['host'=>'127.0.0.1:33992','user'=>'root','pass'=>'','name'=>$job['schema']]);
    $repo=new GameRepository($db);$journal=new CommandJournalRepository($db);$s=$repo->findById($job['game']);
    $entry=$journal->registerProcessing($s->gameId,$job['actor'],$job['id'],$job['type'],$job['payload'],$job['version']);
    echo "READY\n";flush();fgets(STDIN);
    $calls=0;
    try {
        if (!$entry['is_new']) { echo json_encode(['status'=>'duplicate_'.$entry['status'],'calls'=>0])."\n";exit; }
        $repo->executeLockedCommand($s,$journal,(int)$entry['id'],function(GameState $w)use($job,&$calls) {
            ++$calls;
            if ($job['hold'] ?? false) {echo "LOCKED\n";flush();fgets(STDIN);}
            return irEngine()->apply($w,$job['actor'],new Command($job['type'],$job['payload']));
        });
    } catch (StaleStateException) {$fresh=$repo->findById($s->gameId);$journal->markConflict((int)$entry['id'],$fresh->persistenceVersion,'stale_version');}
    catch(CommandJournalException) { /* Same-ID command was committed by another worker. */ }
    echo json_encode(['status'=>$journal->findById((int)$entry['id'])['status'],'calls'=>$calls])."\n";exit;
}

$schema='berserk_ir_'.bin2hex(random_bytes(5));$raw->query("CREATE DATABASE $schema CHARACTER SET utf8mb4");$raw->select_db($schema);$raw->set_charset('utf8mb4');
function irSqlBatch(mysqli $m,string $sql):void {$m->multi_query($sql);do {if($r=$m->store_result())$r->free();if(!$m->more_results())break;}while($m->next_result());}
irSqlBatch($raw,"CREATE TABLE games(ind INT PRIMARY KEY,host_id INT,player_id INT,status VARCHAR(32),json LONGTEXT,version INT NOT NULL DEFAULT 0,date DATETIME,active INT) ENGINE=InnoDB;
CREATE TABLE elements(ind INT PRIMARY KEY,code VARCHAR(32),name VARCHAR(64)) ENGINE=InnoDB;
CREATE TABLE cards(ukid VARCHAR(40) PRIMARY KEY,name VARCHAR(64),descr TEXT,rarity VARCHAR(20),price INT,health INT,move INT,elite INT,single INT,type VARCHAR(20),class VARCHAR(40),strike_weak INT,strike_medium INT,strike_strong INT,element_id INT,prop LONGTEXT) ENGINE=InnoDB;");
foreach(['20261021_command_journal.sql','20261020_battle_history.sql','20261021_auth.sql','20261021_lobby.sql','20261021_lobby_setup.sql'] as $file)irSqlBatch($raw,file_get_contents(__DIR__.'/migrations/'.$file));
$cfg=['host'=>'127.0.0.1','port'=>33992,'user'=>'root','pass'=>'','name'=>$schema];
$auth=new \Berserk\Auth\AuthStore($cfg);$host=$auth->register('reservation_host','SyntheticReservationPassword1');$player=$auth->register('reservation_player','SyntheticReservationPassword1');
$db=new Db(array_replace($cfg,['host'=>'127.0.0.1:33992']));$repo=new GameRepository($db);$journal=new CommandJournalRepository($db);
foreach($db->fetchAll('SHOW TABLE STATUS')as$table)irCheck(strtolower($table['Engine'])==='innodb','InnoDB '.$table['Name']);
$seedId=1000;
function irSqlSeed():GameState {
    global $raw,$repo,$host,$player,$seedId,$db;
    $s=irState();$s->gameId=++$seedId;$s->hostId=$host->id;$s->playerId=$player->id;
    $s->players['host']->userId=$host->id;$s->players['player']->userId=$player->id;
    $s->journalSchema=1;
    // Wall-clock-safe fixture for the actual HTTP controller as well as fixed-clock workers.
    $s->battle['turn_timer']['anchor_at']=time();
    $json=$raw->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));
    $raw->query("INSERT INTO games(ind,host_id,player_id,status,json,version) VALUES($s->gameId,$host->id,$player->id,'battle','$json',0)");
    $db->beginTransaction();(new \Berserk\Core\BattleHistory\HistoryRepository($db))->initialize($s->gameId);$db->commit();
    $raw->query("INSERT INTO lobby_rooms(creator_account_id,accepted_account_id,game_id,state,created_at,updated_at,game_type) VALUES($host->id,$player->id,$s->gameId,'started',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'system')");
    return $repo->findById($s->gameId);
}
function irJob(GameState $s,string $actor,string $type,array $payload=[],bool $hold=false,?string $id=null):array {
    global $schema;return ['schema'=>$schema,'game'=>$s->gameId,'actor'=>$actor,'type'=>$type,'payload'=>$payload,'version'=>$s->persistenceVersion,'id'=>$id??'irsql:'.bin2hex(random_bytes(8)),'hold'=>$hold];
}
function irWorker(array $job):array {
    $p=proc_open([PHP_BINARY,__FILE__,'--worker',base64_encode(json_encode($job)),'--isolated'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    foreach($pipes as$pipe)stream_set_timeout($pipe,15);
    irCheck(trim(fgets($pipes[1])?:'')==='READY','Worker ready');return[$p,$pipes];
}
function irGo(array $worker):void {fwrite($worker[1][0],"GO\n");fflush($worker[1][0]);}
function irFinish(array $worker):array {[$p,$pipes]=$worker;fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);irCheck($exit===0 && $err==='',"Worker error $err $out");return json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);}
function irSqlCommand(GameState $s,string $actor,string $type,array $payload=[]):GameState {
    global $repo,$journal;$job=irJob($s,$actor,$type,$payload);$entry=$journal->registerProcessing($s->gameId,$actor,$job['id'],$type,$payload,$s->persistenceVersion);
    $repo->executeLockedCommand($s,$journal,(int)$entry['id'],fn($w)=>irEngine()->apply($w,$actor,new Command($type,$payload)));
    irCheck($journal->findById((int)$entry['id'])['status']==='committed','Native SQL command committed');return $repo->findById($s->gameId);
}
$evidence=['server'=>$proof,'schema'=>$schema,'races'=>[]];
foreach(['reserve_first','action_first']as$order) {
    $s=irSqlSeed();$reserve=irJob($s,'player','reserve_instant',['period_id'=>1],$order==='reserve_first');$action=irJob($s,'host','gain_coin',['card_id'=>1],$order==='action_first');
    $a=irWorker($order==='reserve_first'?$reserve:$action);irGo($a);
    irCheck(trim(fgets($a[1][1])?:'')==='LOCKED','First worker holds native game lock');
    $b=irWorker($order==='reserve_first'?$action:$reserve);irGo($b);irGo($a);
    $ar=irFinish($a);$br=irFinish($b);$s=$repo->findById($s->gameId);
    irCheck($ar['status']==='committed' && $br['status']==='conflict' && $br['calls']===0,'Stale competing command never reaches Engine');
    if($order==='reserve_first') {
        irCheck(IR::snapshot($s)['status']==='requested' && IR::snapshot($s)['used']===0 && $s->getCard(1)->coins===0,'Reserve accepted before action, no premature grant');
        $s=irSqlCommand($s,'host','gain_coin',['card_id'=>1]);
        irCheck(IR::snapshot($s)['status']==='granted' && IR::snapshot($s)['used']===1,'Fresh action grants once');
    } else {
        irCheck($s->getCard(1)->coins===1 && IR::snapshot($s)['status']==='idle','Accepted action cannot be interrupted by later reserve');
        $s=irSqlCommand($s,'player','reserve_instant',['period_id'=>1]);
        irCheck(IR::snapshot($s)['status']==='requested' && IR::snapshot($s)['used']===0,'Later reserve waits for another action');
    }
    $evidence['races'][$order]=[$ar,$br];
}

// Truly overlapping same-ID requests: second sees processing, replay sees committed.
$s=irSqlSeed();$job=irJob($s,'player','reserve_instant',['period_id'=>1],true,'same-reserve');
$a=irWorker($job);irGo($a);irCheck(trim(fgets($a[1][1])?:'')==='LOCKED','Duplicate race lock');
$b=irWorker($job);irGo($b);$br=irFinish($b);irGo($a);$ar=irFinish($a);
irCheck($br['status']==='duplicate_processing' && $br['calls']===0 && $ar['status']==='committed','Concurrent duplicate does not execute');
$c=irWorker($job);irGo($c);$cr=irFinish($c);irCheck($cr['status']==='duplicate_committed' && $cr['calls']===0,'Committed replay does not execute');
$s=$repo->findById($s->gameId);irCheck($s->persistenceVersion===1 && IR::snapshot($s)['generation']===1,'Duplicate persisted once');
$evidence['races']['duplicate']=[$ar,$br,$cr];

foreach(['cancel_first','grant_first']as$order) {
    $s=irSqlCommand(irSqlSeed(),'player','reserve_instant',['period_id'=>1]);$id=IR::snapshot($s)['request']['id'];
    $cancel=irJob($s,'player','cancel_instant_reservation',['request_id'=>$id],$order==='cancel_first');$action=irJob($s,'host','gain_coin',['card_id'=>1],$order==='grant_first');
    $a=irWorker($order==='cancel_first'?$cancel:$action);irGo($a);irCheck(trim(fgets($a[1][1])?:'')==='LOCKED','Cancel/action lock');
    $b=irWorker($order==='cancel_first'?$action:$cancel);irGo($b);irGo($a);$ar=irFinish($a);$br=irFinish($b);$s=$repo->findById($s->gameId);
    irCheck($ar['status']==='committed' && $br['status']==='conflict','Cancel/grant race serialized');
    irCheck(IR::snapshot($s)['used']===($order==='grant_first'?1:0),'Budget follows committed order');
    if($order==='grant_first') {$s=irSqlCommand($s,'player','decline_instant_priority',['request_id'=>$id]);irCheck(IR::snapshot($s)['used']===1,'SQL decline never refunds');}
    $evidence['races'][$order]=[$ar,$br];
}

// Write failure rolls back stack + quota + command terminal status together.
$s=irSqlCommand(irSqlSeed(),'player','reserve_instant',['period_id'=>1]);$before=$db->fetchOne('SELECT json,version FROM games WHERE ind='.$s->gameId);
$job=irJob($s,'host','gain_coin',['card_id'=>1]);$entry=$journal->registerProcessing($s->gameId,'host',$job['id'],$job['type'],$job['payload'],$s->persistenceVersion);
try {$repo->executeLockedCommand($s,$journal,(int)$entry['id'],function($w){$r=irEngine()->apply($w,'host',new Command('gain_coin',['card_id'=>1]));$w->battle['invalid_json']=NAN;return$r;});throw new RuntimeException('Expected write failure');}
catch(TypeError $e) {irCheck(str_contains($e->getMessage(),'Db::escape'),'Expected invalid JSON failure');}
irCheck($db->fetchOne('SELECT json,version FROM games WHERE ind='.$s->gameId)===$before && $journal->findById((int)$entry['id'])['status']==='processing','Atomic rollback includes grant/quota/journal');
$repo->executeLockedCommand($s,$journal,(int)$entry['id'],fn($w)=>irEngine()->apply($w,'host',new Command('gain_coin',['card_id'=>1])));
$s=$repo->findById($s->gameId);irCheck(IR::snapshot($s)['used']===1 && $s->getCard(1)->coins===1,'Retry commits one grant/action');

$s=irSqlSeed();$s=irSqlCommand($s,'player','reserve_instant',['period_id'=>1]);
$s=irSqlCommand($s,'player','cancel_instant_reservation',['request_id'=>IR::snapshot($s)['request']['id']]);
$s=irSqlCommand($s,'player','reserve_instant',['period_id'=>1]);$s=irSqlCommand($s,'host','gain_coin',['card_id'=>1]);
$s=irSqlCommand($s,'player','decline_instant_priority',['request_id'=>IR::snapshot($s)['request']['id']]);
irCheck((int)$db->fetchOne("SELECT COUNT(*) n FROM battle_history_events e JOIN battle_command_journal j ON j.id=e.journal_id WHERE e.game_id=$s->gameId AND e.event_type='recording_gap.started' AND j.command_type IN ('reserve_instant','cancel_instant_reservation','decline_instant_priority')")['n']===0,'Technical reservation commands do not create history gaps');
irCheck((int)$db->fetchOne("SELECT COUNT(*) n FROM battle_history_batches WHERE game_id=$s->gameId")['n']===5,'Mandatory history commits with all five commands');

// Prepare an isolated application copy for two real HTTP controller processes.
$app=$root.'/app';irCheck(is_file($app.'/www/index.php') && !file_exists($app.'/.git'),'Disposable HTTP copy');
$gameConfig=array_replace($cfg,['host'=>'127.0.0.1:33992']);
file_put_contents($app.'/config/db.php','<?php return '.var_export($gameConfig,true).';');
file_put_contents($app.'/config/auth.local.php','<?php return '.var_export(['db'=>$cfg,'lobby_db'=>$cfg,'limiter_secret'=>str_repeat('a',64),'allow_loopback_http'=>true],true).';');
$sessions=$root.'/sessions';if(!is_dir($sessions))mkdir($sessions);
$tokens=[];foreach(['host'=>$host->id,'player'=>$player->id]as$side=>$account){session_save_path($sessions);session_name('BERSERKAUTH5A');session_id(bin2hex(random_bytes(16)));session_start();$_SESSION=['berserk_authenticated_user_id'=>$account,'auth_csrf'=>bin2hex(random_bytes(32))];$tokens[$side]=['cookie'=>'BERSERKAUTH5A='.session_id(),'csrf'=>$_SESSION['auth_csrf']];session_write_close();}
$s=irSqlSeed();$evidence['http_fixture']=['game_id'=>$s->gameId,'version'=>$s->persistenceVersion,'sessions'=>$tokens];
$evidence['checks']=$irChecks;file_put_contents($root.'/sql-evidence.json',json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "Isolated InnoDB reservation tests: $irChecks checks passed; $schema\n";
