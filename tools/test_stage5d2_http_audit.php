<?php
/** Real HTTP audit. Run ONLY in a disposable code copy with an isolated DB.
 * STAGE5D2_AUDIT_URL=http://127.0.0.1:8797/index.php
 * STAGE5D2_AUDIT_DATADIR=<absolute disposable MariaDB datadir>
 * Exit 1 means an acceptance criterion failed (not an expected-success test).
 */
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,Db,GameRepository,CommandJournalRepository,CardInstance,GameLog};
Autoloader::register();
Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$c=require __DIR__.'/../config/db.php';
$url=getenv('STAGE5D2_AUDIT_URL') ?: '';
$expectedDir=realpath(getenv('STAGE5D2_AUDIT_DATADIR') ?: '/nonexistent');
if (!preg_match('~^http://127\.0\.0\.1:\d+/index\.php$~D',$url)
    || !str_starts_with($c['name'],'berserk_stage5d2_test') || !$expectedDir
    || file_exists(__DIR__.'/../.git')) throw new RuntimeException('Disposable copy / explicit isolated DB required');
$db=new Db($c);
$meta=$db->fetchOne('SELECT DATABASE() AS db,@@port AS port,@@datadir AS datadir,@@version AS version');
if (realpath($meta['datadir']) !== $expectedDir || $meta['db'] !== $c['name']) throw new RuntimeException('Wrong DB instance');
$tables=$db->fetchAll('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
foreach($tables as $table) if($table['ENGINE']!=='InnoDB') throw new RuntimeException('All tables must be InnoDB');
// Prove that the HTTP server serves this disposable code copy before any mutation.
$probeName='stage5d2-probe-'.bin2hex(random_bytes(12)).'.txt';
$probePath=__DIR__.'/../www/'.$probeName;
$probeToken=bin2hex(random_bytes(24));
file_put_contents($probePath,$probeToken);
try {
    $probe=@file_get_contents(substr($url,0,-strlen('index.php')).$probeName,false,stream_context_create(['http'=>['timeout'=>5,'follow_location'=>0]]));
    if($probe!==$probeToken)throw new RuntimeException('HTTP server does not serve this disposable copy');
} finally {unlink($probePath);}
$repo=new GameRepository($db);
$journal=new CommandJournalRepository($db);
$rows=$db->fetchAll('SELECT * FROM cards ORDER BY ind LIMIT 2');
if(count($rows)!==2) throw new RuntimeException('Two catalogue cards required');
$results=[];
function check(string $name,string $expected,bool $pass,array $actual): void {
    global $results;
    $results[]=['scenario'=>$name,'expected'=>$expected,'actual'=>$actual,'result'=>$pass?'PASS':'FAIL'];
    echo ($pass?'PASS ':'FAIL ').$name."\n";
}
function request(string $query,?array $post=null): array {
    global $url;
    $options=['method'=>$post===null?'GET':'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>15];
    if($post!==null){$options['header']='Content-Type: application/x-www-form-urlencoded';$options['content']=http_build_query($post);}
    $body=file_get_contents($url.'?'.$query,false,stream_context_create(['http'=>$options]));
    $headers=$http_response_header ?? [];
    preg_match('~HTTP/\S+ (\d+)~',$headers[0]??'', $m);
    $location=null;
    foreach($headers as $h) if(stripos($h,'Location: ')===0)$location=substr($h,10);
    $json=json_decode((string)$body,true);
    return ['status'=>(int)($m[1]??0),'location'=>$location,'json'=>$json,'json_valid'=>is_array($json),'body_prefix'=>is_array($json)?null:substr((string)$body,0,400)];
}
function fixture(string $pending='',int $host=1,int $player=2): object {
    global $repo,$rows;
    $s=$repo->create($host,$player); $s->status='battle';
    $s->battle=['turn'=>1,'active'=>'host','strike'=>null];
    foreach($rows as $i=>$r)$s->addCard(new CardInstance(instanceId:$i+1,ukid:(string)$r['ukid'],owner:$i===0?'host':'player',zone:'field',row:$i===0?3:4,col:1,hp:10,hpMax:10,move:2,moveMax:2,strikeWeak:1,strikeMedium:2,strikeStrong:3,prop:['save_coins'=>true,'coins'=>['max_value'=>3]]));
    if($pending!=='')$s->battle['pending_cell_marker_pick']=['owner'=>$pending,'card_id'=>1,'marker'=>'bonfire','label'=>'Костёр','cells'=>['3_1','4_5']];
    $s->bumpVersion(); $repo->save($s); return $repo->findById($s->gameId);
}
function persisted(object $s): array {
    global $db;
    return ['game_log'=>GameLog::read($s->gameId),'game'=>$db->fetchOne('SELECT status,version,json FROM games WHERE ind='.$s->gameId),'journal'=>$db->fetchAll('SELECT command_id,status,before_version,after_version FROM battle_command_journal WHERE game_id='.$s->gameId.' ORDER BY id')];
}
function query(object $s,string $role='first',string $route='battle_command'): string {return "$role&game=$s->gameId&ajax=$route";}
function post(object $s,string $id='audit'): array {return ['cmd'=>'gain_coin','card_id'=>'1','expected_version'=>(string)$s->persistenceVersion,'command_id'=>$id];}
function brief(array $r): array {unset($r['json']);return $r;}

$s=fixture();$before=persisted($s);
foreach(['cmd=end_turn','cmd[]=end_turn','cmd=end_turn&cmd[]=resign','cmd[]=resign&cmd=end_turn','cmd[deep][x]=resign','cmd=resign&debug_roll=6*'] as $raw){
    $r=request("first&game=$s->gameId&$raw&sel=1&mode=move");
    check('legacy '.$raw,'302 clean Location; unchanged State/version/journal',$r['status']===302 && $r['location']==="?first&game=$s->gameId&mode=move&sel=1" && persisted($s)===$before,brief($r));
}
foreach(['first','second'] as $role){
    $r=request(query($s,$role,'battle_sync').'&force=1&cmd=resign');
    check("sync $role ignores cmd",'200 snapshot, no writes',$r['status']===200 && ($r['json']['type']??'')==='snapshot' && persisted($s)===$before,brief($r));
}
$r=request(query($s,'first','battle_sync').'&since_version='.$s->persistenceVersion);
check('sync no_change','200 no_change, no writes',($r['json']['type']??'')==='no_change' && persisted($s)===$before,brief($r));
foreach([
 'cmd array'=>['cmd'=>['resign']], 'payload array'=>['card_id'=>['1']],
 'version array'=>['expected_version'=>['1']], 'id array'=>['command_id'=>['x']],
 'empty id'=>['command_id'=>''], 'empty cmd'=>['cmd'=>''],
] as $name=>$change){$r=request(query($s),array_replace(post($s),$change));check($name,'400 JSON; unchanged State/version/journal',$r['status']===400 && $r['json_valid'] && persisted($s)===$before,brief($r));}
$r=request(query($s));check('command GET method','405 JSON; no writes',$r['status']===405 && $r['json_valid'] && persisted($s)===$before,brief($r));
$r=request('game='.$s->gameId.'&ajax=battle_command',post($s));check('missing role','400 JSON; no writes',$r['status']===400 && persisted($s)===$before,brief($r));
$foreign=fixture('',101,202);$b=persisted($foreign);$r=request(query($foreign),post($foreign));check('nonparticipant','403 JSON; no writes',$r['status']===403 && persisted($foreign)===$b,brief($r));

foreach(['','&debug_roll=6*','&debug_roll[]=6'] as $extra){
 $s=fixture();$p=post($s);$r=request(query($s).$extra,$p);$after=persisted($s);$r2=request(query($s).$extra,$p);
 check('keyed success/replay'.$extra,'one committed record; version +1; identical persisted state on replay',$r['status']===200 && ($r['json']['ok']??false) && $r2['status']===200 && count($after['journal'])===1 && $after['journal'][0]['status']==='committed' && (int)$after['game']['version']===$s->persistenceVersion+1 && persisted($s)===$after,['first'=>brief($r),'replay'=>brief($r2),'journal'=>$after['journal']]);
 $changed=$p;$changed['card_id']='2';$r=request(query($s),$changed);
 check('key reused with different payload'.$extra,'409; unchanged persisted state',$r['status']===409 && persisted($s)===$after,brief($r));
 $stale=post($s,'stale');$r=request(query($s),$stale);$a=persisted($s);
 check('stale version'.$extra,'409 conflict; no game update',$r['status']===409 && $a['game']===$after['game'] && end($a['journal'])['status']==='conflict',brief($r));
}
$s=fixture();$b=persisted($s);$p=post($s);$p['card_id']='2';$r=request(query($s),$p);$a=persisted($s);$r2=request(query($s),$p);
check('rejected/replay','200 ok=false; rejected once; unchanged game',$r['status']===200 && ($r['json']['ok']??true)===false && $a['game']===$b['game'] && count($a['journal'])===1 && $a['journal'][0]['status']==='rejected' && persisted($s)===$a,['first'=>brief($r),'replay'=>brief($r2),'journal'=>$a['journal']]);
$s=fixture();$p=post($s);unset($p['command_id']);$r=request(query($s).'&debug_roll=6*',$p);$a=persisted($s);
check('mutation without command_id','every successful game mutation has a committed journal record',!($r['json']['ok']??false) || count($a['journal'])===1,['response'=>brief($r),'ok'=>$r['json']['ok']??null,'version_before'=>$s->persistenceVersion,'version_after'=>$a['game']['version'],'journal'=>$a['journal']]);

foreach(['games','battle_command_journal'] as $table){
 $s=fixture();$b=persisted($s);$id='fault-'.$table;$p=post($s,$id);
 $condition=$table==='games'?'NEW.ind='.$s->gameId:"NEW.game_id=$s->gameId AND NEW.status='committed'";
 $db->query("CREATE TRIGGER stage5d2_save_fault BEFORE UPDATE ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='stage5d2 injected write failure'; END IF; END");
 try{$r=request(query($s),$p);}finally{$db->query('DROP TRIGGER stage5d2_save_fault');}
 $a=persisted($s);$r2=request(query($s),$p);$after=persisted($s);
 check("atomic rollback $table",'500; State/version rolled back; no false committed journal',$r['status']===500 && $a['game']===$b['game'] && count($a['journal'])===1 && $a['journal'][0]['status']==='processing',['response'=>brief($r),'journal'=>$a['journal'],'game_unchanged'=>$a['game']===$b['game'],'engine_game_log'=>$a['game_log']]);
 check("known failure terminal outcome $table",'known rolled-back command obtains a terminal recoverable outcome',($after['journal'][0]['status']??'processing')!=='processing',['replay'=>brief($r2),'command_status'=>$r2['json']['command_status']??null,'journal'=>$after['journal']]);
}
$s=fixture();$p=post($s,'render-failure');
$db->query('RENAME TABLE cards TO stage5d2_cards_unavailable');
try{$r=request(query($s),$p);}finally{$db->query('RENAME TABLE stage5d2_cards_unavailable TO cards');}
$a=persisted($s);$status=request(query($s,'first','battle_command_status').'&command_id=render-failure');$r2=request(query($s),$p);
check('response failure after commit','500 unknown; status/replay recover committed result without reexecution',$r['status']===500 && $a['journal'][0]['status']==='committed' && $status['status']===200 && ($status['json']['command_status']??'')==='committed' && $r2['status']===200 && persisted($s)===$a,['response'=>brief($r),'status'=>$status['json'],'replay'=>brief($r2)]);

foreach(['host','player'] as $owner)foreach(['first','second'] as $role){
 $s=fixture($owner);$p=post($s,'resign');$p['cmd']='resign';unset($p['card_id']);$r=request(query($s,$role),$p);$a=persisted($s);$loaded=$repo->findById($s->gameId);$r2=request(query($s,$role),$p);
 check("pending $owner resign $role",'game_over; opponent wins; one commit; replay no-op',$r['status']===200 && $loaded->status==='game_over' && $loaded->winner===($role==='first'?'player':'host') && count($a['journal'])===1 && $a['journal'][0]['status']==='committed' && persisted($s)===$a,['response'=>brief($r),'winner'=>$loaded->winner,'journal'=>$a['journal']]);
 $r=request(query($s,$role,'battle_sync'));check("game_over sync $owner $role",'200 game_over status, no writes',($r['json']['status']??'')==='game_over' && persisted($s)===$a,brief($r));
 $p['expected_version']=(string)$loaded->persistenceVersion;$p['command_id']='after-over';$r=request(query($s,$role),$p);$after=persisted($s);
 check("command after game_over $owner $role",'rejected journal, unchanged game',($r['json']['ok']??true)===false && end($after['journal'])['status']==='rejected' && $after['game']===$a['game'],brief($r));
}
$s=fixture();$b=persisted($s);
$r=request("first&game=$s->gameId&cmd=resign&debug=0&debug_roll=6*");
check('debug disabled legacy command','302; game/journal unchanged (debug_roll remains an independent session option)',$r['status']===302 && persisted($s)===$b,brief($r));
$s=fixture();$b=persisted($s);$r=request(query($s,'first','battle_sync').'&debug_roll[]=6');
check('debug_roll array','valid JSON; no warnings or game writes',$r['json_valid'] && $r['status']===200 && persisted($s)===$b,brief($r));
foreach(['game_over','mode'] as $stateStatus){$s=fixture();$s->status=$stateStatus;$repo->save($s);$b=persisted($s);$r=request("first&game=$s->gameId&cmd[]=resign");check("legacy array outside battle $stateStatus",'no uncaught error; unchanged State/version/journal',$r['status']<500 && !str_contains($r['body_prefix']??'','Fatal error') && persisted($s)===$b,brief($r));}
$failed=count(array_filter($results,fn($r)=>$r['result']==='FAIL'));
$report=['environment'=>$meta,'engines'=>$tables,'cases'=>$results,'total'=>count($results),'failed'=>$failed];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
if($path=getenv('STAGE5D2_AUDIT_RESULTS'))file_put_contents($path,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
exit($failed?1:0);
