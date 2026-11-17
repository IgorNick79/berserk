<?php
declare(strict_types=1);
// Reuses the verified disposable server and actual authenticated controller harness.
ob_start();
require __DIR__.'/test_instant_reservation_http.php';
define('C1_FIXTURES_ONLY',true);require __DIR__.'/test_single_roll_c1.php';
// Two independent sessions for the same participant avoid PHP's session-file lock masking DB concurrency.
session_save_path($root.'/sessions');session_name('BERSERKAUTH5A');session_id(bin2hex(random_bytes(16)));session_start();
$_SESSION=['berserk_authenticated_user_id'=>(int)$members['player_id'],'auth_csrf'=>bin2hex(random_bytes(32))];
$evidence['http_fixture']['sessions']['player_parallel']=['cookie'=>'BERSERKAUTH5A='.session_id(),'csrf'=>$_SESSION['auth_csrf']];
session_write_close();ob_end_flush();
function c1version(int $game):int {global $m;return (int)$m->query('SELECT version FROM games WHERE ind='.$game)->fetch_assoc()['version'];}
foreach(['tap','magic','shot','throw','discharge','uchr'] as $type) {
    $game=ihSeed();$s=c1state($type);$s->gameId=$game;
    $s->hostId=(int)$members['host_id'];$s->playerId=(int)$members['player_id'];
    $s->players['host']->userId=$s->hostId;$s->players['player']->userId=$s->playerId;$s->journalSchema=1;
    $source=$s->getCard(1);$source->closed=false;$source->coins=4;
    $source->prop['actions']=[['key'=>'primary','type'=>$type,'range'=>6,'coins'=>1,'strike'=>['weak'=>2,'medium'=>2,'strong'=>2]]];
    $json=$m->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));
    $m->query("UPDATE games SET json='$json' WHERE ind=$game");
    $cmd=$type==='uchr'?'uchr':'action';$payload=['card_id'=>1,'target_id'=>2,'action_key'=>'primary'];
    $request=ihHandle(18881,$game,'host',$cmd,0,$payload,'c1-start-'.$type);
    $r=ihRun($request);c1check(($r['body']['command_status']??null)==='committed','HTTP initial '.$type.' '.json_encode($r));
    $state=ihState($game);c1check(isset($state['battle']['single_roll'])&&empty($state['battle']['strike']),'HTTP paused context '.$type);
    if ($type==='shot') {
        $state['battle']['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'expired','remaining_seconds'=>0,'anchor_at'=>null,'pause_reason'=>null];
        $json=$m->real_escape_string(json_encode($state,JSON_THROW_ON_ERROR));
        $m->query("UPDATE games SET json='$json' WHERE ind=$game");
    }
    $version=c1version($game);
    $r=ihRun($request);c1check(($r['body']['command_status']??null)==='committed'&&c1version($game)===$version,'duplicate command id does not reroll/pay');
    $r=ihRun(ihHandle(18882,$game,'host','combat_instant_pass',0));
    c1check(($r['body']['command_status']??null)==='conflict'&&c1version($game)===$version,'stale expected_version');
    $r=ihRun(ihHandle(18882,$game,'player','combat_instant_pass',$version));
    c1check(($r['body']['command_status']??null)==='rejected','foreign priority rejected');
    $r=ihRun(ihHandle(18881,$game,'host','combat_instant_pass',c1version($game)));
    c1check(($r['body']['command_status']??null)==='committed','first HTTP pass');
    $version=c1version($game);
    $a=ihHandle(18881,$game,'player','combat_instant_pass',$version);
    $b=ihHandle(18882,$game,'player_parallel','combat_instant_pass',$version);
    $multi=curl_multi_init();curl_multi_add_handle($multi,$a);curl_multi_add_handle($multi,$b);
    $m->begin_transaction();$m->query('SELECT ind FROM games WHERE ind='.$game.' FOR UPDATE');
    $deadline=microtime(true)+8;$ready=false;
    do {curl_multi_exec($multi,$running);
        if((int)$m->query("SELECT COUNT(*) n FROM battle_command_journal WHERE game_id=$game AND status='processing'")->fetch_assoc()['n']===2){$ready=true;break;}
        usleep(20000);
    }while(microtime(true)<$deadline&&$running);
    $m->commit();do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.1);}while($running);
    $statuses=[];foreach([$a,$b] as $h){$response=json_decode(curl_multi_getcontent($h),true);$statuses[]=$response['command_status']??null;}
    curl_multi_close($multi);sort($statuses);
    c1check($ready&&$statuses===['committed','conflict'],'concurrent final passes serialize '.$type.' '.json_encode([$ready,$statuses]));

    $state=\Berserk\Core\GameState::fromArray(ihState($game));
    c1check(empty($state->battle['single_roll'])&&$state->getCard(2)->hp===17,'HTTP one damage application '.$type);
    c1check($state->getCard(1)->coins===($type==='uchr'?4:3),'HTTP native resource cost once '.$type);
}
echo "Single roll C1 authenticated AJAX/InnoDB: $checks checks passed\n";
