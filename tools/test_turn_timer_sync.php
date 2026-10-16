<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,TurnTimer,Db,GameRepository,CommandJournalRepository};
use Berserk\View\{Template};
use Berserk\View\Screen\{BattleSnapshotRenderer};
use Berserk\View\Screen\Battle\InfoPanel;
Autoloader::register();
Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
function tsAssert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$s=new GameState(990102,1,2);
$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];
$s->persistenceVersion=7;
TurnTimer::prepare($s,'host');
$tpl=new Template(__DIR__.'/../templates/');
$html=(new InfoPanel($tpl))->render($s,'host','host',[],'?first&game=990102');
tsAssert(str_contains($html,'data-turn-timer-value>03:00') && str_contains($html,'Ваше время · Пауза'),'Paused boot UI');
tsAssert(str_contains($html,'#1 · Ваш ход') && !str_contains($html,'Мой ход —'),'Compact header');
$guest=(new InfoPanel($tpl))->render($s,'player','player',[],'?second&game=990102');
tsAssert(str_contains($guest,'Соперник · Пауза') && str_contains($guest,'data-mine="0"'),'Ownership comes from timer');
$s->battle['active']='player';
$earlySwitch=(new InfoPanel($tpl))->render($s,'host','host',[],'?first&game=990102');
tsAssert(str_contains($earlySwitch,'data-mine="1"') && str_contains($earlySwitch,'Ваше время · Пауза'),'Early active switch cannot transfer timer ownership');
$s->battle['active']='host';
TurnTimer::resume($s,1,'start_phase',1000);
$before=$s->toArray();
$renderer=new BattleSnapshotRenderer($tpl);
$host=$renderer->render($s,'host','host',null,[],[],1030);
$guest=$renderer->render($s,'player','player',null,[],[],1030);
tsAssert($host['turn_timer']===$guest['turn_timer'],'Identical server contract for both players');
tsAssert($host['turn_timer']['remaining_seconds']===150 && $host['turn_timer']['server_now']===1030
    && $host['turn_timer']['limit_seconds']===180,'Controlled server projection');
tsAssert($s->toArray()===$before && $s->persistenceVersion===7,'Rendering never writes timer or versions');
$expired=$renderer->render($s,'host','host',null,[],[],1180);
tsAssert($expired['turn_timer']['status']==='expired' && $expired['turn_timer']['remaining_seconds']===0,'Read-only expiry');
tsAssert($s->toArray()===$before && $s->battle['active']==='host','Expiry does not advance turn');
// A persisted version is not an ordering token for read-only wall-clock samples.
// Reproduce a clock correction after a client has already seen read-only expiry.
$newer=clone $s;
$newer->persistenceVersion++;
TurnTimer::settle($newer,1100);
$clockBack=$renderer->render($newer,'host','host',null,[],[],1100);
tsAssert($newer->persistenceVersion>$s->persistenceVersion
    && $clockBack['turn_timer']['period_id']===$expired['turn_timer']['period_id']
    && $clockBack['turn_timer']['server_now']<$expired['turn_timer']['server_now']
    && $clockBack['turn_timer']['status']==='running','Newer version can carry an older temporal projection');
echo "Turn timer snapshot/UI tests passed\n";

$url=getenv('TURN_TIMER_TEST_URL')?:'';
if($url==='') {echo "Skipping timer HTTP checks: TURN_TIMER_TEST_URL not configured\n";exit;}
$config=require __DIR__.'/../config/db.php';
$expected=realpath(getenv('TURN_TIMER_TEST_DATADIR')?:'/nonexistent');
tsAssert($expected!==false && $config['name']==='berserk_turn_timer_t2_test'
    && !file_exists(__DIR__.'/../.git') && preg_match('~^http://127\.0\.0\.1:\d+/index\.php$~D',$url)===1,'Disposable HTTP/DB environment required');
$db=new Db($config);
$meta=$db->fetchOne('SELECT @@datadir AS dir,DATABASE() AS db');
tsAssert(realpath($meta['dir'])===$expected && $meta['db']===$config['name'],'Wrong DB instance');
foreach($db->fetchAll('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $table)tsAssert($table['ENGINE']==='InnoDB','InnoDB required');
// Verify the HTTP server serves this isolated source tree before creating any fixtures.
$token=bin2hex(random_bytes(16));$probe=__DIR__.'/../www/timer-probe-'.$token.'.txt';
file_put_contents($probe,$token);
try {tsAssert(file_get_contents(substr($url,0,-strlen('index.php')).basename($probe))===$token,'Wrong HTTP source tree');}
finally {unlink($probe);}
$repo=new GameRepository($db);$journal=new CommandJournalRepository($db);
function tsRequest(string $query,?array $post=null): array {
    global $url;
    $http=['method'=>$post===null?'GET':'POST','timeout'=>10,'ignore_errors'=>true];
    if($post!==null){$http['header']='Content-Type: application/x-www-form-urlencoded';$http['content']=http_build_query($post);}
    return json_decode(file_get_contents($url.'?'.$query,false,stream_context_create(['http'=>$http])),true,512,JSON_THROW_ON_ERROR);
}
foreach(['running','paused','expired','legacy'] as $mode) {
    $state=$repo->create(1,2);$state->status='battle';
    $state->players['host']->side=1;$state->players['player']->side=2;
    $state->battle=['active'=>'host','turn'=>1,'strike'=>null,'hidden_row_revealed'=>false];
    if($mode!=='legacy') {
        TurnTimer::prepare($state,'host');
        if($mode!=='paused')TurnTimer::resume($state,1,'start_phase',time()-($mode==='expired'?200:20));
    }
    $repo->save($state);
    $before=$db->fetchOne('SELECT json,version FROM games WHERE ind='.$state->gameId);
    $query='game='.$state->gameId;
    $h=tsRequest('first&'.$query.'&ajax=battle_sync&force_snapshot=1');
    $g=tsRequest('second&'.$query.'&ajax=battle_sync&force_snapshot=1');
    tsAssert($h['type']==='snapshot' && $g['type']==='snapshot','Both viewers receive snapshot');
    if($mode==='legacy')tsAssert($h['turn_timer']===null && $g['turn_timer']===null,'Legacy does not invent a period');
    else {
        foreach(['period_id','owner','status','limit_seconds','pause_reason'] as $key)tsAssert($h['turn_timer'][$key]===$g['turn_timer'][$key],'Shared timer identity/status');
        tsAssert(abs($h['turn_timer']['remaining_seconds']-$g['turn_timer']['remaining_seconds'])<=1,'Shared countdown');
        tsAssert($h['turn_timer']['status']===$mode,'Server status projection');
    }
    $unchanged=tsRequest('first&'.$query.'&ajax=battle_sync&since_version='.$state->persistenceVersion);
    tsAssert($unchanged['type']==='no_change' && array_key_exists('turn_timer',$unchanged),'No-change carries timer');
    $status=tsRequest('second&'.$query.'&ajax=battle_command_status&command_id=missing-timer-probe');
    tsAssert($status['command_status']==='unknown' && array_key_exists('turn_timer',$status),'Recovery status carries timer, remains unknown');
    tsAssert($before===$db->fetchOne('SELECT json,version FROM games WHERE ind='.$state->gameId),'Polling does not persist seconds/expiry');
    tsAssert($journal->find($state->gameId,'player','missing-timer-probe')===null,'Polling creates no journal entries');
    if($mode==='expired') {
        tsAssert($unchanged['turn_timer']['remaining_seconds']===0,'Expired no-change shows zero');
        $loaded=$repo->findById($state->gameId);
        tsAssert($loaded->battle['active']==='host' && $loaded->battle['turn_timer']['period_id']===1,'No automatic turn transition');
        $next=tsRequest('first&'.$query.'&ajax=battle_command',['cmd'=>'end_turn','expected_version'=>$state->persistenceVersion,'command_id'=>'timer-explicit-end']);
        tsAssert($next['command_status']==='committed' && $next['turn_timer']['period_id']===2
            && $next['turn_timer']['owner']==='player' && $next['turn_timer']['remaining_seconds']>=179,'Explicit command starts next server period');
    }
}
echo "Turn timer HTTP/no-change/read-only tests passed\n";
