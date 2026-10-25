<?php
declare(strict_types=1);
require __DIR__.'/stage31_fixtures.php';
use Berserk\Core\{ActionResolution,CardInstance,Engine,Command,GameState,OchiKronga};
use Berserk\Core\BattleHistory\Recorder;
$s=s31state();$s->addCard(new CardInstance(instanceId:9,ukid:'eyes-journal-fixture',owner:'host',zone:'field',row:1,col:2,hp:10,hpMax:10,closed:true,prop:[OchiKronga::PROP=>['filter'=>'forests']]));
$s->getCard(1)->element='forests';$s->getCard(3)->element='forests';$s->getCard(3)->hp=19;$s->getCard(3)->prop['actions']=[['type'=>'heal','key'=>'h','value'=>1,'self'=>true]];
ActionResolution::effect($s,$s->getCard(1),'strike','ordinary',['hit'=>true]);ActionResolution::effect($s,$s->getCard(1),'shot','ordinary',['hit'=>true]);
$plain=GameState::fromArray($s->toArray());$observed=GameState::fromArray($s->toArray());
$recorder=new Recorder($s->gameId,1,[],[],true,fn($card)=>'Fixture '.$card->instanceId);$observed->historyRecorder=$recorder;
$engine=new Engine(null,fn()=>1800000000);$command=new Command('action',['card_id'=>3,'target_id'=>3,'action_key'=>'h']);
$a=$engine->apply($plain,'host',$command);$b=$engine->apply($observed,'host',$command);
s31check($a->success&&$b->success&&$plain->toArray()===$observed->toArray(),'whole persisted state independent of Recorder');
s31check(!$plain->getCard(9)->closed&&$plain->getCard(9)->flags[OchiKronga::STATE]['uses']===1,'native heal opens once');
$events=$recorder->batch($observed)->data()['events'];$opens=array_values(array_filter($events,fn($e)=>$e['type']==='card.status'&&($e['payload']['target_id']??null)===9));
s31check(count($opens)===1&&$opens[0]['payload']['closed']===false,'existing journal receives exactly one safe opening status');
foreach (['host','player','public'] as $viewer) {
    s31check(is_array($opens[0][$viewer]), 'opening visible in journal to '.$viewer);
    $text=\Berserk\Core\BattleHistory\JournalText::format('card.status',$opens[0][$viewer],$viewer);
    s31check($text === 'Fixture 9 открывается.', 'readable opening text for '.$viewer);
}

echo "Ochi Kronga: native opening DTO identical with/without mandatory Recorder; status recorded once\n";
