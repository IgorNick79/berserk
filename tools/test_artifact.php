<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest.php';
use Berserk\Core\{BattleHelper,CardInstance,CardStats,Command,Engine,GameState,StrikeResolver,ZoneManager};
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\View\Template;
use Berserk\View\Screen\BattleScreen;
$start=$checks;
function artifactFixture():GameState {
 $s=new GameState(911,11,22);$s->status='battle';$s->firstPlayer='host';$s->battle=['active'=>'host','turn'=>2];
 $s->cards[1]=new CardInstance(1,'synthetic_artifact','host','field',row:2,col:2,hp:5,hpMax:5,move:9,moveMax:9,strikeWeak:9,strikeMedium:9,strikeStrong:9,type:'artifact',prop:['actions'=>[
  ['key'=>'uchr','type'=>'uchr','value'=>3],['key'=>'heal','type'=>'heal','value'=>2,'own'=>true]
 ]]);
 $s->cards[2]=new CardInstance(2,'synthetic_creature','player','field',row:2,col:3,hp:5,hpMax:5,move:2,moveMax:2,strikeWeak:1,strikeMedium:2,strikeStrong:3);
 $s->cards[3]=new CardInstance(3,'synthetic_creature','host','field',row:1,col:1,hp:3,hpMax:5,move:2,moveMax:2,strikeWeak:1,strikeMedium:2,strikeStrong:3);
 return $s;
}
$s=artifactFixture();$a=$s->cards[1];
check($a->type==='artifact'&&$a->hp===5,'artifact type and real HP retained');
check(!CardStats::hasAnyStrike($a)&&BattleHelper::abilities($a)===['has_strike'=>false,'has_uchr'=>false],'basic attacks unavailable despite injected strike/uchr stats');
check(BattleHelper::getMoveCells($s,$a)===[],'artifact has no ordinary move highlights');
check(BattleHelper::getAttackTargets($s,$a,'strike','host')===[]&&BattleHelper::getAttackTargets($s,$a,'uchr','host')===[],'no basic attack candidates');
foreach(['move'=>['row'=>3,'col'=>2],'strike'=>['target_id'=>2],'uchr'=>['target_id'=>2]]as$cmd=>$payload){
 $s=artifactFixture();$before=$s->toArray();$r=(new Engine())->apply($s,'host',new Command($cmd,['card_id'=>1]+$payload));
 check(!$r->success&&$s->toArray()===$before,'server rejects '.$cmd.' without consuming action');
}
$s=artifactFixture();check(!(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'uchr','target_id'=>2]))->success,'action route cannot bypass UCHR restriction');
$s=artifactFixture();check(!(new StrikeResolver($s,new Engine()))->declareForcedAdjacent($s->cards[1],$s->cards[2],false,['test'=>true])->success,'forced strike path cannot bypass artifact type');
$s=artifactFixture();$r=(new Engine())->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'heal','target_id'=>3]));
check($r->success&&$s->cards[3]->hp===5,'artifact uses its own standard ability');
$s=artifactFixture();$s->cards[3]->prop=['actions'=>[['key'=>'heal','type'=>'heal','value'=>2,'own'=>true]]];$s->cards[1]->hp=3;
check(!isset(BattleHelper::getAttackTargets($s,$s->cards[3],'action:heal','host')[1]),'creature-only heal excludes artifact in UI');
check(!(new Engine())->apply($s,'host',new Command('action',['card_id'=>3,'action_key'=>'heal','target_id'=>1]))->success,'server rejects creature-only heal targeting artifact');
$s=artifactFixture();$s->battle['active']='player';$r=(new Engine())->apply($s,'player',new Command('strike',['card_id'=>2,'target_id'=>1]));
check($r->success,'ordinary creature can target artifact through existing strike route');
$s=artifactFixture();$engine=new Engine();$engine->applyDamage($s,$s->cards[1],2,'strike',$s->cards[2]);
check($s->cards[1]->hp===3&&$s->cards[1]->zone==='field','artifact takes ordinary wounds');
$engine->applyDamage($s,$s->cards[1],9,'strike',$s->cards[2]);check($s->cards[1]->dying&&$s->cards[1]->hp===0,'artifact enters existing dying phase');$engine->finalizeDying($s);check($s->cards[1]->zone==='graveyard','artifact dies on lethal wounds');
$s=artifactFixture();(new Engine())->checkGameOver($s);check($s->winner===null,'creature plus artifact continues battle');
$s->cards[3]->zone='graveyard';(new Engine())->checkGameOver($s);check($s->winner==='player','artifact alone does not prevent defeat');
$s=artifactFixture();$s->status='place';$s->mode='system';$s->getPlayer('host')->side=1;$s->getPlayer('player')->side=2;
$s->cards[1]->zone='squad';$s->cards[1]->row=$s->cards[1]->col=null;
check((new PrepareProcessor($s))->placeCard('host',new Command('place_card',['card_id'=>1,'row'=>1,'col'=>2]))->success,'artifact uses ordinary ground placement');
check($s->cards[1]->zone==='field'&&$s->cards[1]->row===1,'artifact occupies ordinary field cell');
$s=artifactFixture();$catalog=['synthetic_artifact'=>['name'=>'Artifact','type'=>'artifact','price'=>1],'synthetic_creature'=>['name'=>'Creature','price'=>1]];
$data=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$catalog,['sel'=>1])['data'];
check(str_contains($data['field_html'],'data-mini-card="1"'),'artifact rendered by existing field component');
check(str_contains($data['panel_html'],'Artifact')&&str_contains($data['panel_html'],'5/5'),'artifact selected with HP');
check(!str_contains($data['panel_html'],'mode=strike')&&!str_contains($data['panel_html'],'mode=uchr')&&str_contains($data['panel_html'],'action:heal'),'UI hides basic attacks and retains own abilities');
check(GameState::fromArray($s->toArray())->cards[1]->type==='artifact','artifact survives state roundtrip');
$s=artifactFixture();check(BattleHelper::getMoveCells($s,$s->cards[3])!==[],'first-set creature movement remains available');
check((new Engine())->apply($s,'host',new Command('move',['card_id'=>3,'row'=>1,'col'=>2]))->success,'first-set creature can move');
$s=artifactFixture();$s->battle['active']='player';check((new Engine())->apply($s,'player',new Command('strike',['card_id'=>2,'target_id'=>1]))->success,'first-set creature strike remains available');
echo 'Artifact: '.($checks-$start)." checks passed (in-memory; no live DB).\n";
