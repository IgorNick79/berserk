<?php
declare(strict_types=1);
require __DIR__.'/test_matriarch_combat.php';
use Berserk\Core\{Command,Dice,Engine,CardInstance,CardStats,MatriarchOrderProcessor,BattleWaitResolver,TurnProcessor};
function os():\Berserk\Core\GameState{
 $s=cs();$s->getCard(2)->hp=$s->getCard(2)->hpMax=100;
 $s->getCard(4)->coins=1;$s->getCard(4)->prop=['zom'=>true,'start'=>['side'=>2,'type'=>'get_coins','coins'=>1],
  'actions'=>[['key'=>'matriarch_order','name'=>'Приказ матриарха','type'=>'impact','effect'=>'matriarch_order','coins'=>1,'self'=>true]]];
 return $s;
}
function chooseOrder(\Berserk\Core\GameState $s,int $id):void{
 $p=$s->battle[MatriarchOrderProcessor::PENDING];cmd($s,'host','choose_matriarch_order',['choice_id'=>$p['id'],'target_id'=>$id]);
}
function activate(\Berserk\Core\GameState $s):void{cmd($s,'host','action',['card_id'=>4,'action_key'=>'matriarch_order']);}

$s=os();activate($s);$p=$s->battle[MatriarchOrderProcessor::PENDING];ca($s->getCard(4)->coins===1&&!$s->getCard(4)->closed,'no early cost');
cmd($s,'host','cancel_pending',['choice_id'=>$p['id']]);ca($s->getCard(4)->coins===1,'cancel no cost');
activate($s);$s=cr($s);chooseOrder($s,2);
ca($s->getCard(4)->coins===0&&$s->getCard(4)->closed,'single cost');
ca($s->battle['matriarch_order']['initial_ids']===[1,5],'fixed initial IDs');
ca((BattleWaitResolver::current($s)['required_players']??[])===['host'],'order owner');
$choice=$s->battle[MatriarchOrderProcessor::PENDING];
$wrong=(new Engine())->apply($s,'player',new Command('choose_matriarch_order',['choice_id'=>$choice['id'],'target_id'=>1]));ca(!$wrong->success,'wrong owner rejected');
$s->getCard(5)->closed=true;$s->getCard(5)->flags['attacks_used_this_turn']=8;
$s->getCard(5)->markers=['stun'=>['value'=>1],'spider_web'=>['value'=>1]];
$s->getCard(5)->modifiers=[['stat'=>'cannot_attack','value'=>1],['stat'=>'stamina','value'=>1]];
$_SESSION['debug_roll']='6,1,6,1';unset($_SESSION['debug_roll_state']);Dice::init();chooseOrder($s,5);
ca(($s->battle['strike']['attacker_id']??0)===5,'restrictions and later stamina do not cancel');
$replay=(new Engine())->apply($s,'host',new Command('choose_matriarch_order',['choice_id'=>$choice['id'],'target_id'=>5]));ca(!$replay->success,'choice replay rejected');
ca($s->getCard(4)->coins===0,'no double payment');
$s->getCard(4)->zone='graveyard';$s->getCard(4)->hp=0; // Applied effect outlives its source.
$s->getCard(1)->zone='graveyard';$s->getCard(1)->hp=0;
finish($s);ca(empty($s->battle['matriarch_order']),'dead next participant excluded, source death does not hang');
ca($s->getCard(5)->closed&&$s->getCard(5)->flags['attacks_used_this_turn']===8,'restricted resource preserved');
echo "cost/cancel/snapshot/restrictions/stamina latch/grave/replay/source death passed\n";

$s=os();$s->getCard(1)->prop['stamina']=true;$s->getCard(5)->prop['zoa']=true;
activate($s);chooseOrder($s,2);ca(($s->battle['strike']['attacker_id']??0)===5,'only stamina excluded, universal protection not excluded');
ca($s->battle['matriarch_order']['initial_ids']===[5],'initial protection snapshot');finish($s);ca(empty($s->battle['matriarch_order']),'single participant auto completion');

$s=os();$s->getCard(4)->row=3;$s->getCard(4)->col=2;$s->getCard(1)->prop['stamina']=true;$s->getCard(5)->zone='graveyard';
activate($s);chooseOrder($s,2);ca(($s->battle['strike']['attacker_id']??0)===4,'Matriarch fights while closed');finish($s);
ca($s->getCard(4)->closed&&$s->getCard(4)->coins===0,'Matriarch cost remains');

$s=os();$s->getCard(1)->prop['stamina']=true;$s->getCard(5)->prop['stamina']=true;
ca(!(new Engine())->apply($s,'host',new Command('action',['card_id'=>4,'action_key'=>'matriarch_order']))->success,'no recipients unavailable');
ca($s->getCard(4)->coins===1,'no empty series cost');

$s=os();$s->getCard(2)->prop['stamina']=true;activate($s);chooseOrder($s,2);
$s->getCard(2)->zone='graveyard';$s->getCard(2)->hp=0;chooseOrder($s,1);ca(empty($s->battle['matriarch_order']),'target gone ends series');

foreach([1,2] as $side){$s=os();$s->getPlayer('host')->side=$side;$s->getCard(4)->coins=0;(new TurnProcessor($s,new Engine()))->applyStartEffects();
 ca($s->getCard(4)->coins===($side===2?1:0),'start coin side '.$side);$s=cr($s);ca($s->getCard(4)->coins===($side===2?1:0),'reload start coin');}
echo "single/Matriarch/stamina/no recipients/target gone/start coin passed\n";
