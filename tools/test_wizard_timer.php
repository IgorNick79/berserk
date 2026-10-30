<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,GameSettings,Engine,Command,TurnTimer,TurnProcessor,TurnTimeoutEligibility,MatchPause};
use Berserk\Core\BattleHistory\Recorder;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
function observe(GameState $s,int $id): Recorder {$r=new Recorder(51,$id,[],[],true);$r->commandStarted($s,'host',new Command('turn_ack'));return $r;}
$n=0;function cw(bool $ok,string $why):void {global $n;$n++;if(!$ok)throw new RuntimeException($why);}
foreach([0,180] as $seconds){
 $s=new GameState(51,1,2);$s->status='battle';$s->battle=['turn'=>1,'active'=>'host','hidden_row_revealed'=>true];$s->settings->battle['turn_seconds']=$seconds;
 $engine=new Engine(null,static fn()=>1000);$processor=new TurnProcessor($s,$engine);
 $s->historyRecorder=observe($s,1);$processor->afterStartPhase('host');$processor->afterStartPhase('host');
 $events=$s->historyRecorder->batch($s)->data()['events'];cw(count(array_filter($events,fn($e)=>$e['type']==='turn.started'))===1,'first turn exactly once '.$seconds);
 cw(($seconds===0)===!isset($s->battle['turn_timer']),'no fake timer '.$seconds);
 $loaded=GameState::fromArray($s->toArray());$loaded->historyRecorder=observe($loaded,2);(new TurnProcessor($loaded,$engine))->afterStartPhase('host');cw($loaded->historyRecorder->batch($loaded)->data()['events']===[],'reload no duplicate '.$seconds);
 $s->battle['active']='player';$s->historyRecorder=observe($s,3);TurnTimer::prepare($s,'player');$processor->afterStartPhase('player');$processor->afterStartPhase('player');
 cw(count(array_filter($s->historyRecorder->batch($s)->data()['events'],fn($e)=>$e['type']==='turn.started'))===1,'next turn once '.$seconds);
 cw(MatchPause::apply($s,'player',new Command('request_pause',['duration_seconds'=>60]),1001)->success,'request pause '.$seconds);$request=$s->battle['match_pause']['request_id'];
 cw(MatchPause::apply($s,'host',new Command('accept_pause',['request_id'=>$request]),1002)->success,'accept pause '.$seconds);
 foreach(['host','player'] as $side)cw(MatchPause::apply($s,$side,new Command('pause_ready',['request_id'=>$request]),1003)->success,'ready '.$side.' '.$seconds);
 cw($s->battle['match_pause']['mode']==='idle','resumed '.$seconds);cw(($seconds===0)===!isset($s->battle['turn_timer']),'resume no fake clock '.$seconds);
 if($seconds===0)cw(!TurnTimeoutEligibility::check($s,100000)['allowed']&&TurnTimer::snapshot($s,100000)===null,'never times out');
 else cw(TurnTimer::snapshot($s,1003)['status']==='running','timed resumed');
 $s->battle['turn']=2;$s->battle['active']='host';$s->battle['turn_phase']=['sub'=>['remaining'=>[]]];$s->historyRecorder=observe($s,4);TurnTimer::prepare($s,'host');$processor->afterStartPhase('host');cw($s->historyRecorder->batch($s)->data()['events']===[],'pending delays start '.$seconds);unset($s->battle['turn_phase']);$processor->afterStartPhase('host');cw(count(array_filter($s->historyRecorder->batch($s)->data()['events'],fn($e)=>$e['type']==='turn.started'))===1,'pending finishes once '.$seconds);
}
foreach([0,180] as $seconds)foreach(['cancel','reject','timeout','accepted_timeout'] as $ending){
 $s=new GameState(52,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1];$s->settings->battle['turn_seconds']=$seconds;
 TurnTimer::prepare($s,'host');TurnTimer::resume($s,1,'start_phase',1000);
 cw(MatchPause::apply($s,'host',new Command('request_pause',['duration_seconds'=>60]),1000)->success,'pause ending request');$id=$s->battle['match_pause']['request_id'];
 if($ending==='accepted_timeout'){cw(MatchPause::apply($s,'player',new Command('accept_pause',['request_id'=>$id]),1001)->success,'pause timeout accepted');cw(MatchPause::expire($s,1061)->success,'accepted pause expires');}
 elseif($ending==='timeout')cw(MatchPause::expire($s,1020)->success,'request deadline');
 else cw(MatchPause::apply($s,$ending==='cancel'?'host':'player',new Command($ending==='cancel'?'cancel_pause_request':'reject_pause',['request_id'=>$id]),1001)->success,'pause cancelled/rejected');
 cw($s->battle['match_pause']['mode']==='idle','pause exit idle');cw(($seconds===0)===!isset($s->battle['turn_timer']),'pause exit never invents timer');
}
cw(GameSettings::fromArray([])->battle['turn_seconds']===180,'historical settings default 180');
echo "Wizard timer/pause/Recorder: $n checks passed\n";
