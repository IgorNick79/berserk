<?php
require __DIR__ . '/test_battle_snapshot_renderer.php';
$state = battleSnapshotState();
$card = $state->getCard(1);
$card->ukid = 'ui-preview-test-' . bin2hex(random_bytes(6));
$cardsInfo[$card->ukid] = ['name'=>'Preview test', 'health'=>3];
$path = __DIR__ . '/../www/assets/cards/s1/preview/' . $card->ukid . '.webp';
$created = [];
$dir = dirname($path);
while (!is_dir($dir)) { $created[] = $dir; $dir = dirname($dir); }
foreach (array_reverse($created) as $dir) mkdir($dir);
$render = function () use ($state, &$cardsInfo) {
    return (new Berserk\View\Screen\BattleSnapshotRenderer(new Berserk\View\Template(__DIR__.'/../templates')))
        ->render($state, 'host', 'host', null, $cardsInfo, ['sel'=>1])['fragments']['panel_html'];
};
try {
    $html = $render();
    battleSnapshotAssert(!str_contains($html, 'panel-card-preview') && !str_contains($html, 'panel-image'), 'Missing image leaves no container');
    battleSnapshotAssert(str_contains($html, '<b>HP</b> 3/3') && str_contains($html, '<b>Move</b> 1/1') && str_contains($html, '<b>Strike</b> 1-2-3'), 'Stats use current card data');
    // Only file existence is relevant to server rendering; never add fake assets to the project.
    file_put_contents($path, 'test');
    $html = $render();
    battleSnapshotAssert(str_contains($html, '/preview/'.$card->ukid.'.webp'), 'Existing preview rendered');
    battleSnapshotAssert(str_contains($html, '/original/'.$card->ukid.'.jpg'), 'Original URL follows ukid');
    unlink($path);
    battleSnapshotAssert(!str_contains($render(), 'data-card-original'), 'Next render removes old image');
    echo "Card preview rendering tests passed.\n";
} finally {
    if (is_file($path)) unlink($path);
    foreach ($created as $dir) rmdir($dir);
}
