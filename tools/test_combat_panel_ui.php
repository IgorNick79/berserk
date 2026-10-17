<?php
declare(strict_types=1);
ob_start(); require __DIR__ . '/test_battle_snapshot_renderer.php'; ob_end_clean();
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
function combatPanelFixture(string $stage, string $viewer, int $selection): array {
    global $cardsInfo;
    $s=battleSnapshotState(); $s->getCard(2)->revealed=true;
    if ($stage !== 'normal') $s->battle['strike']=[
        'kind'=>'cell_marker','state'=>'results','attacker_id'=>1,'target_id'=>2,
        'cell_marker'=>['row'=>3,'col'=>1],'confirmed'=>[],
    ];
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
        battleSnapshotAssert(str_contains($d['info_panel_html'],'>Сражение</h3>'), 'Combat heading exists');
        battleSnapshotAssert(str_contains($d['info_panel_html'],'data-battle-fragment="panel_html" hidden'), 'Existing selection starts hidden');
        battleSnapshotAssert(str_contains($d['info_panel_html'],'cmd=confirm_strike'), 'Combat confirmation preserved');
        if($sel) {
            battleSnapshotAssert(substr_count($d['panel_html'],'<button')===1 && str_contains($d['panel_html'],'data-combat-back'), 'Card inspection only has Close button');
            battleSnapshotAssert(!str_contains($d['panel_html'],'cmd=')&&!str_contains($d['panel_html'],'mode='), 'Inspection contains no game commands');
            battleSnapshotAssert(str_contains($d['panel_html'],'button small cancel'), 'Close uses cancel styling');
        }
        $normal=combatPanelFixture('normal',$viewer,$sel);
        battleSnapshotAssert(!str_contains($normal['info_panel_html'],'Фаза сражения')&&!str_contains($normal['panel_html'],'data-combat-back'), 'Normal view unchanged');
    }
    echo "Combat panel tests passed for both viewers and selected/unselected cards.\n";
}
