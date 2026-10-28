<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,Dice};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function tc($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$d=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/temple-ranged.json'),true);
foreach($d['types']as$type)foreach($d['defense']as$die){
 $s=new GameState(-900,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];$e=new Engine();
 $s->addCard(new CardInstance(instanceId:1,ukid:'attacker',owner:'host',zone:'field',row:3,col:3,hp:10,hpMax:10,prop:['actions'=>[['key'=>'attack','type'=>$type,'value'=>2,'poison'=>1]]]));
 $s->addCard(new CardInstance(instanceId:2,ukid:'temple',owner:'player',zone:'field',row:5,col:3,hp:10,hpMax:10,prop:['ranged_block_roll'=>['types'=>['uchr','throw','shot'],'block_on'=>[5,6]]]));
 if($type==='uchr')$s->addCard(new CardInstance(instanceId:3,ukid:'middle',owner:'host',zone:'field',row:4,col:3,hp:10,hpMax:10));
 $_SESSION=['debug_roll'=>'4,'.$die.',2'];Dice::init();
 $cmd=new Command($type==='uchr'?'uchr':'action',['card_id'=>1,'target_id'=>2,'action_key'=>'attack']);
 $r=$e->apply($s,'host',$cmd);tc($r->success,$r->error??'attack');tc($s->battle['strike']['temple_defense']['die']===$die,'defense stored');
 tc($s->getCard(2)->hp===($die>=5?10:8),'damage');if($die>=5)tc(empty($s->getCard(2)->markers['poison']),'no poison');
 foreach(['host','player'] as $viewer){
  $_GET=[];
  $html=(new \Berserk\View\Screen\Battle\InfoPanel(new \Berserk\View\Template(__DIR__.'/../templates')))->render($s,$viewer,$viewer,['attacker'=>['name'=>'Атакующий'],'temple'=>['name'=>'Воин храма']], '?game=1');
  tc(str_contains($html,'class="dice">') && str_contains($html,'Защитник'),'block die in native dice result');
  tc(str_contains($html,'Атака заблокирована')===($die>=5),'block result in native result');
 }
 $s=GameState::fromArray($s->toArray());$before=Dice::remaining();tc(!$e->apply($s,'host',$cmd)->success,'replay rejected');tc(Dice::remaining()===$before,'no extra roll');
}
// Only configured types roll; changing successful values is data-driven too.
$s=new GameState(-901,1,2);$s->status='battle';$s->battle=['strike'=>[]];
$t=new CardInstance(instanceId:1,ukid:'config',owner:'host',prop:['ranged_block_roll'=>['types'=>['shot'],'block_on'=>[4]]]);
$_SESSION=['debug_roll'=>'4,6'];Dice::init();
tc(!\Berserk\Core\TempleWarrior::rangedBlock($s,$t,'throw'),'unlisted type not checked');tc(Dice::remaining()===[4,6],'unlisted type no roll');
tc(\Berserk\Core\TempleWarrior::rangedBlock($s,$t,'shot'),'configured value 4 blocks');
tc(!\Berserk\Core\TempleWarrior::rangedBlock($s,$t,'shot'),'unlisted value 6 not blocked');
echo "PASS $n checks; FAIL 0\n";
