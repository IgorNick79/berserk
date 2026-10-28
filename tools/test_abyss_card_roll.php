<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,CardRoll,Dice};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function ck($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$s=new GameState(-500,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1];
foreach([1,2,3,4] as $id)$s->addCard(new CardInstance(instanceId:$id,ukid:'fixture',owner:$id<3?'host':'player',zone:'field',row:$id,col:2,hp:10,hpMax:10,prop:$id<3?['zoz'=>true,'next_six_on_enemy_death_or_ally_incarnation'=>true]:[]));
$e=new Engine();$e->forceDeath($s,$s->getCard(3),'destroy');
ck($s->getCard(1)->flags[CardRoll::READY]===true,'enemy death arms first');ck($s->getCard(2)->flags[CardRoll::READY]===true,'independent second');
$s=GameState::fromArray($s->toArray());$_SESSION=['debug_roll'=>'2,3,4'];Dice::init();
ck(CardRoll::roll($s,$s->getCard(1))===6,'raw six');ck(Dice::remaining()===[2,3,4],'forced six preserves debug queue');
ck(!$s->getCard(1)->flags[CardRoll::READY],'consumed');ck($s->getCard(2)->flags[CardRoll::READY],'other card unconsumed');
ck(Dice::roll()===2,'instant reroll is ordinary');ck(!$s->getCard(1)->flags[CardRoll::READY],'reroll does not restore');
$s=GameState::fromArray($s->toArray());ck(CardRoll::roll($s,$s->getCard(1))===3,'next ordinary primary roll');
ck(CardRoll::roll($s,$s->getCard(4))===4,'other card uses normal queue');ck($s->getCard(1)->prop['zoz']===true,'zoz retained');
echo "PASS $n checks; FAIL 0\n";
