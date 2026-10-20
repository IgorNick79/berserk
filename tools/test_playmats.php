<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,PlaymatCatalog as Mats,GameState,Db,GameRepository,StaleStateException,TurnTimer};
use Berserk\View\Template;
use Berserk\View\Screen\{BattleScreen,DraftScreen,ViewScreen};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$count=0;function check(bool $value,string $label):void {global $count;$count++;if(!$value)throw new RuntimeException($label);}
$s=new GameState(1,1,2);check($s->getPlayer('host')->playmatId===null,'Legacy default');
check(Mats::url(null)===Mats::DEFAULT_URL&&Mats::url('invalid')===Mats::DEFAULT_URL,'Fallback URL');
check(count(Mats::ITEMS)===18,'18 stable IDs');
foreach(Mats::ITEMS as $id=>$url){check(is_file(__DIR__.'/../www'.$url),'Local asset '.$id);}
Mats::assign($s->players['host']);Mats::assign($s->players['player']);
check(isset(Mats::ITEMS[$s->players['host']->playmatId],Mats::ITEMS[$s->players['player']->playmatId]),'Independent assignments');
$id=$s->players['host']->playmatId;Mats::assign($s->players['host']);check($s->players['host']->playmatId===$id,'Assignment idempotent');
$restored=GameState::fromArray($s->toArray());check($restored->players['host']->playmatId===$id,'JSON roundtrip');
$legacy=$s->toArray();unset($legacy['players']['host']['playmat_id']);check(GameState::fromArray($legacy)->players['host']->playmatId===null,'Missing legacy property');
$late=new \Berserk\Core\PlayerState(0);Mats::assign($late);check($late->playmatId===null,'Absent participant not assigned');$late->userId=3;Mats::assign($late);check(isset(Mats::ITEMS[$late->playmatId]),'Late assignment policy');
$token=Mats::renderToken($s);$s->players['host']->playmatId='mat-018';check(Mats::renderToken($s)===$token,'Cosmetic token stable');$s->battle['pending']=['choice'=>1];check(Mats::renderToken($s)!==$token,'Pending changes invalidate render token');
$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];$s->players['host']->playmatId='mat-001';$s->players['player']->playmatId='mat-018';
$tpl=new Template(__DIR__.'/../templates');foreach(['host','player'] as $viewer){$view=(new BattleScreen($tpl))->prepare($s,$viewer,$viewer,null,[])['data'];check($view['own_playmat']===Mats::url($s->players[$viewer]->playmatId),'Own below '.$viewer);check($view['opponent_playmat']===Mats::url($s->players[$s->getOpponentKey($viewer)]->playmatId),'Opponent above '.$viewer);}
// PreGame uses the selected player's image without changing its presentation structure.
foreach(['host','player'] as $viewer){
 $draft=(new DraftScreen($tpl))->prepare($s,$viewer,$viewer,null,[])['data'];
 check($draft['playmat_url']===Mats::url($s->players[$viewer]->playmatId),'Draft player mat '.$viewer);
 $deckView=(new ReflectionClass(\Berserk\Core\DeckView::class))->newInstanceWithoutConstructor();
 $s->addCard(new \Berserk\Core\CardInstance(instanceId:$viewer==='host'?501:502,ukid:'mat-deck-fixture',owner:$viewer,zone:\Berserk\Core\CardInstance::ZONE_DECK,hp:5,hpMax:5));
 // Runtime deck rendering avoids any database adapter in this presentation test.
 $view=(new ViewScreen($deckView,$tpl))->prepare($s,$viewer,$viewer,null,[])['data'];
 check($view['playmat_url']===Mats::url($s->players[$viewer]->playmatId),'Deck player mat '.$viewer);
}
if(!in_array('--db',$argv,true)){echo "Playmat unit/view: $count checks passed\n";exit;}
$config=require __DIR__.'/../config/db.php';check($config['name']==='berserk_playmat_test'&&!is_dir(__DIR__.'/../.git'),'Disposable DB only');
$db=new Db($config);$meta=$db->fetchOne('SELECT @@datadir AS dir');check(str_contains(str_replace('\\','/',$meta['dir']),'/Berserk/playmat-tests/data/'),'Disposable datadir');$repo=new GameRepository($db);
$s=$repo->create(1,2);check(isset(Mats::ITEMS[$s->players['host']->playmatId],Mats::ITEMS[$s->players['player']->playmatId]),'Creation assigns both');$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null,'pending_any_death'=>[['id'=>9]],'turn_timer'=>['status'=>'paused','remaining_seconds'=>123]];$repo->save($s);
$before=$s->toArray();$render=Mats::renderToken($s);$stale=$repo->findById($s->gameId);$opponent=$s->players['player']->playmatId;
$s=$repo->setPlaymat($s->gameId,1,'mat-017');$expected=$before;$expected['players']['host']['playmat_id']='mat-017';check($s->toArray()===$expected,'Only own cosmetic JSON changed');check($s->players['player']->playmatId===$opponent,'Opponent untouched');check(Mats::renderToken($s)===$render,'No gameplay invalidation');
$stale->battle['action']='stale';try{$repo->save($stale);check(false,'Stale save rejected');}catch(StaleStateException){check(true,'Stale save rejected');}
$fresh=$repo->findById($s->gameId);$fresh->battle['action']='committed';$repo->save($fresh);$after=$repo->setPlaymat($s->gameId,2,'mat-016');check($after->battle['action']==='committed'&&$after->players['host']->playmatId==='mat-017','Cosmetic after action retains both');
foreach([[999,'mat-001'],[1,'bad']] as [$actor,$mat]){try{$repo->setPlaymat($s->gameId,$actor,$mat);check(false,'Invalid rejected');}catch(InvalidArgumentException){check(true,'Invalid rejected');}}
$version=$after->persistenceVersion;check($repo->setPlaymat($s->gameId,2,'mat-016')->persistenceVersion===$version,'Same ID no-op');
// Three simultaneous DB connections: gameplay CAS versus both cosmetic writers.
$children=[];
foreach(['action','host','player'] as $actor){
 $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/test_playmat_race.php',(string)$s->gameId,$actor],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($proc))throw new RuntimeException('Cannot launch race');fclose($pipes[0]);$children[]=[$proc,$pipes];
}
foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'Concurrent worker: '.$out.$err);}
$race=$repo->findById($s->gameId);check($race->battle['race_actions']===25&&$race->players['host']->playmatId==='mat-017'&&$race->players['player']->playmatId==='mat-016','All gameplay actions and both mats survive concurrent writes');
// HTTP fixture has a paused timer and no automatic pending choice.
$http=$repo->create(1,2);$http->status='battle';$http->battle=['active'=>'host','turn'=>1,'strike'=>null];TurnTimer::prepare($http,'host');$repo->save($http);
$foreign=$repo->create(9,10);$foreign->status='battle';$foreign->battle=$http->battle;$repo->save($foreign);
file_put_contents(__DIR__.'/../tmp/playmat-fixture.json',json_encode(['game'=>$http->gameId,'foreign'=>$foreign->gameId,'token'=>Mats::renderToken($http),'version'=>$http->persistenceVersion]));
echo "Playmat unit/view/InnoDB: $count checks passed\n";
