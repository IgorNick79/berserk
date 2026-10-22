<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,CardInstance,Command,CombatContinuation,Dice,Engine,GameState,StrikeResolver};
Autoloader::register(); Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(static function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
function ca(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);}
function cs():GameState{
 $s=new GameState(9901,101,202);$s->status='battle';$s->battle=['turn'=>1,'active'=>'host','strike'=>null];
 foreach([1=>['host',3,3],2=>['player',4,3],3=>['player',6,5],4=>['host',1,1],5=>['host',3,4]] as $id=>[$o,$r,$c])
 $s->addCard(new CardInstance(instanceId:$id,ukid:'cc'.$id,owner:$o,zone:'field',row:$r,col:$c,hp:30,hpMax:30,strikeWeak:1,strikeMedium:2,strikeStrong:3));
 $s->getCard(2)->closed=true;\Berserk\Core\TurnTimer::prepare($s,'host');\Berserk\Core\TurnTimer::resume($s,1,'start_phase',time());return $s;
}
function cr(GameState $s):GameState{return GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));}
function cmd(GameState $s,string $p,string $t,array $a=[]):void{
 $a['wait_id']=\Berserk\Core\InformationalWaitManager::token($s);
 $r=(new Engine())->apply($s,$p,new Command($t,$a));ca($r->success,$t.':'.$r->error);
}
function start(GameState $s):void{$_SESSION['debug_roll']='6,1,6,1,6,1';unset($_SESSION['debug_roll_state']);Dice::init();
 $r=(new StrikeResolver($s,new Engine()))->declare('host',new Command('strike',['card_id'=>1,'target_id'=>2]),['kind'=>'test','step'=>1]);ca($r->success,'declare');\Berserk\Core\InformationalWaitManager::reconcile($s,time());}
function finish(GameState &$s):void{
 for($i=0;$i<50&&!empty($s->battle['strike']);$i++){
  $s=cr($s);$st=$s->battle['strike'];
  if($st['state']==='results'||$st['state']==='waiting_push_ack'){
   foreach(['host','player'] as $p)if(!in_array($p,$s->battle['strike']['confirmed']??[],true)&&!empty($s->battle['strike']))cmd($s,$p,'confirm_strike');
  }elseif($st['state']==='waiting_defender')cmd($s,'player','choose_defender',['defender_id'=>0]);
  elseif($st['state']==='waiting_choice')cmd($s,$st['choice_winner']==='attack'?$s->getCard($st['attacker_id'])->owner:$s->getOpponentKey($s->getCard($st['attacker_id'])->owner),'choose_strike_mode',['mode'=>'normal']);
  else throw new RuntimeException('Unexpected '.json_encode($st));
 }
}
function instant(string $key,array $effect):array{return ['key'=>$key,'name'=>$key,'trigger'=>'turn','target'=>'self','no_close'=>true,'effect'=>$effect];}
$s=cs();start($s);finish($s);ca(($s->battle['combat_completion']['kind']??'')==='test','simple completed');
CombatContinuation::settle($s,new Engine());ca(!CombatContinuation::active($s),'settle idempotent');
echo "simple/reload passed\n";

// A real before window, then a real after window, including result ACK and reload.
$s=cs();$s->getCard(3)->prop['instants']=[instant('heal',['type'=>'heal','value'=>1])];start($s);
ca(!empty($s->battle['turn_instant_stack']),'before');
cmd($s,'player','pass_turn_instant');$s=cr($s);finish($s);
ca(!empty($s->battle['turn_instant_stack'])&&empty($s->battle['combat_completion']),'after holds root');
cmd($s,'player','play_turn_instant',['card_id'=>3,'instant_key'=>'heal']);cmd($s,'player','pass_turn_instant');
ca(!empty($s->battle['turn_instant_result']),'after result');$s=cr($s);cmd($s,'player','turn_instant_result_ok');
ca(!CombatContinuation::active($s)&&!empty($s->battle['combat_completion']),'after completed once');
echo "before/after no coin/reload passed\n";

// A forced child first; two remaining items must wait for its complete lifecycle.
$s=cs();$s->getCard(3)->prop['instants']=[

 ['key'=>'force','name'=>'force','trigger'=>'turn','target'=>'enemy_open_creature','no_close'=>true,'effect'=>['type'=>'forced_strike_adjacent']]];
foreach([6,7] as $id)$s->addCard(new CardInstance(instanceId:$id,ukid:'stack'.$id,owner:'player',zone:'field',row:6,col:$id-4,hp:10,hpMax:12,prop:['instants'=>[instant('heal'.($id-5),['type'=>'heal','value'=>1])]]));
start($s);cmd($s,'player','pass_turn_instant');finish($s);$before=$s->getCard(3)->hp;
cmd($s,'player','play_turn_instant',['card_id'=>6,'instant_key'=>'heal1']);
cmd($s,'player','play_turn_instant',['card_id'=>7,'instant_key'=>'heal2']);
cmd($s,'player','play_turn_instant',['card_id'=>3,'instant_key'=>'force']);
cmd($s,'player','choose_instant_pick',['target_id'=>5]);
cmd($s,'player','choose_forced_strike_adjacent',['target_id'=>2]);
cmd($s,'player','pass_turn_instant');
ca(!empty($s->battle['strike'])||!empty($s->battle['turn_instant_stack']),'child active');
ca($s->getCard(3)->hp===$before,'remaining items not run early');
for($i=0;$i<20&&CombatContinuation::active($s);$i++){
 $s=cr($s);
 if(!empty($s->battle['turn_instant_stack']))cmd($s,$s->battle['turn_instant_stack']['priority'],'pass_turn_instant');
 elseif(!empty($s->battle['turn_instant_result']))cmd($s,'player','turn_instant_result_ok');
 elseif(!empty($s->battle['strike']))finish($s);
 else CombatContinuation::settle($s,new Engine());
}
ca(!CombatContinuation::active($s),'nested child and remaining stack completed');
ca(!empty($s->battle['combat_completion']),'nested positive completion');
echo "child/two remaining items/reload passed\n";

$s=cs();$s->getCard(1)->coins=1;
$s->getCard(1)->prop['on_wounding_strike']=['min_coins'=>1,'type'=>'discharge','strike'=>['weak'=>1,'medium'=>2,'strong'=>2],'range'=>6,'near_shot'=>true];
$s->getCard(3)->prop['instants']=[instant('heal',['type'=>'heal','value'=>1])];
start($s);cmd($s,'player','pass_turn_instant');finish($s);
cmd($s,'player','play_turn_instant',['card_id'=>3,'instant_key'=>'heal']);cmd($s,'player','pass_turn_instant');
$s=cr($s);cmd($s,'player','turn_instant_result_ok');
ca(!empty($s->battle['pending_coin_ability'])&&CombatContinuation::active($s),'coin obligation holds completion '.json_encode($s->battle));
$p=$s->battle['pending_coin_ability'];cmd($s,'host','choose_coin_ability',['choice_id'=>$p['id'],'target_id'=>2]);
finish($s);ca(!CombatContinuation::active($s),'coin child result completed');
echo "after/coin/reload passed\n";

$s=cs();$s->getCard(1)->prop['strike_effects']=[['levels'=>['strong'],'type'=>'degasca_sequence']];
$s->addCard(new CardInstance(instanceId:9,ukid:'learn',owner:'host',zone:'deck',order:1,hp:5,hpMax:5,prop:['strike_effects'=>[['levels'=>['strong'],'marker'=>['type'=>'stun','value'=>1]]]]));
start($s);finish($s);ca(!empty($s->battle['pending_degasca_sequence']),'real Degasca pending');
ca(CombatContinuation::active($s)&&empty($s->battle['combat_completion']),'Degasca holds completion');
$s=cr($s);cmd($s,'host','choose_degasca_card',['card_id'=>9]);
ca(!CombatContinuation::active($s)&&!empty($s->battle['combat_completion']),'Degasca resumes original exactly once');
echo "Degasca/reload passed\n";
