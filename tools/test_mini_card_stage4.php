<?php
declare(strict_types=1);
ob_start(); require __DIR__ . '/test_battle_snapshot_renderer.php'; ob_end_clean();
use Berserk\View\Ui\{ActiveCardEffects,LiveBattleCard,BattleCardMarkup};
use Berserk\Core\{CardStats,CardInstance,GameState};
use Berserk\View\Screen\{BattleScreen,ReplayScreen};
use Berserk\View\Template;
putenv('BERSERK_LEGACY_MINICARDS');
// Exercise the actual movement resolver: base OVA 1 plus two earned OVA modifiers.
$movingState=new GameState(999,101,202);$movingState->status='battle';$movingState->battle=['active'=>'host'];
$mover=battleSnapshotCard(80,'movement_fixture','host',CardInstance::ZONE_FIELD,3,3,true,0,
    ['ova'=>1,'on_move'=>[['type'=>'modifier','stat'=>'ova','value'=>1],['type'=>'modifier','stat'=>'ovz','value'=>-1]]]);
$mover->move=3;$mover->moveMax=3;$movingState->addCard($mover);
$resolver=new \Berserk\Core\Movement\MovementResolver($movingState,new \Berserk\Core\Engine());
foreach([[3,4],[4,4]] as [$row,$col]) {
    $result=$resolver->move('host',new \Berserk\Core\Command('move',['card_id'=>80,'row'=>$row,'col'=>$col]));
    battleSnapshotAssert($result->success,$result->error ?? 'Actual move');
}
$earned=implode(' ',ActiveCardEffects::forCard($mover,$movingState));
battleSnapshotAssert(CardStats::getOva($movingState,$mover)===3&&str_contains($earned,'ОВА +2')&&str_contains($earned,'ОВЗ -2'),'Actual movement effects project earned deltas, not base values');
$state=battleSnapshotState();$c=$state->getCard(1);
$c->prop=['direct'=>true,'ova'=>1,'ovz'=>2];
battleSnapshotAssert(ActiveCardEffects::forCard($c,$state)===[],'Unchanged base properties absent');
$c->modifiers=[['stat'=>'ova','value'=>1,'expire'=>'end_of_turn'],['stat'=>'ova','value'=>1,'expire'=>'end_of_turn'],['stat'=>'ovz','value'=>-1],['stat'=>'direct','value'=>1]];
$c->markers=['stun'=>['value'=>1,'expire'=>1,'timing'=>'owner_turn'],'spider_web'=>['value'=>1],'poison'=>['value'=>3]];
$effects=ActiveCardEffects::forCard($c,$state);
battleSnapshotAssert(CardStats::getOva($state,$c)===3 && count($effects)===5,'OVA changes independent from base; multiple statuses');
battleSnapshotAssert(str_contains(implode(' ',$effects),'ОВА +2') && str_contains(implode(' ',$effects),'ОВЗ -1') && !str_contains(implode(' ',$effects),'poison'),'Signed totals and separate poison');
$c->modifiers[]=['stat'=>'unknown_future_stat','value'=>2,'condition'=>'<img src=x onerror=alert(1)>'];
battleSnapshotAssert(str_contains(implode(' ',ActiveCardEffects::forCard($c,$state)),'unknown_future_stat'),'Unknown modifiers not dropped');
$source=battleSnapshotCard(30,'line_source','host',CardInstance::ZONE_FIELD,1,2,true,0,['has_line'=>true,'line_strike_aura'=>['value'=>2]]);$state->addCard($source);$c->prop['has_line']=true;
battleSnapshotAssert(str_contains(implode(' ',ActiveCardEffects::forCard($c,$state)),'Аура строя: удар +2'),'Active source aura uses connected-line helper');unset($state->cards[30]);unset($c->prop['has_line']);
$c->coins=2;$c->armor=1;$c->row=2;$c->col=2;$c->prop['actions']=[['type'=>'jump','key'=>'jump','name'=>'Прыжок','range'=>2]];
$visible=LiveBattleCard::render($c,$state,'host',false,'&lt;script&gt;','3/3');
battleSnapshotAssert(!str_contains($visible,'<script>') && !str_contains($visible,'<img src=x') && str_contains($visible,'&lt;img'),'Dynamic effects escaped');
$hidden=LiveBattleCard::render($c,$state,'player',true,'???','?/?');
battleSnapshotAssert(!str_contains($hidden,'data-mini-hint')&&!str_contains($hidden,'unknown_future_stat')&&!str_contains($hidden,'mini-art'),'Hidden card contains no indicators/art/effects');
$c->closed=true;$c->dying=true;
battleSnapshotAssert(str_contains(LiveBattleCard::render($c,$state,'host',false,'Name','3/3'),'mini-closed mini-dying'),'Closed/dying preserved');
$c->closed=false;$c->dying=false;
$legacy=BattleCardMarkup::render('','Legacy','3/3','','','');
battleSnapshotAssert(str_contains($legacy,'battle-card__main')&&!str_contains($legacy,'battle-mini-card'),'Replay component unchanged');
$tpl=new Template(__DIR__.'/../templates');
$replayCard=['instance_id'=>'a','definition'=>'a','owner'=>'host','zone'=>'field','row'=>1,'col'=>1,'closed'=>false,'revealed'=>true,'hp'=>3,'hp_max'=>3,'move'=>1,'move_max'=>1,'strike'=>null,'coins'=>2,'armor'=>1,'statuses'=>[],'markers'=>[],'effects'=>[]];
$preview=['metadata'=>['game_id'=>1,'participants'=>['host'=>101,'player'=>202]],'gaps'=>[],'steps'=>[['step_seq'=>1,'step_type'=>'initial','last_event_seq'=>1,'state'=>['turn'=>1,'active'=>'host','catalog'=>['a'=>['name'=>'Replay card']],'cards'=>[$replayCard]]]]];
$replay=(new ReplayScreen($tpl))->render($preview,1);
battleSnapshotAssert(!str_contains($replay,'battle-mini-card.js')&&!str_contains($replay,'mini-art')&&str_contains($replay,'Replay card'),'Replay HTML remains legacy');
// Image availability probe only; unique files are always removed. Browser routes fixture art.
$dir=__DIR__.'/../www/assets/cards/s1';$made=[];$walk=$dir;
while(!is_dir($walk)){$made[]=$walk;$walk=dirname($walk);}foreach(array_reverse($made) as $d)mkdir($d);
$paths=[];$artIndices=[];
try {
 foreach([1,2,3,5] as $i){$card=$state->getCard($i);$card->ukid='s1_stage4_'.bin2hex(random_bytes(6));$path=$dir.'/'.$card->ukid.'.webp';file_put_contents($path,'availability-probe');$paths[]=$path;$artIndices[$card->ukid]=[1=>1,2=>3,3=>2,5=>1][$i];$cardsInfo[$card->ukid]=['name'=>[1=>'Лесной страж',2=>'Хранитель затерянной древней цитадели',3=>'Крылатый дозорный',5=>'Союзник'][ $i ],'health'=>3,'price'=>4];}
 $enemy=$state->getCard(2);$enemy->revealed=true;$enemy->coins=2;$enemy->armor=1;$enemy->markers=['poison'=>['value'=>2],'stun'=>['value'=>1]];$enemy->modifiers=[['stat'=>'ova','value'=>2],['stat'=>'ovz','value'=>-1]];
 $c->modifiers=[['stat'=>'ova','value'=>2]];$c->markers=[];
 $secret=battleSnapshotCard(9,'s1_secret','player',CardInstance::ZONE_FIELD,6,5,false);$secret->coins=8;$secret->prop=['ova'=>9];$secret->markers=['stun'=>['value'=>1],'poison'=>['value'=>5]];$state->addCard($secret);$cardsInfo['s1_secret']=['name'=>'SECRET-NAME'];
 $state->cell_markers['3_3']=[['type'=>'lantern_light']];
 $before=serialize($state->toArray());
 $render=fn($ui)=>(new BattleScreen($tpl))->prepare($state,'host','host',null,$cardsInfo,$ui)['data'];
 $data=$render(['sel'=>1,'mode'=>'strike']);
 battleSnapshotAssert($before===serialize($state->toArray()),'View projection does not mutate game state');
 battleSnapshotAssert(str_contains($data['field_html'],'mini-art')&&str_contains($data['field_html'],'frame_enemy_wide'),'Actual renderer uses new image/frame');
 $enemy->coins=99;$enemy->armor=99;$enemy->markers['poison']['value']=99;$crowded=$render(['sel'=>1,'mode'=>'strike']);$enemy->coins=2;$enemy->armor=1;$enemy->markers['poison']['value']=2;
 $savedMods=$c->modifiers;$c->modifiers=[['stat'=>'ova','value'=>3]];$buffed=$render(['sel'=>1,'mode'=>'strike']);$c->modifiers=$savedMods;
 $c->closed=true;$closed=$render(['sel'=>1]);$c->closed=false;$c->dying=true;$dying=$render(['sel'=>1]);$c->dying=false;
 putenv('BERSERK_LEGACY_MINICARDS=1');$rollback=$render(['sel'=>1]);putenv('BERSERK_LEGACY_MINICARDS');
 battleSnapshotAssert($rollback['mini_card_design']==='legacy'&&!str_contains($rollback['field_html'],'battle-mini-card'),'Environment rollback');
 if(in_array('--fixture',$argv,true)) echo json_encode(['crowded'=>$crowded,'art_indices'=>$artIndices,'buffed'=>$buffed,'initial'=>$data,'jump'=>$render(['sel'=>1,'mode'=>'action:jump']),'move'=>$render(['sel'=>1,'mode'=>'strike']),'closed'=>$closed,'dying'=>$dying,'rollback'=>$rollback,'replay'=>$replay],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 else echo "Stage 4 PHP: base/active effects, poison, signs, stun/web, escaping, hidden data, state immutability, live renderer, replay and rollback passed.\n";
} finally {foreach($paths as $path)unlink($path);foreach($made as $d)rmdir($d);}
