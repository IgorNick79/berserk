<?php
declare(strict_types=1);
ob_start();require __DIR__.'/test_battle_snapshot_renderer.php';ob_end_clean();
use Berserk\Core\{CardInstance,GameState};
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
function pilePanelFixture(string $stage='pile',string $viewer='host',int $selected=0,string $pile='own_grave'):array {
 global $cardsInfo;
 $s=battleSnapshotState();$s->getCard(2)->revealed=true;$catalog=$cardsInfo;
 foreach(['host','player'] as $owner)foreach([CardInstance::ZONE_GRAVEYARD,CardInstance::ZONE_EXILE] as $zone)for($n=0;$n<14;$n++){
  $id=100+($owner==='host'?0:100)+($zone===CardInstance::ZONE_EXILE?50:0)+$n;
  $card=battleSnapshotCard($id,'pile_'.$id,$owner,$zone,null,null);
  if($n===0){$card->markers['incarnation']=['value'=>2,'threshold'=>3];$card->flags['valhalla_active']=true;}
  $s->addCard($card);$catalog[$card->ukid]=['name'=>'Плашка '.$id,'health'=>8,'move'=>1,'strike'=>['weak'=>2,'medium'=>3,'strong'=>4],'price'=>4,'element'=>'Тьма'];
 }
 if($stage==='instant')$s->battle['turn_instant_stack']=['state'=>'ordering','priority'=>$viewer,'phase'=>'turn','stack'=>[]];
 if($stage==='combat')$s->battle['strike']=['kind'=>'cell_marker','state'=>'results','attacker_id'=>1,'target_id'=>2,'cell_marker'=>['row'=>3,'col'=>1],'confirmed'=>[]];
 if($stage==='tasks')$s->battle['turn_phase']=['phase'=>'start','side'=>'active','active_key'=>$viewer,'passive_key'=>$s->getOpponentKey($viewer),'active_queue'=>[
  ['id'=>'incarnation-task','label'=>'Инкарнация','type'=>'incarnation'],['id'=>'valhalla-task','label'=>'Вальхалла','type'=>'valhalla']],'passive_queue'=>[]];
 $before=serialize($s->toArray());
 $data=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,$viewer,$viewer,null,$catalog,['sel'=>$selected,'pile'=>$pile])['data'];
 battleSnapshotAssert(serialize($s->toArray())===$before,'Pile view is read-only');return $data;
}
if(in_array('--fixture',$argv??[],true)){echo json_encode(pilePanelFixture($argv[2]??'pile',$argv[3]??'host',(int)($argv[4]??0),$argv[5]??'own_grave'),JSON_UNESCAPED_UNICODE);exit;}
$n=0;function pileCheck(bool $condition,string $label):void{global $n;$n++;battleSnapshotAssert($condition,$label);}
foreach(['host','player'] as $viewer)foreach(['own_grave','opp_grave','own_exile','opp_exile'] as $pile){
 $d=pilePanelFixture('pile',$viewer,1,$pile);pileCheck($d['ui']['sel']===0,'Old card closed');pileCheck($d['pile_reveal_html']==='','No list below field');
 pileCheck(str_contains($d['info_panel_html'],'data-pile-list="'.$pile.'"'),'Correct panel list');
 $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$d['info_panel_html']);$x=new DOMXPath($dom);
 pileCheck($x->query('//*[@data-pile-list]//a[contains(@class,"prepare-card-link") and contains(@href,"sel=")]')->length>=14,'Clickable Draft cards');
 $id=($viewer==='host'?100:200);if(str_starts_with($pile,'opp_'))$id=$viewer==='host'?200:100;if(str_ends_with($pile,'exile'))$id+=50;
 $selected=pilePanelFixture('pile',$viewer,$id,$pile);pileCheck($selected['ui']['sel']===$id&&str_contains($selected['panel_html'],'Плашка '.$id),'Selected pile card opens');
 pileCheck(str_contains($selected['panel_html'],'К списку')&&!str_contains($selected['panel_html'],'mode='),'Inspection has return, no battle actions');
 pileCheck(str_contains($selected['info_panel_html'],'data-pile-list="'.$pile.'" hidden'),'List hides behind card');
 $wrong=pilePanelFixture('pile',$viewer,$id+($id<200?100:-100),$pile);pileCheck($wrong['ui']['sel']===0,'Cannot select card from another pile');
}
$d=pilePanelFixture('tasks');pileCheck(str_contains($d['info_panel_html'],'cmd=turn_task&amp;task_id=incarnation-task')||str_contains($d['info_panel_html'],'task_id=incarnation-task'),'Incarnation task retained');pileCheck(str_contains($d['info_panel_html'],'task_id=valhalla-task'),'Valhalla task retained');
foreach(['host','player'] as $viewer){$d=pilePanelFixture('instant',$viewer,1,'');pileCheck(str_contains($d['info_panel_html'],'data-battle-fragment="panel_html" hidden'),'Stack closes selected card');pileCheck(str_contains($d['info_panel_html'],'data-combat-key="instant:1"'),'Stack has local context identity');pileCheck(str_contains($d['info_panel_html'],'cmd=pass_turn_instant'),'Stack controls retained');pileCheck(str_contains($d['panel_html'],'data-combat-back')&&!str_contains($d['panel_html'],'mode='),'Stack inspection read-only');}
$d=pilePanelFixture('combat','host',1);pileCheck(str_contains($d['info_panel_html'],'>Сражение</h3>')&&$d['pile_reveal_html']!==''&&!str_contains($d['info_panel_html'],'data-pile-list'),'Combat/pile behavior unchanged');
echo "Pile/instant InfoPanel: $n checks passed\n";
