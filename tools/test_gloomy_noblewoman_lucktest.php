<?php
declare(strict_types=1);
// Run in a disposable copy without private auth.local.php, like test_lucktest_debug.php.
require __DIR__.'/test_lucktest_debug.php';
use Berserk\Core\{CardStats,CardInstance};
use Berserk\Core\Prepare\LuckTestScenario;
$nb=$checks;debugConfig([11,22]);
$entries=LuckTestScenario::parse(file_get_contents(__DIR__.'/../docs/gloomy-noblewoman.lucktest.json'),4);
$noble=card('s1_154',7,'creature',json_decode(file_get_contents(__DIR__.'/../docs/gloomy-noblewoman.prop.json'),true));$noble['health']=8;
$satyr=card('s1_81',5,'creature',['on_heal_instead_wound'=>['uses_per_turn'=>3]]);$satyr['health']=12;
$king=card('s1_94',6,'creature',json_decode(file_get_contents(__DIR__.'/../docs/king-priest.prop.json'),true));$king['health']=10;
$zombie=card('s1_151');$zombie['health']=11;
[$s,$r]=debugStart($entries,4,[$noble,$satyr,$king,$zombie]);check($r->success,'noble companion Quick Game: '.$r->error);
foreach($s->lucktestDebug['bindings']as$b){$c=$s->getCard($b['instance_id']);check($c->hp===$b['hp'],'requested initial wound');if($c->ukid==='s1_154'){check($c->hpMax===12,'noble native maximum');check(CardStats::computeArmor($s,$c)===1,'noble native armor');}}
check(!$s->battle['card_healing']['first_used'],'creation never consumes healing');unlink($configFile);
echo 'Noble LuckTest companion: '.($checks-$nb)." checks passed\n";
