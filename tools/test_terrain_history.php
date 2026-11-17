<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest.php';
use Berserk\Core\{Command,Engine};
use Berserk\Core\BattleHistory\{Recorder,ReplayState};
$config=__DIR__.'/../config/auth.local.php';
if (is_file($config)) throw new RuntimeException('Run in a disposable worktree without auth.local.php');
file_put_contents($config,'<?php return '.var_export(['debug_account_ids'=>[11,22]],true).';');
$start=$checks;
try {
    $s=fresh();
    $s->lucktestDebug=['scenario_account_id'=>11,'scenario'=>[['owner'=>'host','ukid'=>'s2_11']]];
    $terrain=card('s2_11',4,'terrain');$terrain['health']=0;$terrain['move']=0;$terrain['single']=false;
    $terrain['strike_weak']=$terrain['strike_medium']=$terrain['strike_strong']=0;
    $creature=card('s1_test',3);$creature['single']=false;
    $s->historyRecorder=new Recorder($s->gameId,1,[],[],true);
    $before=$s->toArray();
    $result=(new Engine(fixture([$terrain,$creature])))->apply($s,'host',new Command('confirm_settings',[
        'lucktest_quick_game'=>'1','lucktest_balanced'=>'0',
    ]));
    check($result->success && $s->status==='battle','exact s2_11 Debug scenario starts with production-style Recorder: '.($result->error??''));
    $batch=$s->historyRecorder->batch($s)->data();
    check(count($batch['captures'])>0,'initial snapshots captured');
    foreach($batch['captures'] as $capture) foreach(['state','public','host','player'] as $view) {
        ReplayState::validate($capture[$view]);
        $terrains=array_filter($capture[$view]['cards'],static fn($c)=>$c['zone']==='terrain');
        check(count($terrains)>0,'terrain present in '.$view.' snapshot');
        $invalid=$capture[$view];$invalid['cards'][0]['zone']='unknown_zone';
        try {ReplayState::validate($invalid);check(false,'unknown zone accepted');}
        catch(InvalidArgumentException){check(true,'unknown zones still rejected');}
    }
    echo 'Terrain history: '.($checks-$start)." checks passed (no live DB).\n";
} finally {unlink($config);}
