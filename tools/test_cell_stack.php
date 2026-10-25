<?php
declare(strict_types=1);
$root=is_file(__DIR__.'/../src/Core/GameState.php') ? dirname(__DIR__) : 'C:/Users/mi/Documents/Codex/berserk';
ob_start();require $root.'/tools/test_battle_snapshot_renderer.php';ob_end_clean();
use Berserk\Core\{GameState,CardInstance};
use Berserk\View\Screen\BattleSnapshotRenderer;
use Berserk\View\Template;
$scenario=json_decode(file_get_contents($root.'/debug/scenarios/ui_cell_stack.json'),true,512,JSON_THROW_ON_ERROR);
$s=new GameState(992,101,202);$s->status='battle';$s->battle=['turn'=>1,'active'=>'host','strike'=>null];$info=[];
foreach($scenario['cards'] as $entry){$id=$s->nextInstanceId();$card=battleSnapshotCard($id,$entry['ukid'],$entry['owner'],CardInstance::ZONE_FIELD,$entry['row'],$entry['col'],true);$card->hp=$entry['hp'];$card->hpMax=7;$s->addCard($card);$info[$card->ukid]=['name'=>$card->ukid,'health'=>7];}
$ids=array_keys($s->cards);$s->cards[$ids[1]]->markers=['stun'=>['value'=>1]];
$probes=[];$artDir=$root.'/www/assets/cards/s1';$made=!is_dir($artDir);if($made)mkdir($artDir,0777,true);
foreach($ids as $id){$card=$s->cards[$id];$old=$card->ukid;$card->ukid='s1_stack_'.bin2hex(random_bytes(8));$info[$card->ukid]=$info[$old];$file=$artDir.'/'.$card->ukid.'.webp';file_put_contents($file,'availability-probe');$probes[]=$file;}
register_shutdown_function(function()use($probes,$made,$artDir){foreach($probes as $file)if(is_file($file))unlink($file);if($made)rmdir($artDir);});
$renderer=new BattleSnapshotRenderer(new Template($root.'/templates'));
$render=function()use($renderer,$s,&$info){$before=json_encode($s->toArray());$result=$renderer->render($s,'host','host',null,$info,['sel'=>0,'mode'=>'strike','pile'=>'']);battleSnapshotAssert($before===json_encode($s->toArray()),'Render must not mutate state');return $result['fragments']['field_html'];};
$s->cell_markers=['3_2'=>[['type'=>'bomb'],['type'=>'reduce_attack']],'1_1'=>[['type'=>'bonfire']],'1_2'=>[['type'=>'bonfire'],['type'=>'row_spell'],['type'=>'gate']],'1_3'=>[['type'=>'lantern_light']]];
$out=['two'=>$render(),'ids'=>$ids];
$s->cell_markers['3_2'][]=['type'=>'bonfire'];$out['occupied_fire']=$render();array_pop($s->cell_markers['3_2']);
$saved=$s->cards;$s->cards=array_reverse($s->cards,true);$out['reordered']=$render();$s->cards=$saved;
foreach($ids as $id)battleSnapshotAssert(str_contains($out['two'],'data-cell-occupant="'.$id.'"'),'Both IDs retained');
$s->cards[$ids[1]]->owner='host';$out['same']=$render();$s->cards[$ids[1]]->owner='player';
for($i=0;$i<4;$i++){$card=battleSnapshotCard($s->nextInstanceId(),'extra'.$i,'host',CardInstance::ZONE_FIELD,3,2,true);$s->addCard($card);$info[$card->ukid]=['name'=>'Extra '.$i];if($i===0)$out['three']=$render();}
$out['six']=$render();$s->cell_markers['3_2']=array_fill(0,12,['type'=>'bomb']);$out['overflow']=$render();
unset($s->cards[$ids[1]]);foreach($s->cards as $id=>$card)if(!in_array($id,$ids,true))unset($s->cards[$id]);$out['removed']=$render();
$s->cell_markers=[];$out['clean']=$render();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
