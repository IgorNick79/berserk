<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest_debug.php';
use Berserk\Core\{Command, Engine, GameState, CardInstance};
use Berserk\Core\Prepare\LuckTestScenario;
use Berserk\Lobby\RoomSetup;
$deckChecks=$checks;
debugConfig([11,22]);
$example=json_decode(file_get_contents(__DIR__.'/../docs/swamp-queen.lucktest.json'),true,512,JSON_THROW_ON_ERROR);
$catalog=[card('s1_130',7,'creature',json_decode(file_get_contents(__DIR__.'/../docs/swamp-queen.prop.json'),true)),
    card('s1_1',3),card('s1_128',5),card('s1_125',4)];
$db=fixture($catalog);$s=fresh();
$e=new Engine($db,fn()=>1800000000,fn($min,$max)=>$min);
$payload=['lucktest_card_count'=>'8','lucktest_scenario'=>json_encode($example,JSON_THROW_ON_ERROR),'debug_roll'=>'6,1,4,5'];
$r=$e->apply($s,'host',new Command('confirm_settings',$payload));
check($r->success,'public example generates: '.$r->error);
check($s->status==='battle'&&count($s->cards)===60,'full physical decks and squads materialized once');
$ids=[];
foreach($s->lucktestDebug['bindings'] as $binding){
    $card=$s->getCard($binding['instance_id']);$ids[]=$card->instanceId;
    check($card->ukid===$binding['ukid']&&$card->owner===$binding['owner'],'catalog instance bound');
    if(($binding['zone']??null)==='deck'){
        check($card->zone==='deck'&&$card->row===null&&$card->col===null&&$card->hpMax===8&&$card->moveMax===2,'deck contains full DB snapshot, not a field card');
        check($card->price===($card->ukid==='s1_128'?5:4),'actual catalog cost');
    }else check($card->zone==='field'&&$card->row===$binding['row']&&$card->col===$binding['col'],'fixed ring placement');
}
check(count(array_unique($ids))===10,'each requested entry has its own instance');
check(!(new \Berserk\Core\ZoneManager($s))->isFieldOccupied(2,3),'ring centre remains free');
check(count($s->getCardsInZone('host','field'))===8&&count($s->getCardsInZone('host','deck'))===22,'deck does not add field creatures or alter K');
$saved=$s->toArray();$s=GameState::fromArray(json_decode(json_encode($saved,JSON_THROW_ON_ERROR),true));
check($s->toArray()['cards']===$saved['cards']&&$s->lucktestDebug===$saved['lucktest_debug'],'GameState reload retains deck and bindings');
LuckTestScenario::verify($s);
check(count($s->cards)===60,'verification/reload does not create copies');
check(!$e->apply($s,'host',new Command('confirm_settings',$payload))->success&&count($s->cards)===60,'repeat generation does not duplicate instances');
$entries=LuckTestScenario::parse(json_encode($example),8);
check(LuckTestScenario::root($entries)===$example,'public JSON round-trip including deck');
$onlyDeck=['deck'=>[['owner'=>'player','ukid'=>'s1_128'],['owner'=>'player','ukid'=>'s1_128']]];
$entries=LuckTestScenario::parse(json_encode($onlyDeck),3);
check(count(LuckTestScenario::fixed($entries,$catalog)['player'])===0&&count(LuckTestScenario::fixed($entries,$catalog,'deck')['player'])===2,'deck entries do not become fixed squad cards');
foreach([
    ['cards'=>[], 'deck'=>[]], ['cards'=>null,'deck'=>$onlyDeck['deck']],
    ['deck'=>[['owner'=>'host','ukid'=>'s1_128','row'=>1,'col'=>2]]],
    ['deck'=>[['owner'=>'enemy','ukid'=>'s1_128']]], ['deck'=>[['owner'=>'host','ukid'=>'s1_128','count'=>2]]],
    ['deck'=>array_fill(0,23,['owner'=>'host','ukid'=>'s1_128'])],
] as $invalid){
    try{LuckTestScenario::parse(json_encode($invalid),8);check(false,'invalid deck accepted');}catch(DomainException){check(true,'invalid deck rejected');}
}
try{LuckTestScenario::fixed(LuckTestScenario::parse('{"deck":[{"owner":"host","ukid":"missing"}]}',3),$catalog);check(false,'missing deck card');}
catch(DomainException){check(true,'unknown deck UKID rejected before generation');}
$room=RoomSetup::parse('lucktest',$payload,11);$lobby=new GameState(324,11,33);RoomSetup::apply($lobby,$room,fixture($catalog));
check($lobby->status==='battle'&&count(array_filter($lobby->lucktestDebug['bindings'],fn($b)=>($b['zone']??null)==='deck'))===2,'lobby JSON route preserves deck');
$s=fresh();$duplicate=['deck'=>[['owner'=>'host','ukid'=>'s1_128'],['owner'=>'host','ukid'=>'s1_128']]];
$r=(new Engine(fixture($catalog),fn()=>1800000000,fn($min,$max)=>$min))->apply($s,'host',new Command('confirm_settings',[
    'lucktest_card_count'=>'3','lucktest_scenario'=>json_encode($duplicate),'debug_roll'=>'6,1,4,5']));
check($r->success,'deck-only scenario generates');
$bindings=$s->lucktestDebug['bindings'];
check(count($bindings)===2&&$bindings[0]['instance_id']!==$bindings[1]['instance_id'],'duplicate requested deck cards are distinct instances');
check($s->getCard($bindings[0]['instance_id'])->zone==='deck'&&$s->getCard($bindings[1]['instance_id'])->zone==='deck','duplicates remain in deck after auto placement');
unlink($configFile);
echo 'LuckTest deck: ', $checks-$deckChecks," new checks passed\n";
