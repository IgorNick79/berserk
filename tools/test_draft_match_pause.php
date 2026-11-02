<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,GameSettings,Engine,Command,MatchPause};
use Berserk\Core\Prepare\{DraftTimer,PrepareProcessor};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$checks=0;function dpCheck(bool $ok,string $m):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($m);}
foreach(['default','unlimited'] as $mode)foreach(['rejected','cancelled','ready','request_timeout','deadline','late_request_timeout','late_deadline'] as $exit){
 $s=new GameState(-99005,1,2);$s->status='draft';$s->settings->draft['timer_mode']=$mode;
 $s->draft=['turn'=>'host','grid'=>array_fill(0,9,'test'),'pool'=>[],'picked'=>['host'=>[],'player'=>[]]];
 DraftTimer::initialize($s,$s->settings,1000);$now=1005;$e=new Engine(clock:static function()use(&$now){return $now;});
 $before=DraftTimer::remainingAction($s,$now);$total=DraftTimer::remainingTotal($s,'host',$now);
 dpCheck($e->apply($s,'host',new Command('request_pause',['duration_seconds'=>60]))->success,'request '.$mode);
 $rid=$s->battle['match_pause']['request_id'];$now=1010;
 dpCheck(DraftTimer::remainingAction($s,$now)===$before&&DraftTimer::remainingTotal($s,'host',$now)===$total,'frozen request');
 foreach(['draft_row','draft_col','draft_confirm','draft_pass','finish_draft'] as $type){$saved=$s->toArray();$r=$e->apply($s,'host',new Command($type,['row'=>1,'col'=>1]));dpCheck(!$r->success&&$s->toArray()===$saved,'blocked '.$type);}
 $saved=$s->toArray();$r=(new PrepareProcessor($s))->resolveDraftTimeouts(90000);dpCheck($r->success&&!$r->events&&$saved===$s->toArray(),'direct sync frozen without DB');
 if($exit==='rejected')$e->apply($s,'player',new Command('reject_pause',['request_id'=>$rid]));
 elseif($exit==='cancelled')$e->apply($s,'host',new Command('cancel_pause_request',['request_id'=>$rid]));
 elseif(str_contains($exit,'request_timeout')){ $now=str_starts_with($exit,'late_')?1030:1025;MatchPause::expire($s,$now); }
 else{dpCheck($e->apply($s,'player',new Command('accept_pause',['request_id'=>$rid]))->success,'accept');$now=1020;dpCheck(DraftTimer::remainingAction($s,$now)===$before,'active frozen');if($exit==='ready'){ $e->apply($s,'host',new Command('pause_ready',['request_id'=>$rid]));$e->apply($s,'player',new Command('pause_ready',['request_id'=>$rid])); }else{$now=str_starts_with($exit,'late_')?1075:1070;MatchPause::expire($s,$now);}}
 $late=str_starts_with($exit,'late_')?5:0;$expected=$before===null?null:$before-$late;$expectedTotal=$total===null?null:$total-$late;
 dpCheck(!MatchPause::blocking($s),'resumed');dpCheck(DraftTimer::remainingAction($s,$now)===$expected,'remaining preserved on resume');
 dpCheck(DraftTimer::remainingTotal($s,'host',$now)===$expectedTotal,'total preserved on resume');
 $saved=$s->toArray();$duplicate=$e->apply($s,'host',new Command('pause_ready',['request_id'=>$rid]));dpCheck(!$duplicate->success&&$saved===$s->toArray(),'duplicate resume cannot shift timer again');
 if($mode!=='unlimited')dpCheck(DraftTimer::remainingAction($s,$now+3)===$expected-3,'clock resumes once');
}
echo "Draft MatchPause: $checks checks passed (in-memory)\n";
