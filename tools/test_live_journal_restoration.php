<?php
declare(strict_types=1);
if (getenv('BERSERK_STAGE4D_RESTORE_ISOLATED')!=='1' || file_exists(__DIR__.'/../.git')) throw new RuntimeException('Disposable source copy only');
require __DIR__.'/stage31_fixtures.php';
use Berserk\Core\{Engine,Command,GameState,InformationalWaitManager,Result};
use Berserk\Core\BattleHistory\{Recorder,HistoryRuntime,JournalText};
function jcheck(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$checks=[];$catalog=[];
$s=s31state();$s->getCard(3)->revealed=false;
$r=new Recorder(1,1,[],[],true,fn($card)=>'Known '.$card->instanceId);
$r->commandStarted($s,'host',new Command('fixture'));
$r->liveEvent($s,'move.started',['card_id'=>3,'from'=>[2,2],'to'=>[2,3]],[3]);
$r->commandFinished($s,Result::ok());$events=$r->batch($s)->data()['events'];
$move=array_values(array_filter($events,fn($e)=>$e['type']==='move.started'))[0];
jcheck(str_contains(JournalText::format('move.started',$move['host']),'Known 3'),'no replay definition needed');
jcheck($move['player']===null,'hidden opponent projection');
jcheck(!str_contains(json_encode($move['host']),'s31_3'),'no ukid fallback');
$checks[]='safe names independent of replay catalog; hidden projection null';
$r=new Recorder(1,2,[],[],true,fn($card)=>null);$r->commandStarted($s,'host',new Command('fixture'));
$r->liveEvent($s,'move.started',['card_id'=>3,'from'=>[2,2],'to'=>[2,3]],[3]);$r->commandFinished($s,Result::ok());
$move=array_values(array_filter($r->batch($s)->data()['events'],fn($e)=>$e['type']==='move.started'))[0];
jcheck(str_starts_with(JournalText::format('move.started',$move['host']),'Карта перемещается'),'missing definition generic visible label');
jcheck($move['player']===null,'missing definition never reveals hidden card');$checks[]='missing card definition retains safe generic action';
foreach(['place_card','unplace_card']as$command){$s->status='place';$r=new Recorder(1,3,[],[],true);$r->commandStarted($s,'host',new Command($command));$r->commandFinished($s,Result::ok());jcheck($r->batch($s)->data()['events']===[],'placement outside text coverage');}
$s->status='battle';$r=new Recorder(1,4,[],[],true);$r->commandStarted($s,'host',new Command('unknown_command'));$r->commandFinished($s,Result::ok());
jcheck(count(array_filter($r->batch($s)->data()['events'],fn($e)=>$e['type']==='recording_gap.started'))===1,'other command coverage remains strict');
$checks[]='placement excluded, other uncovered commands still create gaps';
$runtime=new HistoryRuntime([]);
jcheck(!$runtime->previews(7),'mandatory journal does not enable preview');
$checks[]='mandatory journal leaves preview OFF';

// Actual deterministic gameplay, paired with and without the observer. Compare the whole persisted DTO.
$seed=s31state(['unanswer'=>true]);$plain=GameState::fromArray($seed->toArray());$observed=GameState::fromArray($seed->toArray());$open=[];$seq=10;$now=time();$calls=[];
$pair=function(string $side,string $type,array $payload=[])use(&$plain,&$observed,&$open,&$seq,$now,&$calls):void{
    $engine=new Engine(null,fn()=>$now);s31roll();$a=$engine->apply($plain,$side,new Command($type,$payload));
    $recorder=new Recorder(1,++$seq,[],array_values($open),true,fn($card)=>'Known '.$card->instanceId);$observed->historyRecorder=$recorder;
    s31roll();$b=$engine->apply($observed,$side,new Command($type,$payload));$observed->historyRecorder=null;
    jcheck($a->success&&$b->success,$type.': '.($a->error??$b->error));
    jcheck($a->events===$b->events&&$plain->toArray()===$observed->toArray(),'observer changed gameplay '.$type);
    foreach($recorder->batch($observed)->data()['actions']as$action){if(($action['status']??'')==='open')$open[$action['id']]=['action_type'=>$action['type'],'action_id'=>$action['id'],'completion_json'=>json_encode($action['completion'])];else unset($open[$action['id']]);}
    $calls[]=$type;
};
$pair('host','move',['card_id'=>3,'row'=>2,'col'=>3]);$pair('host','strike',['card_id'=>1,'target_id'=>2]);
for($i=0;$i<12&&!empty($plain->battle['strike']);$i++){
    $x=$plain->battle['strike'];$mode=$x['state'];
    if($mode==='waiting_defender')$pair('player','choose_defender',['defender_id'=>0]);
    elseif($mode==='waiting_choice')$pair($x['choice_winner']==='attack'?'host':'player','choose_strike_mode',['mode'=>'normal']);
    elseif($mode==='results'){foreach(['host','player']as$side)if(!empty($plain->battle['strike'])&&!in_array($side,$plain->battle['strike']['confirmed']??[],true))$pair($side,'confirm_strike',['wait_id'=>(string)InformationalWaitManager::token($plain)]);}
    else throw new RuntimeException('Unexpected combat phase '.$mode);
}
jcheck(empty($plain->battle['strike']),'combat settled');$pair('host','end_turn');
$checks[]='actual move/strike/ACK/end_turn DTO and results identical with observer';
echo json_encode(['status'=>'PASS','checks'=>$checks,'paired_commands'=>$calls],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
