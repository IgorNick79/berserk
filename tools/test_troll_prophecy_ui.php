<?php
declare(strict_types=1);
define('C1_FIXTURES_ONLY',true);require __DIR__.'/test_single_roll_c1.php';
use Berserk\Core\{CardInstance,Dice,Engine,Command,GameState,ProphecyProcessor};
use Berserk\View\{Template,Screen\BattleScreen};
$fixtures=[];$screen=new BattleScreen(new Template(getcwd().'/templates'));
foreach(['host','player']as$owner)foreach([true,false]as$elite){
 $s=c1state('strike',false);$s->nextInstanceId=100;$s->battle['active']=$owner;
 if($owner==='player')foreach($s->cards as$c)$c->owner=$c->owner==='host'?'player':'host';
 $s->getCard(1)->ukid='s1_132';$s->getCard(2)->ukid='s1_151';$s->getCard(1)->closed=false;
 $s->getCard(1)->prop=['strike_prophecy'=>['count'=>2,'all_elite'=>['damage'=>2],'all_ordinary'=>['poison'=>2]]];
 foreach([10,11]as$id)$s->addCard(new CardInstance(instanceId:$id,ukid:'shown_'.$id,owner:$owner,zone:'deck',order:$id,elite:$elite,hp:5,hpMax:5));
 $info=[];foreach($s->cards as$c)$info[$c->ukid]=['name'=>match($c->ukid){'s1_132'=>'Двухголовый тролль','s1_151'=>'Зомби',default=>$c->ukid},'prop'=>$c->prop];
 $render=fn(GameState $state,string $viewer)=>$screen->prepare($state,$viewer,$viewer,null,$info,['inspect'=>2])['data']['info_panel_html'];
 $before=$render($s,$owner);$_SESSION=['debug_roll'=>'6,1'];Dice::init();$e=new Engine();
 c1check($e->apply($s,$owner,new Command('strike',['card_id'=>1,'target_id'=>2]))->success,'Native Troll strike');
 c1check(($s->battle['pending_prophecy']['context']??null)==='strike','Native prophecy opened');
 c1check($s->battle['pending_prophecy']['title_target_ukid']==='s1_151','Target identity retained');
 if(!$elite)c1check(($s->getCard(2)->markers['poison']['value']??null)===2,'Native poison applied');
 $expected=$elite?'Зомби получает +2 урона':'Зомби: отравление 2';
 foreach(['host','player']as$viewer){
  $html=$render($s,$viewer);$d=new DOMDocument();@$d->loadHTML('<?xml encoding="UTF-8">'.$html);$x=new DOMXPath($d);
  c1check(str_contains($html,$expected),'Both viewers see named damage/poison result');
  c1check(!str_contains($x->query('//div[contains(@class,"card-choice")]/h3')->item(0)->textContent,'s1_151'),'No technical card code in title');
  c1check(str_contains($html,'data-instant-result="prophecy:'),'Both viewers foreground prophecy over retained card');
  c1check($x->query('//a[contains(@href,"cmd=close_prophecy")]')->length===($viewer===$owner?1:0),'Only prophecy owner can close');
 }
 $result=$render($s,$owner);$hp=$s->getCard(2)->hp;$markers=$s->getCard(2)->markers;
 $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));
 c1check(str_contains($render($s,$owner),$expected),'Names survive F5');
 $p=new ProphecyProcessor($s,$e);c1check(!$p->close($s->getOpponentKey($owner))['ok'],'Opponent close rejected');
 c1check($p->close($owner)['ok']&&$s->getCard(2)->hp===$hp&&$s->getCard(2)->markers===$markers,'Close never repeats damage or poison');
 $fixtures[$owner.($elite?'_damage':'_poison')]=['before'=>$before,'result'=>$result,'expected_text'=>$expected,'confirm_label'=>'Закрыть'];
 $legacy=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true));$legacy->battle['pending_prophecy']=['owner'=>$owner,'source_id'=>1,'source_ukid'=>'s1_132','context'=>'strike','shown_ukids'=>[],'actions'=>[],'title'=>$elite?'обе элитные — s1_151 получает +2 урона':'обе рядовые — отравление 2'];
 c1check(str_contains($render($legacy,$owner),$expected),'Already saved damage/poison title resolves card name');
}
$dir=getenv('TROLL_UI_FIXTURES');if($dir)foreach($fixtures as$key=>$data)file_put_contents($dir.'/troll-'.$key.'.json',json_encode($data,JSON_THROW_ON_ERROR));
echo "Troll prophecy UI: $checks checks passed\n";
