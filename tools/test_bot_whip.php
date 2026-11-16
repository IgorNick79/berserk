<?php
declare(strict_types=1);
require __DIR__.'/test_whip_cancel.php';
use Berserk\Core\{Engine,Command,GameState,BattleWaitResolver};
use Berserk\Core\Observation\PlayerObservation;
use Berserk\Core\Legal\LegalActions;
use Berserk\Core\Combat\CombatDecisionPlanner;
$checks=0;function bwCheck(bool $ok,string $why):void{global$checks;++$checks;if(!$ok)throw new RuntimeException($why);}
function bwPlan(GameState $s,string $owner):array{$before=$s->toArray();$o=PlayerObservation::build($s,$owner);$l=LegalActions::build($s,$owner);$p=CombatDecisionPlanner::decide($o,$l);bwCheck($s->toArray()===$before,'read-only projection');if($p['action']!==null)bwCheck(in_array($p['action'],$l['actions'],true),'planner uses only legal action');return$p;}
foreach(['host','player'] as $owner)foreach(['safe','lethal','closed','missing','foreign'] as $case){
 $a=whipCard(['owner'=>$owner]);$b=whipCard(['instanceId'=>2,'ukid'=>'ally','owner'=>$owner,'row'=>3,'col'=>4,'hp'=>6,'prop'=>[]]);$s=whipState($a,$b);$s->persistenceVersion=0;
 $s->battle['active']=$owner;$s->battle['turn_phase']['active_key']=$owner;$s->battle['turn_phase']['passive_key']=$s->getOpponentKey($owner);
 $r=(new Engine())->apply($s,$owner,new Command('turn_task',['task_id'=>'turn_start_1_0']));bwCheck($r->success&&isset($s->battle['pending_whip']),'native whip pending');
 if($case==='lethal'){$a->hp=1;$b->hp=1;}
 if($case==='closed'){$a->closed=true;$b->closed=true;}
 if($case==='missing'){$s->battle['pending_whip']['targets']=[999];}
 if($case==='foreign'){$s->battle['pending_whip']['targets']=[2];$b->owner=$s->getOpponentKey($owner);}
 $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));$s->persistenceVersion=0;
 $other=bwPlan($s,$s->getOpponentKey($owner));bwCheck($other['action']===null,'foreign observer cannot choose');
 $plan=bwPlan($s,$owner);bwCheck($plan['action']!==null,'bot always resolves supported choice');
 bwCheck($plan['action']['type']===($case==='safe'?'choose_whip_target':'cancel_pending'),'safe target or cancel');
 $before=$s->getCard(2)->hp;$r=(new Engine())->apply($s,$owner,new Command($plan['action']['type'],$plan['action']['payload']));bwCheck($r->success&&empty($s->battle['pending_whip'])&&empty($s->battle['turn_phase']),'native continuation finishes');
 if($case!=='safe')bwCheck($s->getCard(2)->hp===$before,'decline has no damage');
 bwCheck(!(new Engine())->apply($s,$owner,new Command($plan['action']['type'],$plan['action']['payload']))->success,'retry cannot repeat whip');
}
// Two Keepers: the bot can select the active start task as well as its target.
$s=whipState(whipCard(),whipCard(['instanceId'=>2,'row'=>3,'col'=>4]));$s->persistenceVersion=0;
$s->addCard(whipCard(['instanceId'=>3,'owner'=>'player','row'=>5,'col'=>5,'prop'=>[]]));
$second=$s->battle['turn_phase']['active_queue'][0];$second['id']='turn_start_2_0';$second['card_id']=2;$s->battle['turn_phase']['active_queue'][]=$second;
$p=bwPlan($s,'host');bwCheck(($p['action']['type']??null)==='turn_task','active multi-task queue supported');
$r=(new Engine())->apply($s,'host',new Command($p['action']['type'],$p['action']['payload']));bwCheck($r->success&&isset($s->battle['pending_whip']),'task opens native choice');
$p=bwPlan($s,'host');bwCheck(($p['action']['type']??null)==='choose_whip_target','multi-task target supported');
for($i=0;$i<6&&!empty($s->battle['turn_phase']);$i++){
 $p=bwPlan($s,'host');bwCheck($p['action']!==null,'remaining Keeper continuation '.json_encode([$p,BattleWaitResolver::current($s),$s->battle['turn_phase']??null]));
 $r=(new Engine())->apply($s,'host',new Command($p['action']['type'],$p['action']['payload']));bwCheck($r->success,'remaining Keeper command');
}
bwCheck(empty($s->battle['turn_phase'])&&empty($s->battle['pending_whip']),'two Keepers complete start phase');
$s=whipState(whipCard(),whipCard(['instanceId'=>2,'row'=>3,'col'=>4]));$s->persistenceVersion=0;
// Unrelated start task must remain unsupported.
$s->battle['pending_whip']=null;$s->battle['turn_phase']['active_queue']=[$second,$second];foreach($s->battle['turn_phase']['active_queue'] as &$t)$t['type']='turn_start:unknown';unset($t);
bwCheck(bwPlan($s,'host')['action']===null,'unrelated effects are not enabled');
echo "Bot Whip: $checks checks passed\n";
