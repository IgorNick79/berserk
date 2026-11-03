<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,GameSettings,CardInstance,TurnProcessor,Engine};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$s=new GameState((int)$argv[1],1,2);$s->journalSchema=1;$s->status='battle';$s->mode='lucktest';$s->players['host']->side=1;$s->players['player']->side=2;$s->firstPlayer='host';
$input=['turn_time_mode'=>$argv[2]??'adaptive','turn_fixed_seconds'=>$argv[5]??'225','turn_creature_seconds'=>$argv[6]??'30'];
if($input['turn_time_mode']==='legacy')$input=['turn_seconds'=>'180'];
$s->settings=GameSettings::fromArray(Berserk\Lobby\RoomSetup::parse('lucktest',$input)['settings']);
foreach(['host'=>(int)($argv[3]??6),'player'=>(int)($argv[4]??2)] as $owner=>$count)for($i=0;$i<$count;$i++){
 $prop=(($argv[7]??'')==='pending'&&$owner==='host'&&$i===0)?['prophecy'=>true]:[];
 $s->addCard(new CardInstance(instanceId:$s->nextInstanceId(),ukid:'card_'.($i+1),owner:$owner,zone:'field',row:($owner==='host'?1:4)+intdiv($i,5),col:$i%5+1,hp:5,hpMax:5,price:3,prop:$prop));
}
(new TurnProcessor($s,new Engine()))->startBattle();
if ((int)($argv[3]??6)===0) (new Berserk\Core\DamageResolver($s))->checkGameOver();
echo json_encode($s->toArray(),JSON_THROW_ON_ERROR);
