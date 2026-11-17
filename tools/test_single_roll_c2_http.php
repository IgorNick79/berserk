<?php
declare(strict_types=1);
// Reuses the verified disposable server and actual authenticated controller harness.
ob_start();
require __DIR__.'/test_instant_reservation_http.php';
define('C2_FIXTURES_ONLY',true);require __DIR__.'/test_single_roll_c2.php';
// Two independent sessions for the same participant avoid PHP's session-file lock masking DB concurrency.
session_save_path($root.'/sessions');session_name('BERSERKAUTH5A');session_id(bin2hex(random_bytes(16)));session_start();
$_SESSION=['berserk_authenticated_user_id'=>(int)$members['player_id'],'auth_csrf'=>bin2hex(random_bytes(32))];
$evidence['http_fixture']['sessions']['player_parallel']=['cookie'=>'BERSERKAUTH5A='.session_id(),'csrf'=>$_SESSION['auth_csrf']];
session_write_close();ob_end_flush();
function c1version(int $game):int {global $m;return (int)$m->query('SELECT version FROM games WHERE ind='.$game)->fetch_assoc()['version'];}
foreach(['tap','magic','shot','throw','discharge','uchr'] as $type) {
    $game=ihSeed();$s=c1state($type);$s->gameId=$game;
    $s->getCard(3)->prop=$props['s1_181'];$s->getCard(4)->prop=$props['s1_174'];
    $s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
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
    foreach([['host','combat_instant_pass',[]],['player','combat_instant_play',['card_id'=>4,'instant_key'=>'black_mark']],['player','combat_instant_pass',[]]] as [$actor,$cmd,$payload]){
        $step=ihRun(ihHandle(18882,$game,$actor,$cmd,c1version($game),$payload));c1check(($step['body']['command_status']??null)==='committed','Mary/Luck setup '.$cmd);
    }
    $r=ihRun(ihHandle(18881,$game,'host','combat_instant_play',c1version($game),['card_id'=>3,'instant_key'=>'udacha']));
    c1check(($r['body']['command_status']??null)==='committed','real Luck declaration');
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

    c1check(isset(ihState($game)['battle']['pending_dice_choice']),'F5 persists dice choice');
    $r=ihRun(ihHandle(18881,$game,'host','choose_dice_choice',c1version($game),['choice'=>'reroll:any']));
    c1check(($r['body']['command_status']??null)==='committed','real one-die reroll');
    $saved=ihState($game);c1check(($saved['battle']['single_roll']['stage']??null)==='results','HTTP nonempty stack waits ACK');
    $snapshot=\Berserk\Core\GameState::fromArray($saved);c1check($snapshot->getCard(1)->hp===($saved['battle']['single_roll']['attack_dice']===1?17:19),'HTTP Mary reads Luck reroll');
    $token=\Berserk\Core\InformationalWaitManager::token(\Berserk\Core\GameState::fromArray($saved));
    foreach(['host','player'] as $actor){
        $ackRequest=ihHandle(18882,$game,$actor,'confirm_strike',c1version($game),['wait_id'=>$token]);
        $r=ihRun($ackRequest);
        c1check(($r['body']['command_status']??null)==='committed','HTTP ACK '.$actor);
        $ackVersion=c1version($game);$replay=ihRun($ackRequest);
        c1check(($replay['body']['command_status']??null)==='committed'&&c1version($game)===$ackVersion,'ACK command_id replay inert');
        if($actor==='host')c1check(isset(ihState($game)['battle']['single_roll']),'partial ACK retains original action');
    }
    $state=\Berserk\Core\GameState::fromArray(ihState($game));
    c1check(empty($state->battle['single_roll'])&&$state->getCard(2)->hp===17,'HTTP one damage application '.$type);
    c1check($state->getCard(1)->coins===($type==='uchr'?4:3),'HTTP native resource cost once '.$type);
}
foreach(['shaksi','row_spell'] as $passive){
 $game=ihSeed();$s=c1state('shot');$s->gameId=$game;$s->hostId=(int)$members['host_id'];$s->playerId=(int)$members['player_id'];
 $s->players['host']->userId=$s->hostId;$s->players['player']->userId=$s->playerId;$s->journalSchema=1;
 $s->getCard(1)->closed=false;$s->getCard(1)->move=$s->getCard(1)->moveMax=3;
 $s->getCard(3)->prop=$props['s1_181'];$s->getCard(3)->row=3;$s->getCard(3)->col=4;
 $s->getCard(4)->prop=$props['s1_174'];
 $s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
 if($passive==='shaksi'){
  $s->getCard(1)->prop=$props['s1_185'];$command='jump';$payload=['card_id'=>1,'row'=>4,'col'=>4];
 }else{
  $s->getCard(1)->prop=$props['s1_96'];$s->getCard(1)->hp=20;$action=$props['s1_96']['actions'][0];
  $s->getCard(1)->flags['row_spell_pending']=['row'=>3,'action'=>$action];
  $s->battle['turn_phase']=['phase'=>'start','active_key'=>'host','passive_key'=>'player','side'=>'active',
    'active_queue'=>[['id'=>'row_spell_1','type'=>'row_spell','card_id'=>1]],'passive_queue'=>[],'pending_ack'=>null];
  $command='turn_task';$payload=['task_id'=>'row_spell_1'];
 }
 $json=$m->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));$m->query("UPDATE games SET json='$json' WHERE ind=$game");
 $r=ihRun(ihHandle(18881,$game,'host',$command,0,$payload));c1check(($r['body']['command_status']??null)==='committed','passive trigger HTTP '.$passive.' '.json_encode($r['body']['error']??null));
 c1check((ihState($game)['battle']['single_roll']['kind']??null)===$passive,'passive checkpoint persisted');
 foreach([['host','combat_instant_play',['card_id'=>3,'instant_key'=>'udacha']],['host','combat_instant_pass',[]],['player','combat_instant_pass',[]],['host','choose_dice_choice',['choice'=>'reroll:any']]] as [$actor,$cmd,$payload]){
  $r=ihRun(ihHandle(18881,$game,$actor,$cmd,c1version($game),$payload));c1check(($r['body']['command_status']??null)==='committed','passive step '.$cmd);
 }
 $saved=ihState($game);$die=$saved['battle']['single_roll']['attack_dice'];$token=\Berserk\Core\InformationalWaitManager::token(\Berserk\Core\GameState::fromArray($saved));
 foreach(['host','player'] as $actor){$r=ihRun(ihHandle(18882,$game,$actor,'confirm_strike',c1version($game),['wait_id'=>$token]));c1check(($r['body']['command_status']??null)==='committed','passive ACK '.$actor);}
 // Acknowledged rerolls now offer a fresh response window before the native passive tail.
 for($i=0;$i<3 && (ihState($game)['battle']['single_roll']['state']??null)==='waiting_instant';$i++){
  $fresh=ihState($game)['battle']['single_roll'];
  c1check($fresh['attack_dice']===$die&&empty($fresh['instant_stack']),'fresh HTTP response retains die without replay');
  $r=ihRun(ihHandle(18882,$game,$fresh['instant_priority'],'combat_instant_pass',c1version($game)));
  c1check(($r['body']['command_status']??null)==='committed','passive reroll response pass');
 }
 $state=ihState($game);$key=$passive==='shaksi'?'pending_shaksi_roll':'pending_row_spell_pick';
 c1check(($state['battle'][$key]['dice']??null)===$die,'passive receives final HTTP die');
 $pick=$passive==='shaksi'?['target_id'=>$state['battle'][$key]['candidates'][0]]:['target_ids'=>array_slice($state['battle'][$key]['candidates'],0,$state['battle'][$key]['x'])];
 $r=ihRun(ihHandle(18881,$game,'host',$passive==='shaksi'?'choose_shaksi_roll':'choose_row_spell_targets',c1version($game),$pick));
 c1check(($r['body']['command_status']??null)==='committed'&&empty(ihState($game)['battle'][$key]),'passive native choice completes');
}
echo "Single roll C2 real-card AJAX/InnoDB: $checks checks passed\n";
