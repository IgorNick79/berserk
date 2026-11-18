<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,CardInstance,Command,Engine,GameState,SymbioteProcessor,TurnProcessor,ZoneManager,BattleWaitResolver};
use Berserk\Core\Choice\{ChoiceRegistry,SymbioteSeatingChoice};
use Berserk\View\{Template};
use Berserk\View\Screen\BattleScreen;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function syCheck($v,$m){global$n;$n++;if(!$v)throw new RuntimeException($m);}
function syState():GameState{
 $s=new GameState(911,11,22);$s->status='battle';$s->getPlayer('host')->side=1;$s->getPlayer('player')->side=2;
 $s->cards[10]=new CardInstance(10,'host','host','field',row:2,col:2,hp:5,hpMax:5,move:2,moveMax:2,strikeWeak:1);
 $s->cards[20]=new CardInstance(20,'enemy','player','field',row:4,col:2,hp:5,hpMax:5,strikeWeak:1);
 return$s;
}
function syAdd(GameState$s,int$id,string$owner='host',string$kind='companion'):CardInstance{
 return $s->cards[$id]=new CardInstance($id,'sym'.$id,$owner,'field',row:$owner==='host'?1:6,col:1,hp:3,hpMax:3,move:5,moveMax:5,strikeWeak:1,prop:['symbiote'=>$kind]);
}
function syChoose(GameState$s,int$target):void{
 $p=$s->battle[SymbioteProcessor::PENDING];$r=(new Engine())->apply($s,$p['owner'],new Command('choose_symbiote_host',['choice_id'=>$p['id'],'target_id'=>$target]));syCheck($r->success,$r->error??'choice failed');
}
$s=syState();$sym=syAdd($s,1);(new TurnProcessor($s,new Engine()))->startBattle();
syCheck(ChoiceRegistry::current($s)instanceof SymbioteSeatingChoice,'mandatory registry choice');
syCheck($sym->zone==='field'&&empty($sym->flags['symbiote_host_id']),'single host is not automatically assigned or killed');
syCheck(BattleWaitResolver::current($s)['required_players']===['host'],'wait owner correctly projected');
$before=$s->toArray();$p=$s->battle[SymbioteProcessor::PENDING];
syCheck(!(new Engine())->apply($s,'player',new Command('choose_symbiote_host',['choice_id'=>$p['id'],'target_id'=>10]))->success&&$before===$s->toArray(),'opponent rejected without mutation');
syCheck(!(new Engine())->apply($s,'host',new Command('end_turn'))->success,'ordinary action blocked during seating');
$s=GameState::fromArray($s->toArray());syChoose($s,10);$sym=$s->cards[1];
syCheck($sym->flags['symbiote_host_id']===10&&$sym->row===2&&$sym->col===2,'link and coordinates persist after reload');
syCheck(empty($s->battle[SymbioteProcessor::PENDING]),'initial turn continues after seating');
syCheck(!(new Engine())->apply($s,'host',new Command('choose_symbiote_host',['choice_id'=>$p['id'],'target_id'=>10]))->success,'repeated stale selection rejected');
$beforeMove=$sym->move;
syCheck((new Engine())->apply($s,'host',new Command('move',['card_id'=>10,'row'=>2,'col'=>3]))->success,'host uses native movement');
syCheck($sym->row===2&&$sym->col===3&&$sym->move===$beforeMove,'follower moves simultaneously without spending movement');
syCheck(!(new Engine())->apply($s,'host',new Command('move',['card_id'=>1,'row'=>3,'col'=>3]))->success,'symbiote cannot move independently');
(new Engine())->forceDeath($s,$s->cards[10],'destroy');(new Engine())->finalizeDying($s);syCheck($sym->zone==='graveyard','host death kills symbiote through existing lifecycle');
foreach(['deck','hand','exile','flying']as$zone){$s=syState();syAdd($s,1);$s->cards[1]->flags['symbiote_host_id']=10;$z=new ZoneManager($s);match($zone){'deck'=>$z->toDeck($s->cards[10]),'hand'=>$z->toHand($s->cards[10]),'exile'=>$z->toExile($s->cards[10]),'flying'=>$z->toFlying($s->cards[10])};syCheck($s->cards[1]->zone==='graveyard'||$s->cards[1]->dying,'host exit '.$zone);}
$s=syState();syAdd($s,1);$proc=new SymbioteProcessor($s,new Engine());
foreach(['fly','artifact','terrain']as$type){$s->cards[10]->type=$type;syCheck($proc->hosts($s->cards[1])===[],'not a valid host: '.$type);}
$s->cards[10]->type='creature';$s->cards[10]->prop=['incorporeal'=>true];syCheck($proc->hosts($s->cards[1])===[],'corporeal symbiote cannot sit on incorporeal host');
$s->cards[1]->prop['incorporeal']=true;syCheck($proc->hosts($s->cards[1])===[10],'incorporeal symbiote does not violate corporeality restriction');
$s=syState();syAdd($s,1);$s->cards[1]->flags['symbiote_host_id']=10;$s->cards[10]->type='artifact';SymbioteProcessor::refresh($s);syCheck($s->cards[1]->zone==='graveyard'||$s->cards[1]->dying,'host type change kills follower');
$s=syState();syAdd($s,1);$s->cards[10]->type='fly';(new TurnProcessor($s,new Engine()))->startBattle();syCheck($s->cards[1]->zone==='graveyard','only flyers: unseated dies');
$s=syState();syAdd($s,1,'host','parasite');$proc=new SymbioteProcessor($s,new Engine());
syCheck($proc->hosts($s->cards[1])===[20],'parasite can choose opponent front row');
$s->cards[20]->row=5;syCheck($proc->hosts($s->cards[1])===[],'parasite cannot choose deeper opponent row');
$s->cards[20]->row=3;syCheck($proc->hosts($s->cards[1])===[20],'parasite can choose invading enemy');
$s=syState();syAdd($s,1);syAdd($s,2);$s->cards[11]=new CardInstance(11,'other','host','field',row:2,col:3,hp:5,hpMax:5);
(new TurnProcessor($s,new Engine()))->startBattle();$p=$s->battle[SymbioteProcessor::PENDING];syCheck($p['source_id']===null,'player chooses source when several symbiotes');
$r=(new Engine())->apply($s,'host',new Command('choose_symbiote_host',['choice_id'=>$p['id'],'card_id'=>2]));syCheck($r->success,'explicit source selection');syChoose($s,10);
syCheck(!in_array(10,(new SymbioteProcessor($s,new Engine()))->hosts($s->cards[1]),true),'occupied host excluded');
$p=$s->battle[SymbioteProcessor::PENDING];syCheck(!(new Engine())->apply($s,'host',new Command('choose_symbiote_host',['choice_id'=>$p['id'],'target_id'=>10]))->success,'second symbiote rejected on occupied host');syChoose($s,11);
$s=syState();syAdd($s,1);syAdd($s,2,'player','parasite');(new TurnProcessor($s,new Engine()))->startBattle();syChoose($s,10);syCheck($s->cards[2]->zone==='graveyard','later parasite with no remaining host dies after seating');
$s=syState();$s->getPlayer('player')->side=1;$s->getPlayer('host')->side=2;syAdd($s,1);syAdd($s,2,'player');(new TurnProcessor($s,new Engine()))->startBattle();syCheck($s->battle[SymbioteProcessor::PENDING]['owner']==='player','first player order is based on side, not host role');
$s=syState();syAdd($s,1);$s->cards[1]->flags['symbiote_host_id']=10;$s->cards[1]->row=2;$s->cards[1]->col=2;$s->cards[1]->owner='player';$s->battle=['active'=>'host','turn'=>1];
$info=[];foreach($s->cards as$c)$info[$c->ukid]=['name'=>$c->ukid,'price'=>1];
$d=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$info,['sel'=>1])['data'];
syCheck(str_contains($d['field_html'],'data-cell-counter aria-label="Карт в клетке: 2">2</span>'),'marker is total 2');
syCheck(strpos($d['field_html'],'data-cell-occupant="10"')<strpos($d['field_html'],'data-cell-occupant="1"'),'host displayed first even with larger ID');
syCheck(str_contains($d['panel_html'],'sym1'),'selected parasite retains own panel');
syCheck($s->cards[1]->owner==='player'&&$s->cards[10]->owner==='host','ownership remains independent');
$s=syState();syAdd($s,1);$s->cards[1]->flags['symbiote_host_id']=10;$s->cards[1]->row=2;$s->cards[1]->col=2;$s->cards[1]->markers['rooted']=true;$s->battle=['active'=>'host','turn'=>1];
syCheck(!(new Engine())->apply($s,'host',new Command('move',['card_id'=>10,'row'=>2,'col'=>3]))->success,'host respects existing rooted movement restriction of follower');
$s=syState();syAdd($s,1);$s->cards[1]->flags['symbiote_host_id']=10;$s->cards[1]->prop['lose_coins_on_move']=true;$s->cards[1]->coins=2;$s->cards[1]->row=2;$s->cards[1]->col=2;$s->battle=['active'=>'host','turn'=>1];
syCheck((new Engine())->apply($s,'host',new Command('move',['card_id'=>10,'row'=>2,'col'=>3]))->success,'host moves with follower effect');
syCheck($s->cards[1]->coins===0,'follower uses native movement effects');
$s=syState();syAdd($s,1,'host','parasite');$s->cards[20]->row=5;(new TurnProcessor($s,new Engine()))->startBattle();syCheck($s->cards[1]->zone==='graveyard','parasite without reachable enemy host dies');
$s=syState();syAdd($s,1);$s->cards[1]->flags['symbiote_host_id']=10;$s->cards[10]->prop['incorporeal']=true;SymbioteProcessor::refresh($s);syCheck($s->cards[1]->zone==='graveyard'||$s->cards[1]->dying,'host corporeality change kills corporeal follower');
$s=syState();syAdd($s,1);$s->historyRecorder=new \Berserk\Core\BattleHistory\Recorder($s->gameId,1,[],[],true);(new TurnProcessor($s,new Engine()))->startBattle();syChoose($s,10);syCheck(count($s->historyRecorder->batch($s)->data()['captures'])>0,'initial history resumes after seating');
$s=syState();syAdd($s,1);$s->cards[1]->flags['symbiote_host_id']=10;(new ZoneManager($s))->toDeck($s->cards[1]);syCheck(empty($s->cards[1]->flags['symbiote_host_id']),'leaving follower cannot retain stale host link');
echo "Symbiotes: $n checks passed (no DB).\n";
