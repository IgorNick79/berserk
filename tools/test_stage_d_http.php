<?php
declare(strict_types=1);
require __DIR__.'/test_single_roll_c2_http.php';define('D_FIXTURES_ONLY',true);require __DIR__.'/test_stage_d.php';
use Berserk\Core\{GameState,InformationalWaitManager as IW};
function dSeed(string $type,bool $hermit=false):int {
 global $members,$m,$dp;$game=ihSeed();$s=c1state($type,false);$s->gameId=$game;
 $s->hostId=(int)$members['host_id'];$s->playerId=(int)$members['player_id'];$s->players['host']->userId=$s->hostId;$s->players['player']->userId=$s->playerId;$s->journalSchema=1;
 $s->getCard(1)->closed=false;$s->getCard(1)->coins=4;
 $s->getCard(1)->prop['actions']=[['key'=>'primary','type'=>$type,'range'=>6,'coins'=>1,'strike'=>['weak'=>3,'medium'=>3,'strong'=>3]]];
 $s->getCard(3)->owner='player';$s->getCard(3)->prop=$dp[$hermit?'s1_184':'s1_157'];
 if(!$hermit)$s->getCard(4)->prop=$dp['s1_20'];
 $s->getCard(4)->flags['damage_taken_this_strike']=50;
 $s->battle['turn_timer']=['period_id'=>1,'owner'=>'host','status'=>'running','remaining_seconds'=>180,'anchor_at'=>time(),'pause_reason'=>null];
 $json=$m->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));$m->query("UPDATE games SET json='$json' WHERE ind=$game");return $game;
}
function dHttp(int $game,string $actor,string $cmd,array $payload=[]):void {
 $r=ihRun(ihHandle(18881,$game,$actor,$cmd,c1version($game),$payload));c1check(($r['body']['command_status']??null)==='committed','D HTTP '.$cmd.' '.($r['body']['error']??''));
}
function dHttpAck(int $game):void {
 $token=IW::token(GameState::fromArray(ihState($game)));
 foreach(['host','player'] as $actor)dHttp($game,$actor,'confirm_strike',['wait_id'=>$token]);
}
foreach(['tap','magic','uchr','shot','throw','discharge'] as $type){
 $game=dSeed($type);$request=ihHandle(18881,$game,'host',$type==='uchr'?'uchr':'action',0,['card_id'=>1,'target_id'=>2,'action_key'=>'primary']);
 $r=ihRun($request);c1check(($r['body']['command_status']??null)==='committed','D native action');
 $version=c1version($game);$r=ihRun($request);c1check(($r['body']['command_status']??null)==='committed'&&c1version($game)===$version,'D original command replay inert');
 if(in_array($type,['shot','throw','discharge'],true)){
  c1check(empty(ihState($game)['battle']['single_roll']),'D forbidden ranged phase absent');continue;
 }
 $s=GameState::fromArray(ihState($game));c1check($s->getCard(2)->hp===19&&$s->battle['single_roll']['computed_value']===3,'main checkpoint before wounds');
 if($type!=='uchr')dHttp($game,'player','combat_instant_play',['card_id'=>4,'instant_key'=>'flash']);
 dHttp($game,'player','combat_instant_play',['card_id'=>3,'instant_key'=>'krik']);
 dHttp($game,'player','combat_instant_pass');dHttp($game,'host','combat_instant_pass');
 $s=GameState::fromArray(ihState($game));c1check($s->battle['single_roll']['stage']==='results'&&$s->getCard(2)->hp===19,'main stack persists until ACK');
 $token=IW::token($s);dHttp($game,'host','confirm_strike',['wait_id'=>$token]);
 c1check(isset(ihState($game)['battle']['single_roll']),'partial main ACK');
 $oldVersion=c1version($game);$a=ihHandle(18881,$game,'player','confirm_strike',$oldVersion,['wait_id'=>$token]);$b=ihHandle(18882,$game,'player_parallel','confirm_strike',$oldVersion,['wait_id'=>$token]);
 $multi=curl_multi_init();curl_multi_add_handle($multi,$a);curl_multi_add_handle($multi,$b);$m->begin_transaction();$m->query('SELECT ind FROM games WHERE ind='.$game.' FOR UPDATE');
 $end=microtime(true)+8;$ready=false;do{curl_multi_exec($multi,$running);if((int)$m->query("SELECT COUNT(*) n FROM battle_command_journal WHERE game_id=$game AND status='processing'")->fetch_assoc()['n']===2){$ready=true;break;}usleep(20000);}while(microtime(true)<$end&&$running);$m->commit();
 do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,.1);}while($running);
 $statuses=[];foreach([$a,$b] as $h)$statuses[]=json_decode(curl_multi_getcontent($h),true)['command_status']??null;curl_multi_close($multi);sort($statuses);
 c1check($ready&&$statuses===['committed','conflict'],'concurrent main ACK exactly once');
 $s=GameState::fromArray(ihState($game));c1check($s->getCard(2)->hp===($type==='uchr'?17:18)&&$s->getCard(3)->coins===1,'D attack and coin once');
 c1check($s->getCard(1)->coins===($type==='uchr'?4:3),'D resource cost once');
}
foreach(['tap','magic','uchr'] as $type){
 $game=dSeed($type,true);dHttp($game,'host',$type==='uchr'?'uchr':'action',['card_id'=>1,'target_id'=>2,'action_key'=>'primary']);dHttpAck($game);
 c1check((ihState($game)['battle']['strike']['combat_instant_window']??null)==='wounds','late native wound window');
 dHttp($game,'player','combat_instant_play',['card_id'=>3,'instant_key'=>'raneniya']);dHttp($game,'player','combat_instant_pass');dHttp($game,'host','combat_instant_pass');
 c1check(ihState($game)['battle']['pending_wound_transfer']['donors']===[2=>3],'HTTP current-action donor ledger');
 foreach([['wt_amount',['amount'=>2]],['wt_target',['target_id'=>4]],['wt_target_amount',['amount'=>2]]] as [$cmd,$p])dHttp($game,'player',$cmd,$p);
 $s=GameState::fromArray(ihState($game));c1check($s->getCard(2)->hp===18&&$s->getCard(4)->hp===17,'native wound transfer exactly once');
 c1check($s->battle['strike']['state']==='results','wounds result waits ACK');dHttpAck($game);
 c1check(empty(ihState($game)['battle']['strike']),'wounds ACK finishes native continuation');
}
$game=dSeed('tap',true);$s=GameState::fromArray(ihState($game));$s->getCard(4)->prop=$dp['s1_184'];
$json=$m->real_escape_string(json_encode($s->toArray(),JSON_THROW_ON_ERROR));$m->query("UPDATE games SET json='$json' WHERE ind=$game");
dHttp($game,'host','action',['card_id'=>1,'target_id'=>2,'action_key'=>'primary']);dHttpAck($game);
foreach([3,4] as $id)dHttp($game,'player','combat_instant_play',['card_id'=>$id,'instant_key'=>'raneniya']);
dHttp($game,'player','combat_instant_pass');dHttp($game,'host','combat_instant_pass');
dHttp($game,'player','wt_amount',['amount'=>3]);dHttp($game,'player','wt_target',['target_id'=>3]);
$last=ihHandle(18881,$game,'player','wt_target_amount',c1version($game),['amount'=>3],'d-final-transfer');$r=ihRun($last);c1check(($r['body']['command_status']??null)==='committed','two Hermits native transfer');
$version=c1version($game);$r=ihRun($last);c1check(($r['body']['command_status']??null)==='committed'&&c1version($game)===$version,'transfer duplicate inert');
$s=GameState::fromArray(ihState($game));c1check(empty($s->battle['pending_wound_transfer'])&&$s->getCard(2)->hp===19&&$s->battle['strike']['wounds_received'][2]===0,'two Hermits cannot consume preexisting wounds');
c1check($s->getCard(3)->hp===16&&$s->battle['strike']['wounds_received'][3]===3,'HTTP provenance moves with current wounds');dHttpAck($game);
echo "Stage D authenticated AJAX/InnoDB: $checks checks passed\n";
