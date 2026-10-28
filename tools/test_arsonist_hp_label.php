<?php
require __DIR__.'/../src/Core/Autoloader.php';
\Berserk\Core\Autoloader::register();\Berserk\Core\Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$s=new \Berserk\Core\GameState(-720,1,2);
$s->addCard(new \Berserk\Core\CardInstance(instanceId:1,ukid:'target',owner:'player'));
$s->battle['turn_phase']=['pending_ack'=>['label'=>'Гном-поджигатель','items'=>[['instance_id'=>1,'delta'=>-2,'unit'=>'hp']]]];
$c=new \Berserk\Core\Choice\TurnAckChoice();
$p=$c->spec($s,'host',['target'=>['name'=>'Цель']],'?game=1','host');
if(!str_contains($p->title,'Цель −2 ❤️ HP'))throw new RuntimeException('Missing HP label');
$s->battle['turn_phase']['pending_ack']['items'][0]=['instance_id'=>1,'delta'=>2];
$p=$c->spec($s,'host',['target'=>['name'=>'Цель']],'?game=1','host');
if(str_contains($p->title,'❤️'))throw new RuntimeException('Coin delta mislabeled');
$s->battle['turn_phase']['pending_ack']['items'][0]=['instance_id'=>1,'delta'=>0,'unit'=>'hp'];
$p=$c->spec($s,'host',['target'=>['name'=>'Цель']],'?game=1','host');
if(!str_contains($p->title,'урон предотвращён'))throw new RuntimeException('Missing prevention label');
// Exercise both auto-run and manually selected tasks without an opposite target.
foreach([false,true] as $manual){
 $s=new \Berserk\Core\GameState(-721,1,2);$s->status='battle';$s->battle=['turn'=>2,'active'=>'host'];
 $s->addCard(new \Berserk\Core\CardInstance(instanceId:1,ukid:'arsonist',owner:'host',zone:'field',row:3,col:2,hp:5,hpMax:5));
 $task=['id'=>'fire','type'=>'turn_start','card_id'=>1,'payload'=>['type'=>'damage','target'=>'opposite','value'=>2]];
 $s->battle['turn_phase']=['phase'=>'start','side'=>'active','active_key'=>'host','passive_key'=>'player','active_queue'=>[$task],'passive_queue'=>[],'sub'=>null,'pending_ack'=>null];
 $tp=new \Berserk\Core\TurnPhaseProcessor($s,new \Berserk\Core\Engine());
 if($manual){$r=$tp->runTask('host',new \Berserk\Core\Command('turn_task',['task_id'=>'fire']));if(!$r->success)throw new RuntimeException($r->error);}else $tp->resume();
 if(!empty($s->battle['turn_phase']))throw new RuntimeException('Empty damage task still requires ACK');
}
foreach([['Яд',-1,'−1 ❤️ HP'],['Регенерация',1,'+1 ❤️ HP']] as [$label,$delta,$expected]){
 $s->battle['turn_phase']=['pending_ack'=>['label'=>$label,'items'=>[['instance_id'=>1,'delta'=>$delta,'unit'=>'hp']]]];
 $p=$c->spec($s,'host',[],'?game=1','host');if(!str_contains($p->title,$expected))throw new RuntimeException('Missing HP for '.$label);
}
echo "PASS 7 checks; FAIL 0\n";
