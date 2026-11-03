<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest.php';
foreach ([[0,0],[2,0],[0,3],[5,5]] as [$gold,$silver]) foreach ([false,true] as $enabled) {
 $room=\Berserk\Lobby\RoomSetup::parse('sealed',['handicap_enabled'=>$enabled?'1':'0','handicap_gold'=>(string)$gold,'handicap_silver'=>(string)$silver]);
 $state=new \Berserk\Core\GameState(1,11,22);$state->settings=\Berserk\Core\GameSettings::fromArray($room['settings']);
 $state->getPlayer('host')->side=1;$state->getPlayer('player')->side=2;
 $p=new \Berserk\Core\Prepare\PrepareProcessor($state);$m=new ReflectionMethod($p,'dealCards');$m->setAccessible(true);$m->invoke($p);
 check($state->getPlayer('host')->resources===['gold'=>24,'silver'=>22],'host unchanged');
 check($state->getPlayer('player')->resources===['gold'=>25+($enabled?$gold:0),'silver'=>23+($enabled?$silver:0)],'opponent receives bonus');
 $before=$state->getPlayer('player')->resources;$m->invoke($p);check($before===$state->getPlayer('player')->resources,'no double addition');
}
echo "Deal handicap regression passed (in-memory state).\n";

$catalog=[];foreach([3,5,8,10] as $price)foreach([false,true] as $gold){$c=card('handicap_'.$price.'_'.(int)$gold,$price);$c['elite']=$gold;$catalog[]=$c;}
foreach([false,true] as $balance)foreach([[0,0],[2,0],[0,3],[5,5]] as [$gold,$silver])foreach([false,true] as $enabled){
 $room=\Berserk\Lobby\RoomSetup::parse('lucktest',['handicap_enabled'=>$enabled?'1':'0','handicap_gold'=>(string)$gold,'handicap_silver'=>(string)$silver,'lucktest_balance_metals'=>$balance?'1':'0']);
 $settings=\Berserk\Core\GameSettings::fromArray($room['settings']);$off=clone $settings;$off->battle['handicap_enabled']=false;$outputs=[];
 foreach([$off,$settings] as $config){mt_srand(7);$outputs[]=(new \Berserk\Core\Prepare\LuckTestGenerator(static fn($a,$b)=>mt_rand($a,$b)))->generate($catalog,$config);}
 check($outputs[0]['host']===$outputs[1]['host'],'LuckTest host unchanged');check(total($outputs[1]['player'])===6,'LuckTest card count retained');
 if(!$enabled||($gold===0&&$silver===0))check($outputs[0]===$outputs[1],'disabled or zero handicap identical');
}
$settings=\Berserk\Core\GameSettings::fromArray(\Berserk\Lobby\RoomSetup::parse('lucktest',['handicap_enabled'=>'1','handicap_gold'=>'5','handicap_silver'=>'5'])['settings']);
$fixed=['host'=>array_fill(0,6,$catalog[0]),'player'=>array_fill(0,6,$catalog[1])];
$squads=(new \Berserk\Core\Prepare\LuckTestGenerator())->generate($catalog,$settings,$fixed);
check(total($squads['host'])===6&&total($squads['player'])===6,'mandatory count retained');
check(count($squads['player'])===1&&$squads['player'][0]['ukid']===$catalog[1]['ukid'],'unreachable handicap does not replace mandatory cards');
echo "LuckTest handicap combinations and mandatory-card boundary passed.\n";

$sum=static function(array $army): array {$v=['gold'=>0,'silver'=>0];foreach($army as $c)$v[$c['elite']?'gold':'silver']+=$c['price']*$c['count'];return $v;};
$examples=[];
foreach ([[0,0],[2,0],[0,3],[5,5]] as [$gold,$silver]) {
 $setup=\Berserk\Lobby\RoomSetup::parse('lucktest',['handicap_enabled'=>'1','handicap_gold'=>(string)$gold,'handicap_silver'=>(string)$silver]);
 mt_srand(7);$army=(new \Berserk\Core\Prepare\LuckTestGenerator(static fn($a,$b)=>mt_rand($a,$b)))->generate($catalog,\Berserk\Core\GameSettings::fromArray($setup['settings']));
 $examples[]=['bonus'=>['gold'=>$gold,'silver'=>$silver],'host'=>$sum($army['host']),'opponent'=>$sum($army['player'])];
}
$out=__DIR__.'/../docs/evidence/opponent-handicap';if(!is_dir($out))mkdir($out,0777,true);
file_put_contents($out.'/generator-examples.json',json_encode($examples,JSON_PRETTY_PRINT));
echo json_encode($examples,JSON_PRETTY_PRINT)."\n";
