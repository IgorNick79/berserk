<?php
declare(strict_types=1);
// Child process for a bounded test barrier. Never a runtime/background game worker.
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository,Engine,Command,InformationalAutoAck,InformationalWaitManager,StaleStateException};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$config=require __DIR__.'/../config/db.php';
if(file_exists(__DIR__.'/../.git') || $config['name']!=='berserk_turn_timer_t2_test')throw new RuntimeException('Disposable tree required');
[$script,$game,$mode,$barrier,$slot]=$argv;
if(in_array($mode,['commit-unknown','rollback-unknown'],true)){
    // Process-local fault injection: no runtime file changes. Execute real SQL COMMIT
    // (or ROLLBACK), then lose its acknowledgement at the existing Db boundary.
    $source=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../src/Core/Db.php'));
    $needle="        \$this->transactionActive = false;\n    }\n\n    public function rollback";
    $fault=$mode==='commit-unknown'?'TransactionCommitUnknownException':'TransactionRollbackUnknownException';
    if($mode==='rollback-unknown')$source=str_replace('$this->connection->commit()','$this->connection->rollback()',$source);
    $source=str_replace($needle,"        \$this->transactionActive = false;\n        throw new ".$fault."('Injected lost acknowledgement');\n    }\n\n    public function rollback",$source,$count);
    if($count!==1)throw new RuntimeException('Fault injection boundary changed');
    eval(substr($source,5));
}
$db=new Db($config);$meta=$db->fetchOne('SELECT @@datadir AS dir');
$expected=realpath(getenv('TURN_TIMER_TEST_DATADIR')?:'/nonexistent');
if($expected===false || realpath($meta['dir'])!==$expected)throw new RuntimeException('Wrong datadir');
$repo=new GameRepository($db);$s=$repo->findById((int)$game);$journal=new CommandJournalRepository($db);
$engine=new Engine($db,static fn():int=>1010);
if(in_array($mode,['commit-unknown','rollback-unknown'],true)){
    $service=new InformationalAutoAck($db,$repo,$engine);$service->check($s,'host');$service->check($s,'host');
    echo "unknown acknowledgement child passed\n";exit;
}
if($mode==='manual'){
    $command=new Command('confirm_strike',['wait_id'=>InformationalWaitManager::token($s)]);
    $a=$journal->registerProcessing($s->gameId,'player','race-manual',$command->type,$command->payload,$s->persistenceVersion);
    if(!$engine->apply($s,'player',$command)->success)throw new RuntimeException('Manual fixture failed');
}
file_put_contents($barrier.'.'.$slot,'ready');
$until=microtime(true)+10;
while(!file_exists($barrier.'.go')){
    if(microtime(true)>$until)throw new RuntimeException('Barrier timed out');usleep(1000);
}
if($mode==='manual'){
    try{$repo->saveCommittedCommand($s,$journal,(int)$a['id']);}
    catch(StaleStateException){$journal->markConflict((int)$a['id'],$repo->findById($s->gameId)->persistenceVersion,'stale_state');}
}else{(new InformationalAutoAck($db,$repo,$engine))->check($s,'host');}
echo "race child passed\n";
