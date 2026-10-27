<?php
declare(strict_types=1);
require __DIR__.'/test_instant_reservation_http.php';
define('FREE_INSTANT_FIXTURES_ONLY',true);require __DIR__.'/test_free_instant_reservation.php';
use Berserk\Core\InstantReservation as IR;
$freeHttp=[];
foreach(['host','player']as$active){
    $game=ihSeed();$s=fState($active);fRevive($s);$s->gameId=$game;$s->journalSchema=1;
    $s->hostId=(int)$members['host_id'];$s->playerId=(int)$members['player_id'];
    foreach(['host','player']as$side)$s->players[$side]->userId=$side==='host'?$s->hostId:$s->playerId;
    if($s->battle['turn_timer']['status']==='running')$s->battle['turn_timer']['anchor_at']=time();
    $json=$m->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));$m->query("UPDATE games SET json='$json' WHERE ind=$game");
    $p=$s->getOpponentKey($active);
    $r=ihRun(ihHandle(18881,$game,$p,'choose_revive_target',0,['target_id'=>5]));
    irCheck(($r['body']['command_status']??null)==='committed','HTTP first mandatory step '.($r['body']['error']??''));
    irCheck(($r['body']['instant_reservation']['can_free']??false)&&$r['body']['instant_reservation']['mode']==='free'&&$r['body']['instant_reservation']['priority_owner']===$p,'HTTP snapshot projects free mode and unchanged mandatory owner');
    $s=\Berserk\Core\GameState::fromArray(ihState($game));$origin=IR::controls($s,$p,time())['origin_choice_id'];
    $a=ihHandle(18881,$game,$p,'reserve_instant',1,['period_id'=>1,'origin_choice_id'=>$origin]);
    // A second authenticated session for the SAME account avoids PHP session-lock serialization.
    $original=$evidence['http_fixture']['sessions'][$p];$sid=bin2hex(random_bytes(16));$csrf=bin2hex(random_bytes(32));
    $account=$p==='host'?$s->hostId:$s->playerId;
    file_put_contents($root.'/sessions/sess_'.$sid,'berserk_authenticated_user_id|i:'.$account.';auth_csrf|s:64:"'.$csrf.'";');
    $evidence['http_fixture']['sessions'][$p]=['cookie'=>'BERSERKAUTH5A='.$sid,'csrf'=>$csrf];
    $b=ihHandle(18882,$game,$p,'choose_revive_cell',1,['row'=>3,'col'=>4]);
    $evidence['http_fixture']['sessions'][$p]=$original;
    $multi=curl_multi_init();curl_multi_add_handle($multi,$a);curl_multi_add_handle($multi,$b);
    $m->begin_transaction();$m->query("SELECT ind FROM games WHERE ind=$game FOR UPDATE");$ready=false;$deadline=microtime(true)+8;
    do{curl_multi_exec($multi,$running);$n=(int)$m->query("SELECT COUNT(*) n FROM battle_command_journal WHERE game_id=$game AND status='processing'")->fetch_assoc()['n'];if($n===2){$ready=true;break;}usleep(20000);}while(microtime(true)<$deadline&&$running);
    $m->commit();do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.1);}while($running);
    $responses=[];foreach([$a,$b]as$h)$responses[]=json_decode(curl_multi_getcontent($h),true,512,JSON_THROW_ON_ERROR);curl_multi_close($multi);
    irCheck($ready,'Both same-account HTTP commands reached journal before game lock released');
    $statuses=array_column($responses,'command_status');sort($statuses);irCheck($statuses===['committed','conflict'],'Free HTTP race serialized');
    $state=ihState($game);irCheck($state['battle'][IR::KEY]['used']===0,'HTTP free race consumes no budget');
    $version=(int)$m->query("SELECT version FROM games WHERE ind=$game")->fetch_assoc()['version'];
    foreach(['force_snapshot=1','since_version='.$version]as$query){
        $h=curl_init("http://127.0.0.1:18881/index.php?game=$game&ajax=battle_sync&$query");curl_setopt_array($h,[CURLOPT_COOKIE=>$original['cookie'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$sync=ihRun($h);
        irCheck(($sync['body']['instant_reservation']['status']??null)===$state['battle'][IR::KEY]['status'],'Reload/no-change projection preserves reservation');
    }
    if($responses[0]['command_status']==='committed'){
        irCheck($state['battle'][IR::KEY]['request']['reason']==='free'&&isset($state['battle']['pending_revive']),'HTTP free request preserves pending');
        $r=ihRun(ihHandle(18882,$game,$p,'choose_revive_cell',2,['row'=>3,'col'=>4]));
        irCheck(($r['body']['command_status']??null)==='committed'&&ihState($game)['battle'][IR::KEY]['status']==='granted','HTTP completion grants once');
        $before=$m->query("SELECT json,version FROM games WHERE ind=$game")->fetch_assoc();$replay=ihRun($a);
        irCheck(($replay['body']['command_status']??null)==='committed'&&$m->query("SELECT json,version FROM games WHERE ind=$game")->fetch_assoc()===$before,'Lost free-reserve response replay cannot grant twice');
    }else{
        $r=ihRun(ihHandle(18881,$game,$p,'reserve_instant',2,['period_id'=>1,'origin_choice_id'=>$origin]));
        irCheck(($r['body']['command_status']??null)==='rejected'&&ihState($game)['battle'][IR::KEY]['status']==='idle','HTTP stale free identity cannot silently charge');
    }
    $freeHttp[]=['active'=>$active,'game'=>$game,'statuses'=>array_column($responses,'command_status')];
}
file_put_contents($root.'/free-http-evidence.json',json_encode(['checks'=>$irChecks,'races'=>$freeHttp],JSON_PRETTY_PRINT));
echo "Free two-session HTTP/InnoDB tests: $irChecks checks passed\n";
