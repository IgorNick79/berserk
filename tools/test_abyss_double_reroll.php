<?php
declare(strict_types=1);
ob_start();require __DIR__.'/test_combat_instant_phases.php';ob_end_clean();
use Berserk\Core\{CardRoll,Dice,GameState,Engine,Command,InstantProcessor};
$n=0;function ac($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
foreach(['reroll:any'] as $choice){
 $a=cipCard(['instanceId'=>1,'owner'=>'host','row'=>3,'col'=>3,'hp'=>20,'prop'=>['next_six_on_enemy_death_or_ally_incarnation'=>true],'flags'=>[CardRoll::READY=>true]]);
 $t=cipCard(['instanceId'=>2,'owner'=>'player','row'=>4,'col'=>3,'hp'=>20]);
 $catcher=cipCard(['instanceId'=>3,'owner'=>'player','row'=>6,'col'=>5,'prop'=>cipCatcherInstantProp()]);
 $second=cipCard(['instanceId'=>4,'owner'=>'player','row'=>6,'col'=>4,'prop'=>cipCatcherInstantProp()]);
 $s=cipState($a,$t,$catcher,$second);$s->battle['strike']=null;$e=new Engine();
 $_SESSION=['debug_roll'=>'5,2,3,4,1'];Dice::init();
 $r=$e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>2]));ac($r->success,'native strike '.$r->error);
 if(($s->battle['strike']['state']??'')==='waiting_defender')ac($e->apply($s,'player',new Command('choose_defender',['defender_id'=>0]))->success,'no defender');
 ac($s->battle['strike']['attack_dice']===6,'primary six');ac($s->battle['strike']['defend_dice']===5,'other roll consumes debug');
 ac(!$s->getCard(1)->flags[CardRoll::READY],'flag spent before window');
 $s=GameState::fromArray($s->toArray());$ip=new InstantProcessor($s,$e);
 ac($ip->playCombat('player',3,'luck')->success,'catcher played');ac($ip->playCombat('player',4,'luck')->success,'second catcher played');$ip->passCombat('player');$ip->passCombat('host');
 ac(!empty($s->battle['pending_dice_choice']),'actual dice choice');
 $s=GameState::fromArray($s->toArray());
 ac($e->apply($s,'player',new Command('choose_dice_choice',['choice'=>$choice]))->success,'instant choice');
 if($choice==='reroll:any'){ac($s->battle['strike']['attack_dice']===2&&$s->battle['strike']['defend_dice']===3,'ordinary rerolls');}
 else {ac($s->battle['strike']['attack_dice']===6&&$s->battle['strike']['defend_dice']===6,'plus makes equality');ac(($s->battle['strike']['result']['winner']??'')==='defend','defender advantage');}
 ac(!$s->getCard(1)->flags[CardRoll::READY],'not restored');
 ac(!empty($s->battle['pending_dice_choice']),'second reroll waiting');
 $s=GameState::fromArray($s->toArray());
 ac($e->apply($s,'player',new Command('choose_dice_choice',['choice'=>'reroll:any']))->success,'second reroll resolves');
 ac($s->battle['strike']['attack_dice']===4&&$s->battle['strike']['defend_dice']===1,'second reroll uses new ordinary values');
 ac(!$s->getCard(1)->flags[CardRoll::READY],'flag remains spent after both rerolls');
 ac(count($s->battle['strike']['reroll_trace'])===2,'both rerolls traced');
 ac($s->battle['strike']['reroll_trace'][1]['after']['attack']===4,'trace matches stored die');
 ac(!$s->battle['strike']['reroll_trace'][1]['attacker_six_ready'],'trace confirms consumed passive');
}
echo "PASS $n real combat-window checks; FAIL 0\n";
