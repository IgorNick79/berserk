<?php
declare(strict_types=1);
// Disposable test process only. Never used by application runtime.
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository,Engine,Command,GameState,StaleStateException,AutomaticMatchPause};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$config=require __DIR__.'/../config/db.php';
$expected=realpath(getenv('TURN_TIMER_TEST_DATADIR')?:'/nonexistent');
if($expected===false || $config['name']!=='berserk_turn_timer_t2_test' || file_exists(__DIR__.'/../.git'))throw new RuntimeException('Disposable tree required');
$db=new Db($config);$meta=$db->fetchOne('SELECT @@datadir AS dir,DATABASE() AS db');
if(realpath($meta['dir'])!==$expected || $meta['db']!==$config['name'])throw new RuntimeException('Wrong test DB');
[$script,$game,$actor,$type,$now,$barrier,$slot]=$argv;
$repo=new GameRepository($db);$journal=new CommandJournalRepository($db);$s=$repo->findById((int)$game);
$payload=$type==='request_pause'?['duration_seconds'=>60]:['request_id'=>$s->battle['match_pause']['request_id']];
$command=new Command($type,$payload);$engine=new Engine($db,static fn():int=>(int)$now);
$a=$type==='expire_pause'?null:$journal->registerProcessing($s->gameId,$actor,'mp-race-'.$slot,$type,$payload,$s->persistenceVersion);
file_put_contents($barrier.'.'.$slot,'ready');$until=microtime(true)+15;
while(!file_exists($barrier.'.go')){if(microtime(true)>$until)throw new RuntimeException('Barrier timeout');usleep(1000);}
if($type==='expire_pause')(new AutomaticMatchPause($db,$repo,$engine))->check($s,$actor);
else try{
    $repo->executeLockedCommand($s,$journal,(int)$a['id'],static fn(GameState $locked)=> tracer($engine,$locked,$actor,$command));
}catch(StaleStateException){$journal->markConflict((int)$a['id'],$repo->findById($s->gameId)->persistenceVersion,'stale_state');}
function tracer(Engine $engine,GameState $state,string $actor,Command $cmd):\Berserk\Core\Result{return $engine->apply($state,$actor,$cmd);}
echo "Pause race child passed\n";
