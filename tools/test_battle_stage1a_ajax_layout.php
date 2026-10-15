<?php
declare(strict_types=1);
require __DIR__ . '/test_battle_snapshot_renderer.php';
$tpl = new Berserk\View\Template(__DIR__ . '/../templates');
foreach (['host', 'player'] as $viewer) {
    $s = battleSnapshotState();
    $data = (new Berserk\View\Screen\BattleScreen($tpl))->prepare($s, $viewer, $viewer, null, $cardsInfo, ['sel' => 1])['data'];
    $html = $tpl->parse('screen/battle.tpl', $data);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    $roots = $xpath->query('//*[@data-battle-sync-root]');
    battleSnapshotAssert($roots->length === 1, 'Exactly one AJAX root must remain');
    $root = $roots->item(0);
    battleSnapshotAssert(str_contains($root->getAttribute('class'), 'battle-shell'), 'AJAX root must occupy viewport shell');
    foreach (['field_html','fly_zones_html','panel_html','piles_html','pile_reveal_html','info_panel_html'] as $key) {
        battleSnapshotAssert($xpath->query('//*[@data-battle-fragment="'.$key.'"]')->length === 1, 'Exactly one fragment: '.$key);
        battleSnapshotAssert($xpath->query('.//*[@data-battle-fragment="'.$key.'"]', $root)->length === 1, 'Fragment must remain inside AJAX root: '.$key);
    }
    battleSnapshotAssert($xpath->query('//*[@data-battle-fragment="info_panel_html"]//*[@data-battle-fragment="panel_html"]')->length === 1, 'Selected card belongs to InfoPanel content in Stage 1B');
    battleSnapshotAssert($xpath->query('//button[@aria-controls="battle-debug" and contains(@class,"battle-menu")]')->length === 1, 'Burger retains accessible debug target');
    $page = $tpl->parse('page.tpl', ['game_id'=>981,'role'=>$viewer,'screen'=>$html,'page_class'=>'battle-page','scripts'=>'<script src="/assets/js/battle-sync.js"></script>']);
    battleSnapshotAssert(substr_count($page, 'class="battle-page"') === 2 && str_contains($page, '/assets/js/battle-sync.js'), 'Page must retain viewport class and AJAX script');
}
$page = $tpl->parse('page.tpl', ['game_id'=>981,'role'=>'host','screen'=>'Other screen','page_class'=>'','scripts'=>'']);
battleSnapshotAssert(!str_contains($page, 'battle-page'), 'Other screens must remain outside scoped reset');
echo "Stage 1A AJAX layout contract tests passed.\n";