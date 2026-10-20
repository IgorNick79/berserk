<?php
declare(strict_types=1);
// Destructive synthetic fixture setup is restricted to this disposable server and a copy without .git.
if (getenv('BERSERK_STAGE4A_ISOLATED') !== '1' || file_exists(__DIR__.'/../.git')) throw new RuntimeException('Disposable copy required');
spl_autoload_register(function(string $class):void {
    $file=__DIR__.'/../src/'.str_replace('\\','/',preg_replace('/^Berserk\\\\/','',$class)).'.php';
    if (is_file($file)) require $file;
});
use Berserk\Core\{Db,GameRepository,GameState,CommandJournalRepository};
use Berserk\Core\BattleHistory\{HistoryRepository,HistoryBatch,ReplayState};
function connection():Db {
    $schema=getenv('STAGE4A_SCHEMA') ?: 'berserk_stage4a';
    if (!preg_match('/^berserk_stage4a(?:_run[0-9]+)?$/D',$schema)) throw new RuntimeException('Invalid synthetic schema');
    $db=new Db(['host'=>'127.0.0.1:33389','user'=>'stage4a1','pass'=>'stage4a1_test_only','name'=>$schema]);
    $p=$db->fetchOne('SELECT @@port port,@@datadir dir,DATABASE() db,VERSION() version');
    $actual=strtolower(rtrim(str_replace('\\','/',$p['dir']),'/'));
    if ((int)$p['port']!==33389 || $actual!==strtolower('C:/Users/mi/Documents/Berserk/stage4a-verification/data') || $p['db']!==$schema) throw new RuntimeException('Wrong isolated instance');
    return $db;
}
function check(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
function rejects(callable $fn,string $message):void { try {$fn();}catch(Throwable $e){return;}throw new RuntimeException('Expected rejection: '.$message); }
function replay(bool $closed=false,bool $finished=false):array {
    return ['version'=>1,'ruleset'=>'synthetic-v1','participants'=>['host'=>['label'=>'Synthetic A'],'player'=>['label'=>'Synthetic B']],
        'turn'=>1,'active'=>'host','cards'=>[['instance_id'=>'hydra-1','definition'=>'hydra:v1','owner'=>'host','zone'=>'field','order'=>null,
            'row'=>0,'col'=>0,'slot'=>0,'hp'=>10,'hp_max'=>10,'move'=>2,'move_max'=>2,'armor'=>0,'armor_max'=>0,
            'strike'=>['weak'=>1,'medium'=>2,'strong'=>3],'coins'=>0,'closed'=>$closed,'revealed'=>true,'statuses'=>[],'markers'=>[],'effects'=>[]]],
        'catalog'=>['hydra:v1'=>['revision'=>'synthetic-v1','name'=>'Synthetic Hydra','image'=>'synthetic/hydra-v1.png','description'=>'Frozen synthetic reference','type'=>'creature','element'=>'neutral','classes'=>['hydra'],'price'=>5]],
        'markers'=>[],'finished'=>$finished,'winner'=>$finished?'host':null];
}
function action(string $id,string $status='settled'):array {
    return ['id'=>$id,'root'=>$id,'parent'=>null,'type'=>'synthetic','initiator'=>'host','status'=>$status,'start'=>1,'end'=>1,'completion'=>['reason'=>'synthetic']];
}
function event(string $id,string $type='resolved'):array {
    return ['action'=>$id,'type'=>$type,'version'=>1,'payload'=>['secret'=>'server-only'],
        'public'=>['message'=>'public'],'host'=>['message'=>'owner','known'=>'host-only'],'player'=>['message'=>'opponent'],
        'at'=>'2026-10-20 12:00:00.000001'];
}
function capture(string $id,string $key,array $state,bool $initial=false,bool $final=false,int $through=1,string $type='combat'):array {
    return ['action'=>$id,'boundary'=>$key,'type'=>$type,'through'=>$through,'initial'=>$initial,'final'=>$final,
        'state'=>$state,'public'=>$state,'host'=>$state,'player'=>$state];
}
function batch(string $id,string $key, bool $initial=false):HistoryBatch {
    return new HistoryBatch([action($id)],[event($id)],[capture($id,$key,replay(),$initial)]);
}
function newGame(string $status='recording',string $completeness='complete'):int {
    global $db,$repo,$history;
    $s=$repo->create(101,202);$db->beginTransaction();$history->initialize($s->gameId,$status,$completeness);$db->commit();return $s->gameId;
}
function register(int $game,string $id,?int $expected=null):array {
    global $db,$journal;
    $expected??=(int)$db->fetchOne("SELECT version FROM games WHERE ind=$game")['version'];
    return $journal->registerProcessing($game,'host',$id,'synthetic',[],$expected);
}
/** Synthetic coordinator only: mirrors existing locks, with an explicit append before markCommitted. */
function write(array $command,HistoryBatch $batch, bool $finished=false):array {
    global $db,$repo,$journal,$history;
    $game=(int)$command['game_id'];$v=(int)$command['expected_version'];
    $db->beginTransaction();
    try {
        $journal->requireProcessingForCommit((int)$command['id'],$game,$v);
        $s=$repo->findById($game,true);check($s!==null&&$s->persistenceVersion===$v,'CAS conflict');
        $s->status=$finished?'game_over':'battle';$s->winner=$finished?'host':null;
        $s->battle=['synthetic'=>'after-'.$command['command_id']];$repo->save($s);
        $out=$history->append($game,(int)$command['id'],$v+1,$batch);
        $journal->markCommitted((int)$command['id'],$v,$v+1);$db->commit();return $out;
    } catch(Throwable $e) {if($db->inTransaction())$db->rollback();throw $e;}
}
function counts(int $game):array {
    global $db;$out=[];
    foreach(['batches','actions','events','steps']as$t)$out[$t]=(int)$db->fetchOne("SELECT COUNT(*) n FROM battle_history_$t WHERE game_id=$game")['n'];
    $out['game']=$db->fetchOne("SELECT json,version,status FROM games WHERE ind=$game");
    $out['meta']=$db->fetchOne("SELECT * FROM battle_history_matches WHERE game_id=$game");return $out;
}
$db=connection();
// Worker mode uses the already-created synthetic schema only.
if (($argv[1]??'')==='worker') {
    $repo=new GameRepository($db);$journal=new CommandJournalRepository($db);$history=new HistoryRepository($db);
    $id=(int)$argv[2];$marker=$argv[3];$hold=($argv[4]??'')==='hold';$command=$journal->findById($id);
    $v=(int)$command['expected_version'];$game=(int)$command['game_id'];$start=microtime(true);
    $db->beginTransaction();
    try {
        $journal->requireProcessingForCommit($id,$game,$v);$s=$repo->findById($game,true);
        file_put_contents($marker,'locked');if($hold)usleep(900000);
        check($s->persistenceVersion===$v,'worker stale');$s->status='battle';$repo->save($s);
        $history->append($game,$id,$v+1,batch('worker-'.$id,'worker-'.$id));$journal->markCommitted($id,$v,$v+1);$db->commit();
        echo json_encode(['status'=>'committed','elapsed'=>microtime(true)-$start]);exit(0);
    }catch(Throwable $e){if($db->inTransaction())$db->rollback();echo json_encode(['status'=>'conflict','error'=>$e->getMessage()]);exit(2);}
}
$results=[];
function test(string $name, callable $run):void {
    global $results;
    try{$detail=$run();$results[]=['name'=>$name,'status'=>'PASS','detail'=>$detail];echo "PASS $name\n";}
    catch(Throwable $e){$results[]=['name'=>$name,'status'=>'FAIL','error'=>$e->getMessage()];echo "FAIL $name: ".$e->getMessage()."\n";}
}
// Fresh disposable DB only: never drop or rewrite an existing schema.
if($db->fetchOne("SHOW TABLES LIKE 'games'")!==null)throw new RuntimeException('Fresh disposable database required; retain previous results');
$db->query("CREATE TABLE games (ind INT NOT NULL AUTO_INCREMENT PRIMARY KEY,host_id INT NOT NULL,player_id INT NOT NULL,status VARCHAR(32) NOT NULL,json LONGTEXT NOT NULL,version INT NOT NULL DEFAULT 0,date DATETIME,active INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$repo=new GameRepository($db);$journal=new CommandJournalRepository($db);$history=new HistoryRepository($db);
$migration=file_get_contents(__DIR__.'/migrations/20261020_battle_history.sql');
function migrate(string $sql):void {
    global $db;
    $sql=preg_replace('/^--.*$/m','',$sql);
    foreach(explode(';',$sql)as$statement)if(trim($statement)!=='')$db->query(trim($statement));
}
test('migration creates five InnoDB utf8mb4 tables without TRIGGER privilege',function()use($migration,$db){
    $grants=$db->fetchAll('SHOW GRANTS FOR CURRENT_USER');$grantText=json_encode($grants);
    check(!str_contains($grantText,'ALL PRIVILEGES')&&!preg_match('/\bTRIGGER\b/',$grantText),'test account has no TRIGGER privilege');
    check(!preg_match('/CREATE\s+TRIGGER/i',$migration),'migration has no triggers');
    migrate($migration);$tables=$db->fetchAll("SHOW TABLE STATUS WHERE Name LIKE 'battle_history_%'");check(count($tables)===5,'table count');
    foreach($tables as$t)check($t['Engine']==='InnoDB'&&str_starts_with($t['Collation'],'utf8mb4'),'engine charset');
    return ['tables'=>$tables,'privileges'=>'explicit schema grants without TRIGGER'];
});
test('migration repeat stops without changing data',function()use($migration,$db){$before=$db->fetchAll("SHOW TABLE STATUS WHERE Name LIKE 'battle_history_%'");rejects(fn()=>migrate($migration),'repeat');check(count($before)===5,'original schema preserved');});
test('ReplayState exact v1 contract, hidden card DTO and immutable input copies',function(){
    $s=replay();ReplayState::validate($s);$bad=$s;$bad['pending']=['token'=>'secret'];rejects(fn()=>ReplayState::validate($bad),'pending');
    $bad=$s;$bad['version']=2;rejects(fn()=>ReplayState::validate($bad),'future');
    $bad=$s;$bad['cards'][]=$bad['cards'][0];rejects(fn()=>ReplayState::validate($bad),'duplicate instance');
    $bad=$s;$bad['cards'][0]['flags']=['secret'=>true];rejects(fn()=>ReplayState::validate($bad),'internal flags');
    $hidden=$s;$hidden['cards'][0]['definition']=null;$hidden['catalog']=[];$hidden['cards'][0]['hp']=null;$hidden['cards'][0]['strike']=null;ReplayState::validate($hidden);
    $b=new HistoryBatch([action('frozen')],[event('frozen')],[capture('frozen','frozen',$s,true)]);$hash=$b->hash();$s['cards'][0]['closed']=true;
    check(!$b->data()['captures'][0]['state']['cards'][0]['closed']&&$b->hash()===$hash,'copied at capture');
});
test('atomic state + events + two different snapshots + journal, same version',function()use($history,$db){
    $g=newGame();$c=register($g,'two-captures');$a=action('two');$a['end']=2;
    $b=new HistoryBatch([$a],[event('two'),event('two')],[capture('two','two:1',replay(false),true,false,1),capture('two','two:2',replay(true),false,false,2)]);
    $out=write($c,$b);$rows=$db->fetchAll("SELECT * FROM battle_history_steps WHERE game_id=$g ORDER BY step_seq");
    check(count($rows)===2&&$rows[0]['persistence_version']===$rows[1]['persistence_version'],'same final version');
    check($rows[0]['state_json']!==$rows[1]['state_json']&&$rows[0]['state_hash']!==$rows[1]['state_hash'],'different states');
    check($db->fetchOne('SELECT status FROM battle_command_journal WHERE id='.$c['id'])['status']==='committed','journal committed');
    check((int)$out['first_event_seq']===1&&(int)$out['last_step_seq']===2,'seq');
    $read=$history->steps($g,'host');check(count($read)===2,'read captures');
    check($history->steps($g,'host')===$read,'read stable');
    check($db->fetchAll("SELECT * FROM battle_history_steps WHERE game_id=$g ORDER BY step_seq")===$rows,'read does not rewrite snapshots or hashes');
    check($history->committedBatch($g,(int)$c['id'])['batch_hash']===$b->hash(),'retry outcome');
});
foreach(['event','snapshot']as$fault)test('full rollback on mandatory '.$fault.' SQL insertion failure',function()use($fault,$db){
    $g=newGame();$c=register($g,'fail-'.$fault);$before=counts($g);
    $table=$fault==='event'?'battle_history_events':'battle_history_steps';
    $order=$fault==='event'?'command_order':'capture_order';
    // Isolated-only CHECK injection, scoped to this synthetic game; no trigger privilege needed.
    $db->query("ALTER TABLE $table ADD CONSTRAINT stage4a_fault CHECK (game_id<>$g OR $order<>2)");
    try{
        $a=action('failure');$a['end']=2;$b=new HistoryBatch([$a],[event('failure'),event('failure')],
            [capture('failure','failure:1',replay(),true,false,1),capture('failure','failure:2',replay(true),false,false,2)]);
        rejects(fn()=>write($c,$b),'mandatory SQL write');
        check(counts($g)===$before,'state/actions/events/steps/batch/sequences all rolled back');
        check($db->fetchOne('SELECT status FROM battle_command_journal WHERE id='.$c['id'])['status']==='processing','pre-registered processing survives');
    }finally{$db->query("ALTER TABLE $table DROP CONSTRAINT stage4a_fault");}
    write($c,$b);check(counts($g)['events']===2&&counts($g)['steps']===2,'retry after rollback allocates no gaps');
});
test('duplicate boundary in later command rolls back whole command',function(){
    $g=newGame();write(register($g,'dedup:1'),batch('dedup:1','stable',true));$c=register($g,'dedup:2');$before=counts($g);
    rejects(fn()=>write($c,batch('dedup:2','stable')),'boundary unique');check(counts($g)===$before,'rollback duplicate boundary');
});
test('journal command identity retry and unknown-commit reconciliation without Engine',function()use($history,$journal){
    $g=newGame();$c=register($g,'retry');$b=batch('retry','retry',true);$out=write($c,$b);$before=counts($g);
    // Deliberately discard successful response, reconnect and consult durable journal + batch.
    $fresh=connection();$h=new HistoryRepository($fresh);$j=new CommandJournalRepository($fresh);
    $same=$j->registerProcessing($g,'host','retry','synthetic',[],(int)$c['expected_version']);
    check(!$same['is_new']&&$same['status']==='committed','same command');
    check($h->committedBatch($g,(int)$c['id'])===$out,'durable same outcome');
    rejects(fn()=>write($c,$b),'no reappend');check(counts($g)===$before,'no duplicate retry');
    rejects(fn()=>$j->registerProcessing($g,'host','retry','different',[],(int)$c['expected_version']),'payload conflict');
    check($h->committedBatch($g+100000,(int)$c['id'])===null,'wrong game no outcome');
});
test('Hydra combat -> voluntary End -> immediate end_turn preserves three frames',function()use($history,$db){
    $g=newGame();write(register($g,'hydra-combat'),batch('hydra-combat','hydra:combat',true));
    $first=$db->fetchOne("SELECT state_json,state_hash FROM battle_history_steps WHERE game_id=$g AND step_seq=1");
    foreach(['series_end','turn_end']as$type){$a=action($type);write(register($g,$type),new HistoryBatch([$a],[event($type)],[capture($type,'hydra:'.$type,replay(true),false,false,1,$type)]));}
    $rows=$history->steps($g,'public');check(array_column($rows,'step_type')===['combat','series_end','turn_end'],'three semantic steps');
    check(array_map(fn($r)=>json_decode($r['replay_json'],true)['cards'][0]['closed'],$rows)===[false,true,true],'closed sequence');
    check($db->fetchOne("SELECT state_json,state_hash FROM battle_history_steps WHERE game_id=$g AND step_seq=1")===$first,'earlier capture unchanged');
    return ['types'=>array_column($rows,'step_type'),'closed'=>[false,true,true]];
});
test('natural Hydra ending closes in combat, no artificial End step',function()use($history){
    $g=newGame();write(register($g,'natural'),new HistoryBatch([action('natural')],[event('natural')],[capture('natural','natural',replay(true),true)]));
    $r=$history->steps($g,'host');check(count($r)===1&&$r[0]['step_type']==='combat'&&json_decode($r[0]['replay_json'],true)['cards'][0]['closed'],'one closed combat');
});
test('open action spans commands, child cause, cancellation lifecycle',function()use($db){
    $g=newGame();write(register($g,'open'),new HistoryBatch([action('root','open')],[event('root')],[]));
    $child=action('child');$child['root']='root';$child['parent']='root';$child['end']=1;
    $e=event('child');$e['cause']=1;
    $root=['id'=>'root','status'=>'cancelled','end'=>2,'completion'=>['remaining'=>'cancelled','prior'=>'retained']];
    write(register($g,'cancel'),new HistoryBatch([$child,$root],[$e,event('root')],[capture('root','cancelled',replay(),false,false,2)]));
    $a=$db->fetchOne("SELECT * FROM battle_history_actions WHERE game_id=$g AND action_id='root'");
    check($a['lifecycle']==='cancelled'&&(int)$a['started_event_seq']===1&&(int)$a['ended_event_seq']===3,'spanning lifecycle');
    check(!str_contains($a['completion_json'],'pending'),'no full pending copy');
});
test('root and child may both settle within a single command',function()use($db){
    $g=newGame();$root=action('same-root');$root['end']=3;$child=action('same-child');$child['root']='same-root';$child['parent']='same-root';$child['start']=2;$child['end']=2;
    write(register($g,'same-command'),new HistoryBatch([$root,$child],[event('same-root'),event('same-child'),event('same-root')],[capture('same-root','same-command',replay(),true,false,3)]));
    check(count($db->fetchAll("SELECT action_id FROM battle_history_actions WHERE game_id=$g AND lifecycle='settled'"))===2,'both settled');
});
test('event historical projections, hidden event, reveal later, full history locked until final',function()use($history){
    $g=newGame();$a=action('secret');$a['end']=2;$hidden=event('secret');$hidden['public']=null;$hidden['player']=null;$hidden['host']=['known'=>'owner-secret'];
    $allHidden=$hidden;$allHidden['host']=null;
    write(register($g,'secret'),new HistoryBatch([$a],[$hidden,$allHidden],[capture('secret','secret',replay(),true,false,2)]));
    check(count($history->events($g,'player'))===0&&count($history->events($g,'public'))===0&&count($history->events($g,'host'))===1,'visibility');
    rejects(fn()=>$history->completedServerHistory($g),'active full read');
    $old=$history->events($g,'host');$reveal=event('reveal');$reveal['player']=['message'=>'newly revealed'];
    write(register($g,'reveal'),new HistoryBatch([action('reveal')],[$reveal],[]));
    check($history->events($g,'host')[0]===$old[0],'historical view immutable');
    check(count($history->events($g,'player'))===1&&!str_contains(json_encode($history->events($g,'player')),'server-only'),'server payload isolated');
    rejects(fn()=>$history->steps($g,'server'),'no full projection alias');
    write(register($g,'final'),new HistoryBatch([action('final')],[event('final')],[capture('final','final',replay(true,true),false,true)],true),true);
    $full=$history->completedServerHistory($g);check(count($full['events'])===4&&str_contains(json_encode($full),'server-only'),'confirmed full unlock');
    rejects(fn()=>write(register($g,'late'),batch('late','late')),'no records after final');
});
test('metadata disabled, incomplete, completed-without-initial remain distinct',function()use($db){
    $g=newGame('disabled','none');$before=counts($g);rejects(fn()=>write(register($g,'disabled'),batch('disabled','disabled')),'disabled');check(counts($g)===$before,'disabled unchanged');
    $g=newGame('recording','incomplete');write(register($g,'partial'),new HistoryBatch([action('partial')],[event('partial')],[capture('partial','partial',replay(true,true),false,true)],true),true);
    $m=$db->fetchOne("SELECT * FROM battle_history_matches WHERE game_id=$g");check($m['recording_status']==='completed'&&$m['completeness']==='incomplete'&&!(bool)$m['has_initial'],'completion does not imply complete history');
});
test('snapshot projections preserve hidden information independently of server state',function()use($history){
    $g=newGame();$server=replay();$hidden=$server;$hidden['catalog']=[];
    $hidden['cards'][0]['definition']=null;$hidden['cards'][0]['hp']=null;$hidden['cards'][0]['strike']=null;$hidden['cards'][0]['revealed']=false;
    $c=capture('private','private',$server,true);$c['public']=$hidden;$c['player']=$hidden;
    write(register($g,'private'),new HistoryBatch([action('private')],[event('private')],[$c]));
    check(!str_contains($history->steps($g,'player')[0]['replay_json'],'Synthetic Hydra'),'opponent catalog redacted');
    check(str_contains($history->steps($g,'host')[0]['replay_json'],'Synthetic Hydra'),'owner view retains knowledge');
});
test('integrity check rejects modified full state, every projection and checksum without repair',function()use($history,$db){
    $g=newGame();$c=register($g,'integrity');$state=replay();
    $state['catalog']['hydra:v1']['name']='Синтетическая Гидра';
    $state['catalog']['hydra:v1']['description']="line one\nline two / slash \\ quote \"";
    write($c,new HistoryBatch([action('integrity')],[event('integrity')],[capture('integrity','integrity',$state,true)]));
    $original=$db->fetchOne("SELECT * FROM battle_history_steps WHERE game_id=$g");
    $bytes=json_encode(['format'=>'capture-sha256-v1','schema_version'=>1,'state_json'=>$original['state_json'],
        'public_json'=>$original['public_json'],'host_json'=>$original['host_json'],'player_json'=>$original['player_json']],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    check($original['state_hash']===hash('sha256',$bytes),'hash covers final stored bytes, not mutable source arrays');
    foreach(['state_json','public_json','host_json','player_json','state_hash']as$column){
        $changed=$column==='state_hash'?str_repeat('0',64):'{}';
        $db->query("UPDATE battle_history_steps SET $column='".$db->escape($changed)."' WHERE game_id=$g");
        foreach(['public','host','player']as$view){
            try{$history->steps($g,$view);throw new RuntimeException('Tampered frame was returned');}
            catch(UnexpectedValueException $e){check(str_contains($e->getMessage(),'integrity failure'),'explicit integrity failure');}
        }
        $row=$db->fetchOne("SELECT * FROM battle_history_steps WHERE game_id=$g");
        check($row[$column]===$changed,'reader did not repair modified data');
        if($column!=='state_hash')check($row['state_hash']===$original['state_hash'],'reader did not regenerate checksum');
        // Restore original bytes solely in the isolated fixture, never in the production API.
        $db->query("UPDATE battle_history_steps SET $column='".$db->escape($original[$column])."' WHERE game_id=$g");
    }
    check(count($history->steps($g,'player'))===1,'original bytes still verify');
    $legacy=hash('sha256',$original['state_json']);$db->query("UPDATE battle_history_steps SET state_hash='$legacy' WHERE game_id=$g");
    rejects(fn()=>$history->steps($g,'host'),'state-only legacy hash must not authorize unverified projections');
    check($db->fetchOne("SELECT state_hash FROM battle_history_steps WHERE game_id=$g")['state_hash']===$legacy,'legacy hash not rewritten');
});
test('completed full-history reader also refuses corrupted snapshot',function()use($history,$db){
    $g=newGame();write(register($g,'integrity-final'),new HistoryBatch([action('integrity-final')],[event('integrity-final')],
        [capture('integrity-final','integrity-final',replay(true,true),true,true)],true),true);
    $before=$history->completedServerHistory($g);check(count($before['steps'])===1,'valid completed frame');
    $db->query("UPDATE battle_history_steps SET state_json='{}' WHERE game_id=$g");
    try{$history->completedServerHistory($g);throw new RuntimeException('Corrupt full history was returned');}
    catch(UnexpectedValueException $e){check(str_contains($e->getMessage(),'integrity failure'),'full-reader integrity error');}
    check($db->fetchOne("SELECT state_hash FROM battle_history_steps WHERE game_id=$g")['state_hash']===$before['steps'][0]['state_hash'],'full reader never rehashes');
});
test('retry and duplicate semantic boundary preserve exact original snapshot bytes',function()use($history,$db){
    $g=newGame();$c=register($g,'append-only');$b=batch('append-only','append-only',true);$out=write($c,$b);
    $before=$db->fetchAll("SELECT * FROM battle_history_steps WHERE game_id=$g ORDER BY step_seq");
    check($history->committedBatch($g,(int)$c['id'])===$out,'same committed batch');
    rejects(fn()=>write($c,$b),'duplicate command');
    rejects(fn()=>write(register($g,'other-command'),batch('other-action','append-only')),'duplicate semantic boundary');
    check($db->fetchAll("SELECT * FROM battle_history_steps WHERE game_id=$g ORDER BY step_seq")===$before,'all snapshot bytes and hashes unchanged');
    $src=file_get_contents(__DIR__.'/../src/Core/BattleHistory/HistoryRepository.php');
    check(!preg_match('/(?:UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+battle_history_steps\b/i',$src),'no snapshot mutation API');
    check(!preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i',$src),'no upsert rewrite');
});
test('causal and lifecycle references cannot point to future or unrelated events',function(){
    $g=newGame();$e=event('causal');$e['cause']=2;$before=counts($g);
    rejects(fn()=>write(register($g,'bad-cause'),new HistoryBatch([action('causal')],[$e],[])),'future cause');check(counts($g)===$before,'cause rollback');
    $a=action('one');$a['end']=2;$b=action('two');$b['start']=2;$b['end']=2;
    rejects(fn()=>write(register($g,'bad-end'),new HistoryBatch([$a,$b],[event('one'),event('two')],[])),'wrong lifecycle event');check(counts($g)===$before,'lifecycle rollback');
});
test('schema constraints event/capture order and per-game sequences',function()use($db){
    $g=newGame();write(register($g,'constraints'),batch('constraints','constraints',true));
    $e=$db->fetchOne("SELECT * FROM battle_history_events WHERE game_id=$g");
    rejects(fn()=>$db->query("INSERT INTO battle_history_events SELECT * FROM battle_history_events WHERE game_id=$g"),'event seq unique');
    $e['event_seq']=2; // distinct PK, same command_order: independently exercises its unique constraint
    rejects(fn()=>insertFixture('battle_history_events',$e),'command event order unique');
    $row=$db->fetchOne("SELECT * FROM battle_history_steps WHERE game_id=$g");
    unset($row['initial_guard'],$row['final_guard']);$row['step_seq']=2;$row['boundary_key']='other';$row['is_initial']=0;
    rejects(fn()=>insertFixture('battle_history_steps',$row),'capture order unique');
    $row['capture_order']=2;$row['is_initial']=1;
    rejects(fn()=>insertFixture('battle_history_steps',$row),'initial unique');
    $row['is_initial']=0;$row['boundary_key']='constraints';
    rejects(fn()=>insertFixture('battle_history_steps',$row),'boundary unique');
});
function insertFixture(string $table,array $row):void {
    global $db;$values=[];foreach($row as$v)$values[]=$v===null?'NULL':"'".$db->escape((string)$v)."'";
    $db->query('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',$values).')');
}
test('out of transaction writes and unsupported format fail closed',function()use($history,$db){
    rejects(fn()=>$history->initialize(1),'transaction');$g=newGame();$e=event('version');$e['version']=2;
    $before=counts($g);rejects(fn()=>write(register($g,'v2'),new HistoryBatch([action('version')],[$e],[])),'version');check(counts($g)===$before,'version rollback');
    $db->beginTransaction();try{rejects(fn()=>$history->events($g,'host'),'uncommitted reads');}finally{$db->rollback();}
});
test('two processes serialize game writes with contiguous event/step sequences',function()use($db){
    $g=newGame();$v=(int)$db->fetchOne("SELECT version FROM games WHERE ind=$g")['version'];$a=register($g,'concurrent-a',$v);$b=register($g,'concurrent-b',$v+1);
    $dir=getenv('STAGE4A_RESULTS');$marker=$dir.'/worker-a-'.bin2hex(random_bytes(4));
    $cmd=[PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'worker',(string)$a['id'],$marker,'hold'];
    $p1=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes1);check(is_resource($p1),'worker1');
    $until=microtime(true)+8;while(!file_exists($marker)&&microtime(true)<$until)usleep(10000);check(file_exists($marker),'worker acquired game lock');
    $cmd[5]=(string)$b['id'];$cmd[6]=$marker.'-b';$cmd[7]='now';
    $p2=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes2);check(is_resource($p2),'worker2');
    $o1=stream_get_contents($pipes1[1]);$err1=stream_get_contents($pipes1[2]);$o2=stream_get_contents($pipes2[1]);$err2=stream_get_contents($pipes2[2]);
    foreach([$pipes1,$pipes2]as$pipes)foreach($pipes as$pipe)fclose($pipe);
    $code1=proc_close($p1);$code2=proc_close($p2);check($code1===0&&$code2===0,'workers '.$o1.$o2.$err1.$err2);
    $r2=json_decode($o2,true);check($r2['elapsed']>0.2,'second writer waited for game lock');
    check(array_map('intval',array_column($db->fetchAll("SELECT event_seq FROM battle_history_events WHERE game_id=$g ORDER BY event_seq"),'event_seq'))===[1,2],'contiguous events');
    check(array_map('intval',array_column($db->fetchAll("SELECT step_seq FROM battle_history_steps WHERE game_id=$g ORDER BY step_seq"),'step_seq'))===[1,2],'contiguous steps');return [$o1,$o2];
});
test('concurrent stale command rolls back without allocating any history',function()use($db){
    $g=newGame();$v=(int)$db->fetchOne("SELECT version FROM games WHERE ind=$g")['version'];$a=register($g,'stale-a',$v);$b=register($g,'stale-b',$v);
    $dir=getenv('STAGE4A_RESULTS');$marker=$dir.'/stale-a-'.bin2hex(random_bytes(4));
    $cmd=[PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'worker',(string)$a['id'],$marker,'hold'];
    $p1=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes1);check(is_resource($p1),'worker1');
    $until=microtime(true)+8;while(!file_exists($marker)&&microtime(true)<$until)usleep(10000);check(file_exists($marker),'stale worker lock');
    $cmd[5]=(string)$b['id'];$cmd[6]=$marker.'-b';$cmd[7]='now';
    $p2=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes2);check(is_resource($p2),'worker2');
    $outputs=[];foreach([$pipes1,$pipes2]as$pipes){$outputs[]=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);foreach($pipes as$pipe)fclose($pipe);}
    $code1=proc_close($p1);$code2=proc_close($p2);check($code1===0&&$code2===2,'one stale writer '.json_encode($outputs));
    check(counts($g)['events']===1&&counts($g)['steps']===1&&counts($g)['batches']===1,'only winning history');
    check($db->fetchOne('SELECT status FROM battle_command_journal WHERE id='.$b['id'])['status']==='processing','loser still registered, uncommitted');return $outputs;
});
$proof=$db->fetchOne('SELECT @@datadir datadir,@@port port,DATABASE() db,VERSION() version');
$out=['environment'=>$proof,'results'=>$results];
file_put_contents(getenv('STAGE4A_RESULTS').'/summary.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
exit(count(array_filter($results,fn($r)=>$r['status']==='FAIL'))?1:0);
