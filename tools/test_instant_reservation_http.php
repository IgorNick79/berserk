<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!in_array('--isolated',$argv,true))throw new RuntimeException('Use --isolated');
define('IR_FIXTURES_ONLY',true);require __DIR__.'/test_instant_reservation.php';
use Berserk\Core\InstantReservation as IR;
$root=getenv('IR_TEST_ROOT')?:'';$data=getenv('IR_TEST_DATADIR')?:'';
$evidence=json_decode(file_get_contents($root.'/sql-evidence.json'),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^berserk_ir_[a-f0-9]+$/D',$evidence['schema']))throw new RuntimeException('Invalid test schema');
$m=new mysqli('127.0.0.1','root','',$evidence['schema'],33992);$m->set_charset('utf8mb4');
$m->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); // Observe both workers at the barrier.
$proof=$m->query('SELECT @@datadir d,@@port p')->fetch_assoc();
irCheck(realpath($data)!==false&&realpath($data)===realpath($proof['d'])&&(int)$proof['p']===33992
    &&str_ends_with(str_replace('\\','/',strtolower(realpath($data))),'/instant-reservation-stage2-validation/mysql-data'),'Isolated HTTP DB identity');
$members=$m->query('SELECT host_id,player_id FROM games WHERE ind='.(int)$evidence['http_fixture']['game_id'])->fetch_assoc();
function ihSeed(bool $legacy=false):int {
    global $m,$members;$id=(int)$m->query('SELECT MAX(ind)+1 id FROM games')->fetch_assoc()['id'];$s=irState();$s->gameId=$id;
    $s->hostId=(int)$members['host_id'];$s->playerId=(int)$members['player_id'];$s->battle['turn_timer']['anchor_at']=time();
    $s->players['host']->userId=$s->hostId;$s->players['player']->userId=$s->playerId;
    $s->journalSchema=1;
    if($legacy)unset($s->battle[IR::KEY]);
    $json=$m->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));
    $m->query("INSERT INTO games(ind,host_id,player_id,status,json,version) VALUES($id,$s->hostId,$s->playerId,'battle','$json',0)");
    global $evidence;
    $metaDb=new \Berserk\Core\Db(['host'=>'127.0.0.1:33992','user'=>'root','pass'=>'','name'=>$evidence['schema']]);
    $metaDb->beginTransaction();(new \Berserk\Core\BattleHistory\HistoryRepository($metaDb))->initialize($id);$metaDb->commit();
    $m->query("INSERT INTO lobby_rooms(creator_account_id,accepted_account_id,game_id,state,created_at,updated_at,game_type) VALUES($s->hostId,$s->playerId,$id,'started',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'system')");return$id;
}
function ihHandle(int $port,int $game,string $actor,string $type,int $version,array $payload=[],?string $id=null): CurlHandle {
    global $evidence;$t=$evidence['http_fixture']['sessions'][$actor];$h=curl_init("http://127.0.0.1:$port/index.php?game=$game&ajax=battle_command");
    curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload+['csrf'=>$t['csrf'],'command_id'=>$id??'irhttp:'.bin2hex(random_bytes(9)),'expected_version'=>$version,'cmd'=>$type]),
        CURLOPT_COOKIE=>$t['cookie'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);return$h;
}
function ihRun(CurlHandle $h):array {$body=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);$out=json_decode($body,true);irCheck(is_array($out),'HTTP JSON '.$status.' '.$body);return ['http'=>$status,'body'=>$out];}
function ihState(int $id):array {global$m;return json_decode($m->query('SELECT json FROM games WHERE ind='.$id)->fetch_assoc()['json'],true,512,JSON_THROW_ON_ERROR);}
$results=[];
for($round=0;$round<4;$round++) {
    $game=ihSeed();$a=ihHandle(18881,$game,'player','reserve_instant',0,['period_id'=>1]);$b=ihHandle(18882,$game,'host','gain_coin',0,['card_id'=>1]);
    $multi=curl_multi_init();curl_multi_add_handle($multi,$a);curl_multi_add_handle($multi,$b);
    $m->begin_transaction();$m->query('SELECT ind FROM games WHERE ind='.$game.' FOR UPDATE');
    $deadline=microtime(true)+8;$ready=false;
    do {curl_multi_exec($multi,$running);$count=(int)$m->query("SELECT COUNT(*) n FROM battle_command_journal WHERE game_id=$game AND status='processing'")->fetch_assoc()['n'];
        if($count===2){$ready=true;break;}usleep(20000);
    } while(microtime(true)<$deadline && $running);
    $m->commit();
    do {curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.1);} while($running);
    $responses=[];foreach([$a,$b]as$h){$text=curl_multi_getcontent($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);$body=json_decode($text,true);irCheck(is_array($body),'Concurrent HTTP JSON '.$status.' '.$text);$responses[]=['http'=>$status,'status'=>$body['command_status']??null,'error'=>$body['error']??null];}
    curl_multi_close($multi);irCheck($ready,'Both AJAX requests reached processing before game lock released: '.json_encode($responses));
    $statuses=array_column($responses,'status');sort($statuses);irCheck($statuses===['committed','conflict'],'Actual AJAX serializes competing commands: '.json_encode($responses));
    $state=ihState($game);$r=$state['battle'][IR::KEY];
    if($responses[0]['status']==='committed') {
        irCheck($r['status']==='requested'&&$r['used']===0,'HTTP reserve commit has no premature grant');
        $response=ihRun(ihHandle(18881,$game,'host','gain_coin',1,['card_id'=>1]));
        irCheck(($response['body']['command_status']??null)==='committed','Retry action with fresh AJAX version');
    }else{
        irCheck($r['status']==='idle'&&$r['used']===0,'HTTP action commit precedes reserve');
        $response=ihRun(ihHandle(18881,$game,'player','reserve_instant',1,['period_id'=>1]));
        irCheck(($response['body']['command_status']??null)==='committed','Retry reservation with fresh AJAX version');
    }
    $results[]=['game'=>$game,'responses'=>$responses];
}

// Full controller duplicate recovery and stale request tokens after a reload.
$game=ihSeed();$h=ihHandle(18881,$game,'player','reserve_instant',0,['period_id'=>1],'same-http-request');$a=ihRun($h);$b=ihRun($h);
irCheck(($a['body']['command_status']??null)==='committed'&&($b['body']['command_status']??null)==='committed','HTTP committed replay');
$row=$m->query('SELECT version,json FROM games WHERE ind='.$game)->fetch_assoc();$state=json_decode($row['json'],true,512,JSON_THROW_ON_ERROR);
irCheck((int)$row['version']===1&&$state['battle'][IR::KEY]['generation']===1,'HTTP replay executes once');
$a=ihRun(ihHandle(18881,$game,'host','gain_coin',1,['card_id'=>1]));$state=ihState($game);$r=$state['battle'][IR::KEY];
irCheck($r['status']==='granted'&&$r['used']===1,'HTTP grant persisted across reload');
$a=ihRun(ihHandle(18882,$game,'player','decline_instant_priority',2,['request_id'=>$r['request']['id']]));
irCheck(($a['body']['command_status']??null)==='committed','HTTP decline returns ordinary control');
$a=ihRun(ihHandle(18882,$game,'player','decline_instant_priority',3,['request_id'=>$r['request']['id']]));
irCheck(($a['body']['command_status']??null)==='rejected','Replayed old grant with new command ID rejected');
$state=ihState($game);irCheck($state['battle'][IR::KEY]['status']==='idle'&&$state['battle'][IR::KEY]['used']===1,'HTTP decline never refunds');

// Configure only the disposable application; existing periods retain their captured limit.
$configPath=$root.'/app/config/instant-reservations.php';$original=file_get_contents($configPath);
try {
    file_put_contents($configPath,"<?php return ['paid_limit'=>1];");
    $game=ihSeed(true);$response=ihRun(ihHandle(18881,$game,'player','reserve_instant',0,['period_id'=>1]));
    irCheck(($response['body']['command_status']??null)==='committed' && ihState($game)['battle'][IR::KEY]['limit']===1,'Configured limit captured by legacy first request');
    file_put_contents($configPath,"<?php return ['paid_limit'=>0];");
    $response=ihRun(ihHandle(18882,$game,'host','gain_coin',1,['card_id'=>1]));$r=ihState($game)['battle'][IR::KEY];
    irCheck(($response['body']['command_status']??null)==='committed'&&$r['limit']===1&&$r['used']===1,'Configuration changes do not rewrite an existing period budget');
    $game=ihSeed(true);$response=ihRun(ihHandle(18881,$game,'player','reserve_instant',0,['period_id'=>1]));
    irCheck(($response['body']['command_status']??null)==='rejected'&&($response['body']['error']??null)==='instant_reservation_limit','Zero configured limit prevents paid reservations');
} finally {file_put_contents($configPath,$original);}
file_put_contents($root.'/http-evidence.json',json_encode(['checks'=>$irChecks,'races'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "Actual two-process AJAX/InnoDB reservation tests: $irChecks checks passed\n";
