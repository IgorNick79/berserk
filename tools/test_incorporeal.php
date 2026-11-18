<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,CardInstance,CardStats,Command,Engine,GameState,ZoneManager,CardHealing};
use Berserk\View\{Template};use Berserk\View\Screen\BattleScreen;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function ic($v,$m){global$n;$n++;if(!$v)throw new RuntimeException($m);}
function ist():GameState{
 $s=new GameState(911,11,22);$s->status='battle';$s->battle=['active'=>'host','turn'=>2,'hidden_row_revealed'=>true];
 $s->cards[1]=new CardInstance(1,'ghost','host','field',row:2,col:2,hp:5,hpMax:5,move:2,moveMax:2,strikeWeak:1,classes:['Дух'],prop:['incorporeal'=>true]);
 $s->cards[2]=new CardInstance(2,'own','host','field',row:2,col:3,hp:5,hpMax:5,move:2,moveMax:2,strikeWeak:1);
 $s->cards[3]=new CardInstance(3,'enemy','player','field',row:4,col:3,hp:5,hpMax:5,strikeWeak:1);return$s;
}
$s=ist();$e=new Engine();ic($e->apply($s,'host',new Command('move',['card_id'=>1,'row'=>2,'col'=>3]))->success,'ordinary move enters occupied cell');ic($s->cards[1]->move===1&&$s->cards[1]->row===2,'movement spent normally');ic(empty($s->cards[1]->flags['symbiote_host_id']),'independent occupant without host');
$before=$s->toArray();ic(!$e->apply($s,'host',new Command('end_turn'))->success&&$s->toArray()===$before,'must leave while movement remains');
ic(!$e->apply($s,'host',new Command('gain_coin',['card_id'=>2]))->success,'other action blocked by move obligation');
ic($e->apply($s,'host',new Command('move',['card_id'=>1,'row'=>3,'col'=>3]))->success,'obligated ordinary move works');ic($s->cards[1]->move===0&&$s->cards[1]->zone==='field','empty destination does not exile');
$s=ist();$s->cards[1]->prop=[];ic(!(new Engine())->apply($s,'host',new Command('move',['card_id'=>1,'row'=>2,'col'=>3]))->success,'class Spirit alone does not grant incorporeality');
$s=ist();$s->cards[1]->prop['actions']=[['key'=>'jump','type'=>'jump','range'=>3]];ic(!(new Engine())->apply($s,'host',new Command('jump',['card_id'=>1,'row'=>2,'col'=>3]))->success,'jump cannot enter occupied cell');
$s=ist();$s->cards[1]->col=3;$s->cards[1]->move=0;$r=(new Engine())->apply($s,'host',new Command('end_turn'));ic($r->success,'end turn executes after exhausted spirit exiled');ic($s->cards[1]->zone==='exile','occupied exhausted spirit goes directly to exile');ic(($s->battle['active']??'')==='player'||!empty($s->battle['turn_phase']),'normal turn lifecycle continues');
$s=ist();$s->cards[1]->move=0;ic((new Engine())->apply($s,'host',new Command('end_turn'))->success&&$s->cards[1]->zone==='field','empty cell no exile on end turn');
foreach(['strike','impact','poison']as$kind){$s=ist();$s->cards[1]->prop['on_death']=[['type'=>'test']];(new Engine())->applyDamage($s,$s->cards[1],99,$kind,$s->cards[3]);ic($s->cards[1]->zone==='exile','lethal '.$kind.' replaces death');ic(!$s->cards[1]->dying&&empty($s->battle['pending_any_death']),'no dying/death-trigger lifecycle');}
$s=ist();(new Engine())->forceDeath($s,$s->cards[1],'destroy');ic($s->cards[1]->zone==='exile','ability destruction replacement');
$s=ist();(new ZoneManager($s))->toGraveyard($s->cards[1]);ic($s->cards[1]->zone==='exile','direct graveyard route replacement');
$s=ist();(new Engine())->forceDeath($s,$s->cards[2],'destroy');(new Engine())->finalizeDying($s);ic($s->cards[2]->zone==='graveyard','ordinary death unchanged');
$s=ist();(new Engine())->applyPoison($s->cards[1],3,'player');ic(empty($s->cards[1]->markers['poison']),'cannot poison');
$s->cards[1]->hp=2;(new CardHealing($s,new Engine()))->begin($s->cards[2],$s->cards[1],3,'test');ic($s->cards[1]->hp===2,'cannot heal through native healing');
$s=ist();$s->cards[1]->col=3;$info=[];foreach($s->cards as$c)$info[$c->ukid]=['name'=>$c->ukid,'price'=>1];$screen=new BattleScreen(new Template(__DIR__.'/../templates'));
$d=$screen->prepare($s,'host','host',null,$info,['sel'=>1])['data'];ic(str_contains($d['field_html'],'<span data-cell-count-value>2</span>'),'independent cards use total 2 marker');ic(str_contains($d['panel_html'],'data-panel-cell-step'),'existing panel switch');
(new ZoneManager($s))->toExile($s->cards[1]);$d=$screen->prepare($s,'host','host',null,$info,['sel'=>2])['data'];ic(!str_contains($d['field_html'],'data-cell-counter'),'marker disappears on exile');
$s=ist();$s->cards[1]->col=3;$s->cards[1]->move=0;$s->battle['strike']=['state'=>'results','confirmed'=>[]];ic(!(new Engine())->apply($s,'host',new Command('end_turn'))->success&&$s->cards[1]->zone==='field','no exile during combat resolution');
$s=ist();$s->cards[1]->move=1;$r=(new Engine())->apply($s,'host',new Command('move',['card_id'=>1,'row'=>2,'col'=>3]));ic($r->success&&$s->cards[1]->zone==='exile','last movement point on occupied cell immediately exiles per latest requirement');
ic(($s->battle['incorporeal_notices'][0]['reason']??'')==='occupied','explicit exile notice records reason');
$info=[];foreach($s->cards as$c)$info[$c->ukid]=['name'=>$c->ukid,'price'=>1];$d=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$info)['data'];ic(str_contains($d['info_panel_html'],'ghost изгнан'),'exile notice names disappearing card');
$s=ist();$s->cards[3]->hp=2;$s->cards[3]->prop=['vampire'=>true];(new Engine())->applyDamage($s,$s->cards[1],1,'strike',$s->cards[3]);ic($s->cards[3]->hp===2,'vampirism cannot use incorporeal victim');
$s=ist();$s->cards[1]->prop['deadeat']=true;$s->cards[1]->hp=2;(new Engine())->forceDeath($s,$s->cards[2],'strike',$s->cards[1]);ic($s->cards[1]->hp===2,'incorporeal cannot gain corpse-eating healing');
$s=ist();$s->cards[1]->hp=2;(new CardHealing($s,new Engine()))->begin($s->cards[2],$s->cards[1],0,'full',[],['full'=>true]);ic($s->cards[1]->hp===2,'full heal cannot bypass prohibition');
$s=ist();ic(!CardStats::canExecuteTarget($s,$s->cards[2],$s->cards[1],99),'execution forbidden');
$s=ist();$s->cards[1]->col=3;$s->cards[1]->move=0;$s->battle['pending_coin_spend']=['owner'=>'host','card_id'=>2];ic(!(new Engine())->apply($s,'host',new Command('end_turn'))->success&&$s->cards[1]->zone==='field','pending takes precedence over occupied-cell exile');
$s=ist();$s->cards[1]->col=3;$s->cards[1]->move=0;$s->historyRecorder=new \Berserk\Core\BattleHistory\Recorder($s->gameId,1,[],[],true);(new Engine())->apply($s,'host',new Command('end_turn'));$batch=$s->historyRecorder->batch($s)->data();ic($s->cards[1]->zone==='exile'&&is_array($batch),'history batch survives occupied-cell exile');
$s=ist();$s->cards[1]->col=3;$s->cards[1]->markers['rooted']=['sources'=>[3]];ic((new Engine())->apply($s,'host',new Command('end_turn'))->success&&$s->cards[1]->zone==='field','no mandatory movement before an opportunity exists');
$s=ist();$s->cards[2]->markers['rooted']=['sources'=>[1]];(new Engine())->forceDeath($s,$s->cards[1],'destroy');ic(empty($s->cards[2]->markers['rooted']),'exile clears restrictions imposed by leaving source');
$s=ist();$s->cards[1]->move=4;for($i=0;$i<4;$i++){ $col=($i%2===0)?3:2;ic((new Engine())->apply($s,'host',new Command('move',['card_id'=>1,'row'=>2,'col'=>$col]))->success,'four movement point scenario step'); }ic($s->cards[1]->zone==='field'&&$s->cards[1]->move===0,'four-point card remains on empty final cell');
$s=ist();$s->cards[1]->move=4;$s->cards[1]->col=1;foreach([2,3,2,3] as $col)ic((new Engine())->apply($s,'host',new Command('move',['card_id'=>1,'row'=>2,'col'=>$col]))->success,'four points ending occupied');ic($s->cards[1]->zone==='exile','four-point card exiles at final occupied cell');
if (in_array('--fixture', $argv, true)) {
 $s=ist();$s->cards[1]->move=1;(new Engine())->apply($s,'host',new Command('move',['card_id'=>1,'row'=>2,'col'=>3]));
 $info=[];foreach($s->cards as$c)$info[$c->ukid]=['name'=>$c->ukid,'price'=>1];
 $d=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$info)['data'];
 echo json_encode(['notice'=>$d['info_panel_html']],JSON_THROW_ON_ERROR);
} else echo "Incorporeal: $n checks passed (no live DB).\n";
