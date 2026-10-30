<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest_manual.php';
use Berserk\Auth\{Account,DebugAccess};
use Berserk\Core\{CardInstance,Command,Dice,Engine,GameRepository,GameState,LuckTestDice,Result,StaleStateException};
use Berserk\Core\Prepare\{LuckTestGenerator,LuckTestScenario};
use Berserk\View\{Template};
use Berserk\View\Screen\{BattleScreen,BattleSnapshotRenderer,SettingsScreen};
use Berserk\View\Ui\GameMenu;
use Berserk\Lobby\{RoomSetup};
$debugStart=$checks;
$configFile=__DIR__.'/../config/auth.local.php';
if(is_file($configFile))throw new RuntimeException('Disposable copy without auth config required');
function debugConfig(mixed $ids):void { global $configFile;file_put_contents($configFile,'<?php return '.var_export(['debug_account_ids'=>$ids],true).';');clearstatcache();if(function_exists('opcache_invalidate'))opcache_invalidate($configFile,true); }
register_shutdown_function(static function()use($configFile){if(is_file($configFile))unlink($configFile);});
foreach([null,[],[11],[11,11],[11,22,33],['11',22],[0,22],[-1,22],['host'=>11,'player'=>22]] as $ids){debugConfig($ids);check(!DebugAccess::allowsId(11),'invalid permission config closes access');}
debugConfig([11,22]);check(DebugAccess::allowsId(11) && DebugAccess::allowsId(22) && !DebugAccess::allowsId(33),'exact account allowlist');
function scenarioJson(array $cards):string{return json_encode(['cards'=>$cards],JSON_THROW_ON_ERROR);}
function debugStart(array $entries=[],int $k=3,array $catalog=[],string $roll='6,1,4,5',?Closure $rng=null,array $extra=[]):array {
    $s=fresh();$db=fixture($catalog?:[card('army',7)]);
    $payload=['lucktest_card_count'=>(string)$k,'debug_roll'=>$roll]+$extra;
    if($entries!==[])$payload['lucktest_scenario']=scenarioJson($entries);
    $r=(new Engine($db,fn()=>1800000000,$rng??fn($min,$max)=>$min))->apply($s,'host',new Command('confirm_settings',$payload));
    return [$s,$r,$db];
}
foreach([3,10] as $k)foreach(['6,1,4,5','1,6,4,5'] as $roll)foreach([1,2] as $side){
    $entries=[['owner'=>'host','ukid'=>'army','row'=>2,'col'=>3],['owner'=>'host','ukid'=>'army','row'=>1,'col'=>2],['owner'=>'player','ukid'=>'army']];
    [$s,$r]=debugStart($entries,$k,[],$roll,fn($min,$max)=>$min===1?$side:$min);
    check($r->success && $s->status==='battle','scenario Battle: '.($r->error??''));
    foreach($s->lucktestDebug['bindings'] as $entry){$c=$s->getCard($entry['instance_id']);check($c->ukid===$entry['ukid'] && $c->owner===$entry['owner'],'mandatory occurrence preserved');if(isset($entry['row']))check($c->row===$entry['row'] && $c->col===$entry['col'],'mandatory cell exact');}
    check(count(array_unique(array_column($s->lucktestDebug['bindings'],'instance_id')))===3,'duplicate ukids bind distinct instances');
    check($s->firstPlayer===($roll[0]==='6'?'host':'player') && $s->getPlayer($s->firstPlayer)->side===$side,'native dice winner and side');
    foreach(['host','player'] as $owner)check(count($s->getCardsInZone($owner,'field'))===$k && count($s->getCardsInZone($owner,'deck'))===30-$k,'K squad plus reserve');
    check($s->lucktestDebug['rolls']['11']['state']['queue']===[4,5],'remaining queue persisted in GameState');
    check(!isset($_SESSION['debug_roll']) && !isset($_SESSION['debug_roll_state']),'adapter restores empty legacy session');
    $copy=GameState::fromArray(json_decode(json_encode($s->toArray()),true));check($copy->lucktestDebug===$s->lucktestDebug,'private cursor/bindings roundtrip');
}
// Full first level plus a mandatory second-level cell; the second-level entry is intentionally first.
$entries=[['owner'=>'host','ukid'=>'army','row'=>1,'col'=>1]];
foreach(\Berserk\Core\ZoneManager::cellLevels('host')[0] as $cell){[$row,$col]=array_map('intval',explode('_',$cell));$entries[]=['owner'=>'host','ukid'=>'army','row'=>$row,'col'=>$col];}
[$s,$r]=debugStart($entries,10);check($r->success && count($s->lucktestDebug['bindings'])===10,'fully fixed squad across levels');
[$s,$r]=debugStart([['owner'=>'host','ukid'=>'army','row'=>1,'col'=>1]],10);check($r->success,'random support fills previous level');
$nested=fresh();$nestedResult=(new Engine(fixture([card('army')])))->apply($nested,'host',new Command('confirm_settings',['settings'=>['lucktest'=>['card_count'=>10]],'lucktest_scenario'=>scenarioJson($entries),'debug_roll'=>'6,1']));
check($nestedResult->success && $nested->settings->lucktest['card_count']===10,'effective settings use native parser including nested payload');
$bad=[
    '{}','[]','{"cards":[]}','{"cards":{},"first_player":"host"}', '{"cards":[{"owner":"host","ukid":"army","row":2}]}',
    '{"cards":[{"owner":"host","ukid":"army","row":"2","col":3}]}','{"cards":[{"owner":"host","ukid":"army","row":2.0,"col":3}]}',
    '{"cards":[{"owner":"host","ukid":"army","price":0}]}','{"cards":[{"owner":"bad","ukid":"army"}]}',
    scenarioJson([['owner'=>'host','ukid'=>'army','row'=>1,'col'=>1]]),scenarioJson([['owner'=>'player','ukid'=>'army','row'=>5,'col'=>1]]),
    scenarioJson([['owner'=>'host','ukid'=>'army','row'=>2,'col'=>3],['owner'=>'host','ukid'=>'army','row'=>2,'col'=>3]]),
    scenarioJson(array_fill(0,4,['owner'=>'host','ukid'=>'army'])),str_repeat(' ',4097),'{bad',
];
foreach($bad as $raw){$s=fresh();$before=$s->toArray();$r=(new Engine(fixture([card('army')])))->apply($s,'host',new Command('confirm_settings',['lucktest_scenario'=>$raw,'lucktest_card_count'=>'3']));check(!$r->success && $s->toArray()===$before,'invalid JSON/geometry rejected atomically');}
[$s,$r]=debugStart([['owner'=>'host','ukid'=>'missing']]);check(!$r->success && $s->cards===[],'catalog lookup rejects unknown card');
[$s,$r]=debugStart([['owner'=>'host','ukid'=>'army']],3,[],'6,1',null,['lucktest_quick_game'=>'0']);check(!$r->success && $s->status==='settings','manual scenario rejected');
$entries=[];foreach(['host'=>'normal','player'=>'fly'] as $owner=>$id)foreach(range(1,3) as $i)$entries[]=['owner'=>$owner,'ukid'=>$id];
[$s,$r]=debugStart($entries,3,[card('normal'),card('fly',3,'fly')]);check(!$r->success && $s->cards===[] && str_contains($r->error,'балансировка'),'impossible fixed balance');
[$s,$r]=debugStart([['owner'=>'host','ukid'=>'expensive']],3,[card('cheap',3),card('expensive',40)],'6,1',null,['lucktest_average_cost'=>'3']);check($r->success && $s->getCard($s->lucktestDebug['bindings'][0]['instance_id'])->price===40,'approximate budget retains expensive mandatory card');
foreach(['6*','off','6,1,4'] as $raw){$s=fresh();$r=(new Engine())->apply($s,'host',new Command('set_lucktest_debug',['debug_roll'=>$raw]));check($r->success,'accepted native Dice syntax');if($raw==='6*'){$before=$s->toArray();$r=(new Engine(fixture([card('army')])))->apply($s,'host',new Command('confirm_settings'));check(!$r->success && $s->toArray()===$before,'infinite preparation protection retained');}}
// The saved cursor also drives a real native battle throw, not only preparation.
[$combat,$r]=debugStart([['owner'=>'host','ukid'=>'army','row'=>3,'col'=>3],['owner'=>'player','ukid'=>'army','row'=>4,'col'=>3]],3,[card('army',7,'creature',['unanswer'=>true])],'6,1,5,2');
$attacker=$combat->lucktestDebug['bindings'][0]['instance_id'];$target=$combat->lucktestDebug['bindings'][1]['instance_id'];
$r=(new Engine(null,fn()=>1800000000))->apply($combat,'host',new Command('strike',['card_id'=>$attacker,'target_id'=>$target]));
check($r->success && $combat->battle['strike']['attack_dice']===5 && $combat->lucktestDebug['rolls']['11']['state']['queue']===[2],'native battle Dice consumes saved account cursor');
// Infinite queues are usable by their account during battle, without bypassing the native initial-roll guard.
$s=fresh();$e=new Engine(fixture([card('army')]));check($e->apply($s,'player',new Command('set_lucktest_debug',['debug_roll'=>'2*']))->success,'second tester stores own infinite queue');
check($e->apply($s,'host',new Command('confirm_settings',['debug_roll'=>'6,1,4']))->success,'host preparation uses its own finite queue');
$hostCursor=$s->lucktestDebug['rolls']['11'];LuckTestDice::run($s,'player',static function(){check(Dice::isInfiniteMode() && Dice::roll()===2 && Dice::roll()===2,'native infinite Dice allowed in battle');return Result::ok();});check($s->lucktestDebug['rolls']['11']===$hostCursor,'player cursor never consumes host cursor');
// Late exception after initial capture: neither Recorder nor cursor is published, then same-source retry works.
$s=fresh();$faultDb=fixture([card('army',7,'creature',['start'=>['type'=>'get_hp','hp'=>PHP_INT_MAX]])]);$engine=new Engine($faultDb);
$engine->apply($s,'host',new Command('set_lucktest_debug',['debug_roll'=>'6,1,4']));$before=$s->toArray();
$recordingCatalog=['army'=>['revision'=>'test','name'=>'Army','image'=>'test/army.jpg','description'=>'fixture','type'=>'creature','element'=>'forests','classes'=>['Воин'],'price'=>7]];
$s->historyRecorder=new \Berserk\Core\BattleHistory\Recorder(123,99,$recordingCatalog,[]);$original=$s->historyRecorder;$observerBefore=serialize($original);
$r=$engine->apply($s,'host',new Command('confirm_settings'));check(!$r->success && $s->toArray()===$before && serialize($original)===$observerBefore,'post-initial exception consumes neither queue nor Recorder');
$faultDb->cards['army']['prop']=json_encode(['start'=>['type'=>'get_hp','hp'=>2]]);$r=$engine->apply($s,'host',new Command('confirm_settings'));check($r->success && $s->lucktestDebug['rolls']['11']['state']['queue']===[4],'same-source retry consumes saved queue only once');
$batch=$s->historyRecorder->batch($s)->data();check(count(array_filter($batch['captures'],fn($capture)=>$capture['initial']))===1 && count($batch['captures'])===2,'retry after initial failure has no duplicate snapshots');
foreach(['public','host','player'] as $view)foreach($batch['captures'] as $capture)check(!str_contains(json_encode($capture[$view]),'lucktest_debug'),'Recorder projections exclude queue/scenario');
[$s,$r]=debugStart([['owner'=>'host','ukid'=>'fly','row'=>2,'col'=>3]],3,[card('fly',7,'fly')]);check($r->success && $s->getCard($s->lucktestDebug['bindings'][0]['instance_id'])->zone==='flying','fixed flying coordinate belongs to preparation only');
// Account and game cursor isolation; direct callback proves the same existing Dice executes.
[$s,$r]=debugStart();$s->players['player']->userId=33;$s->playerId=33;$cursor=$s->lucktestDebug;
LuckTestDice::run($s,'player',static function(){check(!Dice::isInfiniteMode() && Dice::remaining()===[],'ordinary opponent has no inherited queue');return Result::ok();});check($s->lucktestDebug===$cursor,'ordinary opponent cannot consume host cursor');
$other=GameState::fromArray($s->toArray());$other->gameId=124;
LuckTestDice::run($other,'host',static function(){check(Dice::remaining()===[],'queue from different game rejected');return Result::ok();});
debugConfig([]);LuckTestDice::run($s,'host',static function(){check(Dice::remaining()===[],'revoked permission disables queue');return Result::ok();});check($s->lucktestDebug===$cursor,'revocation does not consume cursor');debugConfig([11,22]);
$before=$s->toArray();$r=(new Engine())->apply($s,'host',new Command('set_lucktest_debug',['debug_roll'=>'6*']));check(!$r->success && $s->toArray()===$before,'cannot edit Battle');
// CAS and SQL failure keep the old durable cursor.
$seed=fresh();$db=fixture([card('army')]);(new Engine($db))->apply($seed,'host',new Command('set_lucktest_debug',['debug_roll'=>'6,1,4']));
$db->game=['ind'=>123,'host_id'=>11,'player_id'=>22,'status'=>'settings','version'=>4,'json'=>json_encode($seed->toArray())];$repo=new GameRepository($db);$a=$repo->findById(123);$b=$repo->findById(123);
foreach([$a,$b] as $candidate)check((new Engine($db))->apply($candidate,'host',new Command('confirm_settings'))->success,'queued candidate');$db->failWrite=true;$durable=$db->game;
try{$repo->save($a);check(false,'write failure');}catch(RuntimeException){check($db->game===$durable,'SQL failure retains durable cursor');}$db->failWrite=false;$repo->save($a);$durable=$db->game;
try{$repo->save($b);check(false,'stale cursor persisted');}catch(StaleStateException){check($db->game===$durable,'CAS loser cannot consume cursor again');}
// Real renderers, same battle: ordinary opponent sees no debug even with legacy session flags.
$tpl=new Template(__DIR__.'/../templates');$s=$a;$s->players['player']->userId=33;$s->playerId=33;foreach($s->cards as $c)if($c->owner==='host' && $c->zone==='field'){$c->revealed=false;$c->prop['private_debug_marker']='OPAQUE_TEST_SECRET';}
$_SESSION['debug']='1';DebugAccess::bind(new Account(33,'ordinary'));
$data=(new BattleScreen($tpl))->prepare($s,'player','player',null,[])['data'];check($data['debug_menu_html']==='' && !str_contains($data['info_panel_html'],'battle-debug') && !str_contains($data['info_panel_html'],'OPAQUE_TEST_SECRET'),'ordinary full page has no debug');
$projection=(new BattleSnapshotRenderer($tpl))->render($s,'player','player',null,[]);check(!str_contains(json_encode($projection),'OPAQUE_TEST_SECRET') && !str_contains(json_encode($projection),'debug-panel'),'ordinary AJAX has no debug');
DebugAccess::bind(new Account(11,'tester'));$data=(new BattleScreen($tpl))->prepare($s,'host','host',null,[])['data'];check(str_contains($data['debug_menu_html'],'data-menu-debug') && str_contains($data['info_panel_html'],'OPAQUE_TEST_SECRET'),'authorized LuckTest native debug works');
$s->mode='draft';$data=(new BattleScreen($tpl))->prepare($s,'host','host',null,[])['data'];check($data['debug_menu_html']==='' && !str_contains($data['info_panel_html'],'battle-debug'),'other modes have no debug');
$normal=fresh();$normal->mode='draft';$_SESSION['debug_roll']='6*';Dice::init();
LuckTestDice::run($normal,'host',static function(){check(Dice::remaining()===[],'authenticated normal mode ignores legacy session Dice');return Result::ok();});
check(!isset($_SESSION['debug_roll']) && Dice::remaining()===[],'HTTP context permanently quarantines legacy Dice session');
$s=fresh();$view=(new SettingsScreen())->prepare($s,'host','host',null)['data']['actions_html'];check(str_contains($view,'name="lucktest_scenario"') && str_contains($view,'name="lucktest_url_roll"') && !str_contains($view,'name="debug_roll"'),'permitted host checkbox and scenario fields');
DebugAccess::bind(new Account(33,'ordinary'));$view=(new SettingsScreen())->prepare($s,'host','host',null)['data']['actions_html'];check(!str_contains($view,'lucktest_scenario') && !str_contains(GameMenu::render('preparation',new Account(33,'ordinary'),'token',$s,'host'),'Debug'),'ordinary preparation has no debug');
$blankRoom=RoomSetup::parse('lucktest',['lucktest_scenario'=>'','debug_roll'=>'off'],11);check(!isset($blankRoom['debug']),'empty scenario/off room is ordinary LuckTest');
$room=RoomSetup::parse('lucktest',['lucktest_scenario'=>scenarioJson([['owner'=>'host','ukid'=>'army','row'=>2,'col'=>3]]),'debug_roll'=>'6,1'],11);$lobby=new GameState(321,11,33);RoomSetup::apply($lobby,$room,fixture([card('army')]));check($lobby->status==='battle' && count($lobby->lucktestDebug['bindings'])===1,'Lobby scenario route');
debugConfig([]);try{RoomSetup::apply(new GameState(322,11,33),$room,fixture([card('army')]));check(false,'revoked room accepted');}catch(DomainException){check(true,'Lobby permission rechecked');}
$log=\Berserk\Core\GameLog::read(321);check(!str_contains(json_encode($log),'"lucktest_scenario":') && !str_contains(json_encode($log),'"debug_roll":'),'no raw debug command body in GameLog');
unset($_SESSION['debug']);unlink($configFile);
echo 'LuckTest Debug: '.($checks-$debugStart)." checks passed (isolated catalog, no live DB).\n";
