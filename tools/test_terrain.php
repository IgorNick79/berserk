<?php
declare(strict_types=1);
// Reuse the process-local catalog adapter; never connects to the application's database.
require __DIR__ . '/test_lucktest.php';
use Berserk\Core\{CardInstance,CardStats,Command,DamageResolver,Engine,GameState,ZoneManager};
use Berserk\Core\Prepare\{LuckTestGenerator,LuckTestScenario,PrepareProcessor,SquadRules};
use Berserk\View\{Template};
use Berserk\View\Screen\{BattleScreen,BattleSnapshotRenderer,PlaceScreen};
$start=$checks;
$s=new GameState(999,1,2);$s->status='place';$s->mode='system';
$s->getPlayer('host')->side=1;$s->getPlayer('player')->side=2;$s->firstPlayer='host';
$t=new CardInstance(3,'s2_test_terrain','host',CardInstance::ZONE_SQUAD,hp:100,hpMax:100,move:9,moveMax:9,strikeWeak:9,prop:['actions'=>[]]);
$t->type='terrain'; // Also exercise legacy/external snapshots with stale creature stats.
$t=CardInstance::fromArray($t->toArray());$s->cards[3]=$t;
$s->cards[1]=new CardInstance(1,'s1_test','host','field',row:1,col:1,hp:5,hpMax:5,strikeWeak:1);
$s->cards[2]=new CardInstance(2,'s1_test','player','field',row:6,col:1,hp:5,hpMax:5,strikeWeak:1);
check($t->hp===0 && $t->hpMax===0 && $t->move===0 && !CardStats::hasAnyStrike($t),'terrain normalizes creature-only stats');
$p=new PrepareProcessor($s);check(!$p->placeCard('host',new Command('place_card',['card_id'=>3,'row'=>1,'col'=>2]))->success,'server rejects field placement');
$second=new CardInstance(4,'s2_other','host','squad',type:'terrain');
check(SquadRules::validate($s,'host',[$second])!==null,'server rejects second terrain');
check(SquadRules::validate($s,'player',[new CardInstance(5,'s2_other','player','squad',type:'terrain')])===null,'one terrain per owner');
check($p->confirmPlace('host')->success && $t->zone===CardInstance::ZONE_TERRAIN && $t->row===null && $t->col===null,'confirmation automatically moves terrain');
check(SquadRules::validate($s,'host',[$second])!==null,'deployed terrain still counts');
try {(new ZoneManager($s))->toTerrain($second);check(false,'second terrain accepted');}catch(DomainException){check(true,'zone enforces single terrain');}
check($p->confirmPlace('player')->success && $s->status==='battle','terrain does not block battle entry');
$s->battle=['active'=>'host','turn'=>1];$engine=new Engine();
check(!$engine->apply($s,'host',new Command('strike',['card_id'=>3,'target_id'=>2]))->success,'terrain cannot declare strike');
check(!$engine->apply($s,'host',new Command('move',['card_id'=>3,'row'=>2,'col'=>2]))->success,'terrain cannot move');
$before=$t->toArray();$engine->applyDamage($s,$t,999,'strike',$s->cards[2]);check($before===$t->toArray(),'terrain ignores damage without side effects');
$copy=GameState::fromArray($s->toArray());check($copy->getCard(3)->toArray()===$t->toArray(),'terrain serialization');
$engine->checkGameOver($s);check($s->winner===null,'creatures and terrain keep battle alive');
$catalog=['s2_test_terrain'=>['name'=>'Test Terrain','descr'=>'Terrain description','price'=>1],'s1_test'=>['name'=>'Creature','price'=>1]];
$screen=new BattleScreen(new Template(__DIR__.'/../templates'));
$data=$screen->prepare($s,'host','host',null,$catalog,['sel'=>3])['data'];
check(str_contains($data['piles_html'],'Test Terrain')&&str_contains($data['piles_html'],'sel=3'),'existing terrain slot opens selected card');
check(str_contains($data['panel_html'],'Test Terrain')&&!str_contains($data['panel_html'],'data-stat="hp"'),'terrain selected without HP display');
check(!str_contains($data['panel_html'],'mode=strike')&&!str_contains($data['panel_html'],'mode=uchr'),'terrain has no basic attack buttons');
$fragments=(new BattleSnapshotRenderer(new Template(__DIR__.'/../templates')))->render($s,'host','host',null,$catalog,['sel'=>3]);
check(str_contains($fragments['fragments']['piles_html'],'sel=3'),'AJAX snapshot carries terrain slot');
$s->cards[1]->zone='graveyard';$engine->checkGameOver($s);check($s->winner==='player','terrain does not prevent elimination');
$s->winner=null;$s->status='battle';$s->cards[1]->zone='field';$s->cards[2]->zone='graveyard';$engine->checkGameOver($s);check($s->winner==='host','creature owner beats eliminated opponent');
$s->winner=null;$s->status='battle';$s->cards[1]->type='artifact';$engine->checkGameOver($s);check($s->winner==='player','future artifact does not count as creature');
$terrain=card('s2_terrain',1,'terrain');$creature=card('s2_creature',3);
$entries=LuckTestScenario::parse('{"cards":[{"owner":"host","ukid":"s2_terrain"}]}',5);
$fixed=LuckTestScenario::fixed($entries,[$terrain,$creature]);check($fixed['host'][0]['ukid']==='s2_terrain','Debug accepts set 2 IDs from catalog');
$gen=new LuckTestGenerator();for($i=0;$i<10;$i++){
 $armies=$gen->generate([$terrain,$creature],settings(['card_count'=>5,'balanced'=>false]),$fixed);
 foreach($armies as $army)check(array_sum(array_map(fn($c)=>$c['type']==='terrain'?$c['count']:0,$army))<=1,'generated squad terrain limit');
}
try{$gen->generate([$terrain,$creature],settings(['card_count'=>5]),['host'=>[$terrain,$terrain]]);check(false,'duplicate fixed accepted');}catch(DomainException){check(true,'fixed terrain limit');}
try{LuckTestScenario::fixed([['owner'=>'host','ukid'=>'s2_terrain','hp'=>1]],[$terrain]);check(false,'terrain hp accepted');}catch(DomainException){check(true,'Debug rejects HP for terrain');}
$s->status='place';$s->getPlayer('host')->clearConfirmations();$s->cards[1]->type='creature';
$place=(new PlaceScreen(new Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$catalog)['data'];
check($place['squad_count']===0 && str_contains($place['squad_html'],'Местность'),'placement lists terrain without reserving a field cell');
// Full Quick Game path, including random placement, confirm_place and startBattle.
for ($run=0;$run<5;$run++) {
    $quick=fresh();$normal=card('s2_creature',3);$normal['single']=false;
    $r=(new Engine(fixture([$terrain,$normal])))->apply($quick,'host',new Command('confirm_settings',[
        'lucktest_quick_game'=>'1','lucktest_card_count'=>5,'lucktest_balanced'=>'0',
    ]));
    check($r->success && $quick->status==='battle','full Quick Game accepts terrain catalog');
    foreach($quick->cards as $c) if($c->type==='terrain' && !empty($c->flags['lucktest_squad'])) {
        check($c->zone===CardInstance::ZONE_TERRAIN && $c->row===null && $c->hp===0,'Quick Game terrain automatically placed');
    }
}
$deal=new GameState(555,1,2);$deal->status='deal';$deal->getPlayer('host')->resources=['gold'=>100,'silver'=>100];
$deal->cards[1]=new CardInstance(1,'synthetic_terrain1','host','hand',type:'terrain',price:1);
$deal->cards[2]=new CardInstance(2,'synthetic_terrain2','host','hand',type:'terrain',price:1);
$recruit=new PrepareProcessor($deal);
check($recruit->pickCard('host',new Command('pick_card',['ukid'=>'synthetic_terrain1']))->success,'real recruitment accepts first terrain');
$before=$deal->toArray();check(!$recruit->pickCard('host',new Command('pick_card',['ukid'=>'synthetic_terrain2']))->success && $deal->toArray()===$before,'real recruitment rejects second without mutation');
check($recruit->confirmDeal('host')->success && $recruit->confirmDeal('player')->success,'normal recruitment confirms');
check($deal->getCard(1)->zone===CardInstance::ZONE_TERRAIN,'normal placement entry uses terrain zone');
echo 'Terrain: '.($checks-$start)." checks passed (isolated; no live DB).\n";
