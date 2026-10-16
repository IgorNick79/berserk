<?php
declare(strict_types=1);
// Disposable, bounded test process. Not a runtime worker.
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository,Engine,Command,AutomaticTurnEnd,AutomaticBattleProgress,StaleStateException};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$config=require __DIR__.'/../config/db.php';
if(file_exists(__DIR__.'/../.git') || $config['name']!=='berserk_turn_timer_t2_test')throw new RuntimeException('Disposable source tree required');
[$script,$game,$mode,$barrier,$slot]=$argv;
if(in_array($mode,['commit-unknown','rollback-unknown','save-failed','p2-commit-unknown','p2-commit-unknown-http'],true)){
    $source=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../src/Core/Db.php'));
    if(in_array($mode,['rollback-unknown','save-failed'],true)){
        $source=str_replace('$result = $this->connection->query($sql);',
            'if (str_starts_with($sql, "UPDATE games SET ")) throw new DbQueryException("Injected UPDATE failure"); $result = $this->connection->query($sql);',$source,$count);
        if($count!==1)throw new RuntimeException('Db query boundary changed');
    }
    if($mode!=='save-failed'){
        $next=$mode==='rollback-unknown'?'inTransaction':'rollback';
        $fault=$mode==='rollback-unknown'?'TransactionRollbackUnknownException':'TransactionCommitUnknownException';
        $needle="        \$this->transactionActive = false;\n    }\n\n    public function ".$next;
        $source=str_replace($needle,"        \$this->transactionActive = false;\n        throw new ".$fault."('Injected lost acknowledgement');\n    }\n\n    public function ".$next,$source,$count);
        if($count!==1)throw new RuntimeException('Db transaction boundary changed');
    }
    eval(substr($source,5));
}
$db=new Db($config);$meta=$db->fetchOne('SELECT @@datadir AS dir');
$expected=realpath(getenv('TURN_TIMER_TEST_DATADIR')?:'/nonexistent');
if($expected===false || realpath($meta['dir'])!==$expected)throw new RuntimeException('Wrong datadir');
$repo=new GameRepository($db);$s=$repo->findById((int)$game);$journal=new CommandJournalRepository($db);
$e=new Engine($db,static fn():int=>1200);
if($mode==='p2-commit-unknown-http'){
    $_GET=['first'=>'','game'=>(string)$s->gameId,'ajax'=>'battle_sync','force_snapshot'=>'1'];
    $_POST=[];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/index.php';
    require __DIR__.'/../www/index.php';exit;
}
if(in_array($mode,['commit-unknown','rollback-unknown','save-failed','p2-commit-unknown'],true)){
    $out=$mode==='p2-commit-unknown'?(new AutomaticBattleProgress($db,$repo,$e))->check($s,'player')
        :(new AutomaticTurnEnd($db,$repo,$e))->check($s,'player');
    if($out->certain!==($mode==='save-failed'))throw new RuntimeException('Wrong transaction certainty');
    if(!in_array($mode,['p2-commit-unknown','save-failed'],true))(new AutomaticTurnEnd($db,$repo,$e))->check($s,'player');
    echo "T3 fault child passed\n";exit;
}
if($mode==='manual'){
    $cmd=new Command('end_turn');$a=$journal->registerProcessing($s->gameId,'host','manual-timeout-end','end_turn',[],$s->persistenceVersion);
    if(!$e->apply($s,'host',$cmd)->success)throw new RuntimeException('Manual end-turn failed');
}
file_put_contents($barrier.'.'.$slot,'ready');$until=microtime(true)+10;
while(!file_exists($barrier.'.go')){if(microtime(true)>$until)throw new RuntimeException('Barrier timeout');usleep(1000);}
if($mode==='manual'){
    try{$repo->saveCommittedCommand($s,$journal,(int)$a['id']);}
    catch(StaleStateException){$journal->markConflict((int)$a['id'],$repo->findById($s->gameId)->persistenceVersion,'stale_state');}
}else{(new AutomaticTurnEnd($db,$repo,$e))->check($s,'player');}
echo "T3 race child passed\n";
