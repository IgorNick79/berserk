<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest.php';
use Berserk\Core\{Engine,Command};
use Berserk\Core\Prepare\LuckTestScenario;
use Berserk\Core\BattleHistory\Recorder;
$start=$checks;
$bad='{"cards":[{"owner":"host","ukid":"s2_24"},]}';
try{LuckTestScenario::parse($bad,15);check(false,'trailing comma accepted');}catch(DomainException $e){check(str_contains($e->getMessage(),'JSON'),'exact submitted trailing comma rejected');}
$entries=LuckTestScenario::parse('{"cards":[{"owner":"host","ukid":"s2_24"}]}',15);
check($entries[0]['ukid']==='s2_24','corrected JSON accepted');
$config=__DIR__.'/../config/auth.local.php';if(is_file($config))throw new RuntimeException('Disposable worktree required');
file_put_contents($config,'<?php return '.var_export(['debug_account_ids'=>[11,22]],true).';');
try{
 $s=fresh();$s->lucktestDebug=['scenario_account_id'=>11,'scenario'=>$entries];$s->historyRecorder=new Recorder($s->gameId,1,[],[],true);
 $artifact=card('s2_24',4,'artifact');$artifact['release_id']=2;$artifact['single']=false;
 $creature=card('s1_normal',3);$creature['release_id']=1;$creature['single']=false;
 $r=(new Engine(fixture([$artifact,$creature])))->apply($s,'host',new Command('confirm_settings',['lucktest_quick_game'=>'1','lucktest_balanced'=>'0']));
 check($r->success&&$s->status==='battle','corrected artifact scenario starts with Recorder');
 $artifacts=array_filter($s->cards,fn($c)=>$c->ukid==='s2_24');check(count($artifacts)===1,'only explicit artifact occurrence');
 $a=array_values($artifacts)[0];check($a->owner==='host'&&$a->zone==='field'&&$a->hp>0,'artifact placed on host field with health');
 check(count($s->historyRecorder->batch($s)->data()['captures'])>0,'history snapshots captured');
 echo 'Artifact Debug: '.($checks-$start)." checks passed (synthetic catalog, no live DB).\n";
}finally{unlink($config);}
