<?php
declare(strict_types=1);
require __DIR__.'/test_effective_features.php';
use Berserk\Core\{CardInstance, Engine, ProphecyProcessor};
$before=$checks;
foreach([false,true] as $machinator) {
    $s=efState();$s->nextInstanceId=100;
    if($machinator)$s->getCard(2)->prop=['prophecy_reorder'=>true];
    else foreach($s->cards as $c)$c->prop=[];
    foreach([10,11,12] as $id)$s->addCard(new CardInstance(instanceId:$id,ukid:'secret_'.$id,owner:'host',zone:'deck',order:$id-9));
    $p=new ProphecyProcessor($s,new Engine());$peek=$p->peek('host',1);$first=$peek['cards'][0];
    $p->commit('host',$s->getCard(1),$peek,'simple','Пророчество 1',[]);
    efCheck($p->close('host')['ok'],'close explicitly ordered deck');
    $s=efRoundtrip($s);$p=new ProphecyProcessor($s,new Engine());
    efCheck($p->peek('host',1)['ukids']===['secret_11'],'next prophecy does not repeat first card, with or without extra action');
    efCheck($first->order===null,'consumed explicit deck order removed');
}
$s=efState();$s->nextInstanceId=100;$s->getCard(2)->prop=['prophecy_reorder'=>true];
foreach([10,11] as $id)$s->addCard(new CardInstance(instanceId:$id,ukid:'secret_'.$id,owner:'host',zone:'deck',order:$id-9));
$p=new ProphecyProcessor($s,new Engine());$p->commit('host',$s->getCard(1),$p->peek('host',1),'simple','Пророчество 1',[]);
efCheck($p->startReorder('host')->success,'Machinator may explicitly retain first card');
efCheck($p->close('host')['ok']&&$p->peek('host',1)['ukids']===['secret_10'],'explicit Machinator top still respected');
$s = efState();
$s->nextInstanceId = 100;
$s->getCard(1)->prop = ['prophecy' => 1, 'prophecy_transform' => true];
$s->getCard(2)->prop = ['prophecy' => 1];
$s->addCard(new CardInstance(instanceId: 10, ukid: 'elite', owner: 'host', zone: 'deck', order: 1, elite: true));
$s->addCard(new CardInstance(instanceId: 11, ukid: 'next', owner: 'host', zone: 'deck', order: 2));
$s->battle['turn_phase'] = [
    'phase' => 'start', 'side' => 'active', 'active_key' => 'host', 'passive_key' => 'player',
    'sub' => ['parent_type' => 'prophecy', 'pending_id' => 'prophecy_1', 'remaining' => [
        ['id' => 'prophecy_1', 'card_id' => 1],
        ['id' => 'prophecy_2', 'card_id' => 2],
    ]],
];
$engine = new Engine();
$turn = new \Berserk\Core\TurnProcessor($s, $engine);
$turn->triggerProphecyForCard($s->getCard(1), 'host');
efCheck($turn->transformSeeker('host', new \Berserk\Core\Command('transform_seeker'))->success, 'Seeker transforms');
efCheck(empty($s->battle['pending_prophecy']), 'Transform closes the current prophecy');
efCheck(array_column($s->battle['turn_phase']['sub']['remaining'], 'id') === ['prophecy_2'], 'Only the other prophecy remains queued');
efCheck($s->getCard(1)->zone === CardInstance::ZONE_FLYING, 'Seeker enters the flying zone');

$s = efState();
$s->nextInstanceId = 100;
$s->persistenceVersion = 1;
$s->getCard(2)->prop = ['prophecy_block' => true];
$s->addCard(new CardInstance(instanceId: 10, ukid: 'odd', owner: 'host', zone: 'deck', price: 3));
$engine = new Engine();
efCheck($engine->tryProphecyBlock($s, $s->getCard(3), $s->getCard(2), 'strike'), 'Odd prophecy blocks the attack');
$plan = \Berserk\Core\Combat\CombatDecisionPlanner::decide(
    \Berserk\Core\Observation\PlayerObservation::build($s, 'host'),
    \Berserk\Core\Legal\LegalActions::build($s, 'host'),
);
efCheck($plan['action'] === ['type' => 'close_prophecy', 'payload' => []], 'Bot closes the attack block reveal');
efCheck($engine->apply($s, 'host', new \Berserk\Core\Command('close_prophecy'))->success, 'Native command closes block');
efCheck(empty($s->battle['pending_prophecy']), 'The blocked attack no longer holds the game');

echo 'Prophecy deck order: ',$checks-$before," new checks passed\n";
