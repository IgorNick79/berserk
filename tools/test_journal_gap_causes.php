<?php
declare(strict_types=1);
if (getenv('BERSERK_JOURNAL_GAP_ISOLATED')!=='1' || file_exists(__DIR__.'/../.git')) throw new RuntimeException('Disposable source copy only');
require __DIR__.'/stage31_fixtures.php';
use Berserk\Core\{GameState,Engine,Command,ZoneManager,InformationalWaitManager};
use Berserk\Core\BattleHistory\{Recorder,JournalText};
$catalog=[];foreach(range(1,12)as$id)$catalog['s31_'.$id]=['revision'=>'gap-test','name'=>'Card '.$id,'image'=>'','description'=>'','type'=>'creature','element'=>'neutral','classes'=>[],'price'=>0];
$checks=[];$traces=[];$open=[];$journal=0;
function gc(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function runGap(GameState $s,array $cat,string $side,string $type,array $payload=[]):array{
 global $open,$journal,$traces;
 $r=new Recorder(1,++$journal,$cat,array_values($open),true);$s->historyRecorder=$r;
 $result=(new Engine())->apply($s,$side,new Command($type,$payload));
 gc($result->success,$type.': '.$result->error);$data=$r->batch($s)->data();
 foreach($data['actions']as$a){if(($a['status']??'')==='open')$open[$a['id']]=['action_id'=>$a['id'],'action_type'=>$a['type'],'completion_json'=>json_encode($a['completion'])];else unset($open[$a['id']]);}
 $traces[]=['command'=>$type,'events'=>array_map(fn($e)=>['type'=>$e['type'],'reason'=>$e['payload']['reason']??null,'host'=>$e['host']===null?null:JournalText::format($e['type'],$e['host']),'player'=>$e['player']===null?null:JournalText::format($e['type'],$e['player'])],$data['events']),'captures'=>count($data['captures'])];
 $s->historyRecorder=null;return$data;
}
function gaps(array $d):array{return array_values(array_filter($d['events'],fn($e)=>$e['type']==='recording_gap.started'));}
$s=new GameState(1,101,202);$s->status='place';$s->getPlayer('host')->side=1;$s->getPlayer('player')->side=2;
foreach(['host','player']as$side){$cells=ZoneManager::cellLevels($side)[0];for($i=0;$i<6;$i++){$id=$i+1+($side==='player'?6:0);$card=s31card($id,$side,1,1);$card->zone='squad';$card->row=null;$card->col=null;$s->addCard($card);}}
$count=0;
foreach(['host','player']as$side){$cells=ZoneManager::cellLevels($side)[0];for($i=0;$i<6;$i++){[$row,$col]=array_map('intval',explode('_',$cells[$i]));$d=runGap($s,[],$side,'place_card',['card_id'=>$i+1+($side==='player'?6:0),'row'=>$row,'col'=>$col]);gc(count(gaps($d))===0,'placement is outside text coverage');$count+=count(gaps($d));}}
$d=runGap($s,[],'host','confirm_place');gc(count(gaps($d))===0,'first confirmation exempt');
$d=runGap($s,[],'player','confirm_place');$count+=count(gaps($d));gc($s->status==='battle'&&$count===1,'placement excluded; only uncertified battle start remains');
gc(count(array_filter($d['events'],fn($e)=>$e['type']==='turn.started'&&$e['host']!==null))===1,'turn still visible with empty catalog');
$checks[]='PASS actual placement12 excluded from text coverage; one snapshot certification gap and one visible turn remain';
$open=[];$s=s31state(['unanswer'=>true]);$s->getCard(3)->revealed=false;$s->getCard(2)->closed=true;
$d=runGap($s,$catalog,'host','move',['card_id'=>3,'row'=>2,'col'=>3]);$moves=array_values(array_filter($d['events'],fn($e)=>$e['type']==='move.started'));gc(count($moves)===1&&$moves[0]['host']!==null&&$moves[0]['player']===null,'owner projections');
$checks[]='PASS actual move: owner projection visible, opponent hidden';
$retry=new Recorder(1,998,$catalog,[],true);$s->historyRecorder=$retry;$again=(new Engine())->apply($s,'host',new Command('move',['card_id'=>3,'row'=>2,'col'=>3]));gc(!$again->success && $retry->batch($s)->data()['events']===[],'rejected repeat produced history');$s->historyRecorder=null;$checks[]='PASS rejected duplicate move creates no events or gap';
$d=runGap($s,$catalog,'host','strike',['card_id'=>1,'target_id'=>2]);
for($i=0;$i<12&&!empty($s->battle['strike']);$i++){
 $x=$s->battle['strike'];$mode=$x['state'];
 if($mode==='waiting_defender')runGap($s,$catalog,'player','choose_defender',['defender_id'=>0]);
 elseif($mode==='waiting_choice')runGap($s,$catalog,$x['choice_winner']==='attack'?'host':'player','choose_strike_mode',['mode'=>'normal']);
 elseif($mode==='results'){foreach(['host','player']as$p)if(!empty($s->battle['strike'])&&!in_array($p,$s->battle['strike']['confirmed']??[],true))runGap($s,$catalog,$p,'confirm_strike',['wait_id'=>(string)InformationalWaitManager::token($s)]);}
 else throw new RuntimeException('Unexpected combat wait '.$mode);
}
gc(empty($s->battle['strike']),'ordinary combat settled');runGap($s,$catalog,'host','end_turn');$checks[]='PASS actual ordinary strike, dice/results/ACK and end_turn';
$open=[];$s=s31state(['unanswer'=>true]);$s->getCard(1)->prop['unknown_test_feature']=true;
$d=runGap($s,$catalog,'host','move',['card_id'=>3,'row'=>2,'col'=>3]);gc(count(gaps($d))===1&&count($d['captures'])===0,'uncertified snapshot');
gc(count(array_filter($d['events'],fn($e)=>$e['type']==='move.started'&&$e['host']!==null))===1,'snapshot rejection must not remove text event');
$checks[]='PASS unknown unrelated prop rejects snapshot but retains known movement event';
$open=[];$s=s31state(['actions'=>[['type'=>'uchr','value'=>2]]]);$s->getCard(2)->col=5;s31roll();
$d=runGap($s,$catalog,'host','uchr',['card_id'=>1,'target_id'=>2]);gc(gaps($d)[0]['payload']['reason']==='uncertified_mechanic_or_display','uchr reason');
$d=runGap($s,$catalog,'host','confirm_strike',['wait_id'=>(string)InformationalWaitManager::token($s)]);gc(gaps($d)[0]['payload']['reason']==='uncovered_command:confirm_strike','first uchr ACK no root');
$d=runGap($s,$catalog,'player','confirm_strike',['wait_id'=>(string)InformationalWaitManager::token($s)]);gc(gaps($d)[0]['payload']['reason']==='uncertified_mechanic_or_display','final uchr ACK eligibility');
$checks[]='PASS actual uchr + both ACK reproduce seq29/34/36 reason pattern without supported combat root';
$s=s31state();$s->battle['pending_unknown_fixture']=['owner'=>'host','value'=>17];$before=$s->toArray();$r=new Recorder(1,999,$catalog,[],true);$r->commandStarted($s,'host',new Command('technical_fixture'));$r->liveEvent($s,'turn.started',['turn'=>1,'active'=>'host']);$r->commandFinished($s,\Berserk\Core\Result::ok());gc($before===$s->toArray(),'observer changed state/pending');gc(count(gaps($r->batch($s)->data()))===1,'text event with uncovered command still gap');$checks[]='PASS uncovered technical command can coexist with text, observer preserves pending';
echo json_encode(['checks'=>$checks,'trace'=>$traces],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
