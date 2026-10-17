<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/test_battle_snapshot_renderer.php';
ob_end_clean();
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
use Berserk\View\Ui\CardDescription;
use Berserk\Core\CardInstance;

$example = '<img src="/images/icons/icon-tap.png" class="img-fluid" alt="tap">: '
    . '<img src="/images/icons/icon-uchr.png" class="img-fluid" alt="uchr"> на 1.<br />'
    . '<img src="/images/icons/icon-counter.png" alt="counter"><img src="/images/icons/icon-tap.png" alt="tap">: '
    . 'Особый удар «молниеносный выпад» на 3.<br />+1 к ударам по горным.';
$state = battleSnapshotState();
$cardsInfo['own']['descr'] = $example;
$screen = new BattleScreen(new Template(__DIR__.'/../templates'));
$render = fn(int $selected = 1) => $screen->prepare($state, 'host', 'host', null, $cardsInfo, ['sel'=>$selected])['data'];
$data = $render();
if (in_array('--panel', $argv ?? [], true)) {
    echo json_encode($data['panel_html'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
battleSnapshotAssert(strpos($data['panel_html'], 'panel-card-title') < strpos($data['panel_html'], 'panel-card-description'), 'Description remains below title and stats');
battleSnapshotAssert(substr_count($data['panel_html'], 'card-description-icon') === 4, 'All Kshar icons retained');
battleSnapshotAssert(substr_count($data['panel_html'], '<br>') === 2, 'Line breaks retained');
battleSnapshotAssert(str_contains($data['panel_html'], 'молниеносный выпад'), 'Cyrillic text retained');
battleSnapshotAssert(!str_contains($render(3)['panel_html'], 'panel-card-description'), 'Changing card clears previous description');
battleSnapshotAssert(CardDescription::render('') === '', 'Empty description leaves no container');
battleSnapshotAssert(!str_contains($data['panel_html'], '<details class="panel-card-description" open'), 'Description starts collapsed');
battleSnapshotAssert(str_contains($data['panel_html'], '/assets/images/icons/icon-tap.png') && !str_contains($data['panel_html'], 'src="/images/icons/'), 'Old database paths resolve to assets');
battleSnapshotAssert(str_contains(CardDescription::render('<img src="/assets/images/icons/icon-tap.png" alt="tap">'), 'src="/assets/images/icons/icon-tap.png"'), 'New paths are also supported without duplicate prefix');

$unsafe = CardDescription::render('<script>alert(1)</script><img src="/images/icons/icon-tap.png" onerror="alert(1)" style="position:fixed">'
    . '<img src="https://evil.invalid/test.png"><img src="javascript:alert(1)"><b onclick="bad()">safe</b>');
battleSnapshotAssert(!str_contains($unsafe, 'alert') && !str_contains($unsafe, 'onerror') && !str_contains($unsafe, 'onclick') && !str_contains($unsafe, 'style=') && !str_contains($unsafe, 'evil.invalid'), 'Executable markup and external image URLs removed');
battleSnapshotAssert(str_contains($unsafe, '<b>safe</b>') && str_contains($unsafe, 'icon-tap.png'), 'Safe formatting and local icon retained');
foreach (['host', 'player'] as $viewer) {
    $current = $screen->prepare($state, $viewer, $viewer, null, $cardsInfo, ['sel'=>0])['data'];
    $dom = new DOMDocument(); @$dom->loadHTML($current['piles_html']); $x = new DOMXPath($dom);
    foreach ($x->query('//*[contains(@class,"battle-grave") or contains(@class,"battle-exile")]') as $slot) {
        $count = (int) $x->query('.//*[@class="piles-count"]', $slot)->item(0)->textContent;
        battleSnapshotAssert($x->query($count === 0 ? './/button[@disabled and not(@href)]' : './/a[@href]', $slot)->length === 1, 'Zone availability follows count');
    }
}
$state->getCard(4)->zone = CardInstance::ZONE_EXILE;
battleSnapshotAssert(str_contains($render()['piles_html'], '&amp;pile=own_exile'), 'Nonempty exile restores existing link');
$state->getCard(4)->zone = CardInstance::ZONE_FIELD;
battleSnapshotAssert(!str_contains($render()['piles_html'], '&amp;pile=own_exile'), 'Empty exile removes navigation');
echo "Description/zone tests passed: Kshar formatting, empty/switching cards, safe HTML, zero/nonzero zones for both viewers.\n";
