<?php
declare(strict_types=1);
if(getenv('BERSERK_STAGE4B_ISOLATED')!=='1'||file_exists(__DIR__.'/../.git'))throw new RuntimeException('Disposable copy required');
require __DIR__.'/stage31_fixtures.php';
use Berserk\Core\{Db,GameRepository,CommandJournalRepository,GameState,Engine,Command,InformationalWaitManager};
use Berserk\Core\BattleHistory\{HistoryRepository};
function bcheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function bconnect():Db{
    $schema=getenv('STAGE4B_SCHEMA')?:'berserk_stage4b';if(!preg_match('/^berserk_stage4b(?:_run[0-9]+)?$/D',$schema))throw new RuntimeException('Synthetic DB only');
    $db=new Db(['host'=>'127.0.0.1:33389','user'=>'stage4b','pass'=>'stage4b_test_only','name'=>$schema]);
    $p=$db->fetchOne('SELECT @@datadir dir,@@port port,DATABASE() db');
    bcheck((int)$p['port']===33389&&strtolower(rtrim(str_replace('\\','/',$p['dir']),'/'))===strtolower('C:/Users/mi/Documents/Berserk/stage4a-verification/data'),'Wrong datadir/port');return $db;
}
function bcatalog():array{$out=[];foreach([1,2,3,4,90,91]as$id)$out['s31_'.$id]=['revision'=>'fixture-v1','name'=>'Fixture '.$id,'image'=>'fixture/'.$id.'.png','description'=>'Frozen fixture','type'=>'creature','element'=>'neutral','classes'=>[],'price'=>0];return $out;}
function brepo(int $g,bool $enabled=true):GameRepository{global $db;return new GameRepository($db,bcatalog());}
function bnew(array $prop=['unanswer'=>true],bool $enabled=true,bool $start=true):GameState{
    global $db;
    // Synthetic legacy/modern initial positions, never the production creation path.
    $db->query("INSERT INTO games(host_id,player_id,status,json,version,date,active) VALUES(101,202,'place','{}',1,NOW(),1)");
    $s=s31state($prop);$s->gameId=$db->lastInsertId();$s->journalSchema=$enabled?1:null;$s->status='place';$s->getPlayer('host')->confirm('place');
    $db->query("UPDATE games SET json='".$db->escape(json_encode($s->toArray(),JSON_THROW_ON_ERROR))."' WHERE ind=".$s->gameId);
    if($enabled){$db->beginTransaction();(new HistoryRepository($db))->initialize($s->gameId,'recording','incomplete');$db->commit();}
    $s=(new GameRepository($db))->findById($s->gameId);
    if($start){bcmd($s,'player','confirm_place',[],$enabled);bcheck($s->status==='battle','battle started');}return $s;
}
function bcmd(GameState &$s,string $actor,string $type,array $payload=[],bool $enabled=true,?string $id=null,bool $ok=true):array{
    global $db,$journal,$applies;$repo=brepo($s->gameId,$enabled);$before=$s->persistenceVersion;$id??='b'.bin2hex(random_bytes(12));
    $c=$journal->registerProcessing($s->gameId,$actor,$id,$type,$payload,$before);
    if($c['is_new'])$repo->executeLockedCommand($s,$journal,(int)$c['id'],function($locked)use($actor,$type,$payload){global $applies;$applies++;s31roll();return(new Engine())->apply($locked,$actor,new Command($type,$payload));});
    $s=$repo->findById($s->gameId);$c=$journal->findById((int)$c['id']);
    bcheck($c['status']===($ok?'committed':'rejected'),$type.': '.$c['status'].' '.($c['error_message']??''));return $c;
}
function bcombat(GameState &$s,int $target=2):void{
    bcmd($s,'host','strike',['card_id'=>1,'target_id'=>$target]);bfinishCombat($s);
}
function bfinishCombat(GameState &$s):void{
    for($i=0;$i<12&&$s->status==='battle'&&!empty($s->battle['strike']);$i++){
        $x=$s->battle['strike'];$mode=$x['state'];
        if($mode==='waiting_defender'){bcmd($s,'player','choose_defender',['defender_id'=>0]);continue;}
        if($mode==='waiting_choice'){bcmd($s,($x['choice_winner']==='attack'?'host':'player'),'choose_strike_mode',['mode'=>'normal']);continue;}
        bcheck($mode==='results','unexpected combat wait '.$mode);
        foreach(['host','player']as$p)if(!in_array($p,$s->battle['strike']['confirmed']??[],true))bcmd($s,$p,'confirm_strike',['wait_id'=>(string)InformationalWaitManager::token($s)]);
    }
    bcheck($s->status==='game_over'||empty($s->battle['strike']),'combat finished');
}
function bsteps(int $g):array{global $db;return $db->fetchAll("SELECT * FROM battle_history_steps WHERE game_id=$g ORDER BY step_seq");}
function bhp(array $step,int $id):array{foreach(json_decode($step['state_json'],true)['cards']as$c)if($c['instance_id']===(string)$id)return$c;throw new RuntimeException('missing card');}
function bcounts(int $g):array{global $db;$r=[];foreach(['matches','actions','batches','events','steps']as$t)$r[$t]=$db->fetchAll("SELECT * FROM battle_history_$t WHERE game_id=$g".($t==='events'?' ORDER BY event_seq':($t==='steps'?' ORDER BY step_seq':'')));$r['game']=$db->fetchOne("SELECT * FROM games WHERE ind=$g");return$r;}
$db=bconnect();$applies=0;$history=new HistoryRepository($db);
if(($argv[1]??'')==='worker'){
    $journal=new CommandJournalRepository($db);$c=$journal->findById((int)$argv[2]);$g=(int)$c['game_id'];$repo=brepo($g);$s=$repo->findById($g);$s->persistenceVersion=(int)$c['expected_version'];$start=microtime(true);
    try{$repo->executeLockedCommand($s,$journal,(int)$c['id'],function($locked)use($argv){file_put_contents($argv[3],'locked');if($argv[4]==='hold')usleep(900000);return(new Engine())->apply($locked,'host',new Command('move',['card_id'=>(int)$argv[5],'row'=>(int)$argv[6],'col'=>(int)$argv[7]]));});echo json_encode(['status'=>'committed','elapsed'=>microtime(true)-$start]);exit(0);}
    catch(Throwable $e){echo json_encode(['status'=>'conflict','error'=>$e->getMessage()]);exit(2);}
}
bcheck($db->fetchOne("SHOW TABLES LIKE 'games'")===null,'Fresh synthetic schema required');
$db->query("CREATE TABLE games (ind INT AUTO_INCREMENT PRIMARY KEY,host_id INT NOT NULL,player_id INT NOT NULL,status VARCHAR(32),json LONGTEXT,version INT NOT NULL DEFAULT 0,date DATETIME,active INT) ENGINE=InnoDB AUTO_INCREMENT=4000001 DEFAULT CHARSET=utf8mb4");
$journal=new CommandJournalRepository($db);
$sql=preg_replace('/^--.*$/m','',file_get_contents(__DIR__.'/migrations/20261020_battle_history.sql'));
foreach(explode(';',$sql)as$q)if(trim($q)!=='')$db->query($q);
$checks=[];
function bt(string $name,Closure $run):void{global$checks;try{$detail=$run();$checks[]=['name'=>$name,'status'=>'PASS','detail'=>$detail];echo"PASS $name\n";}catch(Throwable$e){$checks[]=['name'=>$name,'status'=>'FAIL','error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()];echo"FAIL $name: ".$e->getMessage()."\n";}}
bt('initial before start effects and battle_ready are distinct immutable captures in one real command',function()use($db){
    $s=bnew(['unanswer'=>true,'start'=>['type'=>'get_hp','hp'=>5]]);$rows=bsteps($s->gameId);
    bcheck(array_column($rows,'step_type')===['initial','battle_ready'],'initial and ready steps');
    bcheck($rows[0]['persistence_version']===$rows[1]['persistence_version'],'one SQL version/command');
    bcheck(bhp($rows[0],1)['hp']===20&&bhp($rows[1],1)['hp']===25,'start effect not reconstructed from final state');
    bcheck(count(array_unique(array_column($rows,'action_id')))===2,'two logical root actions');
    $meta=$db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id='.$s->gameId);bcheck($meta['completeness']==='incomplete'&&(bool)$meta['has_initial'],'experimental initial');
    return array_map(fn($r)=>['step_seq'=>$r['step_seq'],'type'=>$r['step_type'],'version'=>$r['persistence_version'],'state'=>json_decode($r['state_json'],true)],$rows);
});
bt('real Engine movement via GameRepository and journal after reload',function()use($history){
    $s=bnew();$first=bsteps($s->gameId);bcmd($s,'host','move',['card_id'=>1,'row'=>3,'col'=>2]);$r=bsteps($s->gameId);
    bcheck(end($r)['step_type']==='move'&&bhp(end($r),1)['col']===2&&$s->getCard(1)->col===2,'actual movement persisted');
    bcheck($r[0]===$first[0],'initial capture unchanged');bcheck(!str_contains(json_encode($s->toArray()),'historyRecorder'),'no observer serialized');$history->steps($s->gameId,'host');
});
bt('end_turn capture follows next-player start normalization and rejected repeat has no step',function()use($db){
    $s=bnew(['unanswer'=>true],true,false);$s->getCard(2)->move=0;(new GameRepository($db))->save($s);bcmd($s,'player','confirm_place');
    $ready=bsteps($s->gameId);bcheck(bhp(end($ready),2)['move']===0,'next-player move not yet refreshed');bcmd($s,'host','end_turn');$r=bsteps($s->gameId);
    bcheck(end($r)['step_type']==='turn_end'&&bhp(end($r),2)['move']===1,'capture after actual start normalization');
    bcmd($s,'host','end_turn',[],true,null,false);bcheck(bsteps($s->gameId)===$r,'rejected repeat no capture');
});
bt('ordinary strike persists live declaration dice result wounds and root across HTTP commands',function()use($db){
    $s=bnew();$g=$s->gameId;bcmd($s,'host','strike',['card_id'=>1,'target_id'=>2]);
    $open=$db->fetchOne("SELECT * FROM battle_history_actions WHERE game_id=$g AND action_type='combat'");
    bcheck($open['lifecycle']==='open','root open after declaration');bcheck(count(bsteps($g))===2,'no premature combat snapshot');
    bfinishCombat($s);$a=$db->fetchOne("SELECT * FROM battle_history_actions WHERE game_id=$g AND action_type='combat'");
    bcheck($a['action_id']===$open['action_id']&&$a['lifecycle']==='settled','same root settled');
    $e=$db->fetchAll("SELECT * FROM battle_history_events WHERE game_id=$g AND action_id='".$a['action_id']."' ORDER BY event_seq");
    foreach(['combat.started','combat.dice','combat.result','combat.wounds','combat.settled']as$type)bcheck(in_array($type,array_column($e,'event_type'),true),'event '.$type);
    bcheck(count(array_unique(array_column($e,'journal_id')))>=2,'multiple commands own same root');
    $r=bsteps($g);bcheck(end($r)['step_type']==='combat'&&bhp(end($r),2)['hp']===$s->getCard(2)->hp,'final combat after ACK');return$e;
});
bt('real combat death event and graveyard state after final ACK',function()use($db){
    $s=bnew(['unanswer'=>true],true,false);$s->getCard(2)->hp=2;(new GameRepository($db))->save($s);
    bcmd($s,'player','confirm_place');bcombat($s);bcheck($s->getCard(2)->zone==='graveyard','actual death finalized');
    bcheck($db->fetchOne("SELECT event_seq FROM battle_history_events WHERE game_id=".$s->gameId." AND event_type='combat.death'")!==null,'live death event');
    $r=bsteps($s->gameId);bcheck(bhp(end($r),2)['zone']==='graveyard'&&bhp(end($r),2)['hp']===0,'death snapshot after consequences');
});
bt('lethal real strike records actual terminal state without advancing terminal continuations',function()use($db){
    $s=bnew(['unanswer'=>true],true,false);unset($s->cards[4],$s->cards[91]);$s->getCard(2)->hp=1;(new GameRepository($db))->save($s);
    bcmd($s,'player','confirm_place');bcmd($s,'host','strike',['card_id'=>1,'target_id'=>2]);
    if($s->status==='battle'&&($s->battle['strike']['state']??'')==='waiting_defender')bcmd($s,'player','choose_defender',['defender_id'=>0]);
    bcheck($s->status==='game_over'&&$s->winner==='host','Engine terminal from actual lethal damage');$r=bsteps($s->gameId);
    bcheck(end($r)['step_type']==='match_terminal'&&bhp(end($r),2)['hp']===$s->getCard(2)->hp,'actual terminal snapshot');
    bcheck(!empty($s->battle['strike']),'no forced terminal continuation cleanup by recorder');
});
bt('Hydra combat voluntary End and immediate turn_end are three independent steps',function(){
    $s=bnew(['attack_series'=>true,'unanswer'=>true]);bcombat($s);$r=bsteps($s->gameId);$combat=end($r);bcheck(!bhp($combat,1)['closed'],'Hydra stays open');
    bcmd($s,'host','attack_series_end');bcmd($s,'host','end_turn');$r=bsteps($s->gameId);$last=array_slice($r,-3);
    bcheck(array_column($last,'step_type')===['combat','series_end','turn_end'],'three boundaries');bcheck(array_map(fn($x)=>bhp($x,1)['closed'],$last)===[false,true,true],'closed states');bcheck($last[0]===$combat,'old frame immutable');return$last;
});
bt('Hydra natural ending closes in last combat without synthetic End',function(){
    $s=bnew(['attack_series'=>true,'unanswer'=>true]);bcombat($s);bcheck(!empty($s->battle['pending_attack_series_choice']),'first continuation');
    bcmd($s,'host','attack_series_next',['target_id'=>4]);bfinishCombat($s);$r=bsteps($s->gameId);
    bcheck(count(array_filter($r,fn($x)=>$x['step_type']==='combat'))===2,'one step per combat');bcheck(!in_array('series_end',array_column($r,'step_type'),true)&&bhp(end($r),1)['closed'],'natural close in combat');
});
bt('committed retry does not execute Engine or recreate captures including initial',function()use($journal,$history){
    global$applies;$s=bnew([],true,false);$c=bcmd($s,'player','confirm_place');$before=bcounts($s->gameId);$n=$applies;
    $again=$journal->registerProcessing($s->gameId,'player',$c['command_id'],'confirm_place',[],(int)$c['expected_version']);
    bcheck(!$again['is_new']&&$again['status']==='committed','durable committed retry');bcheck($history->committedBatch($s->gameId,(int)$c['id'])!==null,'existing outcome');
    bcheck($applies===$n&&bcounts($s->gameId)===$before,'no new apply/save/snapshot');
});
bt('rejected and technical no-op commands have no new Replay Step',function(){
    $s=bnew();$before=bsteps($s->gameId);bcmd($s,'player','move',['card_id'=>2,'row'=>2,'col'=>4],true,null,false);bcheck(bsteps($s->gameId)===$before,'rejected move no step');
    bcmd($s,'host','confirm_strike',[],true,null,false);bcheck(bsteps($s->gameId)===$before,'rejected stale ACK no step');
    bcmd($s,'host','set_sound',['enabled'=>true],true,null,false);bcheck(bsteps($s->gameId)===$before,'unknown command no step');
    bcmd($s,'host','set_sound_enabled',['sound_enabled'=>true],true,null,false);bcheck(bsteps($s->gameId)===$before,'retired preference command rejected without step');
});
foreach(['events','steps']as$table)bt('actual repository rolls back state events captures and journal on '.$table.' INSERT failure',function()use($db,$journal,$table){
    $s=bnew();$g=$s->gameId;$c=$journal->registerProcessing($g,'host','fault-'.$g,'move',['card_id'=>1,'row'=>3,'col'=>2],$s->persistenceVersion);$before=bcounts($g);
    $seq=$table==='events'?'event_seq':'step_seq';$limit=count($before[$table]);
    $db->query("ALTER TABLE battle_history_$table ADD CONSTRAINT recorder_fault CHECK (game_id<>$g OR $seq<=$limit)");
    try{$failed=false;try{brepo($g)->executeLockedCommand($s,$journal,(int)$c['id'],fn($w)=>(new Engine())->apply($w,'host',new Command('move',['card_id'=>1,'row'=>3,'col'=>2])));}catch(\Berserk\Core\DbQueryException$e){$failed=true;}
        bcheck($failed,'required INSERT failure');bcheck(bcounts($g)===$before,'full rollback, including game JSON/version');bcheck($journal->findById((int)$c['id'])['status']==='processing','registration before tx retained');
    }finally{$db->query("ALTER TABLE battle_history_$table DROP CONSTRAINT recorder_fault");}
    brepo($g)->executeLockedCommand($s,$journal,(int)$c['id'],fn($w)=>(new Engine())->apply($w,'host',new Command('move',['card_id'=>1,'row'=>3,'col'=>2])));
    bcheck($journal->findById((int)$c['id'])['status']==='committed','safe retry after rollback');
});
bt('second initial capture failure rolls back first capture plus initial metadata in real repository',function()use($db,$journal){
    $s=bnew(['start'=>['type'=>'get_hp','hp'=>5]],true,false);$g=$s->gameId;$c=$journal->registerProcessing($g,'player','initial-fault','confirm_place',[],$s->persistenceVersion);$before=bcounts($g);
    $db->query("ALTER TABLE battle_history_steps ADD CONSTRAINT initial_fault CHECK (game_id<>$g OR capture_order<>2)");
    try{$failed=false;try{brepo($g)->executeLockedCommand($s,$journal,(int)$c['id'],fn($w)=>(new Engine())->apply($w,'player',new Command('confirm_place')));}catch(\Berserk\Core\DbQueryException$e){$failed=true;}
        bcheck($failed&&bcounts($g)===$before,'first snapshot, provisioned metadata and battle start all rolled back');
    }finally{$db->query('ALTER TABLE battle_history_steps DROP CONSTRAINT initial_fault');}
    brepo($g)->executeLockedCommand($s,$journal,(int)$c['id'],fn($w)=>(new Engine())->apply($w,'player',new Command('confirm_place')));bcheck(count(bsteps($g))===2,'successful original command retry');
});
bt('legacy game without history has no fabricated writes and ordinary pending timers and GameLog still work',function()use($db){
    $s=bnew(['unanswer'=>true],false);bcmd($s,'host','strike',['card_id'=>1,'target_id'=>2],false);
    bcheck(!empty($s->battle['strike'])&&!empty($s->battle['turn_timer']),'pending and timer persisted');
    foreach(['matches','actions','events','steps','batches']as$t)bcheck((int)$db->fetchOne("SELECT COUNT(*) n FROM battle_history_$t WHERE game_id=".$s->gameId)['n']===0,'no legacy history '.$t);
    bcheck(count(glob(__DIR__.'/../storage/*'))>0,'ordinary diagnostic storage remains');
});
bt('uncertified mechanic creates explicit gap and never becomes complete replay',function()use($db,$history){
    $s=bnew(['on_move'=>[['type'=>'noop_unrecognized']]]);$before=count(bsteps($s->gameId));bcmd($s,'host','move',['card_id'=>1,'row'=>3,'col'=>2]);
    bcheck($s->getCard(1)->col===2,'real mechanic still executed');bcheck(count(bsteps($s->gameId))===$before,'no uncertified move snapshot');
    bcheck($db->fetchOne("SELECT event_seq FROM battle_history_events WHERE game_id=".$s->gameId." AND event_type='recording_gap.started'")!==null,'explicit gap');
    bcheck($db->fetchOne('SELECT completeness FROM battle_history_matches WHERE game_id='.$s->gameId)['completeness']==='incomplete','incomplete');
    try{$history->completedServerHistory($s->gameId);throw new RuntimeException('full history leak');}catch(LogicException$e){}
});
bt('historical hidden row projections redact identity characteristics and catalog',function()use($history){
    $s=bnew();$host=$history->steps($s->gameId,'host');$owner=$history->steps($s->gameId,'player');
    $a=json_decode($host[0]['replay_json'],true);$b=json_decode($owner[0]['replay_json'],true);
    $hidden=array_values(array_filter($a['cards'],fn($c)=>$c['instance_id']==='91'))[0];bcheck($hidden['definition']===null&&$hidden['hp']===null,'opponent hidden data');
    bcheck(!str_contains($host[0]['replay_json'],'Fixture 91')&&str_contains($owner[0]['replay_json'],'Fixture 91'),'catalog visibility');
    bcmd($s,'host','end_turn');bcheck($history->steps($s->gameId,'host')[0]===$host[0],'later reveal never changes old projection');
});
bt('real terminal state is captured partial, no false completed replay or Engine re-execution',function()use($db,$history){
    $s=bnew();bcmd($s,'host','resign');$r=bsteps($s->gameId);bcheck($s->status==='game_over'&&$s->winner==='player','real terminal');bcheck(end($r)['is_final']==='1'&&json_decode(end($r)['state_json'],true)['finished'],'final capture');
    $m=$db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id='.$s->gameId);bcheck($m['recording_status']!=='completed'&&$m['completeness']==='incomplete','partial never completed');
    try{$history->completedServerHistory($s->gameId);throw new RuntimeException('partial full replay unlocked');}catch(LogicException$e){}
    return end($r);
});
bt('mandatory creation marker refuses missing metadata and direct save bypass',function()use($db){
    $s=bnew([],true,false);$db->query('DELETE FROM battle_history_matches WHERE game_id='.$s->gameId);
    $j=new CommandJournalRepository($db);$c=$j->registerProcessing($s->gameId,'player','missingmeta','confirm_place',[],$s->persistenceVersion);$before=bcounts($s->gameId);
    $failed=false;try{brepo($s->gameId)->executeLockedCommand($s,$j,(int)$c['id'],fn($w)=>(new Engine())->apply($w,'player',new Command('confirm_place')));}catch(LogicException $e){$failed=true;}
    bcheck($failed&&bcounts($s->gameId)===$before,'missing mandatory metadata fails closed');
    $failed=false;try{brepo($s->gameId)->save($s);}catch(LogicException $e){$failed=true;}bcheck($failed,'save bypass refused');
});
bt('actual HTTP request produces two distinct initial captures and retry reads same committed outcome',function()use($db){
    $s=bnew(['start'=>['type'=>'get_hp','hp'=>5]],true,false);$g=$s->gameId;
    $folder=getenv('STAGE4B_RESULTS').'/http';if(!is_dir($folder))mkdir($folder);$key=bin2hex(random_bytes(20));
    $source=<<<'PHP'
<?php
declare(strict_types=1);
if(getenv('BERSERK_STAGE4B_ISOLATED')!=='1'||!hash_equals(getenv('STAGE4B_HTTP_KEY'),$_SERVER['HTTP_X_FIXTURE_KEY']??'')){http_response_code(403);exit;}
require getenv('STAGE4B_HTTP_APP').'/src/Core/Autoloader.php';
Berserk\Core\Autoloader::register();Berserk\Core\Autoloader::addNamespace('Berserk\\',getenv('STAGE4B_HTTP_APP').'/src/');
$db=new Berserk\Core\Db(['host'=>'127.0.0.1:33389','user'=>'stage4b','pass'=>'stage4b_test_only','name'=>getenv('STAGE4B_SCHEMA')]);
$proof=$db->fetchOne('SELECT @@datadir dir,@@port port');
if((int)$proof['port']!==33389||strtolower(rtrim(str_replace('\\','/',$proof['dir']),'/'))!==strtolower('C:/Users/mi/Documents/Berserk/stage4a-verification/data'))throw new RuntimeException('Wrong server');
$g=(int)getenv('STAGE4B_HTTP_GAME');$catalog=json_decode(getenv('STAGE4B_HTTP_CATALOG'),true,512,JSON_THROW_ON_ERROR);
$repo=new Berserk\Core\GameRepository($db,$catalog);
$j=new Berserk\Core\CommandJournalRepository($db);$s=$repo->findById($g);
$input=json_decode(file_get_contents('php://input'),true)??[];
$type=$input['type']??'confirm_place';$actor=$input['actor']??'player';$payload=$input['payload']??[];
if(!in_array($type,['confirm_place','strike','choose_defender','choose_strike_mode','confirm_strike'],true)||!in_array($actor,['host','player'],true))throw new RuntimeException('Fixture command not allowed');
$expected=$input['expected']??(int)getenv('STAGE4B_HTTP_VERSION');$id=$input['id']??'http-confirm-place';
$c=$j->registerProcessing($g,$actor,$id,$type,$payload,$expected);
$_SESSION=['debug_roll'=>'6*'];Berserk\Core\Dice::init();
if($c['is_new'])$repo->executeLockedCommand($s,$j,(int)$c['id'],fn($w)=>(new Berserk\Core\Engine())->apply($w,$actor,new Berserk\Core\Command($type,$payload)));
header('Content-Type: application/json');echo json_encode($j->findById((int)$c['id']));
PHP;
    file_put_contents($folder.'/fixture.php',$source);$env=getenv();$env['STAGE4B_HTTP_KEY']=$key;$env['STAGE4B_HTTP_GAME']=(string)$g;
    $env['STAGE4B_HTTP_VERSION']=(string)$s->persistenceVersion;$env['STAGE4B_HTTP_CATALOG']=json_encode(bcatalog());$env['STAGE4B_HTTP_APP']=dirname(__DIR__);
    // Local test-only server, no repository endpoint or public feature gate.
    $p=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),'-S','127.0.0.1:33490','-t',$folder],[1=>['file',$folder.'/server.log','a'],2=>['file',$folder.'/server-errors.log','a']],$pipes,null,$env);bcheck(is_resource($p),'HTTP fixture server');
    try{
        $ready=false;for($i=0;$i<100;$i++){$sock=@fsockopen('127.0.0.1',33490,$err,$why,0.05);if($sock){fclose($sock);$ready=true;break;}usleep(10000);}bcheck($ready,'HTTP listening');
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>['X-Fixture-Key: '.$key,'Content-Type: application/json'],"content"=>'{}','timeout'=>10]]);
        $one=json_decode(file_get_contents('http://127.0.0.1:33490/fixture.php',false,$context),true,512,JSON_THROW_ON_ERROR);bcheck($one['status']==='committed','HTTP committed');
        $rows=bsteps($g);bcheck(count($rows)===2&&$rows[0]['persistence_version']===$rows[1]['persistence_version'],'two captures in one HTTP command');
        bcheck(bhp($rows[0],1)['hp']===20&&bhp($rows[1],1)['hp']===25,'different capture-time field states');$before=bcounts($g);
        $two=json_decode(file_get_contents('http://127.0.0.1:33490/fixture.php',false,$context),true,512,JSON_THROW_ON_ERROR);
        bcheck($two===$one&&bcounts($g)===$before,'HTTP committed retry returned old data');
        $post=function(string $actor,string $type,array $payload=[])use($key,$g){$state=brepo($g)->findById($g);$body=json_encode(['actor'=>$actor,'type'=>$type,'payload'=>$payload,'expected'=>$state->persistenceVersion,'id'=>'http-'.bin2hex(random_bytes(8))]);$cx=stream_context_create(['http'=>['method'=>'POST','header'=>['X-Fixture-Key: '.$key,'Content-Type: application/json'],'content'=>$body,'timeout'=>10]]);$answer=json_decode(file_get_contents('http://127.0.0.1:33490/fixture.php',false,$cx),true,512,JSON_THROW_ON_ERROR);bcheck($answer['status']==='committed','HTTP '.$type.' '.$answer['status']);};
        $post('host','strike',['card_id'=>1,'target_id'=>2]);$root=$db->fetchOne("SELECT * FROM battle_history_actions WHERE game_id=$g AND action_type='combat'");bcheck($root['lifecycle']==='open','HTTP root open');
        for($i=0;$i<12;$i++){$fresh=brepo($g)->findById($g);$x=$fresh->battle['strike']??null;if(!$x)break;
            if($x['state']==='waiting_defender'){$post('player','choose_defender',['defender_id'=>0]);continue;}
            if($x['state']==='waiting_choice'){$post($x['choice_winner']==='attack'?'host':'player','choose_strike_mode',['mode'=>'normal']);continue;}
            bcheck($x['state']==='results','HTTP combat known wait');$actor=in_array('host',$x['confirmed'],true)?'player':'host';$post($actor,'confirm_strike',['wait_id'=>(string)InformationalWaitManager::token($fresh)]);
        }
        $settled=$db->fetchOne("SELECT * FROM battle_history_actions WHERE game_id=$g AND action_type='combat'");bcheck($settled['action_id']===$root['action_id']&&$settled['lifecycle']==='settled','same root across actual HTTP requests');
        return ['command'=>$one,'captures'=>$rows,'http_combat_root'=>$settled];
    }finally{proc_terminate($p);proc_close($p);}
});
bt('concurrent real commands: one committed one stale, no orphan batch or duplicate sequences',function()use($journal,$db){
    $s=bnew();$g=$s->gameId;$v=$s->persistenceVersion;$base=bcounts($g);$a=$journal->registerProcessing($g,'host','race-a','move',[],$v);$b=$journal->registerProcessing($g,'host','race-b','move',[],$v);
    $marker=getenv('STAGE4B_RESULTS').'/race-'.bin2hex(random_bytes(4));$cmd=[PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'worker',(string)$a['id'],$marker,'hold','1','3','2'];
    $p1=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes1);bcheck(is_resource($p1),'worker1');$until=microtime(true)+8;while(!file_exists($marker)&&microtime(true)<$until)usleep(10000);bcheck(file_exists($marker),'game locked');
    $cmd[5]=(string)$b['id'];$cmd[6]=$marker.'-b';$cmd[7]='now';$cmd[8]='3';$cmd[9]='2';$cmd[10]='1';$p2=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes2);bcheck(is_resource($p2),'worker2');$outputs=[];
    foreach([$pipes1,$pipes2]as$pipes){$outputs[]=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);foreach($pipes as$p)fclose($p);}$c1=proc_close($p1);$c2=proc_close($p2);bcheck($c1===0&&$c2===2,'committed and stale '.json_encode($outputs));
    bcheck(count(bsteps($g))===count($base['steps'])+1,'one winning capture');$seq=array_map('intval',array_column($db->fetchAll("SELECT event_seq FROM battle_history_events WHERE game_id=$g ORDER BY event_seq"),'event_seq'));bcheck($seq===range(1,count($seq)),'contiguous sequence');return$outputs;
});
file_put_contents(getenv('STAGE4B_RESULTS').'/summary.json',json_encode(['environment'=>$db->fetchOne('SELECT @@datadir dir,@@port port,DATABASE() db,VERSION() version'),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
exit(count(array_filter($checks,fn($r)=>$r['status']==='FAIL'))?1:0);
