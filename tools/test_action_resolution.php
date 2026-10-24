<?php
declare(strict_types=1);
require __DIR__.'/test_combat_continuation.php';
use Berserk\Core\{ActionResolution,StrikeResolver,Engine};
function notices($s): array { return $s->battle[ActionResolution::KEY]['notifications'] ?? []; }
foreach (['hit','miss','blocked','zero'] as $case) {
 $s=cs();$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'confirmed'=>[],'state'=>'waiting_choice'];
 if($case==='blocked')$s->battle['strike']['coin_blocked']=true;
 if($case==='zero')$s->getCard(2)->prop['damage_reduction']=[['value'=>99]];
 $r=new StrikeResolver($s,new Engine());
 $r->apply(['attack'=>in_array($case,['miss','blocked'],true)?'':'weak','defend'=>'','winner'=>'attack'],false);
 $e=notices($s);ca(count($e)===1,'one strike '.$case);
 if($case==='zero')ca($s->getCard(2)->hp===30,'hit with actual prevented damage');
 ca($e[0]['parent_id']===null,'root never parents itself');
 ca($e[0]['outcome']===($case==='zero'?'hit':$case),'outcome '.$case);
 ca($e[0]['hit']===in_array($case,['hit','zero'],true),'hit distinct from damage');
 $s=cr($s);(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);
 ca(count(notices($s))===1,'reload apply exactly once');
}
$s=cs();start($s);ca(count(notices($s))===1,'forced hit resolved before ACK');
$first=notices($s)[0];finish($s);ca(($s->battle[ActionResolution::KEY]['sequence']??0)===1,'ACK never re-emits');
$s=cs();start($s);$context=$s->battle['strike'];$s=cr($s);ActionResolution::beginCommand($s);
ActionResolution::resolve($s,$context,'hit',['hit'=>true]);ca(notices($s)===[],'copied context cannot re-deliver');
echo "action resolution: hit/miss/block/zero, reload, ACK and duplicate identity passed\n";

use Berserk\Core\{ActionResolver,Command};
foreach([1,0] as $restore){
 $s=cs();$s->getCard(1)->hp=30-$restore;$s->getCard(1)->prop['actions']=[['type'=>'heal','key'=>'h','value'=>1,'self'=>true]];
 $r=(new ActionResolver($s,new Engine()))->handle('host',new Command('action',['card_id'=>1,'target_id'=>1,'action_key'=>'h']));
 ca($r->success,'heal accepted');$e=notices($s);ca(count($e)===1&&$e[0]['hp_restored']===$restore,'actual HP only');
}
$s=cs();$s->getCard(1)->prop['actions']=[['type'=>'heal','key'=>'h','value'=>2,'max_targets'=>3]];
$s->getCard(1)->hp=29;$s->getCard(5)->hp=28;
$a=new ActionResolver($s,new Engine());$r=$a->handle('host',new Command('action',['card_id'=>1,'action_key'=>'h']));ca($r->success&&notices($s)===[],'multi declaration silent');
$id=$s->battle['pending_multi_heal']['action'][ActionResolution::ID];$s=cr($s);ActionResolution::beginCommand($s);
$r=(new ActionResolver($s,new Engine()))->chooseMultiHeal('host',new Command('choose_multi_heal',['target_ids'=>[1,5]]));
ca($r->success,'multi accepted');$e=notices($s);ca(count($e)===1&&$e[0]['hp_restored']===3&&$e[0]['action_id']===$id,'one multi aggregate across reload');
foreach([2,0] as $value){
 $s=cs();$s->getCard(2)->row=6;$s->getCard(1)->prop['actions']=[['type'=>'shot','key'=>'s','value'=>$value,'range'=>5]];
 $_SESSION['debug_roll']='6';unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
 $r=(new ActionResolver($s,new Engine()))->handle('host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'s']));
 ca($r->success,'shot accepted:'.$r->error);$e=notices($s);ca(count($e)===1&&$e[0]['outcome']===($value>0?'hit':'miss'),'shot hit/miss');
}
$s=cs();$s->getCard(1)->hp=20;$s->getCard(1)->prop['vampire']=true;
$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'confirmed'=>[],'state'=>'waiting_choice'];
(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);
$e=notices($s);ca(count($e)===2&&$e[0]['type']==='strike'&&$e[1]['type']==='heal','strike before passive vampire');
ca($e[1]['parent_id']===$e[0]['action_id']&&$e[1]['origin']==='passive'&&$e[1]['hp_restored']===1,'vampire provenance');
echo "action resolution: heal 1/0, multi aggregate/reload, shot hit/miss, vampire order passed\n";

// Rejection cannot erase or add delivery bookkeeping.
$s=cs();start($s);$before=$s->battle[ActionResolution::KEY];
$r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>999]));
ca(!$r->success&&$s->battle[ActionResolution::KEY]===$before,'rejected command leaves notification state intact');
// Cancellation survives reload and never becomes a completed heal.
$s=cs();$s->getCard(1)->prop['actions']=[['type'=>'heal','key'=>'h','value'=>2,'max_targets'=>3]];
cmd($s,'host','action',['card_id'=>1,'action_key'=>'h']);$id=$s->battle['pending_multi_heal']['action'][ActionResolution::ID];
$s=cr($s);ActionResolution::beginCommand($s);$r=(new ActionResolver($s,new Engine()))->cancelPending('host',new Command('cancel_pending'));ca($r->success,'native cancellation');$e=notices($s);ca(count($e)===1&&$e[0]['action_id']===$id&&$e[0]['outcome']==='cancelled','cancel same logical action');
$seq=$s->battle[ActionResolution::KEY]['sequence'];$r=(new Engine())->apply($s,'host',new Command('cancel_pending'));
ca(!$r->success&&$s->battle[ActionResolution::KEY]['sequence']===$seq,'repeat cancellation not delivered');
// Every element is observed, with no forest prefilter.
$s=cs();$s->getCard(1)->element='mountains';$s->getCard(1)->prop['save_coins']=true;
cmd($s,'host','gain_coin',['card_id'=>1]);$e=notices($s);ca(count($e)===1&&$e[0]['type']==='gain_coin'&&$e[0]['element']==='mountains','nonforest interrupter observed');
// The movement boundaries retain exact movement kind and are not ACK events.
foreach(['move','jump','teleport','forced_move'] as $kind){
 $s=cs();$s->getCard(1)->row=2;$s->getCard(1)->col=2;
 $ctx=new \Berserk\Core\Movement\MovementContext($s->getCard(1),'host',3,3,2,2,$kind);
 $proc=new \Berserk\Core\Movement\MovementEffectResolver($s,new Engine());
 if(in_array($kind,['move','jump'],true))$proc->apply($ctx);else $proc->applyForcedPositionChange($ctx);
 $e=notices($s);ca(count($e)===1&&$e[0]['type']===$kind,'movement kind '.$kind);
}
// Bounded delivery metadata, no accumulated cross-command journal.
$s=cs();for($i=0;$i<100;$i++){ActionResolution::beginCommand($s);ActionResolution::effect($s,$s->getCard(1),'probe','ordinary');}
ca(count($s->battle[ActionResolution::KEY]['tokens'])===1&&count(notices($s))===1,'completed unowned tokens are collected');
echo "action resolution: rejection/cancel/repeat, nonforest, four movements and bounded metadata passed\n";

function arCmd($s,string $actor,string $type,array $payload,array &$all):void {cmd($s,$actor,$type,$payload);array_push($all,...notices($s));}
function arDrain(&$s,array &$all):void {
 for($i=0;$i<50&&!empty($s->battle['strike']);$i++){
  $s=cr($s);$st=$s->battle['strike'];
  if($st['state']==='results'||$st['state']==='waiting_push_ack'){
   foreach(['host','player']as$p)if(!empty($s->battle['strike'])&&!in_array($p,$s->battle['strike']['confirmed']??[],true))arCmd($s,$p,'confirm_strike',[],$all);
  }elseif($st['state']==='waiting_defender')arCmd($s,'player','choose_defender',['defender_id'=>0],$all);
  elseif($st['state']==='waiting_choice')arCmd($s,$st['choice_winner']==='attack'?$s->getCard($st['attacker_id'])->owner:$s->getOpponentKey($s->getCard($st['attacker_id'])->owner),'choose_strike_mode',['mode'=>'normal'],$all);
  else throw new RuntimeException('Unexpected notification test state '.$st['state']);
 }
}
$s=cs();$s->getCard(3)->prop['instants']=[['key'=>'force','name'=>'force','trigger'=>'turn','target'=>'enemy_open_creature','no_close'=>true,'effect'=>['type'=>'forced_strike_adjacent']]];
start($s);$all=notices($s);arCmd($s,'player','pass_turn_instant',[],$all);arDrain($s,$all);
arCmd($s,'player','play_turn_instant',['card_id'=>3,'instant_key'=>'force'],$all);
arCmd($s,'player','choose_instant_pick',['target_id'=>5],$all);
arCmd($s,'player','choose_forced_strike_adjacent',['target_id'=>2],$all);
arCmd($s,'player','pass_turn_instant',[],$all);
for($i=0;$i<30&&\Berserk\Core\CombatContinuation::active($s);$i++){
 $s=cr($s);
 if(!empty($s->battle['turn_instant_stack']))arCmd($s,$s->battle['turn_instant_stack']['priority'],'pass_turn_instant',[],$all);
 elseif(!empty($s->battle['turn_instant_result']))arCmd($s,'player','turn_instant_result_ok',[],$all);
 elseif(!empty($s->battle['strike']))arDrain($s,$all);
 else \Berserk\Core\CombatContinuation::settle($s,new Engine());
}
$ids=array_column($all,'action_id');$seq=array_column($all,'sequence');
ca(count($ids)===count(array_unique($ids))&&count($seq)===count(array_unique($seq)),'nested and ACK once');
$strikes=array_values(array_filter($all,fn($e)=>$e['type']==='strike'));
ca(count($strikes)===2&&$strikes[0]['actor_id']===1&&$strikes[1]['actor_id']===5,'separate root and forced child');
$inst=array_values(array_filter($all,fn($e)=>$e['origin']==='instant'));
ca(count($inst)===1&&$strikes[0]['sequence']<$inst[0]['sequence']&&$inst[0]['sequence']<$strikes[1]['sequence'],'resolution order root instant child');
ca($inst[0]['parent_id']===$strikes[0]['action_id']&&$strikes[1]['parent_id']===$inst[0]['action_id'],'nested provenance across suspend');
echo "action resolution: real after instant, forced child, parent identities, reload and total order passed\n";

$s=cs();$s->getCard(3)->prop['instants']=[instant('hold',['type'=>'heal','value'=>1])];start($s);
$pendingId=$s->battle['turn_instant_stack']['continuation']['combat_origin_strike'][ActionResolution::ID];
$s->getCard(1)->closed=true;$s=cr($s);cmd($s,'player','pass_turn_instant');
$e=notices($s);ca(count($e)===1&&$e[0]['action_id']===$pendingId&&$e[0]['outcome']==='cancelled','before-window abort never a hit');
echo "action resolution: real before-window cancellation passed\n";

// Composite healing belongs to the transfer action, even while a surrounding strike exists.
$s=cs();$s->getCard(5)->hp=28;$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null];
ActionResolution::bind($s,$s->battle['strike'],$s->getCard(1),'strike');
$outer=$s->battle['strike'][ActionResolution::ID];
$wt=new \Berserk\Core\WoundTransferProcessor($s,new Engine());
ca($wt->start('host',$s->getCard(1),['kind'=>'volkhv','donor_filter'=>'wounded','target_filter'=>'enemy','max_transfer'=>2,'on_finish'=>'main_phase'])->success,'transfer start');
ca($wt->chooseSource('host',new Command('wt_source',['donor_id'=>5]))->success,'transfer source');
ca($wt->chooseAmount('host',new Command('wt_amount',['amount'=>1]))->success,'transfer amount');
ca($wt->chooseTarget('host',new Command('wt_target',['target_id'=>2]))->success,'transfer target');
$s=cr($s);$wt=new \Berserk\Core\WoundTransferProcessor($s,new Engine());
ca($wt->chooseTargetAmount('host',new Command('wt_target_amount',['amount'=>1]))->success,'transfer resolves after reload');
$e=notices($s);$transfer=array_values(array_filter($e,fn($n)=>$n['type']==='wound_transfer'));
$heal=array_values(array_filter($e,fn($n)=>($n['facts']['ability']??'')==='wound_transfer'));
ca(count($transfer)===1&&count($heal)===1,'one transfer and one positive heal');
ca($transfer[0]['parent_id']===$outer&&$heal[0]['parent_id']===$transfer[0]['action_id'],'composite parent lineage');
ca($heal[0]['hp_restored']===1&&$heal[0]['actor_id']===1&&$heal[0]['facts']['target_id']===5,'transfer actual actor and restored HP');
echo "action resolution: transfer healing lineage/actor/HP after reload passed\n";

$s=cs();$s->getCard(2)->closed=false;
$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'confirmed'=>[],'state'=>'waiting_choice'];
(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'weak','winner'=>'attack'],false);
$e=notices($s);ca(array_column($e,'type')===['strike','answer'],'reply has separate native type');
ca($e[1]['origin']==='response'&&$e[1]['actor_id']===2,'reply actual actor and origin');
$s=cs();$s->getCard(2)->row=6;$s->getCard(2)->prop['damage_reduction']=[['value'=>99]];
$s->getCard(1)->element='mountains';$s->getCard(1)->prop['actions']=[['type'=>'shot','key'=>'s','value'=>2,'range'=>5]];
$_SESSION['debug_roll']='6';unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
$r=(new ActionResolver($s,new Engine()))->handle('host',new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'s']));
$e=notices($s);ca($r->success&&count($e)===1&&$e[0]['hit']===true&&$s->getCard(2)->hp===30,'shot hit survives actual zero damage');
ca($e[0]['element']==='mountains','nonforest shot delivered');
echo "action resolution: distinct reply and nonforest shot hit with prevented damage passed\n";
