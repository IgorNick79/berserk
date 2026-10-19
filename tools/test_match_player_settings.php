<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,Engine,Command,TurnTimer,MatchPlayerSettings};
use Berserk\View\Template;
use Berserk\View\Screen\BattleSnapshotRenderer;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function psAssert(bool $ok,string $m):void{global $n;++$n;if(!$ok)throw new RuntimeException($m);}
function psState():GameState{$s=new GameState(-71031,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];TurnTimer::prepare($s,'host');TurnTimer::resume($s,1,'start_phase',1000);return $s;}
$engine=new Engine(null,static function():int{throw new RuntimeException('Settings must not even read/settle the gameplay clock');});
$s=psState();psAssert($s->getPlayer('host')->soundEnabled&&$s->getPlayer('player')->soundEnabled,'Default on for both');
$legacy=$s->toArray();unset($legacy['players']['host']['sound_enabled'],$legacy['players']['player']['sound_enabled']);$s=GameState::fromArray($legacy);
psAssert($s->getPlayer('host')->soundEnabled&&$s->getPlayer('player')->soundEnabled,'Legacy default on');
foreach(['host','player'] as $actor)foreach([false,true] as $enabled){
    $before=$s->toArray();$r=$engine->apply($s,$actor,new Command(MatchPlayerSettings::COMMAND,['sound_enabled'=>$enabled,'player_key'=>$s->getOpponentKey($actor)]));
    psAssert($r->success,'Explicit setting accepted');psAssert($s->getPlayer($actor)->soundEnabled===$enabled,'Sender changed');
    $after=$s->toArray();$after['version']=$before['version'];$after['players'][$actor]['sound_enabled']=$before['players'][$actor]['sound_enabled'];
    psAssert($after===$before,'No opponent/gameplay state changes');
    $s=GameState::fromArray($s->toArray());psAssert($s->getPlayer($actor)->soundEnabled===$enabled,'Serialization persists preference');
}
foreach([null,[], 'toggle','yes',2,'',new stdClass()] as $invalid){$before=$s->toArray();psAssert(!$engine->apply($s,'host',new Command(MatchPlayerSettings::COMMAND,['sound_enabled'=>$invalid]))->success,'Invalid value rejected');psAssert($before===$s->toArray(),'Invalid request is read-only');}
psAssert(!$engine->apply($s,'spectator',new Command(MatchPlayerSettings::COMMAND,['sound_enabled'=>false]))->success,'Unknown actor rejected');
foreach(['idle','requested','active'] as $mode){
    $s=psState();$clock=new Engine(null,static fn():int=>1010);
    if($mode!=='idle')psAssert($clock->apply($s,'host',new Command('request_pause',['duration_seconds'=>60]))->success,'Request fixture');
    if($mode==='active')psAssert($clock->apply($s,'player',new Command('accept_pause',['request_id'=>$s->battle['match_pause']['request_id']]))->success,'Accept fixture');
    $s->battle['pending_any_death']=[['fixture'=>'keep']];$s->battle['coin_post_combat_continuation']=['fixture'=>'keep'];
    $s->battle['informational_wait']=['wait_id'=>9,'deadline_at'=>1020,'fixture'=>'keep'];$battle=$s->battle;
    psAssert($engine->apply($s,'host',new Command(MatchPlayerSettings::COMMAND,['sound_enabled'=>'0']))->success,'Setting works in '.$mode);
    psAssert($s->battle===$battle,'Pause/timers/ACK/pending/continuation unchanged in '.$mode);
    $version=$s->version;psAssert($engine->apply($s,'host',new Command(MatchPlayerSettings::COMMAND,['sound_enabled'=>false]))->success&&$s->version===$version,'Explicit repeat is idempotent');
    $before=$s->toArray();$projection=MatchPlayerSettings::snapshot($s,'host');
    psAssert($projection===['sound_enabled'=>false]&&MatchPlayerSettings::snapshot($s,'player')===['sound_enabled'=>true],'Projection contains current viewer only');
    psAssert($s->toArray()===$before,'Projection is read-only');
    if($mode!=='idle')psAssert(!$clock->apply($s,'host',new Command('end_turn'))->success,'Setting exception does not unlock gameplay');
}
$s=psState();$s->getPlayer('host')->soundEnabled=false;
$view=(new BattleSnapshotRenderer(new Template(__DIR__.'/../templates/')))->render($s,'host','host',null,[]);
psAssert($view['player_settings']===['sound_enabled'=>false],'Snapshot preference projection');
psAssert(psState()->getPlayer('host')->soundEnabled,'A new match starts enabled');
echo "Match player settings unit tests passed ($n checks)\n";
