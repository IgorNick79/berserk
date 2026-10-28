<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance};
use Berserk\View\Ui\ActiveCardEffects;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$s=new GameState(-710,1,2);$s->status='battle';
foreach([1,2] as $id)$s->addCard(new CardInstance(instanceId:$id,ukid:'line',owner:'host',zone:'field',row:3,col:$id,hp:5,hpMax:5,prop:['has_line'=>true]));
$n=0;function ck($v){global $n;$n++;if(!$v)throw new RuntimeException('Formation label failed');}
foreach($s->cards as $c)ck(in_array('Строй',ActiveCardEffects::forCard($c,$s),true));
$s->getCard(2)->col=4;ck(!in_array('Строй',ActiveCardEffects::forCard($s->getCard(1),$s),true));
$s->getCard(2)->col=2;$s->getCard(2)->owner='player';ck(!in_array('Строй',ActiveCardEffects::forCard($s->getCard(1),$s),true));
$s->getCard(2)->owner='host';$s->getCard(2)->row=4;ck(!in_array('Строй',ActiveCardEffects::forCard($s->getCard(1),$s),true));
echo "PASS $n checks; FAIL 0\n";
