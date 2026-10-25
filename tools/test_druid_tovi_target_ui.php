<?php
declare(strict_types=1);
require __DIR__.'/test_ajax_multi_target.php';
use Berserk\Core\{BattleHelper,Command,Engine,GameState};
use Berserk\Core\Choice\ForcedDirectionalMoveChoice;
use Berserk\Core\Movement\ForcedMovementResolver;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;
use Berserk\View\Ui\{Coords,Panel};
$tpl=new Template(__DIR__.'/../templates');$screen=new BattleScreen($tpl);
foreach(['host','player'] as $owner)foreach(['heal','throw'] as $kind){
    $s=mtFixture('heal');unset($s->battle['pending_multi_heal']);
    $s->battle['active']=$owner;
    foreach($s->cards as $card){
        if($owner==='player'){$card->owner=$card->owner==='host'?'player':'host';$card->row=7-$card->row;}
        $card->revealed=true;
    }
    $s->getCard(1)->prop=['actions'=>[['key'=>'test','type'=>$kind,'value'=>1,'range'=>10,'near_shot'=>true,'own'=>$kind==='heal']]];
    $info=[];foreach($s->cards as $card)$info[$card->ukid]=['name'=>$card->instanceId===1?($kind==='heal'?'Друид':'Тови'):'Цель <'.$card->instanceId.'>'];
    $before=$s->toArray();$targets=BattleHelper::getAttackTargets($s,$s->getCard(1),'action:test',$owner,true);
    mtAssert(count($targets)>0,'Native targets exist');
    $data=$screen->prepare($s,$owner,$owner,null,$info,['sel'=>1,'mode'=>'action:test'])['data'];
    $html=$data['info_panel_html'];
    mtAssert(str_contains($html,'type="radio" name="target_id"'),'Action targets in right panel');
    foreach($targets as $id=>$_){
        mtAssert(str_contains($html,'name="target_id" value="'.$id.'"'),'Exactly native candidate ID');
        $card=$s->getCard($id);mtAssert(str_contains($html,Coords::renderResult($card->row,$card->col,$owner,$card->owner)),'Viewer oriented coordinate');
    }
    mtAssert(substr_count($html,'name="target_id" value=')===count($targets),'No additional targets');
    mtAssert(str_contains($html,'name="action_key" value="test"'),'Native action key preserved');
    mtAssert(!str_contains($html,'Цель <2>'),'Names escaped');
    mtAssert($s->toArray()===$before,'UI has no game mutations');
    $other=$owner==='host'?'player':'host';
    mtAssert(!str_contains($screen->prepare($s,$other,$other,null,$info,['sel'=>1,'mode'=>'action:test'])['data']['info_panel_html'],'name="target_id"'),'Opponent cannot use active radio form');
    if($kind==='throw'){
        $s->getCard(6)->revealed=false;$info[$s->getCard(6)->ukid]['name']='SECRET_TARGET';
        $hidden=$screen->prepare($s,$owner,$owner,null,$info,['sel'=>1,'mode'=>'action:test'])['data']['panel_html'];
        mtAssert(!str_contains($hidden,'SECRET_TARGET')&&str_contains($hidden,'?/? HP'),'Hidden target name and HP remain hidden');
    }
}
$s=mtFixture('heal');unset($s->battle['pending_multi_heal']);
$s->battle[ForcedMovementResolver::PENDING_KEY]=['owner'=>'host','source_id'=>6,'delta_row'=>-1,'delta_col'=>0,'fallback'=>[]];
$spec=(new ForcedDirectionalMoveChoice())->spec($s,'host',[],'?first&game=1','host');
mtAssert($spec->form['type']==='radio'&&$spec->form['name']==='target_id','Tovi forced target is radio');
foreach($spec->form['items'] as $item){
    $c=$s->getCard((int)$item['value']);
    mtAssert(str_contains($item['label_html'],Coords::renderResult($c->row-1,$c->col,'host',$c->owner)),'Only destination coordinate');
    mtAssert(substr_count($item['label_html'],'class="coords coords--')===1,'Single coordinate per option');
    mtAssert(str_contains($item['label_html'],'directional-target-option')&&!str_contains($item['label_html'],' → '),'Aligned destination layout');
}
mtAssert(str_contains(Panel::render($spec),'name="cmd" value="choose_forced_directional_move"'),'Forced command unchanged');
$s=mtFixture('heal');unset($s->battle['pending_multi_heal']);$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'state'=>'results','kind'=>'heal','heal'=>1,'confirmed'=>[],'attack_dice'=>0,'defend_dice'=>0,'result'=>[]];
$html=(new InfoPanel($tpl))->render($s,'host','host',[],'?first&game=1');
mtAssert(str_contains($html,'+1 ❤️ HP'),'Healing result has heart before HP');
echo "Druid/Tovi target radios, coordinates, privacy and HP results passed\n";
