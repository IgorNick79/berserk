<?php
declare(strict_types=1);
require __DIR__ . '/test_battle_snapshot_renderer.php';
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Screen\BattleSnapshotRenderer;
use Berserk\View\Template;
function stage1bDom(string $html): DOMXPath {
    $dom=new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    return new DOMXPath($dom);
}
$tpl=new Template(__DIR__.'/../templates'); $cases=0;
foreach(['host','player'] as $viewer) foreach(['host','player'] as $active) {
    foreach(['normal','cell','result','ack','forced'] as $context) foreach([0,1] as $selection) {
        $_SESSION=[]; $_GET=[];
        $s=battleSnapshotState(); $s->battle['active']=$active;
        $s->getCard(2)->revealed=true;
        if($context==='cell') $s->battle['pending_cell_marker_pick']=[
            'owner'=>$active,'card_id'=>1,'marker'=>'bonfire','label'=>'Костёр','cells'=>['3_1','4_5']];
        if($context==='result') $s->battle['strike']=[
            'kind'=>'cell_marker','state'=>'results','attacker_id'=>1,'target_id'=>0,
            'cell_marker'=>['row'=>3,'col'=>1],'confirmed'=>[]];
        if($context==='ack') $s->battle['turn_phase']=[
            'phase'=>'start','active_key'=>$active,'pending_ack'=>['type'=>'test','text'=>'Подтверждение']];
        if($context==='forced') {
            $s->getCard(1)->prop['forced_strike']=true;
            $s->getCard(2)->closed=true;
        }
        $before=serialize($s->toArray());
        $data=(new BattleScreen($tpl))->prepare($s,$viewer,$viewer,null,$cardsInfo,['sel'=>$selection])['data'];
        $html=$tpl->parse('screen/battle.tpl',$data); $x=stage1bDom($html);
        battleSnapshotAssert(serialize($s->toArray())===$before,'View must not mutate game');
        foreach(['info-panel-header','info-panel-content','info-panel-footer'] as $class)
            battleSnapshotAssert($x->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]')->length===1,'One '.$class);
        battleSnapshotAssert($x->query('//*[contains(@class,"battle-panel-side")]')->length===0,'Old selected-card column removed');
        battleSnapshotAssert($x->query('//*[contains(@class,"info-panel-content")]//*[@data-battle-fragment="panel_html"]')->length===1,'Card belongs to content');
        battleSnapshotAssert($x->query('//footer//*[normalize-space(text())="Завершить ход"]')->length===1,'One visible end-turn control');
        battleSnapshotAssert($x->query('//footer//*[normalize-space(text())="Сдаться"]')->length===1,'One visible resign control');
        battleSnapshotAssert($x->query('//a[contains(@href,"cmd=resign")]')->length===1,'Both viewers can resign in any context');
        $canEnd=$viewer===$active && $context==='normal';
        // Forced-strike source belongs to host only.
        if($context==='forced' && $viewer==='player' && $active==='player') $canEnd=true;
        battleSnapshotAssert($x->query('//a[contains(@href,"cmd=end_turn")]')->length===($canEnd?1:0),'End-turn eligibility '.$viewer.'/'.$active.'/'.$context);
        if($selection) battleSnapshotAssert(str_contains($data['info_panel_html'],$data['panel_html']) && $data['panel_html']!=='','Card rendered without losing existing HTML');
        if(in_array($context,['cell','result','ack'],true)) {
            $content=$x->query('//*[contains(@class,"info-panel-content")]')->item(0);
            battleSnapshotAssert(str_contains($content->lastChild->getAttribute('class'),'info-panel-context'),'Mandatory context remains accessible after selected card');
            if($context==='result') battleSnapshotAssert($x->query('//a[contains(@href,"cmd=confirm_strike")]')->length===1,'Result confirmation remains accessible');
        }
        battleSnapshotAssert($x->query('//*[@id="battle-debug"]')->length===1 && $x->query('//*[contains(@class,"debug-panel")]')->length===0,'Empty debug contains no invented data');
        $cases++;
    }
}
$_SESSION=['debug'=>'1']; $_GET=[]; $s=battleSnapshotState();
$renderer=new BattleSnapshotRenderer($tpl);
$first=$renderer->render($s,'host','host',null,$cardsInfo,['sel'=>1]);
$s->battle['turn']=9;
$next=$renderer->render($s,'host','host',null,$cardsInfo,['sel'=>3]);
battleSnapshotAssert(array_keys($first['fragments'])===['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'],'AJAX fragment keys unchanged');
battleSnapshotAssert(str_contains($next['fragments']['info_panel_html'],'debug-panel') && str_contains($next['fragments']['info_panel_html'],'#9'),'Debug and header update in existing fragment');
battleSnapshotAssert(str_contains($next['fragments']['panel_html'],'Летун'),'Selected-card fragment still updates independently');
echo "Stage 1B UI tests passed ($cases view combinations, debug snapshots and unchanged protocol).\n";
