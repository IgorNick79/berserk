<?php
declare(strict_types=1);
require __DIR__.'/test_lucktest.php';
use Berserk\Core\{CardInstance,Command,Engine,SymbioteProcessor};
use Berserk\Core\BattleHistory\Recorder;
use Berserk\View\Template;
use Berserk\View\Screen\BattleScreen;
$start=$checks;$config=__DIR__.'/../config/auth.local.php';if(is_file($config))throw new RuntimeException('Disposable worktree required');
file_put_contents($config,'<?php return '.var_export(['debug_account_ids'=>[11,22]],true).';');
try{
 $companion=card('s2_4',3,'companion');$parasite=card('s2_6',4,'parasite');
 foreach([&$companion,&$parasite]as&$c){$c['release_id']=2;$c['single']=false;$c['move']=0;}unset($c);
 $normal=card('s1_normal',3);$normal['release_id']=1;$normal['single']=false;
 $db=fixture([$companion,$parasite,$normal]);$db->cards['s2_4']['prop']=$db->cards['s2_6']['prop']=null;
 $s=fresh();$s->historyRecorder=new Recorder($s->gameId,1,[],[],true);
 $s->lucktestDebug=['scenario_account_id'=>11,'scenario'=>[['owner'=>'host','ukid'=>'s2_4'],['owner'=>'host','ukid'=>'s2_6']]];
 $e=new Engine($db);$r=$e->apply($s,'host',new Command('confirm_settings',['lucktest_quick_game'=>'1','lucktest_balanced'=>'0']));
 check($r->success,'Debug accepts actual type values with null props');
 $sym=[];foreach($s->cards as$c)if(in_array($c->ukid,['s2_4','s2_6'],true))$sym[$c->ukid]=$c;
 check(count($sym)===2&&$sym['s2_4']->type==='companion'&&$sym['s2_6']->type==='parasite','catalog type passes into instances unchanged');
 check(empty($sym['s2_4']->prop['symbiote'])&&empty($sym['s2_6']->prop['symbiote']),'no JSON subtype required');
 foreach(['s2_4','s2_6']as$ukid){
  $p=$s->battle[SymbioteProcessor::PENDING];
  if($p['source_id']===null){check($e->apply($s,'host',new Command('choose_symbiote_host',['choice_id'=>$p['id'],'card_id'=>$sym[$ukid]->instanceId]))->success,'choose exact symbiote');$p=$s->battle[SymbioteProcessor::PENDING];}
  check($p['source_id']===$sym[$ukid]->instanceId,'correct source pending');
  $hosts=(new SymbioteProcessor($s,$e))->hosts($sym[$ukid]);check($hosts!==[],'eligible host exists');
  $id=$hosts[0];check($s->cards[$id]->owner===($ukid==='s2_4'?'host':'player'),'correct host ownership');
  check($e->apply($s,'host',new Command('choose_symbiote_host',['choice_id'=>$p['id'],'target_id'=>$id]))->success,'explicit host selection');
  check($sym[$ukid]->flags['symbiote_host_id']===$id,'link assigned');
 }
 check(empty($s->battle[SymbioteProcessor::PENDING]),'both real subtype cards seated');
 $catalog=[];foreach($s->cards as$c)$catalog[$c->ukid]=['name'=>$c->ukid,'price'=>1];
 $d=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,'host','host',null,$catalog)['data'];
 check(substr_count($d['field_html'],'<span data-cell-count-value>2</span>')===2,'both cells display total marker 2');
 foreach(['creature','fly','companion','parasite']as$type)check((new CardInstance(100,'probe','host',type:$type))->isCreature(),'creature family: '.$type);
 foreach(['artifact','terrain']as$type)check(!(new CardInstance(100,'probe','host',type:$type))->isCreature(),'not creature: '.$type);
 $old=new CardInstance(100,'old','host',prop:['symbiote'=>'companion']);check(SymbioteProcessor::subtype($old)===null,'type is authoritative, JSON does not reclassify ordinary card');
 echo 'Symbiote types: '.($checks-$start)." checks passed (no live DB).\n";
}finally{unlink($config);}
