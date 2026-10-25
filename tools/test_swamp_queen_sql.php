<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli'||!in_array('--isolated',$argv,true)||file_exists(__DIR__.'/../.git')) {
    fwrite(STDERR,"Use disposable source copy and --isolated\n");exit(2);
}
require __DIR__.'/test_swamp_queen.php';
use Berserk\Core\{Command, CommandJournalRepository, Db, DbQueryException, Engine, GameRepository, GameState, Result, StaleStateException};
$sqSqlBefore=$checks;
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new Db(['host'=>'127.0.0.1:33991','user'=>'root','pass'=>'','name'=>'berserk_lucktest_stage3_c7a111282b']);
$proof=$db->fetchOne('SELECT @@port AS port,@@datadir AS dir,VERSION() AS version');
efCheck((int)$proof['port']===33991&&str_ends_with(strtolower(str_replace('\\','/',$proof['dir'])),'/stage2a-final-validation/mysql-data/'),'verified isolated database');
$journal=new CommandJournalRepository($db);
function sqSqlEngine(): Engine {
    global $db;
    // The retained test catalog lacks display-name columns; canonical printed
    // names are supplied explicitly. Production uses Engine's cards.name lookup.
    $names=['s1_130'=>'Королева топи','s1_1'=>'Кочевник','s1_128'=>'Тролль','s1_125'=>'Троллок','enemy'=>'Тролль'];
    return new Engine($db,cardNameLookup:static fn($ids)=>array_intersect_key($names,array_fill_keys($ids,true)));
}
function qsRegister(GameState $s,string $owner,string $type,array $payload=[]): array {
    global $journal;
    return $journal->registerProcessing($s->gameId,$owner,'sq_'.bin2hex(random_bytes(10)),$type,$payload,$s->persistenceVersion);
}
function qsExec(GameRepository $repo,GameState &$s,string $owner,string $type,array $payload=[]): void {
    global $journal;
    $entry=qsRegister($s,$owner,$type,$payload);
    $repo->executeLockedCommand($s,$journal,(int)$entry['id'],static function(GameState $w)use($owner,$type,$payload):Result{
        efCheck($w->historyRecorder!==null,'mandatory recorder under lock');
        $r=sqSqlEngine()->apply($w,$owner,new Command($type,$payload));efCheck($r->success,'SQL native command '.$type.': '.$r->error);return $r;
    });
    $s=$repo->findById($s->gameId);
}
function qsDrain(GameRepository $repo,GameState &$s): void {
    for($i=0;$i<30&&!empty($s->battle['turn_phase']);$i++)qsExec($repo,$s,$s->battle['active'],'turn_ack');
    efCheck(empty($s->battle['turn_phase']),'native phase drained');
}
function qsSeed(int $deck): array {
    global $db,$journal;
    $fixture=sqState($deck);$catalog=[];
    foreach($fixture->cards as $c)$catalog[$c->ukid]=['revision'=>'sq-test','name'=>$c->ukid,'image'=>'','description'=>'',
        'type'=>$c->type,'element'=>$c->element,'classes'=>[],'price'=>$c->price];
    $repo=new GameRepository($db,$catalog);$s=$repo->create(101,202);$entry=qsRegister($s,'host','confirm_place');
    $repo->executeLockedCommand($s,$journal,(int)$entry['id'],static function(GameState $w)use($fixture):Result{
        efCheck($w->historyRecorder!==null&&$w->journalSchema===1,'native journal creation retained');
        $w->cards=$fixture->cards;$w->nextInstanceId=$fixture->nextInstanceId;$w->players=$fixture->players;
        $w->status='place';$w->firstPlayer='host';$w->battle=$fixture->battle;
        $w->players['host']->side=1;$w->players['player']->side=2;$w->players['player']->confirm('place');
        return sqSqlEngine()->apply($w,'host',new Command('confirm_place'));
    });
    $s=$repo->findById($s->gameId);qsDrain($repo,$s);
    return [$repo,$s];
}
function qsSnapshot(GameState $s): array {
    global $db;
    return [$db->fetchOne('SELECT json,version FROM games WHERE ind='.$s->gameId),
        $db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id='.$s->gameId),
        $db->fetchOne('SELECT COUNT(*) AS n FROM battle_history_events WHERE game_id='.$s->gameId),
        $db->fetchOne('SELECT COUNT(*) AS n FROM battle_history_batches WHERE game_id='.$s->gameId)];
}
function qsFaultRetry(GameRepository $repo,GameState &$s,string $owner,string $type,array $payload=[]): void {
    global $db,$journal;
    $entry=qsRegister($s,$owner,$type,$payload);$before=qsSnapshot($s);$version=$s->persistenceVersion;
    foreach(['json','sql','journal'] as $fault){
        $failed=false;
        try{
            $repo->executeLockedCommand($s,$journal,(int)$entry['id'],static function(GameState $w)use($owner,$type,$payload,$fault,$db):Result{
                efCheck($w->historyRecorder!==null,'transaction has live journal');
                $r=sqSqlEngine()->apply($w,$owner,new Command($type,$payload));efCheck($r->success,'ability resolves before fault '.$type);
                if($fault==='json')$w->battle['invalid_json_fixture']=NAN;
                elseif($fault==='sql')$w->status=str_repeat('invalid_status_',20);
                else $db->query("UPDATE battle_history_matches SET recording_status='disabled' WHERE game_id=".$w->gameId);
                return $r;
            });
        }catch(TypeError $e){if($fault!=='json'||!str_contains($e->getMessage(),'Db::escape()'))throw $e;$failed=true;}
        catch(DbQueryException $e){if($fault!=='sql'||$e->getCode()!==1406)throw $e;$failed=true;}
        catch(LogicException $e){if($fault!=='journal')throw $e;$failed=true;}
        efCheck($failed&&qsSnapshot($s)===$before,'atomic '.$type.'/'.$fault.' rollback');
        efCheck($journal->findById((int)$entry['id'])['status']==='processing','same command remains retryable');
    }
    $stale=$repo->findById($s->gameId);
    $repo->executeLockedCommand($s,$journal,(int)$entry['id'],static function(GameState $w)use($owner,$type,$payload):Result{
        $r=sqSqlEngine()->apply($w,$owner,new Command($type,$payload));efCheck($r->success,'successful actual retry');return $r;
    });
    $s=$repo->findById($s->gameId);$after=qsSnapshot($s);
    $duplicate=$journal->registerProcessing($s->gameId,$owner,$entry['command_id'],$type,$payload,$version);
    efCheck(!$duplicate['is_new']&&$duplicate['status']==='committed'&&qsSnapshot($s)===$after,'committed duplicate does not execute');
    $conflict=qsRegister($stale,$owner,$type,$payload);$ran=false;$caught=false;
    try{$repo->executeLockedCommand($stale,$journal,(int)$conflict['id'],static function(GameState $w)use(&$ran):Result{$ran=true;return Result::ok();});}
    catch(StaleStateException){$caught=true;}
    efCheck($caught&&!$ran&&qsSnapshot($s)===$after,'CAS rejects before ability execution');
}
$schema=[];
foreach(['games','cards','battle_command_journal','battle_history_matches','battle_history_actions','battle_history_events','battle_history_steps','battle_history_batches'] as $table){
    $status=$db->fetchOne("SHOW TABLE STATUS LIKE '$table'");efCheck(strtolower($status['Engine'])==='innodb','InnoDB '.$table);
    $schema[$table]=preg_replace('/AUTO_INCREMENT=\d+ /','',array_values($db->fetchOne('SHOW CREATE TABLE '.$table))[1]);
}
[$repo,$s]=qsSeed(1);qsExec($repo,$s,'host','end_turn');qsDrain($repo,$s);
qsFaultRetry($repo,$s,'player','end_turn');
efCheck($s->getCard(30)->zone==='field'&&$s->getCard(30)->row===2&&$s->getCard(30)->col===3,'automatic summon survives real reload');
efCheck($s->getCard(30)->closed&&empty($s->battle['pending_swamp_queen_summon']),'native arrival and completion saved');
[$repo,$s]=qsSeed(3);qsExec($repo,$s,'host','end_turn');qsDrain($repo,$s);qsExec($repo,$s,'player','end_turn');
efCheck(count($s->battle['pending_swamp_queen_summon']['candidate_ids'])===3,'mandatory deck choice persisted');
qsFaultRetry($repo,$s,'host','choose_swamp_queen_card',['card_id'=>31]);
efCheck($s->getCard(31)->zone==='field'&&$s->getCard(30)->zone==='deck','only chosen instance moves after SQL retry');
efCheck(empty($s->battle['pending_swamp_queen_summon']),'choice cannot be replayed after completion');
[$repo,$s]=qsSeed(1);
qsFaultRetry($repo,$s,'host','action',['card_id'=>1,'target_id'=>20,'action_key'=>'prophecy_wounds']);
$p=$s->battle['pending_prophecy']['swamp_queen'];
efCheck($p['target_id']===20&&$p['name_match']&&$p['value']===2&&!$p['applied']&&$s->getCard(20)->hp===20,'prophecy original target/name/result saved before damage');
efCheck($s->getCard(1)->closed,'tap saved before prophecy completion');
qsFaultRetry($repo,$s,'host','close_prophecy');
efCheck($s->getCard(20)->hp===18&&empty($s->battle['pending_prophecy'])&&$s->battle['strike']['prophecy_result']['applied'],'final wounds commit once with continuation drained');
foreach($schema as $table=>$sql)efCheck(preg_replace('/AUTO_INCREMENT=\d+ /','',array_values($db->fetchOne('SHOW CREATE TABLE '.$table))[1])===$sql,'no schema change '.$table);
echo json_encode(['environment'=>$proof,'new_checks'=>$checks-$sqSqlBefore,'status'=>'PASS','catalog_names'=>'injected fixture catalog; retained SQL cards lacks name'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
