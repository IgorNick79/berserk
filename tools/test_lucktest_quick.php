<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest_manual.php';
use Berserk\Core\{CardInstance,Command,Dice,Engine,GameRepository,GameState,StaleStateException};
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\Core\BattleHistory\Recorder;
use Berserk\Lobby\RoomSetup;
$quickStart=$checks;
function quickRun(int $k=3, array $prop=[], ?Closure $random=null, string $rolls='4,4,6,1'): array {
    $s=fresh(); manualRolls($rolls);
    $r=(new Engine(fixture([card('army',7,'creature',$prop)]),null,$random))->apply($s,'host',new Command('confirm_settings',['lucktest_card_count'=>(string)$k]));
    return [$s,$r];
}
function quickRejectRestart(GameState $s): void {
    $before=$s->toArray();$now=$s->battle['turn_timer']['anchor_at']??time();
    $r=(new Engine(null,fn()=>$now))->apply($s,'host',new Command('confirm_settings'));
    check(!$r->success && $s->toArray()===$before,'restart cannot change battle, pending or cards');
}
foreach ([3,10] as $k) foreach ([1,2] as $side) foreach (['host','player'] as $winner) {
    [$s,$r]=quickRun($k,[],fn($min,$max)=>$min===1?$side:($side===1?$min:$max),$winner==='host'?'4,4,6,1':'2,2,1,6');
    check($r->success && $s->status==='battle', 'quick enters battle: '.($r->error??''));
    check($s->firstPlayer===$winner && $s->getPlayer($winner)->dice===6 && Dice::remaining()===[], 'native tie and winner retained');
    check($s->getPlayer($winner)->side===$side && $s->battle['active']===($side===1?$winner:$s->getOpponentKey($winner)), 'side and battle active distinct from dice winner');
    check(count($s->cards)===60 && $s->battle['turn']===1, 'one battle with 60 instances');
    $cells=[];
    foreach (['host','player'] as $owner) {
        check(count($s->getCardsInZone($owner,CardInstance::ZONE_FIELD))===$k && count($s->getCardsInZone($owner,CardInstance::ZONE_DECK))===30-$k, 'full field and reserve');
        check($s->getCardsInZone($owner,CardInstance::ZONE_SQUAD)===[] && $s->getPlayer($owner)->confirmed===[], 'no squad or stale confirms');
        foreach ($s->getCardsInZone($owner,CardInstance::ZONE_FIELD) as $c) { $cell=$c->row.'_'.$c->col; check(!isset($cells[$cell]),'no collision');$cells[$cell]=true; }
        foreach ($s->getCardsInZone($owner,CardInstance::ZONE_DECK) as $c) check($c->row===null && $c->col===null && $c->order===null,'reserve untouched');
    }
    foreach (['lucktest_quick_preparation_completed','stage_changed:battle'] as $event) check(count(array_keys($r->events,$event,true))===1,'single completion event');
    $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));quickRejectRestart($s);
}
// Real random choices produce different legal boards.
$boards=[];foreach(range(1,12) as $i) {[$s,$r]=quickRun(10);check($r->success,'random legal board');$boards[json_encode(array_map(fn($c)=>[$c->row,$c->col],$s->getCardsInZone('host',CardInstance::ZONE_FIELD)))]=true;}
check(count($boards)>1,'placement randomness');
foreach ([1,4,7] as $failAt) {
    $s=fresh();$s->persistenceVersion=8;$s->persistenceJson='original';$before=$s->toArray();manualRolls('6,1');$draw=0;
    $offset=strlen(file_get_contents($diagnosticFile));
    $r=(new Engine(fixture([card('army')]),null,static function($min,$max) use (&$draw,$failAt){if(++$draw===$failAt)throw new RuntimeException('injected quick fault');return $min;}))->apply($s,'host',new Command('confirm_settings'));
    check(!$r->success && $s->toArray()===$before && $s->persistenceVersion===8 && $s->persistenceJson==='original','side/partial placement failure atomic');
    $log=substr(file_get_contents($diagnosticFile),$offset);check(str_contains($log,'lucktest_quick_preparation_failed') && str_contains($log,'injected quick fault'),'technical fault logged');
    check(!str_contains($r->error,'injected') && !str_contains($r->error,'.php'),'generic player error');
}
$s=fresh();$before=$s->toArray();manualRolls('6*');
$r=(new Engine(fixture([card('army')])))->apply($s,'host',new Command('confirm_settings'));
check(!$r->success && $s->toArray()===$before,'infinite debug rejected atomically');
// Native start effects and mandatory decision survive automation.
[$s,$r]=quickRun(3,['start'=>['type'=>'get_coins','coins'=>2], 'pre_turn_start'=>['options'=>[['name'=>'Choice','modifiers'=>[['stat'=>'move','value'=>1]]]]]],fn($min,$max)=>$min);
check($r->success && isset($s->battle['pending_card_choice']), 'mandatory prophecy pending remains');
foreach (['host','player'] as $owner) foreach ($s->getCardsInZone($owner,CardInstance::ZONE_FIELD) as $c) check($c->coins===2,'both armies start effect once');
foreach ($s->getCardsInZone('host',CardInstance::ZONE_DECK) as $c) check($c->coins===0,'reserve has no battlefield start effects');
check(($s->battle['turn_timer']['period_id']??null)===1 && $s->battle['turn_timer']['owner']==='host','native timer period and owner');
$pending=$s->battle['pending_card_choice'];$before=$s->toArray();quickRejectRestart($s);check($s->battle['pending_card_choice']===$pending && $s->toArray()===$before,'retry does not repeat start effects/pending');
// Initial capture is native startBattle; no internal Engine.apply command snapshots.
$s=fresh();manualRolls('6,1');$catalog=['army'=>['revision'=>'test','name'=>'Army','image'=>'test/army.jpg','description'=>'fixture','type'=>'creature','element'=>'forests','classes'=>['Воин'],'price'=>7]];
$s->historyRecorder=new Recorder(123,9,$catalog,[]);
$r=(new Engine(fixture([card('army',7)]),null,fn($min,$max)=>$min))->apply($s,'host',new Command('confirm_settings',['lucktest_card_count'=>'3']));
check($r->success,'recorded quick start');$batch=$s->historyRecorder->batch($s)->data();
$initial=array_values(array_filter($batch['captures'],fn($c)=>$c['initial']));check(count($initial)===1,'exactly one native initial');
foreach ($batch['captures'] as $capture) {check($capture['state']['turn']===1 && $capture['state']['active']!==null,'no preparation snapshots');foreach(['public','host','player'] as $view)check(count(array_filter($capture[$view]['cards'],fn($c)=>$c['zone']==='deck'))===0,'reserve hidden in history');}
check(count(array_filter($batch['actions'],fn($a)=>$a['type']==='recording_gap'))===0,'simple quick start has no recording gap');
// Post-initial native start exception also cannot publish partial Battle.
[$s,$r]=quickRun(3,['start'=>['type'=>'get_hp','hp'=>PHP_INT_MAX]],fn($min,$max)=>$min);
check(!$r->success && $s->status==='settings' && $s->cards===[],'startBattle exception atomic');
// Exercise the controlled no-cell path with a damaged board; no alternate geometry.
$blocked=manualToPlace(manualGenerated(3),1);
foreach (array_merge(...\Berserk\Core\ZoneManager::cellLevels('host')) as $cell) {
    [$row,$col]=array_map('intval',explode('_',$cell));$card=new CardInstance($blocked->nextInstanceId++,'block','player');
    $card->zone=CardInstance::ZONE_FIELD;$card->row=$row;$card->col=$col;$blocked->cards[$card->instanceId]=$card;
}
$method=new ReflectionMethod(Engine::class,'randomPlaceSquad');$method->setAccessible(true);$before=$blocked->toArray();
try{$method->invoke(new Engine(),$blocked,new PrepareProcessor($blocked),'host');check(false,'blocked cells accepted');}
catch(RuntimeException $e){check(str_contains($e->getMessage(),'No legal LuckTest placement') && $blocked->toArray()===$before,'no legal cell fails before changing damaged board');}
// Flying cards use the native startBattle zone conversion; reserve never enters flying zone.
$s=fresh();manualRolls('6,1');$r=(new Engine(fixture([card('fly',3,'fly')]),null,fn($min,$max)=>$min))->apply($s,'host',new Command('confirm_settings'));
check($r->success && $s->status==='battle' && count($s->getCardsInZone('host',CardInstance::ZONE_FLYING))===5,'native flying conversion');
// Shared Settings parsing governs the history-routing hint.
$s=fresh();$p=new PrepareProcessor($s);check($p->isLuckTestQuickStart(new Command('confirm_settings')),'default quick hint');
check(!$p->isLuckTestQuickStart(new Command('confirm_settings',['lucktest_quick_game'=>'0'])),'manual hint false');
check(!$p->isLuckTestQuickStart(new Command('confirm_settings',['lucktest_card_count'=>'bad'])),'invalid hint false');
$lobby=new GameState(321,11,22);manualRolls('1,6');RoomSetup::apply($lobby,RoomSetup::parse('lucktest',[]),fixture([card('army')]));check($lobby->status==='battle' && count($lobby->cards)===60,'Lobby quick route');
// CAS still commits one complete candidate when no history recording is configured.
$seed=fresh();$db=fixture([card('army')]);$db->game=['ind'=>123,'host_id'=>11,'player_id'=>22,'status'=>'settings','version'=>4,'json'=>json_encode($seed->toArray())];$repo=new GameRepository($db);$a=$repo->findById(123);$b=$repo->findById(123);
foreach([$a,$b] as $candidate){manualRolls('6,1');check((new Engine($db))->apply($candidate,'host',new Command('confirm_settings'))->success,'complete CAS candidate');}
$repo->save($a);$durable=$db->game;try{$repo->save($b);check(false,'stale quick saved');}catch(StaleStateException){check($db->game===$durable,'stale quick cannot overwrite');}
$loaded=$repo->findById(123);check($loaded->status==='battle' && count($loaded->cards)===60 && $loaded->persistenceVersion===5,'durable full Battle');
unset($_SESSION['debug_roll'],$_SESSION['debug_roll_state']);Dice::init();
echo 'LuckTest quick: '.($checks-$quickStart)." checks passed (no live database).\n";
