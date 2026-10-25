<?php
declare(strict_types=1);
if (($argv[1]??'')==='--fixture') ob_start();
require __DIR__.'/test_effective_features.php';
if (($argv[1]??'')==='--fixture') ob_end_clean();
use Berserk\Core\{CardInstance, CardStats, Command, EffectiveFeatures, Engine, GameState,
    ProphecyProcessor, SwampQueenProcessor, TurnPhaseProcessor, ZoneManager};
use Berserk\Core\Choice\SwampQueenSummonChoice;
use Berserk\View\Ui\Panel;
$sqBefore=$checks;
function sqProp(): array { return json_decode(file_get_contents(__DIR__.'/../docs/swamp-queen.prop.json'),true,512,JSON_THROW_ON_ERROR); }
function sqEngine(?Closure $lookup=null): Engine {
    return new Engine(cardNameLookup:$lookup??static fn($ids)=>array_fill_keys($ids,'Кочевник')+[]);
}
function sqState(int $deck=1): GameState {
    $s=new GameState(-260130,101,202);$s->status='battle';
    $s->battle=['active'=>'player','turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
    foreach([[1,2],[1,3],[1,4],[2,2],[2,4],[3,2],[3,3],[3,4]] as $i=>[$row,$col]) {
        $s->addCard(new CardInstance(instanceId:$i+1,ukid:$i===0?'s1_130':'s1_1',owner:'host',zone:'field',row:$row,col:$col,
            hp:20,hpMax:20,closed:$i===0,move:1,moveMax:1,strikeWeak:1,strikeMedium:2,strikeStrong:3,prop:$i===0?sqProp():[]));
    }
    $s->addCard(new CardInstance(instanceId:20,ukid:'enemy',owner:'player',zone:'field',row:6,col:5,hp:20,hpMax:20));
    for($i=0;$i<$deck;$i++)$s->addCard(new CardInstance(instanceId:30+$i,ukid:$i===1?'s1_125':'s1_128',owner:'host',zone:'deck',
        order:$i+1,hp:10,hpMax:10,move:1,moveMax:1,price:$i===1?4:5,element:'swamps'));
    $s->nextInstanceId=100;
    return $s;
}
function sqEnd(GameState $s,string $owner='player'): void {
    $s->battle['active']=$owner;
    $r=(new Engine())->apply($s,$owner,new Command('end_turn'));
    efCheck($r->success,'native end turn: '.$r->error);
}
function sqChoose(GameState $s,string $type,array $payload): \Berserk\Core\Result {
    return (new Engine())->apply($s,'host',new Command($type,$payload));
}
function sqTwoCells(GameState $s): void {
    $s->getCard(5)->row=1;$s->getCard(5)->col=3;
    foreach([[1,5],[2,5],[3,5]] as $i=>[$row,$col])$s->addCard(new CardInstance(instanceId:40+$i,ukid:'stack',owner:'host',zone:'field',row:$row,col:$col,hp:10,hpMax:10));
}

if (($argv[1]??'')==='--fixture') {
    $s=sqState(3);sqTwoCells($s);sqEnd($s);
    if(($argv[2]??'cards')==='cells')sqChoose($s,'choose_swamp_queen_card',['card_id'=>30]);
    $catalog=['s1_130'=>['name'=>'Королева топи'],'s1_1'=>['name'=>'Кочевник'],'s1_128'=>['name'=>'Тролль'],'s1_125'=>['name'=>'Троллок']];
    $_GET=[];$_SESSION=[];
    $data=(new \Berserk\View\Screen\BattleScreen(new \Berserk\View\Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$catalog,['sel'=>1,'mode'=>'strike'])['data'];
    echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
}

$s=sqState();$raw=$s->getCard(30);sqEnd($s);
efCheck($s->getCard(30)===$raw&&$raw->zone==='field'&&$raw->row===2&&$raw->col===3,'one creature/cell automatically summons same instance');
efCheck($raw->closed&&$raw->revealed&&$raw->hp===$raw->hpMax&&$raw->move===$raw->moveMax,'native summoned arrival contract');
efCheck(empty($s->battle[SwampQueenProcessor::PENDING]),'automatic summon has no pick');
for($i=0;$i<25&&!empty($s->battle['turn_phase']);$i++){
    $r=(new Engine())->apply($s,$s->battle['active'],new Command('turn_ack'));efCheck($r->success,'normal end/start phase ACK');
}
efCheck(empty($s->battle['turn_phase'])&&$s->battle['active']==='host','opponent end continuation completes');
$s=sqState();sqEnd($s,'host');efCheck($s->getCard(30)->zone==='deck','no summon at own end');
foreach(['seven','enemy','artifact','flying','dying','no_deck','price6','wrong_element','hand','blocked','suppressed'] as $case){
    $s=sqState();
    if(in_array($case,['seven','enemy','artifact','flying','dying'],true)){
        $c=$s->getCard(2);
        if($case==='seven')(new ZoneManager($s))->toHand($c);
        if($case==='enemy')$c->owner='player';
        if($case==='artifact')$c->type='artifact';
        if($case==='flying')(new ZoneManager($s))->toFlying($c);
        if($case==='dying'){$c->dying=true;$c->hp=0;}
    }
    if($case==='no_deck')$s->removeCard(30);
    if($case==='price6')$s->getCard(30)->price=6;
    if($case==='wrong_element')$s->getCard(30)->element='forests';
    if($case==='hand')(new ZoneManager($s))->toHand($s->getCard(30));
    if($case==='blocked')$s->cell_markers['2_3']=[['type'=>'bonfire','source'=>'player']];
    if($case==='suppressed')EffectiveFeatures::applyMemoryLoss($s,$s->getCard(20),$s->getCard(1));
    sqEnd($s);efCheck(empty($s->battle[SwampQueenProcessor::PENDING])&&($s->getCard(30)?->zone??'deck')!=='field','ineligible end: '.$case);
}
$s=sqState(3);sqEnd($s);$p=$s->battle[SwampQueenProcessor::PENDING];
efCheck($p['stage']==='choose_card'&&count($p['candidate_ids'])===3&&$p['cell']==='2_3','many cards, automatic sole cell, duplicates kept');
$spec=(new SwampQueenSummonChoice())->spec($s,'host',['s1_130'=>['name'=>'Королева топи'],'s1_128'=>['name'=>'Тролль'],'s1_125'=>['name'=>'Троллок']],'?first&game=1','host');
$html=Panel::render($spec);
efCheck(substr_count($html,'type="radio"')===3&&!str_contains($html,'cancel_pending'),'mandatory Degasca-style radios without refusal');
efCheck(!sqChoose($s,'cancel_pending',[])->success,'cannot cancel mandatory summon');
$s=efRoundtrip($s);mt_srand(987);$expected=[31,32];shuffle($expected);$next=mt_rand();mt_srand(987);
efCheck(sqChoose($s,'choose_swamp_queen_card',['card_id'=>30])->success,'card pick after reload');
efCheck(mt_rand()===$next,'deck shuffled exactly once on completion');
efCheck($s->getCard(30)->zone==='field'&&$s->getCard(31)->zone==='deck'&&$s->getCard(32)->zone==='deck','only chosen physical instance moves');
efCheck(!sqChoose($s,'choose_swamp_queen_card',['card_id'=>30])->success,'retry cannot summon again');
foreach([1,3] as $deck){
    $s=sqState($deck);sqTwoCells($s);$processor=new SwampQueenProcessor($s,new Engine());
    efCheck(count($processor->cells($s->getCard(1),sqProp()['opponent_turn_end'][0]))>=2,'multiple cells with stacked neighbours');
    sqEnd($s);$s=efRoundtrip($s);
    if($deck>1){efCheck(sqChoose($s,'choose_swamp_queen_card',['card_id'=>31])->success,'choose card before cell');$s=efRoundtrip($s);}
    efCheck($s->battle[SwampQueenProcessor::PENDING]['stage']==='choose_cell','mandatory cell pick');
    efCheck(sqChoose($s,'choose_swamp_queen_cell',['cell'=>'2_4'])->success,'choose alternate eligible cell');
    $id=$deck>1?31:30;efCheck($s->getCard($id)->row===2&&$s->getCard($id)->col===4,'selected cell used');
}
$s=sqState(2);$s->addCard(new CardInstance(instanceId:43,ukid:'symbiotic',owner:'host',zone:'field',row:1,col:3,hp:10,hpMax:10));
efCheck(in_array('2_3',(new SwampQueenProcessor($s,new Engine()))->cells($s->getCard(1),sqProp()['opponent_turn_end'][0]),true),'nine adjacent instances are not capped');
foreach(['left','suppressed','card_gone','cell_gone'] as $case){
    $s=sqState(3);sqEnd($s);
    if($case==='left')(new ZoneManager($s))->toHand($s->getCard(1));
    if($case==='suppressed')EffectiveFeatures::applyMemoryLoss($s,$s->getCard(20),$s->getCard(1));
    if($case==='card_gone')foreach([30,31,32] as $id)(new ZoneManager($s))->toHand($s->getCard($id));
    if($case==='cell_gone')$s->addCard(new CardInstance(instanceId:50,ukid:'blocker',owner:'player',zone:'field',row:2,col:3,hp:10,hpMax:10));
    $s=efRoundtrip($s);efCheck(sqChoose($s,'choose_swamp_queen_card',['card_id'=>30])->success,'safe unavailable finish '.$case);
    efCheck(empty($s->battle[SwampQueenProcessor::PENDING]),'pending consumed '.$case);
    efCheck($s->getCard(30)->zone!=='field','no stale summon '.$case);
}

foreach(['own','enemy','none','hand','different_ukid','flying'] as $match){
    $s=sqState();$s->battle['active']='host';$s->getCard(1)->closed=false;
    $names=['s1_130'=>'Королева топи','s1_1'=>'Кочевник','s1_128'=>'Тролль','enemy'=>'Цель','alias'=>'Тролль'];
    if($match==='own')$s->getCard(2)->ukid='s1_128';
    if($match==='enemy')$s->getCard(20)->ukid='s1_128';
    if($match==='different_ukid')$s->getCard(20)->ukid='alias';
    if($match==='hand'){$s->getCard(2)->ukid='s1_128';(new ZoneManager($s))->toHand($s->getCard(2));}
    if($match==='flying'){$s->getCard(20)->ukid='alias';(new ZoneManager($s))->toFlying($s->getCard(20));}
    $e=sqEngine(static fn($ids)=>array_intersect_key($names,array_fill_keys($ids,true)));
    $s->getCard(20)->armor=$s->getCard(20)->armorMax=4;
    $r=$e->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>20,'action_key'=>'prophecy_wounds']));
    efCheck($r->success&&$s->getCard(1)->closed&&$s->getCard(20)->hp===20,'target then tap then prophecy, no early damage '.$match.': '.$r->error);
    $p=$s->battle['pending_prophecy']['swamp_queen'];$value=in_array($match,['own','enemy','different_ukid','flying'],true)?2:1;
    efCheck($p['target_id']===20&&$p['value']===$value&&$p['applied']===false,'name result and original target saved '.$match);
    $s=efRoundtrip($s);
    efCheck($e->apply($s,'host',new Command('close_prophecy'))->success,'native close applies final wound '.$match);
    efCheck($s->getCard(20)->hp===20-$value&&$s->getCard(20)->armor===4,'direct wounds ignore armor without a strike '.$match);
    efCheck($s->battle['strike']['prophecy_result']['applied']===true&&empty($s->battle['pending_prophecy']),'completion persisted '.$match);
    $s=efRoundtrip($s);efCheck(!$e->apply($s,'host',new Command('close_prophecy'))->success&&$s->getCard(20)->hp===20-$value,'retry does not wound again '.$match);
}
foreach(['before','pending'] as $when){
    $s=sqState();$s->battle['active']='host';$s->getCard(1)->closed=false;$e=sqEngine();
    if($when==='before')EffectiveFeatures::applyMemoryLoss($s,$s->getCard(20),$s->getCard(1));
    $r=$e->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>20,'action_key'=>'prophecy_wounds']));
    if($when==='before')efCheck(!$r->success&&!$s->getCard(1)->closed,'suppressed active not declared');
    else {efCheck($r->success,'declare before suppression');EffectiveFeatures::applyMemoryLoss($s,$s->getCard(20),$s->getCard(1));$s=efRoundtrip($s);
        efCheck($e->apply($s,'host',new Command('close_prophecy'))->success&&$s->getCard(20)->hp===20,'pending cannot bypass suppression');}
    efCheck(CardStats::hasDefense($s,$s->getCard(1),'magic',$s->getCard(20)),'zom survives memory loss');
}
foreach(['source_left','target_left','target_dying'] as $case) {
    $s=sqState();$s->battle['active']='host';$s->getCard(1)->closed=false;$e=sqEngine();
    efCheck($e->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>20,'action_key'=>'prophecy_wounds']))->success,'declare before departure '.$case);
    if($case==='source_left')(new ZoneManager($s))->toHand($s->getCard(1));
    if($case==='target_left')(new ZoneManager($s))->toHand($s->getCard(20));
    if($case==='target_dying'){$s->getCard(20)->dying=true;$s->getCard(20)->hp=0;}
    $hp=$s->getCard(20)->hp;$s=efRoundtrip($s);
    efCheck($e->apply($s,'host',new Command('close_prophecy'))->success&&$s->getCard(20)->hp===$hp,'departure finishes without wounds '.$case);
    efCheck(empty($s->battle['pending_prophecy'])&&!$s->battle['strike']['prophecy_result']['applied'],'departure consumes continuation '.$case);
}
foreach(['machinator','summon'] as $route){
    $s=sqState();$s->battle['active']='host';$s->getCard(1)->closed=false;
    if($route==='machinator')$s->getCard(8)->prop=['prophecy_reorder'=>true];
    else $s->getCard(30)->prop=['prophecy_summon'=>true];
    $e=sqEngine();efCheck($e->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>20,'action_key'=>'prophecy_wounds']))->success,'native extra prophecy route '.$route);
    $saved=$s->battle['pending_prophecy']['swamp_queen'];
    if($route==='machinator')efCheck($e->apply($s,'host',new Command('reorder_start'))->success,'native Machinator reorder');
    else {
        efCheck($e->apply($s,'host',new Command('summon_start',['card_id'=>30]))->success,'native prophecy summon');
        efCheck($e->apply($s,'host',new Command('summon_cell',['row'=>2,'col'=>3]))->success,'native summon cell');
        efCheck($e->apply($s,'host',new Command('summon_creature',['target_id'=>2]))->success,'native summon poison choice');
        efCheck($s->getCard(30)->zone==='field'&&$s->getCard(30)->closed,'native arrival preserved');
    }
    efCheck($s->battle['pending_prophecy']['swamp_queen']===$saved,'original target/name/result survives native reconstruction '.$route);
    $s=efRoundtrip($s);
    efCheck($e->apply($s,'host',new Command('close_prophecy',['target_id'=>2]))->success&&$s->getCard(20)->hp===18,'only originally selected target wounded '.$route);
}
// A card summoned from the current prophecy cannot match itself: another copy is required.
foreach ([false,true] as $another) {
    $s=sqState();$s->battle['active']='host';$s->getCard(1)->closed=false;
    $s->getCard(30)->prop=['prophecy_summon'=>true];
    if($another)$s->getCard(3)->ukid='alias';
    $names=['s1_130'=>'Королева топи','s1_1'=>'Кочевник','s1_128'=>'Отряд карателей','enemy'=>'Цель','alias'=>'Отряд карателей'];
    $e=sqEngine(static fn($ids)=>array_intersect_key($names,array_fill_keys($ids,true)));
    efCheck($e->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>20,'action_key'=>'prophecy_wounds']))->success,'declare prophecy with punishers');
    efCheck($e->apply($s,'host',new Command('summon_start',['card_id'=>30]))->success,'start revealed punishers summon');
    efCheck($e->apply($s,'host',new Command('summon_cell',['row'=>2,'col'=>3]))->success,'choose punishers cell');
    efCheck($e->apply($s,'host',new Command('summon_creature',['target_id'=>2]))->success,'finish punishers summon');
    $s=efRoundtrip($s);
    efCheck($e->apply($s,'host',new Command('close_prophecy'))->success,'close summoned punishers prophecy after reload');
    efCheck($s->getCard(20)->hp===($another?18:19),'two wounds require another matching card, summoned card cannot match itself');
}
$s=sqState();$s->battle['active']='host';$s->getCard(1)->closed=false;$calls=0;
$e=sqEngine(static function($ids)use(&$calls){$calls++;return array_fill_keys($ids,'Имя');});
efCheck(!$e->apply($s,'host',new Command('action',['card_id'=>1,'target_id'=>999,'action_key'=>'prophecy_wounds']))->success
    &&$calls===0&&!$s->getCard(1)->closed,'target validation precedes prophecy and tap');

echo 'Swamp queen: ',$checks-$sqBefore," new checks passed\n";
