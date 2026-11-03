<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest.php';

$room=\Berserk\Lobby\RoomSetup::parse('lucktest',[]);
check($room['settings']['lucktest']['card_count']===6 && $room['settings']['lucktest']['total_cost']===45,'new room defaults');
$old=\Berserk\Core\GameSettings::fromArray(['lucktest'=>['card_count'=>3,'average_cost'=>6.5]]);
check($old->luckTestTargetCost()===19.5,'historical average preserved');
$catalog=[];foreach ([3,5,8] as $price) foreach ([false,true] as $gold) {
    $c=card('sample_'.$price.'_'.(int)$gold,$price);$c['elite']=$gold;$catalog[]=$c;
}
$examples=[];mt_srand(42);
foreach (range(3,10) as $count) foreach ([10,45,60] as $budget) {
    $setup=\Berserk\Lobby\RoomSetup::parse('lucktest',['lucktest_card_count'=>(string)$count,'lucktest_total_cost'=>(string)$budget]);
    check(\Berserk\Lobby\RoomSetup::validate(json_decode(json_encode($setup),true))==$setup,'room roundtrip');
    $settings=\Berserk\Core\GameSettings::fromArray($setup['settings']);check($settings->luckTestTargetCost()===$budget*1.0,'total target not multiplied');
    $squads=(new \Berserk\Core\Prepare\LuckTestGenerator(static fn(int $a,int $b): int=>mt_rand($a,$b)))->generate($catalog,$settings);
    foreach ($squads as $squad) check(total($squad)===$count,'exact card count');
    $examples[]=['count'=>$count,'target'=>$budget,'host'=>cost($squads['host']),'player'=>cost($squads['player']),'cards'=>$squads];
}
$settings=\Berserk\Core\GameSettings::fromArray($room['settings']);$settings->lucktest['total_cost']=10;
$unreachable=(new \Berserk\Core\Prepare\LuckTestGenerator())->generate([card('only-five',5)],$settings);
check(cost($unreachable['host'])===30 && total($unreachable['host'])===6,'unreachable budget returns nearest available');
foreach (['9','61','45.5','oops',[]] as $bad) {
    try {\Berserk\Lobby\RoomSetup::parse('lucktest',['lucktest_total_cost'=>$bad]);check(false,'invalid total accepted');}
    catch (\DomainException) {check(true,'invalid total denied');}
}
$out=__DIR__.'/../docs/evidence/lucktest-cost';if(!is_dir($out))mkdir($out,0777,true);
file_put_contents($out.'/generation-examples.json',json_encode($examples,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
foreach ($examples as $e) echo $e['count'].' cards, target '.$e['target'].': '.$e['host'].' / '.$e['player']."\n";
echo "Total cost checks passed (test catalog prices 3,5,8; actual generator, no live DB).\n";
