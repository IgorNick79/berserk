<?php
declare(strict_types=1);
if (getenv('BERSERK_STAGE4D_ISOLATED')!=='1' || file_exists(__DIR__.'/../.git')) throw new RuntimeException('Disposable source copy only');
require __DIR__.'/stage31_fixtures.php';
use Berserk\Core\{Command,Result};
use Berserk\Core\BattleHistory\{Recorder,HistoryRuntime,JournalText};
function dcheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$catalog=[];foreach([1,2,3,4,90,91]as$id)$catalog['s31_'.$id]=['revision'=>'unit','name'=>'Card '.$id,'image'=>'','description'=>'','type'=>'creature','element'=>'neutral','classes'=>[],'price'=>0];
$checks=[];
function dt(string $name,Closure $run):void{global$checks;try{$run();$checks[]=['name'=>$name,'status'=>'PASS'];echo"PASS $name\n";}catch(Throwable$e){$checks[]=['name'=>$name,'status'=>'FAIL','error'=>$e->getMessage()];echo"FAIL $name: ".$e->getMessage()."\n";}}
dt('untrusted zero identity and expired/revoked bindings fail closed',function(){
 $t=str_repeat('a',64);$cfg=['journal_enabled'=>true,'journal_game_ids'=>[5],'journal_test_bindings'=>[$t=>['game_id'=>5,'side'=>'player','expires_at'=>time()+60]]];$r=new HistoryRuntime($cfg);
 dcheck($r->journalSide(5,0,2,[])===null,'zero unauthenticated identity');dcheck($r->journalSide(5,1,2,['berserk_battle_journal_binding'=>$t])==='player','server binding');dcheck($r->journalSide(6,1,2,['berserk_battle_journal_binding'=>$t])===null,'foreign game');
 $cfg['journal_test_bindings'][$t]['expires_at']=time()-1;dcheck((new HistoryRuntime($cfg))->journalSide(5,1,2,['berserk_battle_journal_binding'=>$t])===null,'expiry');$cfg['journal_test_bindings']=[];dcheck((new HistoryRuntime($cfg))->journalSide(5,1,2,['berserk_battle_journal_binding'=>$t])===null,'revocation');
});
dt('missing definition uses safe visible label and old projection is not enriched',function()use($catalog){
 $s=s31state();$s->getCard(1)->revealed=true;unset($catalog['s31_1']);$r=new Recorder(5,1,$catalog,[],true);$r->commandStarted($s,'host',new Command('fixture'));$r->liveEvent($s,'ability.applied',['source_id'=>1,'ability'=>'Secret'],[1]);$r->commandFinished($s,Result::ok());$data=$r->batch($s)->data();
 foreach($data['events']as$e)if($e['type']==='ability.applied')dcheck($e['host']['_journal']['cards']['1']['name']==='Карта'&&$e['player']!==null,'known card retains fallback text without definition');dcheck(JournalText::format('combat.started',['attacker_id'=>1,'target_id'=>2])===null,'no current lookup');
});
dt('nested uncertified combat never throws or creates a fake snapshot',function()use($catalog){
 $s=s31state(['unanswer'=>true]);$r=new Recorder(5,1,$catalog,[],true);$r->commandStarted($s,'host',new Command('strike'));$r->combatStarted($s,['attacker_id'=>1,'target_id'=>2]);$r->combatStarted($s,['attacker_id'=>1,'target_id'=>4]);$r->commandFinished($s,Result::ok());$data=$r->batch($s)->data();
 dcheck(count($data['captures'])===0,'no synthetic snapshot');$open=array_values(array_filter($data['actions'],fn($a)=>$a['status']==='open'));dcheck(count($open)===1&&$open[0]['type']==='combat'&&$open[0]['completion']['event_only'],'one explicit event-only root');
 $again=new Recorder(5,2,$catalog,[['action_type'=>'combat','action_id'=>$open[0]['id'],'contains_gap'=>0,'completion_json'=>json_encode($open[0]['completion'])]],true);$again->commandStarted($s,'host',new Command('confirm_strike'));$again->combatFinished($s);$again->commandFinished($s,Result::ok());dcheck(count($again->batch($s)->data()['captures'])===0,'event-only survives reload');
});
dt('actual dice modification emits once and table recalculation emits no additional dice',function()use($catalog){
 $s=s31state(['unanswer'=>true]);s31combat($s);$r=new Recorder(5,1,$catalog,[],true);$s->historyRecorder=$r;$r->commandStarted($s,'host',new Command('fixture'));$r->combatStarted($s,$s->battle['strike']);$engine=new \Berserk\Core\Engine();
 dcheck($engine->applyCombatEffect($s,['type'=>'dice_choice'],$s->getCard(3),'host','plus:own')===null,'dice effect applied');for($i=0;$i<3;$i++)(new \Berserk\Core\StrikeResolver($s,$engine))->recalcTable();$r->commandFinished($s,Result::ok());$events=array_values(array_filter($r->batch($s)->data()['events'],fn($e)=>$e['type']==='combat.dice_changed'));dcheck(count($events)===1&&$events[0]['payload']['attack']===7,'one actual change event, no recalc event');
});
echo json_encode(['checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";exit(count(array_filter($checks,fn($r)=>$r['status']!=='PASS'))?1:0);
