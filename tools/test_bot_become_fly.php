<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,CardInstance,Command,Engine,GameState,InformationalWaitManager};
use Berserk\Core\Legal\LegalActions;
use Berserk\Core\Observation\PlayerObservation;
use Berserk\Core\Combat\CombatDecisionPlanner;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function bfCheck(bool $v,string $why):void{global $n;$n++;if(!$v)throw new RuntimeException($why);}
function bfState():GameState{
 $s=new GameState(911,11,22);$s->persistenceVersion=1;$s->status='battle';$s->battle=['active'=>'host','turn'=>2];
 $s->cards[1]=new CardInstance(1,'s1_68','host','field',row:3,col:4,hp:5,hpMax:5,prop:['actions'=>[['key'=>'become_fly','type'=>'become_fly','self'=>true]]]);
 $s->cards[2]=new CardInstance(2,'opponent','player','field',row:4,col:1,hp:5,hpMax:5);
 bfCheck((new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>1,'action_key'=>'become_fly']))->success,'actual Lord of Skies ability executes');return $s;
}
foreach([true,false]as$humanFirst){
 $s=bfState();$engine=new Engine();$token=InformationalWaitManager::token($s);
 if($humanFirst)bfCheck($engine->apply($s,'host',new Command('confirm_strike',['wait_id'=>$token]))->success,'human confirms first');
 $s=GameState::fromArray($s->toArray());$s->persistenceVersion=1;
 $legal=LegalActions::build($s,'player');$obs=PlayerObservation::build($s,'player');
 $plan=CombatDecisionPlanner::decide($obs,$legal);
 bfCheck(($plan['action']['type']??null)==='confirm_strike','bot selects standard ACK after become_fly');
 bfCheck(in_array($plan['action'],$legal['actions'],true),'ACK is legal');
 $before=$s->toArray();bfCheck(!$engine->apply($s,'player',new Command('confirm_strike',['wait_id'=>'stale']))->success&&$s->toArray()===$before,'stale ACK rejected without mutation');
 bfCheck($engine->apply($s,'player',new Command($plan['action']['type'],$plan['action']['payload']))->success,'bot ACK accepted');
 if(!$humanFirst)bfCheck($engine->apply($s,'host',new Command('confirm_strike',['wait_id'=>$token]))->success,'human confirms after bot');
 bfCheck(empty($s->battle['strike']),'result cleared after both acknowledgements');
 bfCheck($s->cards[1]->type==='fly'&&$s->cards[1]->zone==='flying'&&$s->cards[1]->closed,'flight and paid action retained');
 bfCheck(!in_array($plan['action'],LegalActions::build($s,'player')['actions'],true),'no duplicate result ACK offered');
}
$s=bfState();$s->battle['strike']['kind']='unsupported_test_ability';bfCheck(!in_array('confirm_strike',array_column(LegalActions::build($s,'player')['actions'],'type'),true),'unknown result kinds remain unsupported');
$s=bfState();$s->battle['strike']['pending_wounds_resolution']=['test'=>true];bfCheck(!in_array('confirm_strike',array_column(LegalActions::build($s,'player')['actions'],'type'),true),'pending wounds still block ACK');
echo "Bot become_fly: $n checks passed (no DB).\n";
