<?php
declare(strict_types=1);
// Like test_lucktest_debug.php, run only in a disposable copy without private auth configuration.
require __DIR__.'/test_lucktest_debug.php';
use Berserk\Core\{Command,Engine,GameState,CardInstance};
use Berserk\Core\Prepare\LuckTestScenario;
$hpStart=$checks;
debugConfig([11,22]);
foreach ([0,-1,1.5,'1',true,null] as $hp) {
 $s=fresh();$before=$s->toArray();
 $r=(new Engine(fixture([card('army')])))->apply($s,'host',new Command('confirm_settings',[
 'lucktest_scenario'=>scenarioJson([['owner'=>'host','ukid'=>'army','hp'=>$hp]])]));
 check(!$r->success&&$s->toArray()===$before,'invalid hp rejected atomically '.json_encode($hp));
}
foreach ([1,8] as $hp) {
 [$s,$r]=debugStart([['owner'=>'host','ukid'=>'army','hp'=>$hp]],3);
 check($r->success,'valid hp start');$id=$s->lucktestDebug['bindings'][0]['instance_id'];
 check($s->getCard($id)->hp===$hp&&$s->getCard($id)->hpMax===8,'only current health overridden');
 $copy=GameState::fromArray(json_decode(json_encode($s->toArray()),true));check($copy->getCard($id)->hp===$hp,'hp reload');
 $copy->getCard($id)->hp=5;$before=$copy->toArray();$retry=(new Engine())->apply($copy,'host',new Command('confirm_settings'));
 check(!$retry->success&&$copy->toArray()===$before,'refresh/repeated start does not reset healed hp');
 foreach($s->cards as $c)if($c->instanceId!==$id)check($c->hp===$c->hpMax,'unspecified cards retain normal hp');
}
[$s,$r]=debugStart([['owner'=>'host','ukid'=>'army','hp'=>9]],3);check(!$r->success&&$s->cards===[],'above hpMax rolls back whole preparation');
try{LuckTestScenario::parse('{"deck":[{"owner":"host","ukid":"army","hp":1}]}',3);check(false,'deck hp accepted');}catch(DomainException){check(true,'deck hp forbidden');}
$raw=file_get_contents(__DIR__.'/../docs/flesh-stitcher.lucktest.json');$entries=LuckTestScenario::parse($raw,3);
check(LuckTestScenario::root($entries)===json_decode($raw,true),'provided JSON roundtrip');
[$s,$r]=debugStart($entries,3,[card('s1_148'),card('s1_151'),card('s1_141')]);check($r->success,'provided JSON complete Quick Game');
foreach($s->getCardsInZone('host',CardInstance::ZONE_FIELD)as$c)check($c->hp===1&&$c->hpMax===8,'all three host troops at 1 hp');
foreach($s->getCardsInZone('player',CardInstance::ZONE_FIELD)as$c)check($c->hp===8,'opponent troops healthy');
check(str_contains(\Berserk\View\Ui\LuckTestDebugFields::scenario('',true),'<dt>hp</dt>'),'hp documented in UI');
unlink($configFile);
echo 'LuckTest scenario HP: '.($checks-$hpStart)." checks passed (isolated catalog, no live DB).\n";
