<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,DeckReorderProcessor,ProphecyProcessor};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function ck($v,$m){global $n;++$n;if(!$v)throw new RuntimeException($m);}
$scenarios=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/deck_reorder.json'),true);
foreach($scenarios['directions'] as $direction)foreach($scenarios['deck_sizes'] as $size){
 $s=new GameState(-955,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>2];$e=new Engine();
 $c=new CardInstance(instanceId:1,ukid:'seer',owner:'player',zone:'field',row:4,col:1,hp:5,hpMax:5);$s->addCard($c);
 for($i=0;$i<$size;$i++){$d=new CardInstance(instanceId:10+$i,ukid:'same',owner:'player',zone:'deck');$d->order=$i;$s->addCard($d);}
 $p=new DeckReorderProcessor($s,$e);ck($p->start($c)===(bool)$size,'empty deck');if(!$size)continue;
 $run=function($action,$extra=[])use(&$s,$e){$p=$s->battle[DeckReorderProcessor::PENDING];$cmd=new Command('choose_deck_reorder',$extra+['action'=>$action,'operation_id'=>$p['id'],'revision'=>$p['revision']]);$r=(new DeckReorderProcessor($s,$e))->choose('player',$cmd);ck($r->success,'command');ck(!(new DeckReorderProcessor($s,$e))->choose('player',$cmd)->success,'replay');$s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));};
 $run('show');$ids=$s->battle[DeckReorderProcessor::PENDING]['remaining'];ck($ids===range(10,9+min(3,$size)),'correct peek');$run('next',['direction'=>$direction]);
 foreach(array_reverse($ids) as $id)$run('pick',['card_id'=>$id]);
 ck(empty($s->battle[DeckReorderProcessor::PENDING]),'auto close');$deck=(new ProphecyProcessor($s,$e))->peek('player',99);$actual=array_map(fn($c)=>$c->instanceId,$deck['cards']);$rest=$size>3?range(13,9+$size):[];$edge=array_reverse($ids);$expected=$direction==='top'?array_merge($edge,$rest):array_merge($rest,array_reverse($edge));ck($actual===$expected,'edge order');
}
// Finish at either stage preserves the selected edge prefix and the untouched tail.
foreach(['top','bottom'] as $direction)foreach([0,1,2] as $manual){
 $s=new GameState(-957,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>2];$e=new Engine();
 $c=new CardInstance(instanceId:1,ukid:'seer',owner:'player',zone:'field',row:4,col:1,hp:5,hpMax:5);$s->addCard($c);
 foreach(range(10,14) as $id){$d=new CardInstance(instanceId:$id,ukid:'same',owner:'player',zone:'deck');$d->order=$id;$s->addCard($d);}
 (new DeckReorderProcessor($s,$e))->start($c);$run('show');
 if($manual){$run('next',['direction'=>$direction]);foreach(array_slice([11,10],0,$manual) as $id)$run('pick',['card_id'=>$id]);$run('finish');}else $run('finish',['direction'=>$direction]);
 $deck=(new ProphecyProcessor($s,$e))->peek('player',99);$ids=array_map(fn($c)=>$c->instanceId,$deck['cards']);
 ck(count(array_unique($ids))===5,'random finish conserves cards');$edge=$direction==='top'?array_slice($ids,0,3):array_reverse(array_slice($ids,-3));
 ck(array_slice($edge,0,$manual)===array_slice([11,10],0,$manual),'chosen prefix unchanged');ck(array_values(array_filter($ids,fn($id)=>$id>=13))===[13,14],'untouched relative order');
}
// Native active discharge: turn cost, range and damage.
foreach([3,6] as $row){
 $s=new GameState(-956,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>2,'strike'=>null,'hidden_row_revealed'=>true];
 $c=new CardInstance(instanceId:1,ukid:'seer',owner:'host',zone:'field',row:1,col:1,hp:5,hpMax:5,prop:['actions'=>[['key'=>'discharge','type'=>'discharge','tap'=>true,'value'=>1,'range'=>4]]]);
 $t=new CardInstance(instanceId:2,ukid:'target',owner:'player',zone:'field',row:$row,col:1,hp:5,hpMax:5);$s->addCard($c);$s->addCard($t);$e=new Engine();
 $r=$e->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'discharge','target_id'=>2]));
 ck($r->success===($row===3),'native discharge range');ck($c->closed===($row===3),'native discharge tap');ck($t->hp===($row===3?4:5),'native discharge wounds');
}
echo "PASS $n checks; FAIL 0\n";
