<?php
declare(strict_types=1);
// Bounded isolated test child. Faults exist only in this process, never in runtime files.
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$config=require __DIR__.'/../config/db.php';
$expected=realpath(getenv('TURN_TIMER_TEST_DATADIR')?:'/nonexistent');
if($expected===false || $config['name']!=='berserk_turn_timer_t2_test' || file_exists(__DIR__.'/../.git'))throw new RuntimeException('Disposable source tree required');
[$script,$game,$mode]=$argv;
if(!in_array($mode,['crash-after-register','hold-executor'],true)) {
    $source=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../src/Core/Db.php'));
    $rollback=str_contains($mode,'rollback');
    if($rollback) {
        $source=str_replace('$result = $this->connection->query($sql);',
            'if (str_starts_with($sql,"UPDATE games SET ") && empty($GLOBALS["mpFailedUpdate"])) { $GLOBALS["mpFailedUpdate"]=true; throw new DbQueryException("Injected UPDATE failure"); } $result = $this->connection->query($sql);',$source,$count);
        if($count!==1)throw new RuntimeException('Db query boundary changed');
    }
    $next=$rollback?'inTransaction':'rollback';
    $fault=$rollback?'TransactionRollbackUnknownException':'TransactionCommitUnknownException';
    $needle="        \$this->transactionActive = false;\n    }\n\n    public function ".$next;
    $source=str_replace($needle,"        \$this->transactionActive = false;\n        if (empty(\$GLOBALS['mpLostAck'])) { \$GLOBALS['mpLostAck']=true; throw new ".$fault."('Injected lost acknowledgement'); }\n    }\n\n    public function ".$next,$source,$count);
    if($count!==1)throw new RuntimeException('Db transaction boundary changed');
    if(in_array($mode,['html-resign-commit-unknown','sound-commit-unknown'],true)) {
        $source=str_replace('$result = $this->connection->query($sql);',
            'if (!empty($GLOBALS["mpLostAck"]) && empty($GLOBALS["mpLostRecoveryRead"])) { $GLOBALS["mpLostRecoveryRead"]=true; throw new DbQueryException("Injected recovery read failure"); } $result = $this->connection->query($sql);',$source,$count);
        if($count!==1)throw new RuntimeException('Db recovery boundary changed');
    }
    eval(substr($source,5));
}
$db=new Db($config);$meta=$db->fetchOne('SELECT @@datadir AS dir,DATABASE() AS db');
if(realpath($meta['dir'])!==$expected || $meta['db']!==$config['name'])throw new RuntimeException('Wrong test DB');
foreach($db->fetchAll('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $table)if($table['ENGINE']!=='InnoDB')throw new RuntimeException('InnoDB required');
$repo=new GameRepository($db);$s=$repo->findById((int)$game);
if($mode==='crash-after-register') {
    $p=$s->battle['match_pause'];$journal=new CommandJournalRepository($db);
    $journal->registerProcessing($s->gameId,$p['initiator'],'mpx:'.$p['generation'].':'.$s->persistenceVersion,'expire_pause',[],$s->persistenceVersion);
    exit(0); // Simulate process death before BEGIN; no executor ever runs here.
}
if($mode==='hold-executor') {
    $barrier=$argv[3];$p=$s->battle['match_pause'];$journal=new CommandJournalRepository($db);
    $a=$journal->registerProcessing($s->gameId,$p['initiator'],'mpx:'.$p['generation'].':'.$s->persistenceVersion,'expire_pause',[],$s->persistenceVersion);
    $repo->executeLockedCommand($s,$journal,(int)$a['id'],static function(\Berserk\Core\GameState $locked)use($barrier):\Berserk\Core\Result {
        file_put_contents($barrier.'.held','locked');$until=microtime(true)+15;
        while(!file_exists($barrier.'.release')){if(microtime(true)>$until)throw new RuntimeException('Hold timeout');usleep(1000);}
        return \Berserk\Core\MatchPause::expire($locked,1100);
    });
    exit(0);
}
$_GET=['first'=>'','game'=>$game,'ajax'=>'battle_sync','force_snapshot'=>'1'];
if(str_starts_with($mode,'status-'))$_GET=['first'=>'','game'=>$game,'ajax'=>'battle_command_status','command_id'=>'fault-accept'];
$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
if($mode==='html-resign-commit-unknown') {
    $_GET=['first'=>'','game'=>$game,'ajax'=>'battle_command'];
    $_POST=['cmd'=>'resign','command_id'=>$argv[3],'expected_version'=>(string)$s->persistenceVersion,'html_resign'=>'1'];
    $_SERVER['REQUEST_METHOD']='POST';
    register_shutdown_function(static function():void {echo "\n__RESIGN_STATUS__".(http_response_code()?:200);});
}
if($mode==='sound-commit-unknown') {
    $_GET=['first'=>'','game'=>$game,'ajax'=>'battle_command'];
    $_POST=['cmd'=>'set_sound_enabled','sound_enabled'=>'0','command_id'=>$argv[3],'expected_version'=>(string)$s->persistenceVersion];
    $_SERVER['REQUEST_METHOD']='POST';
}
$_REQUEST=array_merge($_GET,$_POST);$_SERVER['REQUEST_URI']='/index.php?'.http_build_query($_GET);
require __DIR__.'/../www/index.php';
