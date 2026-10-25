<?php
declare(strict_types=1);
ob_start();require __DIR__.'/test_battle_snapshot_renderer.php';ob_end_clean();
use Berserk\Core\{ActionResolver,CardInstance,Command,Engine,GameState,TurnPhaseProcessor,ZoneManager};
use Berserk\Core\Choice\{GatePickChoice,GreedTeleportChoice,RowPickChoice,TurnAckChoice};
use Berserk\View\Ui\{BattlefieldPosition,CellMarkerPicker,Coords};
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
function gfState(string $owner='host'): GameState {
 $s=new GameState(991,101,202);$s->status='battle';$s->battle=['active'=>$owner,'turn'=>1];
 $s->addCard(battleSnapshotCard(1,'demon',$owner,CardInstance::ZONE_FIELD,2,2,true));$s->getCard(1)->hp=$s->getCard(1)->hpMax=10;
 $s->addCard(battleSnapshotCard(2,'enemy',$s->getOpponentKey($owner),CardInstance::ZONE_FIELD,5,5,true));return $s;
}
$fixtures=[];
foreach(['host','player'] as $owner){
 $s=gfState($owner);$s->battle['pending_gate_pick']=['owner'=>$owner,'source_id'=>1,'count'=>3,'chosen'=>[],'candidates'=>['1_1','3_2','6_5']];
 $choice=new GatePickChoice();$spec=$choice->spec($s,$owner,[],'?game=991',$owner);
 battleSnapshotAssert(!in_array('cancel_pending',$choice->commandTypes(),true)&&count($spec->buttons)===3,'Mandatory gate has no cancel');
 CellMarkerPicker::decorate($spec,$s,$owner,'?game=991');battleSnapshotAssert(count($spec->cellPicker['targets'])===3,'Gates use existing compact picker');
 $before=serialize($s->toArray());$resolver=new ActionResolver($s,new Engine());$result=$resolver->cancelPending($owner,new Command('cancel_pending',[]));battleSnapshotAssert(!$result->success&&serialize($s->toArray())===$before,'Direct cancel rejected without mutation');
 $fly=battleSnapshotCard(3,'fly',$owner,CardInstance::ZONE_FLYING,null,null,true);$s->addCard($fly);
 $fixtures[$owner]=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,$owner,$owner,null,['demon'=>['name'=>'Демон жадности']])['data'];
 foreach([[1,1],[3,2],[6,5]] as [$row,$col]){$result=$resolver->chooseGate($owner,new Command('choose_gate',['row'=>$row,'col'=>$col]));battleSnapshotAssert($result->success,'Required gate placement');}
 battleSnapshotAssert(!isset($s->battle['pending_gate_pick'])&&count($s->cell_markers)===3,'All three mandatory gates placed');
 $s=gfState($owner);$s->cell_markers=['4_1'=>[['type'=>'gate','source'=>$owner],['type'=>'bonfire']],'6_4'=>[['type'=>'gate','source'=>$owner]]];
 $s->battle['pending_greed_teleport']=['owner'=>$owner,'source_id'=>1,'free_gates'=>['4_1','6_4'],'gates'=>['4_1','6_4'],'damage'=>2];
 $spec=(new GreedTeleportChoice())->spec($s,$owner,[],'?game=991',$owner);battleSnapshotAssert(count($spec->buttons)===1&&!str_contains($spec->buttons[0]['url'],'row=4'),'Fire gate hidden');battleSnapshotAssert($spec->buttons[0]['label_html']===Coords::render(6,4,$owner),'Teleport button proper coordinates');
 $resolver=new ActionResolver($s,new Engine());$before=serialize($s->toArray());$result=$resolver->chooseGreedTeleport($owner,new Command('choose_greed_teleport',['row'=>4,'col'=>1]));battleSnapshotAssert(!$result->success&&serialize($s->toArray())===$before,'Stale fire teleport rejected');
 $s->battle['turn_phase']=['phase'=>'end'];$result=$resolver->chooseGreedTeleport($owner,new Command('choose_greed_teleport',['row'=>6,'col'=>4]));battleSnapshotAssert($result->success&&$s->getCard(1)->row===6,'Valid teleport');
 foreach(['host','player'] as $viewer){$spec=(new TurnAckChoice())->spec($s,$viewer,[],'?game=991',$viewer);$pos=BattlefieldPosition::display(6,4,$viewer);battleSnapshotAssert(str_contains($spec->title,'['.Coords::rowLabel($pos['row']).':'.$pos['col'].']'),'Teleport result viewer coordinates');}
 // Real end-turn queue filters bonfire gates before presenting the choice.
 foreach([false,true] as $allBlocked){$s=gfState($owner);$s->getCard(1)->prop=['opponent_turn_end'=>[['type'=>'greed_teleport','damage'=>2,'self_damage'=>3]]];$s->cell_markers=['4_1'=>[['type'=>'gate','source'=>$owner],['type'=>'bonfire']],'6_4'=>[['type'=>'gate','source'=>$owner]]];if($allBlocked)$s->cell_markers['6_4'][]=['type'=>'bonfire'];$s->battle['active']=$s->getOpponentKey($owner);(new TurnPhaseProcessor($s,new Engine()))->beginEndPhase($s->getOpponentKey($owner));if($allBlocked)battleSnapshotAssert(empty($s->battle['pending_greed_teleport'])&&$s->getCard(1)->hp===7,'All gates blocked uses existing self-damage');else battleSnapshotAssert(($s->battle['pending_greed_teleport']['free_gates']??[])===['6_4'],'End phase excludes bonfire');}
 $s=gfState($owner);$s->battle['pending_row_pick']=['owner'=>$owner,'card_id'=>1,'action'=>[]];$spec=(new RowPickChoice())->spec($s,$owner,[],'?game=991',$owner);
 for($ui=1;$ui<=6;$ui++){$physical=$owner==='host'?$ui:7-$ui;$pos=BattlefieldPosition::display($physical,1,$owner);$b=$spec->buttons[$ui-1];battleSnapshotAssert($b['label']==='Ряд '.Coords::rowLabel($pos['row']),'Tergala row label');$copy=clone $s;$copy->cards=array_map(fn($c)=>clone $c,$s->cards);$res=(new ActionResolver($copy,new Engine()))->chooseRow($owner,new Command('choose_row',['row'=>$ui]));battleSnapshotAssert($res->success&&count(ZoneManager::markersAt($copy,$physical.'_1'))===1,'Tergala command mapping unchanged');}
}
foreach(['host','player'] as $owner){
 $s=gfState($owner);$screen=new BattleScreen(new Template(__DIR__.'/../templates'));
 $s->battle['pending_greed_teleport']=['owner'=>$owner,'source_id'=>1,'free_gates'=>['1_1','6_5'],'gates'=>['1_1','6_5'],'damage'=>2];
 $spec=(new GreedTeleportChoice())->spec($s,$owner,[],'?game=991',$owner);
 foreach($spec->buttons as $i=>$button){$pos=BattlefieldPosition::display($i===0?1:6,$i===0?1:5,$owner);battleSnapshotAssert(str_contains($button['class'],'red')===($pos['side']==='enemy'),'Greed button color follows field half');}
 $fixtures[$owner]['teleport_info_html']=$screen->prepare($s,$owner,$owner,null,[])['data']['info_panel_html'];
 unset($s->battle['pending_greed_teleport']);$s->battle['pending_row_pick']=['owner'=>$owner,'card_id'=>1,'action'=>[]];
 $fixtures[$owner]['rows_info_html']=$screen->prepare($s,$owner,$owner,null,[])['data']['info_panel_html'];
 unset($s->battle['pending_row_pick']);
 for($die=1;$die<=6;$die++){$s->battle['pending_row_spell_pick']=['owner'=>$owner,'source_id'=>1,'x'=>1,'candidates'=>[2],'dice'=>$die,'level'=>'medium','val'=>2,'action'=>[]];$before=serialize($s->toArray());$html=$screen->prepare($s,$owner,$owner,null,[])['data']['info_panel_html'];battleSnapshotAssert(str_contains($html,'dice_'.$die.'.svg'),'Rune die face visible through actual InfoPanel');battleSnapshotAssert(serialize($s->toArray())===$before,'Rune die display does not reroll/mutate');if($die===4)$fixtures[$owner]['runes_info_html']=$html;}
}
if(in_array('--fixture',$argv,true))echo json_encode($fixtures,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);else echo "PASS: mandatory gates/picker, forged cancel rejection, fire teleport generation and stale rejection, all-blocked self-damage, valid teleport/coordinates for both viewers, Tergala rows.\n";
