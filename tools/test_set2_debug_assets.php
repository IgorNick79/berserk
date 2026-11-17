<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest.php';
use Berserk\Core\{CardInstance,Command,Engine,GameState};
use Berserk\Core\BattleHistory\Recorder;
use Berserk\View\Template;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Ui\{LiveBattleCard,PreGameCard};
$start=$checks;
$normal=card('s1_normal',3);$normal['single']=false;$normal['release_id']=1;
$terrain=card('s2_11',4,'terrain');$terrain['release_id']=2;$terrain['single']=false;
$other=card('s2_other',3);$other['release_id']=2;$other['single']=false;
$catalog=[$normal,$terrain,$other];
$s=fresh();$r=(new Engine(fixture($catalog)))->apply($s,'host',new Command('confirm_settings',['lucktest_quick_game'=>'1','lucktest_balanced'=>'0']));
check($r->success && $s->status==='battle','ordinary Quick Game starts');
foreach($s->cards as $c)check(!str_starts_with($c->ukid,'s2_'),'set 2 excluded from random squad AND reserve');
$builder=new \Berserk\Core\Prepare\DraftDeckBuilder(fixture($catalog));
check(count($builder->buildDeckCards(['s1_normal']))===1,'ordinary set 1 deck builder unchanged');
try {$builder->buildDeckCards(['s2_11']);check(false,'set 2 accepted by shared deck builder');}
catch(RuntimeException $e){check(str_contains($e->getMessage(),'Debug LuckTest'),'shared draft/sealed deck builder rejects set 2');}
$metadataOnly=card('catalog_extra',3);$metadataOnly['release_id']=2;
try {(new \Berserk\Core\Prepare\DraftDeckBuilder(fixture([$metadataOnly])))->buildDeckCards(['catalog_extra']);check(false,'release metadata ignored');}
catch(RuntimeException){check(true,'deck restriction uses release_id independently of ukid prefix');}
foreach(['system','draft','sealed'] as $mode){
 $blocked=new GameState(555,11,22);$blocked->mode=$mode;$blocked->status='deck';$before=$blocked->toArray();
 $payload=['deck_id'=>1,'other_deck_id'=>2,'valid_deck_ids'=>[1,2],'deck_cards'=>[$terrain],'other_cards'=>[$normal]];
 $result=(new \Berserk\Core\Prepare\PrepareProcessor($blocked))->selectDeck('host',new Command('select_deck',$payload));
 check(!$result->success && $blocked->toArray()===$before,'server rejects set 2 selection without mutation: '.$mode);
}
$config=__DIR__.'/../config/auth.local.php';if(is_file($config))throw new RuntimeException('Disposable worktree required');
file_put_contents($config,'<?php return '.var_export(['debug_account_ids'=>[11,22]],true).';');
$asset=__DIR__.'/../www/assets/cards/s2/s2_11.webp';$dir=dirname($asset);$createdDir=!is_dir($dir);$createdFile=false;
try {
 $s=fresh();$s->lucktestDebug=['scenario_account_id'=>11,'scenario'=>[
  ['owner'=>'host','ukid'=>'s2_11'],['owner'=>'player','ukid'=>'s2_other','zone'=>'deck']
 ]];$s->historyRecorder=new Recorder($s->gameId,1,[],[],true);
 $r=(new Engine(fixture($catalog)))->apply($s,'host',new Command('confirm_settings',['lucktest_quick_game'=>'1','lucktest_balanced'=>'0']));
 check($r->success && $s->status==='battle','explicit Debug set 2 starts with history');
 $found=[];foreach($s->cards as$c)if(str_starts_with($c->ukid,'s2_'))$found[]=$c;
 check(count($found)===2,'only the two explicitly requested set 2 occurrences exist');
 foreach($found as$c)check(($c->ukid==='s2_11'&&$c->owner==='host'&&$c->zone==='terrain')||($c->ukid==='s2_other'&&$c->owner==='player'&&$c->zone==='deck'),'Debug retains exact owner and zone');
 if($createdDir)mkdir($dir,0777,true);
 if(!is_file($asset)){file_put_contents($asset,'synthetic filesystem fixture; not a rendered image');$createdFile=true;}
 clearstatcache();$cards=['s2_11'=>['name'=>'Terrain','type'=>'terrain','release_id'=>2,'price'=>4]];
 $t=array_values(array_filter($found,fn($c)=>$c->ukid==='s2_11'))[0];
 $data=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$cards,['sel'=>$t->instanceId])['data'];
 check(str_contains($data['panel_html'],'/assets/cards/s2/s2_11.webp'),'right panel path from release_id');
 check(str_contains($data['piles_html'],'battle-card battle-mini-card')&&str_contains($data['piles_html'],'mini-frame'),'terrain slot uses standard card renderer and frame');
 check(str_contains($data['piles_html'],'/assets/cards/s2/s2_11.webp'),'terrain slot has art');
 check(!str_contains($data['piles_html'],'mini-hp'),'terrain slot has no HP');
 $pre=(new PreGameCard(new Template(__DIR__.'/../templates')))->render('s2_11',$cards);
 check(str_contains($pre,'/assets/cards/s2/s2_11.webp'),'description image from release_id');
 $cards['s2_11']['release_id']=1;
 $pre=(new PreGameCard(new Template(__DIR__.'/../templates')))->render('s2_11',$cards);
 check(!str_contains($pre,'/assets/cards/s2/s2_11.webp'),'metadata takes precedence over ukid prefix');
 echo 'Set 2 availability/assets: '.($checks-$start)." checks passed (no live DB/browser).\n";
}finally{
 unlink($config);if($createdFile)unlink($asset);if($createdDir)rmdir($dir);
}
