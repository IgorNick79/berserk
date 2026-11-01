<?php
declare(strict_types=1);
// In-memory Engine fixture for browser presentation/gesture checks. No database.
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command};
use Berserk\View\{Template};
use Berserk\View\Screen\PlaceScreen;
use Berserk\View\Ui\PreGameLayout;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$s=new GameState(970004,1,2);$s->status='place';$catalog=[];
$art=glob(__DIR__.'/../www/assets/cards/*/*.webp');
for($id=1;$id<=4;$id++){
    $ukid=$art?basename($art[($id-1)%count($art)],'.webp'):'fixture-'.$id;
    $s->addCard(new CardInstance(instanceId:$id,ukid:$ukid,owner:'host',zone:'squad',hp:5,hpMax:5,price:3,moveMax:1));
    $catalog[$ukid]=['name'=>'Fixture card','health'=>5,'move'=>1,'price'=>3,'strike'=>['weak'=>1,'medium'=>2,'strong'=>3]];
}
$engine=new Engine();$error=null;
foreach(json_decode($argv[1]??'[]',true,512,JSON_THROW_ON_ERROR) as $payload){$r=$engine->apply($s,'host',new Command($payload['cmd'],$payload));if(!$r->success)$error=$r->error;}
$_GET=json_decode($argv[2]??'{}',true,512,JSON_THROW_ON_ERROR);
$tpl=new Template(__DIR__.'/../templates');$data=(new PlaceScreen($tpl))->prepare($s,'host','host',$error,$catalog)['data'];
$html=(new PreGameLayout($tpl))->renderScreen('place',$data);
$page='<html class="pregame-page battle-page place-page roll-page"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/global.css"><link rel="stylesheet" href="/assets/css/pregame.css"><link rel="stylesheet" href="/assets/css/game-menu.css"><script data-stage="place" data-version="'.$s->version.'"></script><script>window.berserkGameCsrf="fixture";</script></head><body class="pregame-page battle-page place-page roll-page"><div class="screen">'.$html.'</div></body></html>';
echo json_encode(['html'=>$page,'error'=>$error,'state'=>$s->toArray()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
