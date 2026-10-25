<?php
declare(strict_types=1);
require __DIR__.'/test_ajax_multi_target.php';
use Berserk\Core\{ActionResolution,CardInstance,Command,Engine,GameState,OchiKronga};
use Berserk\Core\Choice\MultiHealChoice;
use Berserk\View\Ui\{Coords,Panel};
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

foreach (['host','player'] as $owner) {
    $other=$owner==='host'?'player':'host';
    $s=mtFixture('heal');
    if ($owner==='player') {
        $s->battle['active']='player';
        foreach($s->cards as $card) {$card->owner=$card->owner==='host'?'player':'host';$card->row=7-$card->row;}
    }
    $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
    $pending=$s->battle['pending_multi_heal'];$actorBefore=$s->getCard(1)->toArray();
    $engine=new Engine();
    mtAssert(!$engine->apply($s,$other,new Command('cancel_pending'))->success,'Other player cannot cancel fairy');
    mtAssert($s->battle['pending_multi_heal']===$pending,'Rejected cancellation keeps pending');
    $choice=new MultiHealChoice();$info=[];
    foreach($s->cards as $c)$info[$c->ukid]=['name'=>'Фея <лесa>'];
    $spec=$choice->spec($s,$owner,$info,'?game=1',$owner);
    foreach($spec->form['items'] as $item){
        $card=$s->getCard((int)$item['value']);
        mtAssert(str_contains($item['label_html'],Coords::renderResult($card->row,$card->col,$owner,$card->owner)),'Native coordinate badge for owner orientation');
        mtAssert(str_contains($item['label_html'],'&lt;лесa&gt;')&&!str_contains($item['label_html'],'<лесa>'),'Name escaped');
    }
    mtAssert(str_contains(Panel::render($spec),'coords--own'),'Rendered checkbox coordinates');
    mtAssert(str_ends_with($spec->form['cancel'],'cmd=cancel_pending'),'Existing cancellation URL');
    mtAssert($engine->apply($s,$owner,new Command('cancel_pending'))->success,'Owner cancels after reload');
    mtAssert(!isset($s->battle['pending_multi_heal']),'Pending removed');
    mtAssert($s->getCard(1)->toArray()===$actorBefore,'Cancel spends no card resources');
    mtAssert(!$engine->apply($s,$owner,new Command('cancel_pending'))->success,'Repeat cancellation rejected');
    mtAssert($engine->apply($s,$owner,new Command('action',['card_id'=>1,'action_key'=>'multi']))->success,'Can restart heal after cancellation');
    mtAssert($engine->apply($s,$owner,new Command('choose_multi_heal',['target_ids'=>[2,3]]))->success,'Heal remains usable after cancellation');
    mtAssert($s->getCard(2)->hp===9&&$s->getCard(3)->hp===9,'Selected targets healed once');
}

$s=mtFixture('heal');$s->battle['hidden_row_revealed']=true;
$s->addCard(new CardInstance(instanceId:9,ukid:'eyes',owner:'host',zone:'field',row:2,col:2,hp:10,hpMax:10,closed:true,prop:[OchiKronga::PROP=>['filter'=>'forests']]));
$s->getCard(1)->element='forests';
foreach(['strike','shot','heal'] as $type)ActionResolution::effect($s,$s->getCard(1),$type,'ordinary',['hit'=>true,'hp_restored'=>1]);
mtAssert(!$s->getCard(9)->closed,'Opening performed');
$s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
$before=$s->toArray();$panel=new InfoPanel(new Template(__DIR__.'/../templates'));
foreach(['host','player'] as $viewer){
    $html=$panel->render($s,$viewer,$viewer,[], '?game=1');
    mtAssert(str_contains($html,'Очи Кронга открылись'),'Both players see opening even with pending heal');
    mtAssert(str_contains($html,'1/2'),'Opening count shown');
    mtAssert(str_contains($html,'<h3>Информация</h3>')&&str_contains($html,'data-dismiss-ochi>Продолжить</button>'),'Dismissible information block');
    mtAssert(str_contains($html,$viewer==='host'?'Ваши Очи':'У соперника Очи'),'Ownership readable');
    mtAssert(str_contains($html,Coords::renderResult(2,2,$viewer,'host')),'Opening has viewer coordinates');
}
mtAssert($s->toArray()===$before,'Rendering/reload never consumes opening notice');
$s->getCard(9)->closed=true;
mtAssert(str_contains($panel->render($s,'player','player',[],'?game=1'),'Очи Кронга открылись'),'Past opening still visible after later close');
OchiKronga::leftPlay($s->getCard(9));
mtAssert(!str_contains($panel->render($s,'player','player',[],'?game=1'),'Очи Кронга открылись'),'No stale notice after leaving play');
echo "Ochi informer and fairy cancel/coordinate regressions passed\n";
