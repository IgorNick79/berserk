<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Command,Engine,ZoneManager};
use Berserk\View\{Template};
use Berserk\View\Screen\PlaceScreen;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$checks=0;
function prCheck(bool $ok,string $message):void {global $checks;++$checks;if(!$ok)throw new RuntimeException($message);}
function prFixture(string $owner):GameState {
    $s=new GameState(-970004,1,2);$s->status='place';$row=$owner==='host'?1:4;
    $s->addCard(new CardInstance(instanceId:1,ukid:'test-card',owner:$owner,zone:'field',row:$row,col:2,hp:4,hpMax:5,flags:['preserve'=>true]));
    return $s;
}
$engine=new Engine(clock:static fn():int=>1000);
foreach(['host','player'] as $owner){
    $s=prFixture($owner);$row=$owner==='host'?2:5;$card=$s->getCard(1);$before=$card->toArray();$version=$s->version;
    $r=$engine->apply($s,$owner,new Command('place_card',['card_id'=>1,'row'=>$row,'col'=>3]));
    prCheck($r->success,'field move accepted '.$owner);
    prCheck($card->row===$row&&$card->col===3&&$card->zone==='field','coordinates updated without squad transition');
    prCheck($s->version===$version+1,'one state mutation');
    $after=$card->toArray();unset($before['row'],$before['col'],$after['row'],$after['col']);prCheck($before===$after,'card data preserved');
    foreach([['row'=>$row,'col'=>3],['row'=>0,'col'=>1],['row'=>$owner==='host'?4:1,'col'=>2]] as $target){
        $before=$s->toArray();$r=$engine->apply($s,$owner,new Command('place_card',['card_id'=>1]+$target));
        prCheck(!$r->success&&$before===$s->toArray(),'invalid move leaves state unchanged');
    }
    $s->addCard(new CardInstance(instanceId:2,ukid:'occupied',owner:$owner,zone:'field',row:$row,col:4,hp:5,hpMax:5));
    $before=$s->toArray();$r=$engine->apply($s,$owner,new Command('place_card',['card_id'=>1,'row'=>$row,'col'=>4]));
    prCheck(!$r->success&&$before===$s->toArray(),'occupied cell rejected atomically');
    $r=$engine->apply($s,$s->getOpponentKey($owner),new Command('place_card',['card_id'=>1,'row'=>$row,'col'=>2]));prCheck(!$r->success&&$before===$s->toArray(),'foreign move rejected');
    $_GET=['card'=>1];$data=(new PlaceScreen(new Template(__DIR__.'/../templates')))->prepare($s,$owner,$owner,null,['test-card'=>['name'=>'Test']])['data'];
    prCheck(str_contains($data['field_html'],'allowed move-target'),'selected field card highlights empty destinations');
    $s->getPlayer($owner)->confirm('place');$before=$s->toArray();$r=$engine->apply($s,$owner,new Command('place_card',['card_id'=>1,'row'=>$row,'col'=>2]));
    prCheck(!$r->success&&$before===$s->toArray(),'confirmed placement cannot move');
}
$s=prFixture('host');$id=1;
foreach(ZoneManager::cellLevels('host')[0] as $cell){[$row,$col]=array_map('intval',explode('_',$cell));if($row===1&&$col===2)continue;$s->addCard(new CardInstance(instanceId:++$id,ukid:'fill',owner:'host',zone:'field',row:$row,col:$col,hp:5,hpMax:5));}
$before=$s->toArray();$r=$engine->apply($s,'host',new Command('place_card',['card_id'=>1,'row'=>1,'col'=>1]));
prCheck(!$r->success&&$before===$s->toArray(),'source cannot unlock destination by occupying its old cell');
foreach(['choice','rearranging'] as $stage){$s=prFixture('host');$s->battle['pathfinder']=['stage'=>$stage,'owner'=>'host','index'=>0,'queue'=>[],'revealed_to'=>['host'=>true]];$r=$engine->apply($s,'host',new Command('place_card',['card_id'=>1,'row'=>2,'col'=>3]));prCheck($r->success===($stage==='rearranging'),'Pathfinder edit permissions '.$stage);}
$s=prFixture('host');prCheck($engine->apply($s,'host',new Command('request_pause',['duration_seconds'=>60]))->success,'request pause');
$before=$s->toArray();$r=$engine->apply($s,'host',new Command('place_card',['card_id'=>1,'row'=>2,'col'=>3]));prCheck(!$r->success&&$before===$s->toArray(),'requested pause blocks reposition');
$rid=$s->battle['match_pause']['request_id'];prCheck($engine->apply($s,'player',new Command('accept_pause',['request_id'=>$rid]))->success,'accept pause');
$before=$s->toArray();$r=$engine->apply($s,'host',new Command('place_card',['card_id'=>1,'row'=>2,'col'=>3]));prCheck(!$r->success&&$before===$s->toArray(),'active pause blocks reposition');
echo "Place reposition: $checks checks passed (in-memory Engine/renderer, no DB/HTTP claims)\n";
