<?php
declare(strict_types=1);
ob_start(); require __DIR__ . '/test_battle_snapshot_renderer.php'; ob_end_clean();
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
function combatPanelFixture(string $stage, string $viewer, int $selection): array {
    global $cardsInfo;
    $s=battleSnapshotState(); $s->getCard(2)->revealed=true;
    if ($stage === 'combat') $s->battle['strike']=[
        'kind'=>'cell_marker','state'=>'results','attacker_id'=>1,'target_id'=>2,
        'cell_marker'=>['row'=>3,'col'=>1],'confirmed'=>[],
    ];
    if ($stage === 'pending') $s->battle['pending_ui_test'] = ['owner'=>$viewer];
    if ($stage === 'instant') $s->battle['turn_instant_stack'] = ['state'=>'ordering','priority'=>'host','phase'=>'turn','stack'=>[],'participants'=>['host','player']];
    if ($stage === 'instant_result') $s->battle['turn_instant_result'] = ['summary'=>[]];
    if ($stage === 'message') $s->battle['any_death_messages'] = [['message'=>'Важная информация о результате']];
    if ($stage === 'closed') { $s->getCard(1)->closed=true; $s->getCard(3)->closed=true; }
    if ($stage === 'friendly_targets') $s->battle['strike'] = ['kind'=>'strike','state'=>'waiting_defender','attacker_id'=>2,'target_id'=>1,'defenders'=>[5]];
    $before=serialize($s->toArray());
    $data=(new BattleScreen(new Template(__DIR__.'/../templates')))->prepare($s,$viewer,$viewer,null,$cardsInfo,['sel'=>$selection])['data'];
    battleSnapshotAssert($before===serialize($s->toArray()), 'Combat view does not mutate state');
    return $data;
}
if (in_array('--fixture',$argv??[],true)) {
    echo json_encode(combatPanelFixture($argv[2]??'combat',$argv[3]??'host',(int)($argv[4]??1)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} else {
    foreach(['host','player'] as $viewer) foreach([0,1,2] as $sel) {
        $d=combatPanelFixture('combat',$viewer,$sel);
        battleSnapshotAssert(str_contains($d['info_panel_html'],'Фаза сражения')&&!str_contains($d['info_panel_html'],'Основная фаза'), 'Both viewers see combat phase');
        battleSnapshotAssert(str_contains($d['info_panel_html'],'>Информация</h3>') && str_contains($d['info_panel_html'],'data-panel-priority="1"'), 'Combat heading exists');
        battleSnapshotAssert(str_contains($d['info_panel_html'],'data-battle-fragment="panel_html" hidden'), 'Existing selection starts hidden');
        battleSnapshotAssert(str_contains($d['info_panel_html'],'cmd=confirm_strike'), 'Combat confirmation preserved');
        if($sel) {
            battleSnapshotAssert(substr_count($d['panel_html'],'<button')===2 && str_contains($d['panel_html'],'data-combat-back'), 'Card inspection only has Close button');
            battleSnapshotAssert(!str_contains($d['panel_html'],'cmd=')&&!str_contains($d['panel_html'],'mode='), 'Inspection contains no game commands');
            battleSnapshotAssert(str_contains($d['panel_html'],'button small cancel'), 'Close uses cancel styling');
        }
        $normal=combatPanelFixture('normal',$viewer,$sel);
        battleSnapshotAssert(!str_contains($normal['info_panel_html'],'Фаза сражения')&&!str_contains($normal['panel_html'],'data-combat-back'), 'Normal view unchanged');
    }
    foreach (['pending','instant','instant_result','message'] as $stage) foreach (['host','player'] as $viewer) {
        $d=combatPanelFixture($stage,$viewer,1);
        battleSnapshotAssert(str_contains($d['info_panel_html'],'data-panel-priority="1"'), 'Every pending/info stage is priority: '.$stage);
        battleSnapshotAssert(str_contains($d['info_panel_html'],'data-battle-fragment="panel_html" hidden'), 'Priority hides creature before JS: '.$stage);
        battleSnapshotAssert(in_array($stage,['instant','instant_result'],true)
            ? str_contains($d['info_panel_html'],'info-panel-instants')
            : str_contains($d['info_panel_html'],'>Информация</h3>') && str_contains($d['info_panel_html'],'cell_info.svg'), 'Priority heading or bottom instant section');
        if ($stage==='instant_result') battleSnapshotAssert(str_contains($d['info_panel_html'],'cmd=turn_instant_result_ok'), 'Instant OK survives selected creature');
    }
    $closed=combatPanelFixture('closed','host',1);
    $dom=new DOMDocument();@$dom->loadHTML($closed['field_html'].$closed['fly_zones_html']);$x=new DOMXPath($dom);
    battleSnapshotAssert($x->query('//*[contains(concat(" ",normalize-space(@class)," ")," closed ")]')->length===0,'Live cells have no closed class');
    battleSnapshotAssert($x->query('//*[contains(concat(" ",normalize-space(@class)," ")," mini-closed ")]')->length===2,'Only creatures carry closed styling');
    echo "Combat panel tests passed for both viewers and selected/unselected cards.\n";
}
