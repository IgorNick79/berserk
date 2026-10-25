<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' || !in_array('--isolated',$argv,true) || is_dir(__DIR__.'/../.git'))throw new RuntimeException('Disposable CLI copy required');
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,GameState,Engine,Command};
use Berserk\Auth\AuthStore;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$sqlPort=(int)(getenv('LUCKTEST_SQL_PORT') ?: 33987);
$expectedData=getenv('LUCKTEST_SQL_DATADIR') ?: 'C:/Users/mi/Documents/Berserk/lucktest-stage3/sql-data';
$raw=new mysqli('127.0.0.1','root',getenv('LUCKTEST_SQL_PASSWORD'),'', $sqlPort);
$proof=$raw->query('SELECT @@port p,@@datadir d')->fetch_assoc();
if((int)$proof['p']!==$sqlPort || realpath($proof['d'])!==realpath($expectedData) || realpath($expectedData)===false)throw new RuntimeException('Wrong isolated server');
$root=dirname(__DIR__);$fixture=$root.'/debug-http-fixture.json';
if(in_array($argv[1]??'', ['inspect','fail_write','clear_failure'],true)){
    $data=json_decode(file_get_contents($fixture),true,512,JSON_THROW_ON_ERROR);if(!preg_match('/^berserk_debug_http_[a-f0-9]+$/D',$data['schema']))throw new RuntimeException('Invalid schema');
    $raw->select_db($data['schema']);$id=(int)($argv[2]??0);
    if($argv[1]==='fail_write'){$raw->query("CREATE TRIGGER debug_http_failure BEFORE UPDATE ON games FOR EACH ROW BEGIN IF NEW.ind=$id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HTTP SQL failure fixture'; END IF; END");echo '{}';exit;}
    if($argv[1]==='clear_failure'){$raw->query('DROP TRIGGER debug_http_failure');echo '{}';exit;}
    $row=$raw->query("SELECT json,version FROM games WHERE ind=$id")->fetch_assoc();echo json_encode($row,JSON_THROW_ON_ERROR);exit;
}
foreach(['auth.local.php','db.php','battle_history.local.php'] as $name)if(is_file($root.'/config/'.$name))throw new RuntimeException('Refuse to overwrite config');
$schema='berserk_debug_http_'.bin2hex(random_bytes(4));$raw->query("CREATE DATABASE $schema CHARACTER SET utf8mb4");$raw->select_db($schema);$raw->set_charset('utf8mb4');
function batchSql(mysqli $raw,string $sql):void{$raw->multi_query($sql);do{if($r=$raw->store_result())$r->free();if(!$raw->more_results())break;}while($raw->next_result());}
batchSql($raw,"CREATE TABLE games(ind INT AUTO_INCREMENT PRIMARY KEY,host_id INT NOT NULL,player_id INT NOT NULL,status VARCHAR(32) NOT NULL,json LONGTEXT NOT NULL,version INT NOT NULL DEFAULT 0,date DATETIME,active INT) ENGINE=InnoDB;
CREATE TABLE elements(ind INT PRIMARY KEY,code VARCHAR(32),name VARCHAR(64)); INSERT INTO elements VALUES(1,'forests','Forest');
CREATE TABLE cards(ukid VARCHAR(40) PRIMARY KEY,name VARCHAR(64),descr TEXT,rarity VARCHAR(20),price INT,health INT,move INT,elite INT,single INT,type VARCHAR(20),class VARCHAR(40),strike_weak INT,strike_medium INT,strike_strong INT,element_id INT,prop LONGTEXT);
INSERT INTO cards VALUES('army','Army','Synthetic card','common',7,8,2,1,1,'creature','Warrior',1,3,5,1,'{}');");
foreach(['20261021_auth.sql','20261021_command_journal.sql','20261020_battle_history.sql','20261021_lobby.sql','20261021_lobby_setup.sql','20261019_game_chat.sql'] as $migration)batchSql($raw,file_get_contents(__DIR__.'/migrations/'.$migration));
$connection=['host'=>'127.0.0.1','port'=>$sqlPort,'user'=>'root','pass'=>getenv('LUCKTEST_SQL_PASSWORD'),'name'=>$schema];
$auth=new AuthStore($connection);$password='SyntheticDebugPassword1';$host=$auth->register('debug_host',$password);$second=$auth->register('debug_second',$password);$ordinary=$auth->register('ordinary_user',$password);
$config=['db'=>$connection,'lobby_db'=>$connection,'limiter_secret'=>bin2hex(random_bytes(32)),'allow_loopback_http'=>true,'debug_account_ids'=>[$host->id,$second->id]];
file_put_contents($root.'/config/auth.local.php','<?php return '.var_export($config,true).';');$gameConfig=$connection;$gameConfig['host']='127.0.0.1:'.$sqlPort;file_put_contents($root.'/config/db.php','<?php return '.var_export($gameConfig,true).';');
$db=new Db($gameConfig);$repo=new GameRepository($db);
function linked(GameRepository $repo,mysqli $raw,int $host,int $player,string $mode='lucktest',bool $battle=false):int{
    global $db;
    $s=$repo->create($host,$player);
    $journal=new \Berserk\Core\CommandJournalRepository($db);
    $entry=$journal->registerProcessing($s->gameId,'host','http-seed:'.bin2hex(random_bytes(8)),'choose_mode',[],(int)$s->persistenceVersion);
    $repo->executeLockedCommand($s,$journal,(int)$entry['id'],function(GameState $locked)use($db,$mode,$battle): \Berserk\Core\Result {
        $engine=new Engine($db,null,fn($min,$max)=>$min);
        $result=$engine->apply($locked,'host',new Command('choose_mode',['mode'=>$mode]));
        if(!$result->success)return $result;
        $locked->settings->lucktest['card_count']=3;
        return $battle ? $engine->apply($locked,'host',new Command('confirm_settings',['debug_roll'=>'6,1,4','lucktest_scenario'=>json_encode(['cards'=>[['owner'=>'host','ukid'=>'army','row'=>2,'col'=>3]]])])) : $result;
    });
    $id=$s->gameId;$raw->query("INSERT INTO lobby_rooms(creator_account_id,accepted_account_id,game_id,state,created_at,updated_at,game_type,setup_json) VALUES($host,$player,$id,'started',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'$mode',NULL)");return $id;
}
$settingsSecond=linked($repo,$raw,$ordinary->id,$second->id);$settings=linked($repo,$raw,$host->id,$ordinary->id);$other=linked($repo,$raw,$host->id,$ordinary->id);$draft=linked($repo,$raw,$host->id,$ordinary->id,'draft');
$prop=$raw->real_escape_string(json_encode(['diagnostic_secret'=>'HTTP_OPAQUE_SECRET','start'=>['type'=>'get_coins','coins'=>2],'pre_turn_start'=>['options'=>[['name'=>'Choice','modifiers'=>[['stat'=>'move','value'=>1]]]]]]));$raw->query("UPDATE cards SET prop='$prop'");$battle=linked($repo,$raw,$host->id,$ordinary->id,'lucktest',true);$raw->query("UPDATE cards SET prop='{}'");
$data=['schema'=>$schema,'host'=>$host->id,'second'=>$second->id,'ordinary'=>$ordinary->id,'settings_second'=>$settingsSecond,'settings'=>$settings,'other'=>$other,'draft'=>$draft,'battle'=>$battle];file_put_contents($fixture,json_encode($data));echo json_encode($data)."\n";
