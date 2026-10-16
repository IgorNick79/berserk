<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,TurnTimer,InformationalWaitManager as IW};
use Berserk\View\{Template};
use Berserk\View\Screen\Battle\InfoPanel;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool {
    if(!(error_reporting()&$severity))return false;throw new ErrorException($message,0,$severity,$file,$line);
});
function iwAssert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function iwState(string $owner='host'): GameState {
    $s=new GameState(-random_int(1000000000,2000000000),101,202);$s->status='battle';
    $s->battle=['active'=>$owner,'turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
    $s->players[$owner]->side=1;
    foreach([1=>$owner,2=>$s->getOpponentKey($owner)] as $id=>$key)$s->addCard(new CardInstance(
        instanceId:$id,ukid:'info_'.$id,owner:$key,zone:CardInstance::ZONE_FIELD,row:$id===1?3:4,col:3,hp:20,hpMax:20,
        move:1,moveMax:1,strikeWeak:1,strikeMedium:2,strikeStrong:3,
        prop:$id===1?['actions'=>[['key'=>'marker','type'=>'place_cell_marker','marker'=>'bonfire']]]:[]));
    TurnTimer::prepare($s,$owner);TurnTimer::resume($s,1,'start_phase',1000);return $s;
}
function iwApply(Engine $e,GameState $s,string $actor,string $cmd,array $payload=[]): void {
    if($cmd==='confirm_strike' && !array_key_exists('wait_id',$payload))$payload['wait_id']=IW::token($s);
    $r=$e->apply($s,$actor,new Command($cmd,$payload));iwAssert($r->success,$cmd.': '.$r->error);
}
function iwOpen(Engine $e,GameState $s,string $owner='host'): void {
    iwApply($e,$s,$owner,'action',['card_id'=>1,'action_key'=>'marker']);
    iwAssert(!isset($s->battle['informational_wait']),'Target selection cannot activate deadline');
    iwApply($e,$s,$owner,'choose_cell_marker',['row'=>3,'col'=>2]);
}
$now=1000;$e=new Engine(null,static function()use(&$now):int{return $now;});
foreach(['host','player'] as $owner)foreach(['owner','opponent','auto'] as $first){
    $now=1000;$s=iwState($owner);iwOpen($e,$s,$owner);$opponent=$s->getOpponentKey($owner);
    $record=$s->battle['informational_wait'];
    iwAssert($record===['wait_id'=>1,'source'=>'strike.results','required_player'=>$opponent,'period_id'=>1,'started_at'=>1000,'deadline_at'=>1010],'Persistent identity contract');
    $before=serialize($s);IW::token($s);IW::due($s,1010);TurnTimer::snapshot($s,1005);
    iwAssert(serialize($s)===$before,'Read operations must be pure');
    iwAssert(IW::due($s,1009)===null && IW::due($s,1010)===$record,'Exact deadline boundary');
    $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
    iwAssert($s->battle['informational_wait']===$record,'Serialization preserves deadline');
    foreach([null,1,[],new stdClass(),'0','999','legacy:0'] as $bad){
        $before=serialize($s);$r=$e->apply($s,$owner,new Command('confirm_strike',['wait_id'=>$bad]));
        iwAssert(!$r->success && serialize($s)===$before,'Invalid ACK must precede all mutation');
    }
    if($first==='owner'){
        $now=1009;iwApply($e,$s,$owner,'confirm_strike');
        iwAssert($s->battle['informational_wait']===$record && $s->battle['turn_timer']['status']==='paused','Owner ACK at last second must not reset deadline');
        $before=serialize($s);iwAssert(!$e->apply($s,$owner,new Command('confirm_strike',['wait_id'=>'1']))->success && serialize($s)===$before,'Repeated participant ACK is mutation-free');
        $now=1010;iwApply($e,$s,$opponent,'confirm_strike');
    }else{
        $now=$first==='auto'?1040:1005;
        $actor=$first==='auto'?IW::due($s,$now)['required_player']:$opponent;
        iwApply($e,$s,$actor,'confirm_strike');
        iwAssert($s->battle['informational_wait']===$record && IW::due($s,9999)===null,'Opponent obligation closes but owner ID survives');
        iwAssert($s->battle['turn_timer']['anchor_at']===$now && $s->battle['turn_timer']['remaining_seconds']===180,'Resume occurs at execution time, never deadline time');
        $now+=5;iwApply($e,$s,$owner,'confirm_strike');
    }
    iwAssert(empty($s->battle['strike']) && !isset($s->battle['informational_wait']) && $s->battle['informational_wait_generation']===1,'Close clears window, never generation');
}

$now=1000;$s=iwState();iwOpen($e,$s);$old=IW::token($s);
$s->battle['strike']['pending_choice']=['died_id'=>2,'died_ukid'=>'info_2','effect'=>['type'=>'damage','value'=>1],'candidates'=>[1]];
IW::reconcile($s,1005);iwAssert(!isset($s->battle['informational_wait']) && IW::due($s,1015)===null,'Nested death choice deactivates old window');
$now=1020;iwApply($e,$s,'player','choose_death_target',['target_id'=>1]);
iwAssert(IW::token($s)!==$old && $s->battle['informational_wait']['deadline_at']===1030,'Death decision opens fresh deadline');
$before=serialize($s);iwAssert(!$e->apply($s,'host',new Command('confirm_strike',['wait_id'=>$old]))->success && serialize($s)===$before,'Old ACK cannot confirm new same-state screen');

// A single Engine ACK goes from Dice results directly into Main results with no instants.
$now=1000;$s=iwState();iwOpen($e,$s);$s->battle['strike']=['state'=>'results','attacker_id'=>1,'target_id'=>2,'defender_id'=>null,
    'instant_result_stage'=>'dice','confirmed'=>['host'],'attack_dice'=>6,'defend_dice'=>1,'attack_mod'=>0,'defend_mod'=>0,
    'result'=>['attack'=>'strong','defend'=>'','winner'=>'attack'],'defenders'=>[]];
$old=IW::token($s);$now=1005;iwApply($e,$s,'player','confirm_strike');
iwAssert($s->battle['strike']['state']==='results' && IW::token($s)!==$old,'Same before/after state is a distinct Dice/Main generation');
iwAssert($s->battle['informational_wait']['deadline_at']===1015,'Main result starts its own deadline');

foreach(['waiting_push_ack','results'] as $stage){
    $now=1000;$s=iwState();$s->battle['strike']=['state'=>$stage,'confirmed'=>[],'attacker_id'=>1,'target_id'=>2,'defender_id'=>null];
    IW::publish($s);IW::reconcile($s,$now);TurnTimer::reconcileWaiting($s,$now);
    iwAssert(IW::due($s,1010)['source']==='strike.'.$stage,'Whitelist deadline');
    $record=$s->battle['informational_wait'];
    foreach(['pending_incarnation_ack'=>true,'pending_future'=>['owner'=>'player']] as $key=>$pending){
        $bad=GameState::fromArray($s->toArray());$bad->battle[$key]=$pending;
        iwAssert(IW::due($bad,1010)===null,'Unknown forbids auto ACK');IW::reconcile($bad,1005);
        unset($bad->battle[$key]);IW::reconcile($bad,1010);
        iwAssert($bad->battle['informational_wait']['wait_id']>$record['wait_id'] && $bad->battle['informational_wait']['deadline_at']===1020,'Unknown cannot carry old deadline');
    }
    foreach(['wait_id'=>'1','deadline_at'=>1009,'required_player'=>'host','period_id'=>2,'source'=>'turn_phase'] as $key=>$value){
        $bad=GameState::fromArray($s->toArray());$bad->battle['informational_wait'][$key]=$value;
        iwAssert(IW::due($bad,9999)===null && IW::token($bad)===null,'Corrupt metadata cannot authorize ACK');
    }
    $bad=GameState::fromArray($s->toArray());$bad->battle['turn_timer']['period_id']=2;iwAssert(IW::due($bad,9999)===null,'Changed period blocks auto');
    $bad=GameState::fromArray($s->toArray());$bad->battle['turn_timer']['owner']='player';iwAssert(IW::due($bad,9999)===null,'Changed owner blocks auto');
    $s->winner='host';$s->status='game_over';IW::reconcile($s,1010);iwAssert(!isset($s->battle['informational_wait']) && IW::due($s,9999)===null,'Match completion closes P2');
}

// Real combat: blocked push is another informational screen after the initial results.
$sessionBefore=$_SESSION??[];
try{
    $now=1000;$s=iwState();$s->getCard(1)->prop=['strike_effects'=>[['push'=>2]]];
    $s->addCard(new CardInstance(instanceId:3,ukid:'blocker',owner:'player',zone:CardInstance::ZONE_FIELD,row:5,col:3,hp:20,hpMax:20));
    $_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
    iwApply($e,$s,'host','strike',['card_id'=>1,'target_id'=>2]);$old=IW::token($s);
    $now=1001;iwApply($e,$s,'host','confirm_strike');$now=1002;iwApply($e,$s,'player','confirm_strike');
    iwAssert($s->battle['strike']['state']==='waiting_push_ack' && IW::token($s)!==$old
        && $s->battle['informational_wait']['deadline_at']===1012,'Actual results -> blocked push gets new identity/deadline');
    $now=1012;iwApply($e,$s,IW::due($s,$now)['required_player'],'confirm_strike');
    iwAssert($s->battle['turn_timer']['status']==='running','Push opponent ACK resumes owner time');
    iwApply($e,$s,'host','confirm_strike');iwAssert(empty($s->battle['strike']),'Push ACK closes existing lifecycle');

    $now=1000;$s=iwState();$s->getCard(1)->prop=['on_strike_ally_shield'=>['types'=>['strike']]];
    $s->addCard(new CardInstance(instanceId:3,ukid:'shield_ally',owner:'host',zone:CardInstance::ZONE_FIELD,row:3,col:2,hp:20,hpMax:20));
    $_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
    iwApply($e,$s,'host','strike',['card_id'=>1,'target_id'=>2]);$old=IW::token($s);
    iwApply($e,$s,'host','confirm_strike');iwApply($e,$s,'player','confirm_strike');
    iwAssert(!empty($s->battle['pending_segger_shield']) && IW::due($s,9999)===null,'Real Segger choice blocks auto');
    $now=1020;iwApply($e,$s,'host','choose_segger_shield',['value'=>1]);
    iwAssert(IW::token($s)!==$old && $s->battle['informational_wait']['deadline_at']===1030,'Segger result receives a fresh deadline');

    $now=1000;$s=iwState();$s->getCard(2)->coins=1;
    $s->getCard(2)->prop=['coin_block'=>['cost'=>1,'defense'=>'zoa','types'=>['strike']]];
    iwApply($e,$s,'host','strike',['card_id'=>1,'target_id'=>2]);
    iwAssert(!empty($s->battle['pending_coin_ability']) && IW::due($s,9999)===null,'Coin block decision has no deadline');
    $now=1020;iwApply($e,$s,'player','choose_coin_ability',['choice'=>'yes','choice_id'=>$s->battle['pending_coin_ability']['id']]);
    iwAssert($s->battle['strike']['state']==='results' && $s->battle['informational_wait']['deadline_at']===1030,'Coin block publishes only final visible result');

    // Seed the originating resolved strike as in the existing Bon/Berrow regression;
    // all nested commands, origin restoration and result publications use Engine.
    $now=1000;$s=iwState();$s->getCard(1)->coins=1;
    $s->getCard(1)->prop=json_decode(file_get_contents(__DIR__.'/../docs/cards/bon_and_berrow.prop.json'),true,512,JSON_THROW_ON_ERROR);
    $s->addCard(new CardInstance(instanceId:3,ukid:'force_probe',owner:'player',zone:CardInstance::ZONE_FIELD,row:5,col:4,hp:20,hpMax:20,
        prop:['instants'=>[['key'=>'force','trigger'=>'turn','target'=>'enemy_open_creature','effect'=>['type'=>'forced_strike_adjacent','close_attacker_after'=>true]]]]));
    $s->addCard(new CardInstance(instanceId:4,ukid:'forced_actor',owner:'host',zone:CardInstance::ZONE_FIELD,row:3,col:1,hp:20,hpMax:20,strikeWeak:1,strikeMedium:1,strikeStrong:1));
    $s->addCard(new CardInstance(instanceId:5,ukid:'forced_target',owner:'player',zone:CardInstance::ZONE_FIELD,row:4,col:1,hp:20,hpMax:20,closed:true));
    $s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'state'=>'results','confirmed'=>[]];
    (new \Berserk\Core\StrikeResolver($s,$e))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);
    IW::publish($s);IW::reconcile($s,$now);TurnTimer::reconcileWaiting($s,$now);$originId=IW::token($s);
    iwApply($e,$s,'host','confirm_strike');iwApply($e,$s,'player','confirm_strike');
    iwApply($e,$s,'player','play_turn_instant',['card_id'=>3,'instant_key'=>'force']);
    iwApply($e,$s,'player','choose_instant_pick',['target_id'=>4]);
    iwApply($e,$s,'player','choose_forced_strike_adjacent',['target_id'=>5]);
    $_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
    iwApply($e,$s,'player','pass_turn_instant');
    iwAssert(IW::due($s,9999)===null,'Higher priority instant result blocks nested informational deadline');
    $now=1020;iwApply($e,$s,'player','turn_instant_result_ok');
    iwAssert(IW::token($s)!==$originId && IW::record($s)['started_at']===1020,'Nested combat becomes a fresh active result');
    iwApply($e,$s,'host','confirm_strike');iwApply($e,$s,'player','confirm_strike');
    iwAssert(($s->battle['pending_coin_ability']['kind']??'')==='discharge' && IW::due($s,9999)===null,'Origin restoration cannot resurrect its expired screen');
    $now=1030;iwApply($e,$s,'host','choose_coin_ability',['target_id'=>$s->battle['pending_coin_ability']['candidates'][0],'choice_id'=>$s->battle['pending_coin_ability']['id']]);
    iwAssert(IW::record($s)['started_at']===1030 && IW::token($s)!==$originId,'Post-combat discharge publishes a distinct final result');

    $now=1000;$s=iwState();$s->getCard(1)->prop=[
        'start_pre_battle'=>[['effect'=>['type'=>'degasca_study']]],
        'strike_effects'=>[['levels'=>['strong'],'type'=>'degasca_sequence']]];
    $s->addCard(new CardInstance(instanceId:3,ukid:'learn_marker',owner:'host',zone:CardInstance::ZONE_DECK,order:1,
        prop:['strike_effects'=>[['levels'=>['strong'],'marker'=>['type'=>'stun','value'=>1]]]]));
    $_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
    iwApply($e,$s,'host','strike',['card_id'=>1,'target_id'=>2]);$originId=IW::token($s);
    iwApply($e,$s,'host','confirm_strike');iwApply($e,$s,'player','confirm_strike');
    iwAssert(($s->battle['pending_degasca_sequence']['stage']??'')==='choose_card' && IW::due($s,9999)===null,'Real Degasca learning blocks auto');
    $now=1020;iwApply($e,$s,'host','choose_degasca_card',['card_id'=>3]);
    iwAssert(empty($s->battle['strike']) && !isset($s->battle['informational_wait'])
        && (string)$s->battle['informational_wait_generation']===$originId,'Degasca technical restore does not reopen the acknowledged result');
}finally{$_SESSION=$sessionBefore;\Berserk\Core\Dice::init();}

// Legacy no-write token, exact persistence version, no automatic deadline.
$s=iwState();$s->persistenceVersion=8;$s->battle['strike']=['state'=>'waiting_push_ack','confirmed'=>[]];
$before=serialize($s);iwAssert(IW::token($s)==='legacy:8' && IW::due($s,9999)===null && serialize($s)===$before,'Legacy reads do not initialize P2');
$now=1005;iwApply($e,$s,'host','confirm_strike',['wait_id'=>'legacy:8']);
iwAssert(!isset($s->battle['informational_wait']),'Partial legacy ACK cannot invent start time');
$s->persistenceVersion=9;$before=serialize($s);
iwAssert(!$e->apply($s,'player',new Command('confirm_strike',['wait_id'=>'legacy:8']))->success && serialize($s)===$before,'Legacy token is limited to one saved version');
iwApply($e,$s,'player','confirm_strike',['wait_id'=>'legacy:9']);
iwAssert(empty($s->battle['strike']),'Legacy manual confirmation remains available');

// Every existing presentation must transport the same server token.
$tpl=new Template(__DIR__.'/../templates/');
foreach(['ordinary','cell_marker','become_fly','push'] as $kind){
    $now=1000;$s=iwState();iwOpen($e,$s);
    if($kind==='ordinary')unset($s->battle['strike']['cell_marker']);
    if($kind==='become_fly'){$s->battle['strike']['kind']='become_fly';unset($s->battle['strike']['cell_marker']);}
    if($kind==='push'){$s->battle['strike']['state']='waiting_push_ack';unset($s->battle['strike']['cell_marker']);IW::publish($s);IW::reconcile($s,1000);}
    $html=(new InfoPanel($tpl))->render($s,'host','host',[],'?first&game=1');
    iwAssert(str_contains($html,'cmd=confirm_strike&wait_id='.IW::token($s)),'Missing wait_id in '.$kind.' UI');
}
restore_error_handler();
echo "Informational wait generation/deadline/ACK/legacy/Engine/UI tests passed\n";
