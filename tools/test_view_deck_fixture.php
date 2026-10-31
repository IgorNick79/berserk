<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,GameSettings};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$s=new GameState((int)($argv[1]??1),1,2);$s->status='view';$s->mode=GameSettings::MODE_SEALED;
$paths=array_slice(glob(__DIR__.'/../www/assets/cards/s1/*.webp'),0,19);
if (!$paths) $paths=array_map(static fn($i)=>'s1_view_test_'.$i.'.webp',range(1,19));
foreach (['host','player'] as $owner) {
    $s->getPlayer($owner)->deckId=0;
    foreach ($paths as $path) for ($copy=0;$copy<2;$copy++) {
        $c=new CardInstance(instanceId:$s->nextInstanceId(),ukid:basename($path,'.webp'),owner:$owner,zone:'deck',hp:5,hpMax:5,price:3,move:1,moveMax:1,strikeWeak:1,strikeMedium:2,strikeStrong:3,element:'neutral');$s->addCard($c);
    }
}
if (($argv[2]??'')==='battle') {
    $s->status='battle';$s->getPlayer('host')->side=1;$s->getPlayer('player')->side=2;$s->firstPlayer='host';
    foreach(['host','player'] as $owner) { foreach($s->cards as $c)if($c->owner===$owner){$c->zone='field';$c->row=$owner==='host'?3:4;$c->col=3;break;} }
    (new Berserk\Core\TurnProcessor($s,new Berserk\Core\Engine()))->startBattle();
}
echo json_encode($s->toArray(),JSON_THROW_ON_ERROR);
