<?php
declare(strict_types=1);
require __DIR__.'/test_free_instant_reservation_http.php';
define('STAGE4_FIXTURES_ONLY',true);require __DIR__.'/test_instant_stage4.php';
use Berserk\Core\{GameState,InformationalWaitManager as IW,TurnTimer};
function s4HttpSeed(GameState $s):int{
    global$m,$members;$game=ihSeed();$s->gameId=$game;$s->journalSchema=1;$s->hostId=(int)$members['host_id'];$s->playerId=(int)$members['player_id'];
    foreach(['host','player']as$p)$s->players[$p]->userId=$p==='host'?$s->hostId:$s->playerId;
    if(($s->battle['turn_timer']['status']??'')==='running')$s->battle['turn_timer']['anchor_at']=time();
    if(!empty($s->battle['informational_wait'])){IW::publish($s);IW::reconcile($s,time());}
    foreach($s->cards as$c){$ukid=$m->real_escape_string($c->ukid);$m->query("INSERT IGNORE INTO cards(ukid,name,descr,rarity,price,health,move,elite,single,type,class,strike_weak,strike_medium,strike_strong,element_id,prop) VALUES('$ukid','Fixture $ukid','','common',0,30,3,0,0,'creature','',1,2,3,0,'{}')");}
    $json=$m->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));$m->query("UPDATE games SET json='$json' WHERE ind=$game");return$game;
}
function s4HttpCommand(int $game,string $actor,string $cmd,array $payload=[],?string $id=null):array{
    global$m;$v=(int)$m->query("SELECT version FROM games WHERE ind=$game")->fetch_assoc()['version'];
    $r=ihRun(ihHandle(18881,$game,$actor,$cmd,$v,$payload,$id));irCheck(($r['body']['command_status']??null)==='committed','Stage4 HTTP '.$cmd.' '.($r['body']['error']??''));return$r['body'];
}
function s4Sync(int $game,string $actor,bool $force=true):array{
    global$evidence,$m;$t=$evidence['http_fixture']['sessions'][$actor];$v=(int)$m->query("SELECT version FROM games WHERE ind=$game")->fetch_assoc()['version'];
    $url='http://127.0.0.1:18882/index.php?game='.$game.'&ajax=battle_sync&'.($force?'force_snapshot=1':'since_version='.$v);
    $h=curl_init($url);curl_setopt_array($h,[CURLOPT_COOKIE=>$t['cookie'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);return ihRun($h)['body'];
}
$cases=[];
foreach(['host','player']as$active){
    $s=s4Wounds($active);[$a,$p,$h,$caster]=fIds($s);$game=s4HttpSeed($s);
    $before=s4Sync($game,$p);irCheck(!($before['instant_control']['enabled']??true),'No premature Hermit announcement through real snapshot '.json_encode([array_keys($before),$before['error']??null,$before['instant_control']??null]));
    $wait=ihState($game)['battle']['informational_wait']['wait_id'];s4HttpCommand($game,$a,'confirm_strike',['wait_id'=>$wait]);
    $last=ihHandle(18881,$game,$p,'confirm_strike',1,['wait_id'=>$wait],'stage4-final-ack-'.$game);$r=ihRun($last);
    irCheck(($r['body']['command_status']??null)==='committed'&&($r['body']['instant_control']['phase']??null)==='wounds','Real final ACK opens wounds window');
    $v=$m->query("SELECT json,version FROM games WHERE ind=$game")->fetch_assoc();$replay=ihRun($last);
    irCheck(($replay['body']['command_status']??null)==='committed'&&$m->query("SELECT json,version FROM games WHERE ind=$game")->fetch_assoc()===$v,'Duplicate ACK never opens second wounds window');
    foreach([$a,$p]as$viewer)foreach([true,false]as$force){$r=s4Sync($game,$viewer,$force);$ui=$r['instant_control'];
        irCheck($ui['combat']&&$ui['phase']==='wounds'&&$ui['cost']===null&&$ui['enabled']===($viewer===$p),'Both viewers synchronize wounds rights without reservation price');
        irCheck($viewer===$p||$ui['options']===[],'Opponent AJAX cannot disclose Hermit choices');
        if($force)irCheck(substr_count($r['fragments']['info_panel_html'],'data-instant-control')===($viewer===$p?1:0),'One common footer control in real HTML');
    }
    $play=ihHandle(18881,$game,$p,'combat_instant_play',2,['card_id'=>$caster,'instant_key'=>'raneniya'],'stage4-hermit-'.$game);$r=ihRun($play);$v=$m->query("SELECT json,version FROM games WHERE ind=$game")->fetch_assoc();$again=ihRun($play);
    irCheck(($r['body']['command_status']??null)==='committed'&&($again['body']['command_status']??null)==='committed'&&$m->query("SELECT json,version FROM games WHERE ind=$game")->fetch_assoc()===$v,'Lost play response cannot stack Hermit twice');
    for($i=0;$i<3&&empty(ihState($game)['battle']['pending_wound_transfer']);$i++){$state=ihState($game);s4HttpCommand($game,$state['battle']['strike']['instant_priority'],'combat_instant_pass');}
    irCheck(ihState($game)['battle']['pending_wound_transfer']['source_id']===$caster,'Two native passes enter existing transfer handler');
    s4HttpCommand($game,$p,'wt_source',['donor_id'=>$h]);s4HttpCommand($game,$p,'wt_amount',['amount'=>2]);s4HttpCommand($game,$p,'wt_target',['target_id'=>$caster]);
    $final=s4HttpCommand($game,$p,'wt_target_amount',['amount'=>2]);$state=GameState::fromArray(ihState($game));
    irCheck($state->getCard($h)->hp===28&&$state->getCard($caster)->hp===28&&empty($state->battle['strike']),'HTTP transfer conserves wounds and finishes battle');
    irCheck(($final['turn_timer']['owner']??null)===$a&&($final['turn_timer']['status']??null)==='running','Final continuation returns timer to active player');
    irCheck(!(s4Sync($game,$p)['instant_control']['visible']??true),'F5 after resolution hides spent Hermit offer');
    $cases[]=['active'=>$a,'game'=>$game,'result'=>'passed'];
}
// Stable isolated fixtures for headless practical UI tests, independent of completed assertions.
$normal=fState();$normal->getCard(3)->prop['instants']=$normal->getCard(4)->prop['instants'];$normalId=s4HttpSeed($normal);
$free=fState();fRevive($free);$freeId=s4HttpSeed($free);
$combat=s4Wounds();$combatId=s4HttpSeed($combat);$wait=ihState($combatId)['battle']['informational_wait']['wait_id'];s4HttpCommand($combatId,'host','confirm_strike',['wait_id'=>$wait]);s4HttpCommand($combatId,'player','confirm_strike',['wait_id'=>$wait]);
file_put_contents($root.'/stage4-http-evidence.json',json_encode(['checks'=>$irChecks,'cases'=>$cases,'created_at'=>time(),'ui_fixtures'=>['normal'=>$normalId,'free'=>$freeId,'combat'=>$combatId]],JSON_PRETTY_PRINT));
echo "Stage4 actual HTTP/InnoDB: $irChecks checks passed\n";
