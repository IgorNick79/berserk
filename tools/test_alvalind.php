<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,Dice,AlvalindProcessor,TurnTimer,InformationalWaitManager,BattleWaitResolver};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$n=0;function ac($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$d=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/alvalind.json'),true);
function stateA(array $cfg):GameState{
 $s=new GameState(-950,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>2,'strike'=>null,'hidden_row_revealed'=>true];
 foreach([[1,'host',3,3],[2,'host',3,2],[3,'player',4,2],[4,'player',6,5]]as[$id,$owner,$r,$c])$s->addCard(new CardInstance(instanceId:$id,ukid:'card'.$id,owner:$owner,zone:'field',row:$r,col:$c,hp:10,hpMax:10,move:1,moveMax:1,strikeWeak:1,strikeMedium:2,strikeStrong:3,prop:$id===1?json_decode(file_get_contents(__DIR__.'/../debug/scenarios/alvalind.json'),true)['prop']:[]));
 return $s;
}
function settleA(GameState $s,Engine $e):void{
 for($i=0;$i<30;$i++){
  if(!empty($s->battle['pending_card_heal'])){$p=$s->battle['pending_card_heal'];$owner=$p['owner'];$cmd=new Command('choose_card_heal',['heal_token'=>$p['token'],'apply'=>'yes']);
  }elseif(!empty($s->battle['strike']['pending_choice'])){ $p=$s->battle['strike']['pending_choice'];$owner=$s->getCard($p['died_id'])->owner;
   $cmd=new Command('choose_alvalind_death',['choice_id'=>$p['choice_id'],'target_id'=>$p['candidates'][0]]);
  }elseif(($s->battle['strike']['state']??'')==='waiting_choice'){
   $w=BattleWaitResolver::current($s);$owner=$w['required_players'][0];$cmd=new Command('choose_strike_mode',['mode'=>'normal']);
  }elseif(($s->battle['strike']['state']??'')==='results'){
   $owner=in_array('host',$s->battle['strike']['confirmed']??[],true)?'player':'host';$cmd=new Command('confirm_strike',['wait_id'=>InformationalWaitManager::token($s)]);
  }elseif(!empty($s->battle['turn_phase']['pending_ack'])){$owner=$s->battle['active'];$cmd=new Command('turn_ack');}
  else return;
  $snapshot=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
  foreach(get_object_vars($snapshot) as $key=>$value)$s->$key=$value;
  $r=$e->apply($s,$owner,$cmd);ac($r->success,'settle '.$cmd->type.': '.$r->error);
 }
 throw new RuntimeException('Continuation did not settle');
}
foreach($d['end_counts']as$count){
 $s=stateA($d['config']);$s->getCard(1)->markers['alvalind']=['value'=>$count];$now=1000;$e=new Engine(clock:static function()use(&$now){return $now;});
 TurnTimer::prepare($s,'host');TurnTimer::resume($s,1,'start_phase',1000);
 $s->addCard(new CardInstance(instanceId:5,ukid:'orthogonal',owner:'player',zone:'field',row:3,col:4,hp:10,hpMax:10));
 $r=$e->apply($s,'host',new Command('end_turn'));ac($r->success,'end opens choice');$p=$s->battle['pending_alvalind_end'];
 ac($p['value']===1+2*$count,'discharge strength');ac(!in_array(5,$p['targets'],true),'orthogonal neighbor forbidden');
 ac(!$e->apply($s,'host',new Command('choose_alvalind_target',['operation_id'=>$p['id'],'target_id'=>5]))->success,'server rejects adjacent target');ac(!in_array(3,$p['targets'],true),'diagonal neighbor forbidden');ac(in_array(4,$p['targets'],true),'distant target allowed');
 ac($s->battle['active']==='host','owner not changed');ac($s->battle['turn_timer']['status']==='running','timer continues');
 $effects=\Berserk\View\Ui\ActiveCardEffects::forCard($s->getCard(1),$s);ac(in_array('Разряд на 1'.($count?' (+'.(2*$count).')':''),$effects,true),'UI bonus');
 ac(!$e->apply($s,'host',new Command('end_turn'))->success,'repeat end blocked');
 $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));$now=2000;
 $_SESSION=['debug_roll'=>'4'];Dice::init();$cmd=new Command('choose_alvalind_target',['operation_id'=>$p['id'],'target_id'=>4]);
 ac($e->apply($s,'host',$cmd)->success,'expired timer still allows mandatory choice');ac($s->battle['active']==='host','still own turn during discharge');
 ac($s->getCard(4)->hp===10-(1+2*$count),'native discharge damage');ac(!$e->apply($s,'host',$cmd)->success,'replayed discharge denied');
 settleA($s,$e);ac($s->battle['active']==='player','turn transferred');$turn=$s->battle['turn'];(new AlvalindProcessor($s,$e))->advanceEnd();ac($s->battle['turn']===$turn&&$s->battle['active']==='player','completed continuation cannot transfer twice');ac(AlvalindProcessor::count($s->getCard(1))===0,'counters consumed');
}
foreach($d['strike_cases']as$case){
 $s=stateA($d['config']);$e=new Engine();$s->getCard(1)->markers['alvalind']=['value'=>$case['initial']??0];if($case['armor']){$s->getCard(3)->armor=10;$s->getCard(3)->armorMax=10;}
 $_SESSION=['debug_roll'=>$case['rolls']];Dice::init();ac($e->apply($s,'host',new Command('strike',['card_id'=>2,'target_id'=>3]))->success,'native strike');
 if(($s->battle['strike']['state']??'')==='waiting_defender')ac($e->apply($s,'player',new Command('choose_defender',['defender_id'=>0]))->success,'defender');
 settleA($s,$e);ac(AlvalindProcessor::count($s->getCard(1))===$case['expected'],'counter from actual wounds');
}
// Third counter, lethal recoil and nested death choices remain in the original strike continuation.
foreach($d['recoil_hp'] as $hp){
 $s=stateA($d['config']);$e=new Engine();$s->getCard(1)->hp=$hp;$s->getCard(1)->markers['alvalind']=['value'=>2];
 if($hp===1){$s->getCard(1)->prop['on_death']=[['type'=>'damage','target'=>'choice','value'=>2]];$s->getCard(3)->hp=1;$s->getCard(3)->prop=['on_death'=>[['type'=>'damage','target'=>'choice','value'=>1]]];}
 $_SESSION=['debug_roll'=>'1,6'];Dice::init();ac($e->apply($s,'host',new Command('strike',['card_id'=>2,'target_id'=>3]))->success,'third counter strike');
 if(($s->battle['strike']['state']??'')==='waiting_defender')ac($e->apply($s,'player',new Command('choose_defender',['defender_id'=>0]))->success,'third defender');
 settleA($s,$e);ac(AlvalindProcessor::count($s->getCard(1))===0,'third removes all counters');
 ac($hp===10?$s->getCard(1)->hp===8:$s->getCard(1)->zone==='graveyard','recoil native damage and death');
 ac(empty($s->battle['alvalind_recoil']),'recoil continuation closed');
 if($hp===10){
  $s->addCard(new CardInstance(instanceId:5,ukid:'enemy',owner:'player',zone:'field',row:4,col:3,hp:10,hpMax:10,strikeWeak:1,strikeMedium:2,strikeStrong:3));
  $_SESSION=['debug_roll'=>'1,6'];Dice::init();ac($e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>5]))->success,'Alvalind own strike after reset');
  if(($s->battle['strike']['state']??'')==='waiting_defender')ac($e->apply($s,'player',new Command('choose_defender',['defender_id'=>0]))->success,'self defender');
  settleA($s,$e);ac(AlvalindProcessor::count($s->getCard(1))===1,'can accumulate again in same turn');
 }

}
// A discharge can kill a card with an obligatory death choice before handing over.
$s=stateA($d['config']);$e=new Engine();$s->getCard(4)->hp=1;$s->getCard(4)->prop=['on_death'=>[['type'=>'damage','target'=>'choice','value'=>1]]];
ac($e->apply($s,'host',new Command('end_turn'))->success,'lethal end');$p=$s->battle['pending_alvalind_end'];
$_SESSION=['debug_roll'=>'4'];Dice::init();ac($e->apply($s,'host',new Command('choose_alvalind_target',['operation_id'=>$p['id'],'target_id'=>4]))->success,'lethal discharge');
ac(!empty($s->battle['strike']['pending_choice'])&&$s->battle['active']==='host','death blocks turn handover');
$s=GameState::fromArray($s->toArray());settleA($s,$e);ac($s->getCard(4)->zone==='graveyard'&&$s->battle['active']==='player','death finished before handover');
// Death healing opens a child choice; even a reloaded end operation must wait for it.
$s=stateA($d['config']);$e=new Engine();$s->getCard(4)->hp=1;
$s->getCard(4)->prop=['on_death'=>[['type'=>'heal','target'=>'choice','value'=>1]]];
$s->getCard(2)->hp=5;$s->getCard(2)->prop=['first_card_heal'=>['threshold'=>8]];
ac($e->apply($s,'host',new Command('end_turn'))->success,'healing end');$p=$s->battle['pending_alvalind_end'];
$_SESSION=['debug_roll'=>'4'];Dice::init();ac($e->apply($s,'host',new Command('choose_alvalind_target',['operation_id'=>$p['id'],'target_id'=>4]))->success,'healing discharge');
$p=$s->battle['strike']['pending_choice'];ac(in_array(2,$p['candidates'],true),'healing candidate');
ac($e->apply($s,'player',new Command('choose_alvalind_death',['choice_id'=>$p['choice_id'],'target_id'=>2]))->success,'choose healing');
ac(!empty($s->battle['pending_card_heal'])&&$s->battle['active']==='host','healing child holds end operation');
settleA($s,$e);ac($s->getCard(2)->hp===7&&$s->battle['active']==='player','healing child completed before turn transfer');
// No targets: no extra pending, standard end-turn path.
$s=stateA($d['config']);unset($s->cards[4]);$e=new Engine();ac($e->apply($s,'host',new Command('end_turn'))->success,'no targets ends turn');ac(empty($s->battle['pending_alvalind_end'])&&$s->battle['active']==='player','no empty choice');
// Real luck window: counters depend on the resolved dice, never intermediate rolls.
foreach($d['instant_cases'] as $case){
 $s=stateA($d['config']);$e=new Engine();
 $s->getCard(4)->prop=['instants'=>[['key'=>'luck','name'=>'Удача','effect'=>['type'=>'dice_choice'],'target'=>'self','trigger'=>'combat','phase'=>'dice']]];
 $_SESSION=['debug_roll'=>$case['rolls']];Dice::init();
 ac($e->apply($s,'host',new Command('strike',['card_id'=>2,'target_id'=>3]))->success,'instant strike');
 if(($s->battle['strike']['state']??'')==='waiting_defender')ac($e->apply($s,'player',new Command('choose_defender',['defender_id'=>0]))->success,'instant defender');
 ac(AlvalindProcessor::count($s->getCard(1))===0,'no counter before instant');
 $ip=new \Berserk\Core\InstantProcessor($s,$e);ac($ip->playCombat('player',4,'luck')->success,'luck played');$ip->passCombat('player');$ip->passCombat('host');
 $s=GameState::fromArray($s->toArray());
 ac($e->apply($s,'player',new Command('choose_dice_choice',['choice'=>'reroll:any']))->success,'actual reroll');
 settleA($s,$e);ac(AlvalindProcessor::count($s->getCard(1))===$case['expected'],'only final wounds counted');
 ac(!$e->apply($s,'player',new Command('choose_dice_choice',['choice'=>'reroll:any']))->success,'replayed instant rejected');
 ac(AlvalindProcessor::count($s->getCard(1))===$case['expected'],'no duplicate counter');
}
// Opponent attacking on their turn cannot charge the host Alvalind.
$s=stateA($d['config']);$s->battle['active']='player';$e=new Engine();
$_SESSION=['debug_roll'=>'1,6'];Dice::init();ac($e->apply($s,'player',new Command('strike',['card_id'=>3,'target_id'=>2]))->success,'opponent strike');
if(($s->battle['strike']['state']??'')==='waiting_defender')ac($e->apply($s,'host',new Command('choose_defender',['defender_id'=>0]))->success,'opponent defender');
settleA($s,$e);ac(AlvalindProcessor::count($s->getCard(1))===0,'no counter outside owner turn');
echo "PASS $n checks; FAIL 0\n";
