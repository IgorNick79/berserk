<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,CardInstance,Command,Engine,GameState,InstantReservation as IR,InstantProcessor,TurnProcessor,TurnTimer};
Autoloader::register(); Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
set_error_handler(static function($n,$m,$f,$l) { if (error_reporting() & $n) throw new ErrorException($m,0,$n,$f,$l); return false; });
$irChecks=0;
function irCheck(bool $ok,string $message): void { global $irChecks; ++$irChecks; if (!$ok) throw new RuntimeException($message); }
function irState(string $active='host'): GameState {
    $s=new GameState(9917,101,202); $s->status='battle';
    $s->battle=['turn'=>2,'active'=>$active,'strike'=>null,'hidden_row_revealed'=>true];
    $s->players['host']->side=1; $s->players['player']->side=2;
    foreach ([1=>'host',2=>'player',3=>'host',4=>'player'] as $id=>$owner) {
        $s->addCard(new CardInstance(instanceId:$id,ukid:'ir'.$id,owner:$owner,zone:'field',row:$id,col:2,
            hp:10,hpMax:10,move:3,moveMax:3,strikeWeak:1,strikeMedium:2,strikeStrong:3,
            prop:['save_coins'=>true,'instants'=>[['key'=>'heal','trigger'=>'turn','name'=>'Heal','no_close'=>true,
                'uses_per_turn'=>10,'target'=>'ally','effect'=>['type'=>'heal','value'=>1]]]]));
    }
    TurnTimer::prepare($s,$active); TurnTimer::resume($s,1,'start_phase',1000); IR::beginPeriod($s,$active); return $s;
}
function irEngine(int $now=1000): Engine {return new Engine(null,static fn():int=>$now);}
function irCmd(GameState $s,string $actor,string $type,array $payload=[],bool $ok=true,int $now=1000): \Berserk\Core\Result {
    $r=irEngine($now)->apply($s,$actor,new Command($type,$payload));
    irCheck($r->success===$ok,$type.': '.($r->error??'unexpected success')); return $r;
}
function irReserve(GameState $s,string $actor): void {irCmd($s,$actor,'reserve_instant',['period_id'=>IR::snapshot($s)['period_id']]);}
function irReload(GameState $s): GameState {return GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));}
function irGrant(GameState $s,string $actor,int $source): void {
    irReserve($s,$actor); irCmd($s,$s->battle['active'],'gain_coin',['card_id'=>$source]);
    irCheck(IR::snapshot($s)['status']==='granted' && $s->battle['turn_instant_stack']['priority']===$actor,'Native stack grants requester');
}

if (defined('IR_FIXTURES_ONLY')) return;

foreach (['host','player'] as $active) {
    $s=irState($active); $passive=$s->getOpponentKey($active); $source=$active==='host'?1:2;
    $before=$s->toArray(); IR::snapshot($s); IR::safeBoundary($s); irCheck($before===$s->toArray(),'Pure projections');
    irCmd($s,$active,'reserve_instant',['period_id'=>1],false);
    irCmd($s,$passive,'reserve_instant',['period_id'=>0],false);
    irCmd($s,'outsider','reserve_instant',['period_id'=>1],false);
    irReserve($s,$passive); $id=IR::snapshot($s)['request']['id'];
    irCheck(IR::snapshot($s)['used']===0 && $s->battle['turn_timer']['status']==='running','Request costs no budget/time');
    irReserve($s,$passive); irCheck(IR::snapshot($s)['request']['id']===$id,'Repeated request same generation');
    $s=irReload($s); irCheck(IR::snapshot($s)['request']['id']===$id,'Requested reload');
    irCmd($s,$passive,'cancel_instant_reservation',['request_id'=>'stale'],false);
    irCmd($s,$active,'cancel_instant_reservation',['request_id'=>$id],false);
    irCmd($s,$passive,'cancel_instant_reservation',['request_id'=>$id]);
    irCheck(IR::snapshot($s)['used']===0,'Cancel costs nothing');
    for ($i=1;$i<=2;$i++) {
        $s->getCard($source)->closed=false; // Fresh legal action for the next budget probe.
        irGrant($s,$passive,$source); $id=IR::snapshot($s)['request']['id'];
        irCheck(IR::snapshot($s)['used']===$i && $s->battle['turn_timer']['pause_reason']==='opponent_wait','Grant spends once and pauses active clock');
        $s=irReload($s); $state=$s->toArray();
        irCmd($s,$active,'gain_coin',['card_id'=>$source],false); irCheck($s->toArray()===$state,'Blocked ordinary command cannot mutate');
        irCmd($s,$passive,'open_turn_instants',[],false);
        irCmd($s,$passive,'cancel_instant_reservation',['request_id'=>$id],false);
        irCmd($s,$passive,'decline_instant_priority',['request_id'=>$id]);
        irCheck(!isset($s->battle['turn_instant_stack']) && IR::snapshot($s)['used']===$i && $s->battle['turn_timer']['status']==='running','Decline returns control without refund');
        irCmd($s,$passive,'decline_instant_priority',['request_id'=>$id],false);
        $s->getCard($source)->closed=false;
        irCmd($s,$active,'gain_coin',['card_id'=>$source]);
        irCheck(IR::snapshot($s)['status']==='idle','Old request cannot stop twice');
    }
    irCmd($s,$passive,'reserve_instant',['period_id'=>1],false);
    // Native end/start lifecycle (system instant tasks may remain open).
    irCmd($s,$active,'end_turn');
    irCheck(IR::snapshot($s)['request']===null,'End turn clears old request');
    (new TurnProcessor($s,irEngine()))->afterEndPhase($active,$passive);
    irCheck(IR::snapshot($s)['used']===0 && IR::snapshot($s)['period_id']===2,'New individual turn restores budget');
    $s->battle[IR::KEY]['used']=1; IR::beginPeriod($s,$passive);
    irCheck(IR::snapshot($s)['used']===1,'Repeated lifecycle does not refund budget');
}

// No cancellation/re-registration amplification, old tokens never affect a new request.
$s=irState(); irReserve($s,'player'); $old=IR::snapshot($s)['request']['id'];
irCmd($s,'player','cancel_instant_reservation',['request_id'=>$old]); irReserve($s,'player');
irCmd($s,'player','cancel_instant_reservation',['request_id'=>$old],false);
irCmd($s,'host','gain_coin',['card_id'=>1]); irCheck(IR::snapshot($s)['used']===1,'Cancel/re-request grants once');

// The native two-pass stack resolves and returns without a parallel priority engine.
$s=irState();irGrant($s,'player',1);$id=IR::snapshot($s)['request']['id'];
irCmd($s,'player','pass_turn_instant');irCmd($s,'player','decline_instant_priority',['request_id'=>$id],false);
irCmd($s,'host','pass_turn_instant');
irCheck(IR::snapshot($s)['status']==='idle'&&IR::snapshot($s)['used']===1,'Requester cannot revoke opponent priority after passing; two empty passes return control');
$s=irState(); irGrant($s,'player',1); $id=IR::snapshot($s)['request']['id'];
irCmd($s,'player','play_turn_instant',['card_id'=>2,'instant_key'=>'heal']);
irCmd($s,'player','choose_instant_pick',['target_id'=>2]);
irCmd($s,'player','decline_instant_priority',['request_id'=>$id],false);
irCmd($s,'player','pass_turn_instant'); irCheck($s->battle['turn_instant_stack']['priority']==='host','First pass transfers priority');
irCmd($s,'host','pass_turn_instant'); irCheck(!empty($s->battle['turn_instant_result']),'Second pass resolves');
$s=irReload($s); irCmd($s,'player','turn_instant_result_ok');
irCheck(IR::snapshot($s)['status']==='idle' && IR::snapshot($s)['used']===1,'Native result ACK releases grant');

// No opening during intermediate pending, combat, or deferred continuation storage.
foreach (['pending_revive','turn_phase','strike','combat_continuation','coin_post_combat_queue','matriarch_order'] as $key) {
    $s=irState(); irReserve($s,'player'); $s->battle[$key]=['audit'=>true];
    IR::afterCommand($s,irEngine(),new Command('choose_test'));
    irCheck(IR::snapshot($s)['status']==='requested' && IR::snapshot($s)['used']===0,'No grant across '.$key);
    unset($s->battle[$key]); IR::afterCommand($s,irEngine(),new Command('choose_test'));
    irCheck(IR::snapshot($s)['status']==='granted','Grant after final continuation '.$key);
}
$s=irState(); irReserve($s,'player'); $s->getCard(2)->zone='graveyard'; $s->getCard(4)->zone='graveyard';
irCmd($s,'host','gain_coin',['card_id'=>1]); irCheck(IR::snapshot($s)['status']==='idle' && IR::snapshot($s)['used']===0,'Unavailable request dropped without spend');
$s=irState(); irReserve($s,'player'); irCmd($s,'host','end_turn');
irCheck(IR::snapshot($s)['request']===null && IR::snapshot($s)['used']===0,'Unspent request disappears at end turn');
$s=irState(); irCmd($s,'player','reserve_instant',['period_id'=>1,'reason'=>'free','limit'=>99]);
irCheck(IR::snapshot($s)['request']['reason']==='paid' && IR::snapshot($s)['limit']===2,'Client cannot choose free or limit');

// Future Stage 3 seam: server binds a request to the current mandatory owner/source.
$s=irState(); $s->battle[IR::KEY]['used']=2;
$s->battle['pending_revive']=['owner'=>'player','healer_id'=>2,'stage'=>'choose_card'];
$before=$s->battle['pending_revive']; irCmd($s,'player','reserve_instant',['period_id'=>1,'reason'=>'free']);
irCheck(IR::snapshot($s)['request']['reason']==='free','Stage 3B derives free automatically in a connected mandatory window');
$free=['source'=>'pending_revive','choice_id'=>'server-choice:1'];
$r=IR::request($s,irEngine(),'player',new Command('reserve_instant',['period_id'=>1]),$free);
irCheck($r->success && IR::snapshot($s)['request']['reason']==='free' && $s->battle['pending_revive']===$before,'Server free registration preserves pending, even at paid limit');
$s=irReload($s); IR::afterCommand($s,irEngine(),new Command('choose_revive_target'));
irCheck(IR::snapshot($s)['status']==='requested','Free waits for entire mandatory action');
unset($s->battle['pending_revive']); IR::afterCommand($s,irEngine(),new Command('choose_revive_cell'));
irCheck(IR::snapshot($s)['status']==='granted' && IR::snapshot($s)['used']===2,'Free grant consumes no paid use');
irCmd($s,'player','decline_instant_priority',['request_id'=>IR::snapshot($s)['request']['id']]);
$s=irState(); $s->battle['pending_revive']=['healer_id'=>1];
$r=IR::request($s,irEngine(),'player',new Command('reserve_instant',['period_id'=>1]),$free);
irCheck(!$r->success,'Free source must belong to requester');
$s=irState(); irCmd($s,'player','reserve_instant',['period_id'=>1],false,1181);
$s=irState(); irReserve($s,'player'); irCmd($s,'host','resign');
irCheck(IR::snapshot($s)['request']===null,'Terminal state drops request');

// Shared source eligibility, preserving the existing no_close exception.
foreach (['dead','hidden','closed','no_coins'] as $case) {
    $s=irState();foreach([2,4]as$id){$c=$s->getCard($id);
        if($case==='dead')$c->hp=0;
        elseif($case==='hidden')$c->revealed=false;
        elseif($case==='closed')$c->closed=true;
        else $c->prop['instants'][0]['coins']=3;
    }
    irCmd($s,'player','reserve_instant',['period_id'=>1],$case==='closed');
}

// Reservation root opts into existing frames: LIFO child combat precedes older healing.
$s=irState();$s->getCard(3)->row=3;$s->getCard(3)->col=3;
$s->getCard(2)->row=4;$s->getCard(2)->col=3;$s->getCard(4)->row=6;$s->getCard(4)->col=5;$s->getCard(4)->hp=5;
$s->getCard(4)->prop['instants']=[['key'=>'force','name'=>'force','trigger'=>'turn','target'=>'enemy_open_creature',
    'no_close'=>true,'effect'=>['type'=>'forced_strike_adjacent']]];
irGrant($s,'player',1);
irCmd($s,'player','play_turn_instant',['card_id'=>2,'instant_key'=>'heal']);irCmd($s,'player','choose_instant_pick',['target_id'=>4]);
irCmd($s,'player','play_turn_instant',['card_id'=>4,'instant_key'=>'force']);irCmd($s,'player','choose_instant_pick',['target_id'=>3]);
irCmd($s,'player','choose_forced_strike_adjacent',['target_id'=>2]);
irCmd($s,'player','pass_turn_instant');irCmd($s,'host','pass_turn_instant');
irCheck(IR::snapshot($s)['status']==='granted'&&$s->getCard(4)->hp===5
    &&!empty($s->battle['combat_continuation']['stacks']),'LIFO suspends older entry until forced child is complete');
for($i=0;$i<60&&IR::snapshot($s)['status']==='granted';$i++) {
    $s=irReload($s);
    if(!empty($s->battle['turn_instant_stack']))irCmd($s,$s->battle['turn_instant_stack']['priority'],'pass_turn_instant');
    elseif(!empty($s->battle['turn_instant_result']))irCmd($s,'player','turn_instant_result_ok');
    elseif(!empty($s->battle['strike'])){
        $strike=$s->battle['strike'];$kind=$strike['state'];
        if($kind==='waiting_defender')irCmd($s,'player','choose_defender',['defender_id'=>0]);
        elseif($kind==='waiting_instant')irCmd($s,$strike['instant_priority'],'combat_instant_pass');
        elseif($kind==='waiting_choice')irCmd($s,$strike['choice_winner']==='attack'?'host':'player','choose_strike_mode',['mode'=>'normal']);
        elseif($kind==='results'){
            $actor=in_array('host',$strike['confirmed']??[],true)?'player':'host';
            irCmd($s,$actor,'confirm_strike',['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]);
        }else throw new RuntimeException('Unexpected child stage '.$kind);
    }else throw new RuntimeException('Invisible reservation continuation');
}
irCheck(IR::snapshot($s)['status']==='idle' && $s->getCard(4)->hp===6 && IR::snapshot($s)['used']===1,
    'Older healing resumes once after nested combat/reloads, then ordinary control returns');
echo "Instant reservation: $irChecks checks passed\n";
