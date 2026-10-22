<?php
declare(strict_types=1);
require __DIR__.'/test_matriarch_order.php';
use Berserk\Core\{Command,Engine,GameState,MatriarchOrderProcessor,BattleWaitResolver,InformationalWaitManager};
use Berserk\Core\Choice\ChoiceRegistry;
use Berserk\View\Ui\{Panel,PanelSpec};

foreach (['host','player'] as $owner) {
    $s=os();
    if ($owner==='player') {
        foreach($s->cards as $card) { $card->owner=$s->getOpponentKey($card->owner); $card->row=7-$card->row; }
        $s->battle['active']='player';
    }
    $s->getCard(2)->col=2;
    $s->getCard(5)->col=2;
    $s->getCard(1)->col=2;
    $engine=new Engine();
    $r=$engine->apply($s,$owner,new Command('action',['card_id'=>4,'action_key'=>'matriarch_order']));ca($r->success,'activate mirrored owner');
    $p=$s->battle[MatriarchOrderProcessor::PENDING];$handler=ChoiceRegistry::current($s);
    $cards=['cc2'=>['name'=>'<script>Enemy</script>'],'cc1'=>['name'=>'<b>Ally</b>']];
    $spec=$handler->spec($s,$owner,$cards,'?game=9901',$owner);
    $html=Panel::render($spec);
    ca($spec->form['type']==='radio'&&$spec->buttons===[],'target uses radio form only');
    ca(str_contains($html,'type="radio"')&&str_contains($html,'name="target_id"'),'radio controls');
    ca(str_contains($html,'coords--enemy')&&str_contains($html,'# [I:'.($owner==='player'?4:2).']'),'oriented Roman target coordinates');
    ca(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'target names escaped');
    ca($spec->form['hidden']['choice_id']===$p['id']&&$spec->form['hidden']['cmd']==='choose_matriarch_order','radio command identity');
    $r=$engine->apply($s,$owner,new Command('choose_matriarch_order',['target_id'=>2,'choice_id'=>$p['id']]));ca($r->success,'apply mirrored order');
    $html=Panel::render($handler->spec($s,$owner,$cards,'?game=9901',$owner));
    ca(str_contains($html,'coords--own')&&str_contains($html,'# [I:'.($owner==='player'?4:2).']'),'participant buttons share oriented Roman coordinates');
    ca(!str_contains($html,'<b>Ally</b>')&&str_contains($html,'&lt;b&gt;Ally&lt;/b&gt;'),'participant names escaped');
}
ca(str_contains(Panel::render(new PanelSpec(title:'test',buttons:[['label'=>'<b>plain</b>','url'=>'?x=1']])),'&lt;b&gt;plain&lt;/b&gt;'),'legacy buttons remain escaped');
echo "Radio targets/colored Roman coordinates/both orientations/HTML escaping passed\n";

// Each final ACK must expose the next battle immediately, at the same server time.
// No helper is allowed to ACK part of the next battle on behalf of another client.
foreach([['host','player'],['player','host']] as $ackOrder) {
    $s=os();activate($s);chooseOrder($s,2);chooseOrder($s,1);
    $firstToken=InformationalWaitManager::token($s);$firstStep=$s->battle['matriarch_order']['step'];
    $now=time();$engine=new Engine(null,static fn():int=>$now);
    $apply=static function(string $actor,string $type,array $payload=[])use(&$s,$engine):void{
        if($type==='confirm_strike')$payload['wait_id']=InformationalWaitManager::token($s);
        $r=$engine->apply($s,$actor,new Command($type,$payload));ca($r->success,$type.':'.$r->error);
        $s=GameState::fromArray($s->toArray());
    };
    if($s->battle['strike']['state']==='waiting_choice')$apply($s->battle['strike']['choice_winner']==='attack'?'host':'player','choose_strike_mode',['mode'=>'normal']);
    $firstToken=InformationalWaitManager::token($s);
    $apply($ackOrder[0],'confirm_strike');
    ca(BattleWaitResolver::current($s)['required_players']===[$ackOrder[1]],'one outstanding client ACK');
    $apply($ackOrder[1],'confirm_strike');
    ca(($s->battle['matriarch_order']['step']??0)===$firstStep+1,'second combat starts in the final ACK request');
    $wait=BattleWaitResolver::current($s);
    ca($wait!==null&&$wait['kind']!=='unknown'&&$wait['required_players']!==[],'next combat always has an actionable owner');
    ca($s->battle['strike']['confirmed']===[],'old confirmations do not leak into next combat');
    if($s->battle['strike']['state']==='results') {
        ca(InformationalWaitManager::token($s)!==$firstToken,'fresh result wait identity');
        ca($wait['required_players']===['host','player'],'both clients can confirm the new result');
        $cards=[];foreach($s->cards as $card)$cards[$card->ukid]=['name'=>$card->ukid];
        foreach(['host','player'] as $viewer) {
            $panel=new \Berserk\View\Screen\Battle\InfoPanel(new \Berserk\View\Template(__DIR__.'/../templates'));
            $html=$panel->render($s,$viewer,$viewer,$cards,'?game=9901');
            ca(str_contains($html,'cmd=confirm_strike'),'next result renders a Continue control for '.$viewer);
        }
    }
}
echo "Immediate first-to-second combat transition/both ACK orders/reload/frozen clock passed\n";
