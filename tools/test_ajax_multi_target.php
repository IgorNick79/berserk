<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, CardInstance, Command, Engine, GameState, Db, GameRepository, CommandJournalRepository};
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
function mtAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function mtFixture(string $kind = 'discharge'): GameState {
    $s = new GameState(-990100, 1, 2);
    $s->status = 'battle';
    $s->battle = ['active'=>'host', 'turn'=>2, 'strike'=>null];
    $action = ['key'=>'multi', 'type'=>'multi_discharge', 'value'=>1, 'max_targets'=>3];
    if ($kind === 'heal') $action = ['key'=>'multi', 'type'=>'heal', 'value'=>1, 'max_targets'=>3];
    for ($id=1; $id<=6; $id++) {
        $owner = ($id===1 || ($kind==='heal' && $id<6)) ? 'host' : 'player';
        $s->addCard(new CardInstance(instanceId:$id, ukid:'multi_fixture_'.$id, owner:$owner,
            zone:CardInstance::ZONE_FIELD, row:$owner==='host'?3:4, col:(($id-1)%5)+1,
            hp:$kind==='heal'?8:10, hpMax:10, move:1, moveMax:1,
            prop:$id===1?['actions'=>[$action]]:[]));
    }
    $engine = new Engine();
    mtAssert($engine->apply($s, 'host', new Command('action', ['card_id'=>1,'action_key'=>'multi']))->success, 'Open real multi-target action');
    if ($kind === 'row') {
        unset($s->battle['pending_multi_discharge']);
        $s->battle['pending_row_spell_pick'] = ['owner'=>'host','source_id'=>1, 'x'=>3,
            'candidates'=>[2,3,4,5,6], 'dice'=>4,'level'=>'medium','val'=>1,'action'=>[]];
    }
    if ($kind === 'broken') $s->battle['pending_multi_discharge']['candidates'] = 'fault-injection';
    return $s;
}

// Optional bridge for the JS/real-HTTP suite. Refuse access to any existing/live DB.
if (in_array($argv[1] ?? '', ['fixture', 'inspect'], true)) {
    $config = require __DIR__ . '/../config/db.php';
    $expected = realpath(getenv('MULTITARGET_TEST_DATADIR') ?: '/nonexistent');
    mtAssert($expected !== false && $config['name']==='berserk_multitarget_test'
        && !file_exists(__DIR__.'/../.git'), 'Disposable checkout and explicit datadir required');
    $db = new Db($config);
    $meta = $db->fetchOne('SELECT @@datadir AS dir, DATABASE() AS db');
    mtAssert(realpath($meta['dir'])===$expected && $meta['db']===$config['name'], 'Wrong DB instance');
    foreach ($db->fetchAll('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $table) {
        mtAssert($table['ENGINE']==='InnoDB', 'InnoDB required');
    }
    $repo = new GameRepository($db);
    if ($argv[1]==='fixture') {
        $created = $repo->create(1,2);
        $s = mtFixture($argv[2] ?? 'discharge');
        $s->gameId=$created->gameId;
        $s->persistenceVersion=$created->persistenceVersion;
        $repo->save($s);
        echo json_encode(['game'=>$s->gameId, 'version'=>$s->persistenceVersion]);
    } else {
        $s=$repo->findById((int)$argv[2]);
        echo json_encode(['state'=>$s->toArray(), 'version'=>$s->persistenceVersion,
            'journal'=>$db->fetchAll('SELECT command_id,command_type,status,expected_version,before_version,after_version FROM battle_command_journal WHERE game_id='.$s->gameId.' ORDER BY id')]);
    }
    exit;
}

// Exercise the actual parser without executing HTTP/bootstrap/database code.
$source=file_get_contents(__DIR__.'/../www/index.php');
$start=strpos($source, 'function ajaxParamString(');
$end=strpos($source, 'function redirectToGamePage(');
eval(substr($source,$start,$end-$start));
if (($argv[1]??'')==='payload') {
    parse_str($argv[2],$_POST);
    echo json_encode(ajaxCommandPayload());
    exit;
}
$valid=['cmd'=>'choose_multi_discharge','target_ids'=>['2','3','4']];
foreach (['choose_multi_discharge','choose_multi_heal','choose_row_spell_targets','choose_anubisar_chain','choose_incarnation_wound','choose_intrigue_start'] as $cmd) {
    $_POST=array_replace($valid,['cmd'=>$cmd]);
    mtAssert(ajaxCommandPayload()===['target_ids'=>['2','3','4']], 'Allow only supported target arrays');
}
foreach ([['cmd'=>'move'], ['target_ids'=>[['2']]], ['target_ids'=>['x'=>'2']],
    ['target_ids'=>[false]],['target_ids'=>[1.5]],['target_ids'=>['-1']],['target_ids'=>['+1']],
    ['target_ids'=>[' 2']],['target_ids'=>['0']],['target_ids'=>['99999999999999999999']],
    ['target_ids'=>array_fill(0,65,'2')],['other'=>['2']],['huge'=>str_repeat('x',17000)]] as $bad) {
    $_POST=array_replace($valid,$bad);
    mtAssert(ajaxCommandPayload()===null, 'Reject malformed/unsupported/oversized payload: '.json_encode($bad));
}
$_POST=['cmd'=>'move','card_id'=>'1','row'=>'2','col'=>'3','expected_version'=>'7','command_id'=>'unit'];
mtAssert(ajaxCommandPayload()===['card_id'=>'1','row'=>'2','col'=>'3'], 'Single commands unchanged');
foreach ([[],[2],[2,3],[2,3,4],[2,3,4,5],[2,2,3],[1],[999]] as $targets) {
    $s=mtFixture();$engine=new Engine();
    $before=$s->toArray();
    $r=$engine->apply($s,'host',new Command('choose_multi_discharge',['target_ids'=>$targets]));
    $unique=array_values(array_unique($targets));
    $ok=count($unique)>0 && count($unique)<=3 && !array_diff($unique,[2,3,4,5,6]);
    mtAssert($r->success===$ok, 'Existing count/candidate rules');
    foreach ([2,3,4,5,6] as $id) mtAssert($s->getCard($id)->hp===10-($ok&&in_array($id,$unique,true)?1:0), 'Exactly once damage per unique target');
    if (!$ok) mtAssert(isset($s->battle['pending_multi_discharge']), 'Invalid pick retains pending');
}
$s=mtFixture();$engine=new Engine();
mtAssert(!$engine->apply($s,'player',new Command('cancel_pending'))->success && isset($s->battle['pending_multi_discharge']), 'Opponent cannot cancel');
$s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
mtAssert($engine->apply($s,'host',new Command('cancel_pending'))->success && !isset($s->battle['pending_multi_discharge']), 'Owner cancels restored pending through Engine');
mtAssert(!$engine->apply($s,'host',new Command('cancel_pending'))->success, 'Repeated cancel has no additional effect');
foreach (['heal'=>'choose_multi_heal','row'=>'choose_row_spell_targets'] as $kind=>$cmd) {
    $s=mtFixture($kind);
    mtAssert((new Engine())->apply($s,'host',new Command($cmd,['target_ids'=>[2,3,4]]))->success, 'Other multi-target command works');
}
echo "AJAX multi-target parser/Engine tests passed\n";
