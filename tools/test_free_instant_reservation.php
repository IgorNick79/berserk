<?php
declare(strict_types=1);
if(!defined('IR_FIXTURES_ONLY'))define('IR_FIXTURES_ONLY',true);
require_once __DIR__.'/test_instant_reservation.php';
use Berserk\Core\{ActionResolution,CardInstance,Choice\ChoiceRegistry,Command,Engine,FirstStrikeJump,
    FreeInstantWindows as FW,GameState,InformationalWaitManager as IW,InstantProcessor,InstantReservation as IR,TurnTimer};
$freeChecks=0;
function fCheck(bool $ok,string $why):void {global$freeChecks;++$freeChecks;if(!$ok)throw new RuntimeException($why);}
function fState(string $active='host'):GameState {
    $s=irState($active);foreach($s->cards as$c){$c->prop=['save_coins'=>true];$c->hp=$c->hpMax=30;}
    $passive=$s->getOpponentKey($active);$healer=$passive==='player'?2:1;$caster=$passive==='player'?4:3;
    $s->getCard($healer)->row=3;$s->getCard($healer)->col=3;$s->getCard($healer)->coins=3;
    $s->getCard($caster)->row=5;$s->getCard($caster)->col=5;
    $s->getCard($caster)->prop['instants']=[['key'=>'free','name'=>'Free','trigger'=>'turn','no_close'=>true,'uses_per_turn'=>2,
        'target'=>'enemy','effect'=>['type'=>'damage','value'=>1]]];
    $attacker=$active==='host'?1:2;$other=$active==='host'?3:4;
    $s->getCard($attacker)->row=2;$s->getCard($attacker)->col=1;$s->getCard($other)->row=2;$s->getCard($other)->col=5;
    return$s;
}
function fIds(GameState $s):array {$a=$s->battle['active'];$p=$s->getOpponentKey($a);return[$a,$p,$p==='player'?2:1,$p==='player'?4:3,$a==='host'?1:2];}
function fRevive(GameState $s):void {
    [,$p,$h]=fIds($s);$s->addCard(new CardInstance(instanceId:5,ukid:'free-grave',owner:$p,zone:'graveyard',hp:0,hpMax:8,move:1,moveMax:1));
    $s->battle['pending_revive']=['healer_id'=>$h,'action'=>['type'=>'revive','coins'=>2], 'cost'=>2,'candidates'=>[5],'chosen_id'=>null,'step'=>'card'];
    TurnTimer::reconcileWaiting($s,1000);
}
function fCmd(GameState $s,string $actor,string $type,array $payload=[],bool $expected=true,int $now=1000):\Berserk\Core\Result {
    $r=irEngine($now)->apply($s,$actor,new Command($type,$payload));fCheck($r->success===$expected,"$type: ".($r->error??'unexpected success'));return$r;
}
function fReserve(GameState $s,?string $actor=null,int $now=1000):void {
    $actor??=$s->getOpponentKey($s->battle['active']);$controls=IR::controls($s,$actor,$now);
    fCheck($controls['can_free']&&!$controls['can_paid']&&$controls['mode']==='free','Projection identifies free window');
    fCmd($s,$actor,'reserve_instant',['period_id'=>$controls['period_id'],'origin_choice_id'=>$controls['origin_choice_id']],true,$now);
    fCheck(IR::snapshot($s)['request']['reason']==='free','Server chooses free reason');
}
function fResult(GameState $s,string $state='results'):void {
    [$a,,$h,,$attacker]=fIds($s);
    $s->battle['strike']=['kind'=>'test-result','attacker_id'=>$attacker,'target_id'=>$h,'defender_id'=>null,'state'=>$state,
        'confirmed'=>[],'attack_dice'=>0,'defend_dice'=>0,'damage_applied'=>true,'strike_hit'=>false,
        'result'=>['attack'=>'','defend'=>'','winner'=>''],'final'=>['attack'=>'','defend'=>'','decreased'=>false]];
    ActionResolution::bind($s,$s->battle['strike'],$s->getCard($attacker),'strike');
    IW::publish($s);IW::reconcile($s,1000);TurnTimer::reconcileWaiting($s,1000);
}
function fDrain(GameState &$s,int $now=1000,?int $woundsCaster=null):array {
    $trace=[];$alreadyGranted=IR::snapshot($s)['status']==='granted';$woundsDeclared=false;
    for($i=0;$i<100;$i++){
        $s=irReload($s);$stack=$s->battle['turn_instant_stack']??null;
        if($stack){$trace[]='turn:'.$stack['phase'];fCmd($s,$stack['priority'],'pass_turn_instant',[],true,$now);continue;}
        if(!empty($s->battle['turn_instant_result'])){fCmd($s,$s->getOpponentKey($s->battle['active']),'turn_instant_result_ok',[],true,$now);continue;}
        if(!empty($s->battle['pending_wound_transfer'])){
            $trace[]='combat:wounds';fCheck($alreadyGranted||IR::snapshot($s)['status']==='requested','Wounds continuation cannot grant a new turn stack');
            fCmd($s,$s->battle['pending_wound_transfer']['owner'],'cancel_pending',[],true,$now);continue;
        }
        if(!empty($s->battle['strike'])){
            $strike=$s->battle['strike'];$owner=$s->getCard($strike['attacker_id'])->owner;$other=$s->getOpponentKey($owner);
            if(!empty($strike['pending_wounds_resolution'])){
                $trace[]='combat:wounds-deferred';fCheck($alreadyGranted||IR::snapshot($s)['status']==='requested','Free grant waits for deferred wounds');
            }
            $trace[]='strike:'.$strike['state'];
            if($strike['state']==='waiting_instant'){
                $trace[]='combat:'.($strike['combat_instant_window']??$strike['instant_phase']??'');
                fCheck($alreadyGranted||IR::snapshot($s)['status']!=='granted','No new free turn grant inside combat window');
                if($woundsCaster!==null&&!$woundsDeclared&&($strike['combat_instant_window']??null)==='wounds'
                    &&$strike['instant_priority']===$s->getCard($woundsCaster)->owner){
                    fCmd($s,$strike['instant_priority'],'combat_instant_play',['card_id'=>$woundsCaster,'instant_key'=>'wounds'],true,$now);$woundsDeclared=true;continue;
                }
                fCmd($s,$strike['instant_priority'],'combat_instant_pass',[],true,$now);
            }elseif($strike['state']==='waiting_defender')fCmd($s,$other,'choose_defender',['defender_id'=>0],true,$now);
            elseif($strike['state']==='waiting_choice')fCmd($s,$strike['choice_winner']==='attack'?$owner:$other,'choose_strike_mode',['mode'=>'normal'],true,$now);
            elseif(in_array($strike['state'],['results','waiting_push_ack'],true)){
                $actor=in_array('host',$strike['confirmed']??[],true)?'player':'host';fCmd($s,$actor,'confirm_strike',['wait_id'=>IW::token($s)],true,$now);
            }else throw new RuntimeException('Unsupported drain stage '.$strike['state']);
            continue;
        }
        fCheck(IR::snapshot($s)['status']==='idle','Whole continuation released reservation');return$trace;
    }
    throw new RuntimeException('Continuation did not settle');
}
if(defined('FREE_INSTANT_FIXTURES_ONLY'))return;

// Each allowlisted external handler is bound explicitly; registration never rewrites its payload.
foreach(['host','player']as$active)foreach(FW::EXTERNAL as$key){
    $s=fState($active);[, $p,$h]=fIds($s);$body=['owner'=>$p,'source_id'=>$h,'card_id'=>$h,'attacker_id'=>$h,'healer_id'=>$h,'current'=>$h,
        'id'=>'policy-id','kind'=>'ordinary','options'=>['on_finish'=>'main_phase']];
    if(in_array($key,['pending_any_death','pending_incarnation_wound','pending_nokami_wound','pending_intrigue_start'],true))$body=[$body];
    $s->battle[$key]=$body;$before=$s->toArray();$origin=FW::current($s,$p,1000);
    fCheck($origin!==null,"Explicit external policy $key $active");fCheck($before===$s->toArray(),'Policy is read-only');
    fReserve($s,$p);fCheck($s->battle[$key]===$body,"Registration preserves $key");
}

foreach(['host','player']as$active)foreach([0,2]as$used){
    $s=fState($active);[$a,$p,$h,$caster,$attacker]=fIds($s);fRevive($s);$s->battle[IR::KEY]['used']=$used;
    $pending=$s->battle['pending_revive'];$clock=$s->battle['turn_timer'];$sourceCoins=$s->getCard($h)->coins;
    fReserve($s);$id=IR::snapshot($s)['request']['id'];
    fCheck($pending===$s->battle['pending_revive']&&$clock===$s->battle['turn_timer']&&$s->getCard($h)->coins===$sourceCoins,'Pending/resources/timer unchanged on reserve');
    $s=irReload($s);fCmd($s,$p,'reserve_instant',['period_id'=>1]);fCheck(IR::snapshot($s)['request']['id']===$id,'Repeated free request keeps identity');
    fCmd($s,$a,'gain_coin',['card_id'=>$attacker],false);fCmd($s,$p,'choose_revive_target',['target_id'=>5]);
    fCheck(IR::snapshot($s)['status']==='requested'&&$s->battle['pending_revive']['step']==='cell','No grant between multi-step choices');
    $s=irReload($s);fCmd($s,$p,'choose_revive_cell',['row'=>3,'col'=>4]);
    fCheck(IR::snapshot($s)['status']==='granted'&&IR::snapshot($s)['used']===$used&&$s->getCard(5)->zone==='field','Final choice revives first and grants free atomically');
    fCheck($s->battle['turn_timer']['pause_reason']==='opponent_wait','Active clock paused at free priority');
    $s=irReload($s);fCmd($s,$p,'decline_instant_priority',['request_id'=>$id]);
    fCheck(IR::snapshot($s)['used']===$used&&$s->battle['turn_timer']['status']==='running','Decline resumes active without paid charge');
    fCmd($s,$p,'cancel_instant_reservation',['request_id'=>$id],false);
    fCmd($s,$a,'gain_coin',['card_id'=>$attacker]);fCheck(IR::snapshot($s)['status']==='idle','Free request cannot stop twice');
}

// A cancelled request never replaces cancellation or the original choice's refund policy.
$s=fState();[, $p,$h]=fIds($s);fRevive($s);fReserve($s);$id=IR::snapshot($s)['request']['id'];
fCmd($s,$p,'cancel_instant_reservation',['request_id'=>$id]);fCmd($s,$p,'choose_revive_target',['target_id'=>5]);fCmd($s,$p,'choose_revive_cell',['row'=>3,'col'=>4]);
fCheck(IR::snapshot($s)['status']==='idle'&&empty($s->battle['turn_instant_stack']),'Cancel request preserves native completion with no stop');
$s=fState();fRevive($s);fReserve($s);fCmd($s,'player','cancel_pending');
fCheck(IR::snapshot($s)['status']==='granted'&&$s->getCard(2)->coins===5,'Native choice cancel refunds its own cost; reserved priority remains free');fDrain($s);

// Two declarations use the old LIFO, then the native result/continuation returns ordinary control.
$s=fState();[, $p,$h,$caster,$attacker]=fIds($s);fRevive($s);fReserve($s);
fCmd($s,$p,'choose_revive_target',['target_id'=>5]);fCmd($s,$p,'choose_revive_cell',['row'=>3,'col'=>4]);
$s->getCard($h)->prop['instants']=[['key'=>'low','name'=>'Low','trigger'=>'turn','no_close'=>true,'target'=>'enemy','effect'=>['type'=>'damage','value'=>1]]];
foreach([[$h,'low'],[$caster,'free']]as[$card,$key]){fCmd($s,$p,'play_turn_instant',['card_id'=>$card,'instant_key'=>$key]);fCmd($s,$p,'choose_instant_pick',['target_id'=>$attacker]);}
fCmd($s,$p,'pass_turn_instant');fCheck(array_column($s->battle['turn_instant_result']['summary'],'label')===['Free','Low'],'LIFO order preserved');
fCmd($s,$p,'turn_instant_result_ok');fCheck(IR::snapshot($s)['status']==='idle'&&$s->getCard($attacker)->hp===28,'Free LIFO returns to ordinary game');

// A real mandatory jump then pull changes decision owner, never the reservation's owner/reason.
foreach(['host','player']as$active){
    $s=fState($active);[$a,$p,$h,$caster,$attacker]=fIds($s);$s->getCard($h)->row=4;$s->getCard($h)->col=3;
    $s->getCard($attacker)->row=3;$s->getCard($attacker)->col=3;
    fCheck((new FirstStrikeJump($s,irEngine()))->openAfterCombat($s->getCard($h),['range'=>2,'pull_adjacent_enemy'=>true]),'Open native post-combat jump');
    TurnTimer::reconcileWaiting($s,1000);fReserve($s);$jumpId=$s->battle['pending_first_strike_jump']['id'];
    fCmd($s,$p,'choose_first_strike_movement',['choice_id'=>$jumpId,'row'=>5,'col'=>3],true,1010);
    fCheck($s->battle['pending_first_strike_jump']['owner']===$a&&IR::snapshot($s)['status']==='requested','Reserve waits through second owner');
    $s=irReload($s);fCmd($s,$a,'choose_first_strike_movement',['choice_id'=>$s->battle['pending_first_strike_jump']['id'],'target_id'=>$attacker],true,1020);
    fCheck(IR::snapshot($s)['status']==='granted'&&$s->battle['turn_timer']['remaining_seconds']===170,'Only active pull choice spends active time');fDrain($s,1020);
}

// Native death-reaction queue: consuming its first item is not the end of the action.
$s=fState();$s->battle['pending_any_death']=[['source_id'=>2,'candidates'=>[1],'poison_value'=>1],['source_id'=>4,'candidates'=>[1],'poison_value'=>1]];
$extra=clone $s->getCard(4);$extra->instanceId=6;$extra->row=6;$extra->col=1;$s->addCard($extra);
fReserve($s);fCmd($s,'player','choose_any_death_target',['target_id'=>0]);fCheck(IR::snapshot($s)['status']==='requested','Wait for the whole death queue');
fCmd($s,'player','choose_any_death_target',['target_id'=>1]);fCheck(IR::snapshot($s)['status']==='granted'&&isset($s->getCard(1)->markers['poison']),'Queue effect occurs before free grant');fDrain($s);

// Native damage kills the original choice source; the surviving independent caster remains usable.
foreach(['host','player']as$active){
    $s=fState($active);[$a,$p,$h,$caster,$attacker]=fIds($s);$s->getCard($h)->hp=1;
    $s->battle['pending_self_wound']=['attacker_id'=>$h,'target_id'=>$attacker,'action'=>['type'=>'self_wound_strike'],'max_wounds'=>1];
    fReserve($s);fCmd($s,$p,'choose_self_wound',['amount'=>1]);
    fCheck($s->getCard($h)->hp<=0&&IR::snapshot($s)['status']==='requested','Native source death waits for result continuation');
    $trace=fDrain($s);fCheck(IR::snapshot($s)['used']===0&&(in_array('turn:after',$trace,true)||in_array('turn:turn',$trace,true)),'Source death reaches safe native or quiescent boundary without paid cost');
}
// Changes in availability/origin do not spend a paid attempt or leave a phantom grant.
foreach(['ability_dead','ability_hidden','target_gone','origin_gone','period','expired','terminal']as$case){
    $s=fState();[, $p,$h,$caster]=fIds($s);fRevive($s);fReserve($s);
    if($case==='ability_dead')$s->getCard($caster)->hp=0;
    elseif($case==='ability_hidden')$s->getCard($caster)->revealed=false;
    elseif($case==='target_gone'){foreach($s->cards as$c)if($c->owner!==$p){$c->zone='graveyard';}}
    elseif($case==='origin_gone')unset($s->cards[$h]);
    elseif($case==='period')IR::endPeriod($s);
    elseif($case==='expired'){$s->battle['turn_timer']['status']='expired';$s->battle['turn_timer']['remaining_seconds']=0;$s->battle['turn_timer']['anchor_at']=null;$s->battle['turn_timer']['pause_reason']=null;}
    else {$s->status='game_over';$s->winner='host';}
    unset($s->battle['pending_revive']);IR::afterCommand($s,irEngine(),new Command('choose_revive_cell'));
    fCheck(IR::snapshot($s)['status']==='idle'&&IR::snapshot($s)['used']===0,"Drop invalid free $case");
}

// Existing paid reason is immutable when a later mandatory choice would itself allow free.
$s=fState();fCmd($s,'player','reserve_instant',['period_id'=>1]);$id=IR::snapshot($s)['request']['id'];fRevive($s);
fCmd($s,'player','reserve_instant',['period_id'=>1,'reason'=>'free']);
fCheck(IR::snapshot($s)['request']['reason']==='paid'&&IR::snapshot($s)['request']['id']===$id,'No paid-to-free upgrade');
fCmd($s,'player','choose_revive_target',['target_id'=>5]);fCmd($s,'player','choose_revive_cell',['row'=>3,'col'=>4]);
fCheck(IR::snapshot($s)['used']===1,'Previously paid request still pays only at grant');fDrain($s);

// Results retain deadline and identity; native after-window consumes the request without duplication.
foreach(['host','player']as$active)foreach(['results','waiting_push_ack']as$state){
    $s=fState($active);[$a,$p]=fIds($s);fResult($s,$state);$record=$s->battle['informational_wait'];$waitId=IW::token($s);
    fReserve($s,$p,1001);fCheck($s->battle['informational_wait']===$record&&IW::token($s)===$waitId,'Reserve cannot reset informational deadline/wait_id');
    $s=irReload($s);fCmd($s,$a,'confirm_strike',['wait_id'=>$waitId],true,1002);
    fCheck(IR::snapshot($s)['status']==='requested','One ACK is not completion');
    fCheck(IW::due($s,1010)!==null,'Original auto-ACK remains due');
    fCmd($s,$p,'confirm_strike',['wait_id'=>$waitId],true,1010);
    fCheck(IR::snapshot($s)['status']==='granted'&&IR::snapshot($s)['used']===0,'Final ACK delivers free priority: '.json_encode([$active,$state,IR::snapshot($s),$s->battle['turn_instant_stack']??null]));
    if($state==='results')fCheck($s->battle['turn_instant_stack']['phase']==='after'&&IR::snapshot($s)['request']['grant_kind']==='native','Reuse original after window');
    fCmd($s,$p,'confirm_strike',['wait_id'=>$waitId],false,1010);$trace=fDrain($s,1010);
    fCheck(count(array_filter($trace,fn($v)=>str_starts_with($v,'turn:')))===1,'No duplicate window after ACK');
}

// Defender order remains before -> defender -> combat -> after, including explicit combat windows.
foreach(['host','player']as$active){
    $s=fState($active);[$a,$p,$target,$caster,$attacker]=fIds($s);
    $s->getCard($attacker)->row=3;$s->getCard($attacker)->col=3;$s->getCard($target)->row=4;$s->getCard($target)->col=3;
    $s->getCard($caster)->row=4;$s->getCard($caster)->col=4;
    $s->getCard($target)->prop['instants']=$s->getCard($caster)->prop['instants'];
    fCmd($s,$a,'strike',['card_id'=>$attacker,'target_id'=>$target]);
    fCheck(($s->battle['turn_instant_stack']['phase']??null)==='before','Original before window still precedes defender');fCmd($s,$p,'pass_turn_instant');
    fCheck(($s->battle['strike']['state']??null)==='waiting_defender','Defender choice remains after before');
    // The before window used no declarations, so after-phase abilities remain potential.
    fReserve($s);$s->getCard($attacker)->prop['instants']=[];
    $s->addCard(new CardInstance(instanceId:6,ukid:'free-combat-windows',owner:$a,zone:'field',row:2,col:5,hp:30,hpMax:30,prop:['instants'=>[
        ['key'=>'dice','trigger'=>'combat','phase'=>'dice','target'=>'self','no_close'=>true,'effect'=>['type'=>'damage_on_dice','value'=>1,'damage'=>2]],
        ['key'=>'power','trigger'=>'combat','phase'=>'power','target'=>'self','no_close'=>true,'effect'=>['type'=>'strike_level','mode'=>'set','value'=>'strong']],
    ]]));
    $s->addCard(new CardInstance(instanceId:7,ukid:'free-wounds',owner:$p,zone:'field',row:6,col:1,hp:30,hpMax:30,prop:['instants'=>[
        ['key'=>'wounds','trigger'=>'combat','phase'=>'wounds','target'=>'self','no_close'=>true,'effect'=>['type'=>'redistribute_wounds']],
    ]]));
    fCmd($s,$p,'choose_defender',['defender_id'=>$caster]);
    fCheck(IR::snapshot($s)['status']==='requested'&&empty($s->battle['turn_instant_stack']),'No extra before window after defender');
    $trace=fDrain($s,1000,7);fCheck(count(array_filter($trace,fn($x)=>$x==='turn:after'))===1&&!in_array('turn:before',$trace,true),'Exactly one native after, no repeated before');
    fCheck(in_array('combat:dice',$trace,true)&&in_array('combat:main',$trace,true),'Free defender request waits across actual dice and main windows');
    // Stage 4 offers wounds only when post-damage donors exist; deterministic cases are in test_instant_stage4.
}

// Forbidden contexts are not admitted solely because a handler/required player exists.
foreach(['pending_forced_strike','pending_dice_choice','pending_combat_pick','pending_instant_pick','pending_forced_strike_adjacent','pending_matriarch_order',
    'pending_after_strike_execute','pending_anubisar_chain','pending_segger_shield','pending_attack_series_choice','pending_degasca_sequence','pending_prophecy','pending_dive']as$key){
    $s=fState();$s->battle[$key]=['owner'=>'player','source_id'=>2,'card_id'=>2];
    fCheck(!IR::controls($s,'player',1000)['can_reserve'],"Forbidden projection $key");fCmd($s,'player','reserve_instant',['period_id'=>1],false);
}
foreach(['dice','main','wounds']as$stage){
    $s=fState();fResult($s);$s->battle['strike']['state']='waiting_instant';$s->battle['strike']['instant_phase']='combat';
    $s->battle['strike']['instant_priority']='player';$s->battle['strike']['combat_instant_window']=$stage;
    fCheck(FW::current($s,'player',1000)===null,'No free in combat '.$stage);fCmd($s,'player','reserve_instant',['period_id'=>1],false);
}
$s=fState();fResult($s);$s->battle['strike']['instant_result_stage']='dice';fCheck(!IR::controls($s,'player',1000)['can_free'],'Dice result is not an external free confirmation');
$s=fState();fResult($s);unset($s->battle['informational_wait'],$s->battle['informational_wait_generation']);fCheck(!IR::controls($s,'player',1000)['can_free'],'Ambiguous legacy result excluded');
$s=fState();fResult($s);fCheck(!IR::controls($s,'player',1010)['can_free'],'Due informational wait cannot accept a late fresh free reserve');

// Start-phase decision binds the whole phase and reuses an existing native instant task.
$s=fState();$s->battle['turn_phase']=['phase'=>'start','active_key'=>'host','passive_key'=>'player','side'=>'passive',
    'passive_queue'=>[['id'=>'a','type'=>'test'],['id'=>'b','type'=>'test']],'active_queue'=>[],'sub'=>null,'pending_ack'=>null];
fReserve($s);(new InstantProcessor($s,irEngine()))->openTurnStackWindow('player',['type'=>'turn_phase'],'turn');
IR::afterCommand($s,irEngine(),new Command('turn_sub'));
fCheck(IR::snapshot($s)['status']==='granted'&&IR::snapshot($s)['request']['grant_kind']==='native','Reuse native start-phase stack without duplicate reserve');

// Projection is public enough to synchronize status, but never leaks a pending payload or mutates it.
$s=fState();fRevive($s);fReserve($s);unset($s->battle['pending_revive']);
(new InstantProcessor($s,irEngine()))->openTurnStackWindow('player',['type'=>'test_child'],'turn');
foreach(['combat_continuation'=>['frames'=>[1=>'active']],'coin_post_combat_queue'=>[['source_id'=>2]],'coin_post_combat_continuation'=>['owner'=>'player']]as$key=>$value){
    $s->battle[$key]=$value;IR::afterCommand($s,irEngine(),new Command('choose_revive_cell'));
    fCheck(IR::snapshot($s)['status']==='requested','Child native window cannot consume parent reservation: '.$key);unset($s->battle[$key]);
}
IR::afterCommand($s,irEngine(),new Command('choose_revive_cell'));fCheck(IR::snapshot($s)['status']==='granted','Quiescent native window can fulfill released request');
$s=fState();fRevive($s);$before=$s->toArray();$p=IR::controls($s,'player',1000);$a=IR::controls($s,'host',1000);
fCheck($before===$s->toArray()&&$p['priority_owner']==='player'&&!$a['can_reserve'],'Read-only projection preserves original choice owner');
fCheck(!isset($p['binding'],$p['origin'],$p['candidates']),'Projection excludes pending internals');
fCmd($s,'player','reserve_instant',['period_id'=>1,'origin_choice_id'=>'stale'],false);
// Free roots use the same nested-combat frames as paid roots, in both orientations.
foreach(['host','player']as$active){
    $s=fState($active);[$a,$p,$healer,$caster,$attacker]=fIds($s);fRevive($s);
    $s->battle[IR::KEY]['limit']=0;fReserve($s);
    fCmd($s,$p,'choose_revive_target',['target_id'=>5]);fCmd($s,$p,'choose_revive_cell',['row'=>3,'col'=>4]);
    $s->getCard($attacker)->row=2;$s->getCard($attacker)->col=3;
    $s->getCard($healer)->closed=true;
    $s->getCard($caster)->prop['instants']=[['key'=>'force','name'=>'force','trigger'=>'turn','target'=>'enemy_open_creature',
        'no_close'=>true,'effect'=>['type'=>'forced_strike_adjacent']]];
    fCmd($s,$p,'play_turn_instant',['card_id'=>$caster,'instant_key'=>'force']);
    fCmd($s,$p,'choose_instant_pick',['target_id'=>$attacker]);
    fCmd($s,$p,'choose_forced_strike_adjacent',['target_id'=>$healer]);
    fCmd($s,$p,'pass_turn_instant');if(!empty($s->battle['turn_instant_stack']))fCmd($s,$s->battle['turn_instant_stack']['priority'],'pass_turn_instant');
    fCheck(IR::snapshot($s)['status']==='granted'&&!empty($s->battle['combat_continuation']['stacks']),'Free root suspended during child battle');
    $trace=fDrain($s);
    fCheck(IR::snapshot($s)['used']===0&&IR::snapshot($s)['status']==='idle'&&count(array_filter($trace,fn($x)=>str_starts_with($x,'strike:')))>0,'Nested free battle completes without budget or duplicate root');
}
echo "Free instant reservation Stage 3B: $freeChecks checks passed\n";
