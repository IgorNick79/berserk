<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,PathfinderProcessor};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$n=0;function ck($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$d=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/pathfinder.json'),true);
foreach($d['cases'] as $case){
 $s=new GameState(-800,1,2);$s->status='place';$s->players[$case['first']]->side=1;$s->players[$s->getOpponentKey($case['first'])]->side=2;$tick=1000;$e=new Engine(clock:static function()use(&$tick):int{return ++$tick;});
 foreach(['host','player'] as $i=>$o)for($c=1;$c<=max(1,$case[$o.'_count']);$c++)$s->addCard(new CardInstance(instanceId:$i*10+$c,ukid:$o.'-'.$c,owner:$o,zone:'field',row:$i?4:3,col:$c,hp:5,hpMax:5,prop:$c<=$case[$o.'_count']?['pathfinder_rearrange'=>true,'actions'=>[['type'=>'shot','value'=>1,'tap'=>true]]]:[],flags:['custom'=>true]));
 ck($e->apply($s,'host',new Command('confirm_place'))->success,'first confirmation');ck($s->status==='place','wait both');
 ck($e->apply($s,'player',new Command('confirm_place'))->success,'second confirmation');
 $expected=[];foreach([$case['first'],$s->getOpponentKey($case['first'])]as$o)if($case[$o.'_count'])$expected[]=$o;
 if(!$expected){ck($s->status==='battle','no scouts normal start');continue;}
 ck(array_column($s->battle['pathfinder']['queue'],'owner')===$expected,'one right per owner ordered');
 ck(!isset($s->battle['turn_timer']),'no timer before battle');
 foreach($expected as $step=>$o){
  ck($s->status==='place','still place');$other=$s->getOpponentKey($o);
  ck(!$e->apply($s,$other,new Command('pathfinder_choice',['step'=>$step,'choice'=>'start']))->success,'waiting player denied');
  $id=$o==='host'?1:11;ck($s->getCard($id)->revealed&&$s->getCard($id)->flags['pathfinder_revealed'],'scout revealed');
  $s->addCard(new CardInstance(instanceId:99,ukid:'hidden-secret',owner:$other,zone:'field',row:$other==='host'?1:6,col:5,hp:5,hpMax:5));
  $catalog=[];foreach($s->cards as $card)$catalog[$card->ukid]=['name'=>$card->ukid];
  $_GET=$_SESSION=[];
  $screen=new \Berserk\View\Screen\PlaceScreen(new \Berserk\View\Template(__DIR__.'/../templates'));
  $view=$screen->prepare($s,$o,$o,null,$catalog)['data'];
  ck(!str_contains($view['field_html'],'hidden-secret'),'hidden rear absent from field');
  ck(!str_contains(json_encode($view),'hidden-secret'),'hidden rear absent from complete place projection');
  $_GET=['card'=>99];$inspected=$screen->prepare($s,$o,$o,null,$catalog)['data'];
  ck(!str_contains(json_encode($inspected),'hidden-secret'),'enemy hidden inspection denied');$_GET=[];
  ck(str_contains($view['confirm_html'],'Переставить карты'),'choice buttons visible');
  $wait=$screen->prepare($s,$other,$other,null,$catalog)['data'];
  ck(str_contains($wait['confirm_html'],'У соперника Следопыт'),'waiting text');
  $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
  if ($step===0 && !empty($case['skip_first'])) {
   ck($e->apply($s,$o,new Command('pathfinder_choice',['step'=>$step,'choice'=>'start']))->success,'decline rearrangement');
   ck(!$e->apply($s,$o,new Command('pathfinder_choice',['step'=>$step,'choice'=>'start']))->success,'decline replay denied');
   continue;
  }
  ck($e->apply($s,$o,new Command('pathfinder_choice',['step'=>$step,'choice'=>'rearrange']))->success,'begin rearrange');
  ck(!$e->apply($s,$o,new Command('pathfinder_choice',['step'=>$step,'choice'=>'rearrange']))->success,'replay rejected');
  ck(!$e->apply($s,$other,new Command('unplace_card',['card_id'=>$other==='host'?1:11]))->success,'waiting edit rejected');
  ck($e->apply($s,$o,new Command('unplace_card',['card_id'=>$id]))->success,'unplace own');
  ck($s->getCard($id)->flags['custom'],'custom state retained');
  ck(!$e->apply($s,$o,new Command('place_card',['card_id'=>$id,'row'=>$o==='host'?4:3,'col'=>5]))->success,'enemy half denied');
  ck($e->apply($s,$o,new Command('place_card',['card_id'=>$id,'row'=>$o==='host'?2:5,'col'=>2]))->success,'reposition own');
  ck($e->apply($s,$o,new Command('confirm_place'))->success,'confirm rearrangement');
 }
 ck($s->status==='battle','battle starts after all');ck($s->getFirstPlayerKey()===$case['first'],'first preserved');
 if($case[$case['first'].'_count']) {
  $sourceId=$case['first']==='host'?1:11;$targetId=$case['first']==='host'?11:1;
  $beforeHp=$s->getCard($targetId)->hp;
  $r=$e->apply($s,$case['first'],new Command('action',['card_id'=>$sourceId,'action_key'=>'shot','target_id'=>$targetId]));
  ck($r->success,'native shot available: '.$r->error);ck($s->getCard($sourceId)->closed,'shot closes scout');ck($s->getCard($targetId)->hp===$beforeHp-1,'shot on one');
 }
 $started=$s->battle['started_at'];ck(!$e->apply($s,'host',new Command('confirm_place'))->success,'no second start');ck($s->battle['started_at']===$started,'start timestamp unchanged');
}
echo "PASS $n checks; FAIL 0\n";
