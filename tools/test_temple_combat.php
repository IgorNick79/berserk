<?php
declare(strict_types=1);
ob_start();require __DIR__.'/test_combat_instant_phases.php';ob_end_clean();
use Berserk\Core\{Engine,GameState,Command,Dice,InstantProcessor};
use Berserk\Core\Choice\TempleDiceChoice;
$n=0;function tw($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$d=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/temple-combat.json'),true);
foreach($d['cases']as$case){
 $a=cipCard(['instanceId'=>1,'owner'=>'host','row'=>3,'col'=>3,'hp'=>20,'prop'=>$case['side']==='attack'?['combat_choose_two'=>true]:[]]);
 $t=cipCard(['instanceId'=>2,'owner'=>'player','row'=>4,'col'=>3,'hp'=>20,'prop'=>$case['side']==='defend'?['combat_choose_two'=>true]:[]]);
 $catcher=cipCard(['instanceId'=>3,'owner'=>'player','row'=>6,'col'=>5,'prop'=>cipCatcherInstantProp()]);
 $a->prop['clumsy']=1;
 $s=cipState($a,$t,$catcher);$s->battle['strike']=null;$e=new Engine();
 [$first,$second]=$case['values'];$_SESSION=['debug_roll'=>($case['side']==='attack'?"$first,4,$second,2,3":"4,$first,$second,2,3")];Dice::init();
 tw($e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>2]))->success,'native declaration');
 if(($s->battle['strike']['state']??'')==='waiting_defender')tw($e->apply($s,'player',new Command('choose_defender',['defender_id'=>0]))->success,'defender');
 if($first!==$second){
  $p=$s->battle['pending_temple_dice'];$owner=$p['owner'];
  foreach(['host','player']as$viewer){$spec=(new TempleDiceChoice())->spec($s,$viewer,[],'?game=1',$viewer);if(($argv[1]??'')==='--fixture'){echo json_encode(['html'=>\Berserk\View\Ui\Panel::render($spec)]);exit;}tw(substr_count($spec->text[0],'panel-dice-value')===3,'three graphical dice');tw(count($spec->buttons)===($viewer===$owner?2:0),'only owner buttons');}
  $s=GameState::fromArray($s->toArray());$cmd=new Command('choose_temple_dice',['id'=>$p['id'],'value'=>$case['choice']]);
  tw(!$e->apply($s,$s->getOpponentKey($owner),$cmd)->success,'wrong owner');tw(!$e->apply($s,$owner,new Command('choose_temple_dice',['id'=>$p['id'],'value'=>6]))->success,'invalid value');
  tw($e->apply($s,$owner,$cmd)->success,'choice accepted');tw(!$e->apply($s,$owner,$cmd)->success,'choice replay');
 }else tw(empty($s->battle['pending_temple_dice']),'equal no pending');
 tw($s->battle['strike']['attack_mod']===-1,'modifier remains after primary choice');
 tw($s->battle['strike'][$case['side']==='attack'?'attack_dice':'defend_dice']===$case['choice'],'chosen die');tw(Dice::remaining()===[2,3],'no repeated rolls');
 $ip=new InstantProcessor($s,$e);tw($ip->playCombat('player',3,'luck')->success,'catcher after choice');$ip->passCombat('player');$ip->passCombat('host');
 tw($e->apply($s,'player',new Command('choose_dice_choice',['choice'=>'reroll:any']))->success,'reroll');
 tw($s->battle['strike']['attack_dice']===2&&$s->battle['strike']['defend_dice']===3,'one die per side rerolled');tw(empty($s->battle['pending_temple_dice']),'double passive not repeated');
}
$a=cipCard(['instanceId'=>1,'owner'=>'host','row'=>3,'col'=>3,'hp'=>20]);
$t=cipCard(['instanceId'=>2,'owner'=>'player','row'=>4,'col'=>3,'hp'=>20,'prop'=>['combat_choose_two'=>true]]);$t->closed=true;
$s=cipState($a,$t);$s->battle['strike']=null;$e=new Engine();$_SESSION=['debug_roll'=>'3,2'];Dice::init();
tw($e->apply($s,'host',new Command('strike',['card_id'=>1,'target_id'=>2]))->success,'attack closed temple');
if(($s->battle['strike']['state']??'')==='waiting_defender')tw($e->apply($s,'player',new Command('choose_defender',['defender_id'=>0]))->success,'closed defender choice');
tw($s->battle['strike']['defend_dice']===0&&empty($s->battle['pending_temple_dice']),'no defensive roll no double choice');tw(Dice::remaining()===[2],'absent defensive roll does not consume dice');
echo "PASS $n checks; FAIL 0\n";
