<?php
/** Local browser fixture for the actual View/JS, without a database or live game. */
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
$_SESSION = [];
$viewer = ($_GET['viewer'] ?? '') === 'player' ? 'player' : 'host';
$case = (string) ($_GET['case'] ?? 'full');
$state = new GameState(77, 101, 202);
$state->status = 'battle';
$state->addCard(new CardInstance(instanceId: 1, ukid: 'krondak', owner: $viewer, zone: CardInstance::ZONE_FIELD, row: 2, col: 2, hp: 7, hpMax: 7));
$state->addCard(new CardInstance(instanceId: 2, ukid: 'enemy', owner: $state->getOpponentKey($viewer), zone: CardInstance::ZONE_FIELD, row: 5, col: 4, hp: 3, hpMax: 3));
$cells = [];
for ($r = 1; $r <= 6; $r++) for ($c = 1; $c <= 5; $c++) {
    if (in_array($r . '_' . $c, ['2_2', '5_4'], true)) continue;
    $cells[] = $r . '_' . $c;
}
if ($case === 'single') $cells = ['6_5'];
if ($case === 'row') $cells = ['4_1', '4_3'];
if ($case === 'half') $cells = ['1_1', '2_5', '3_4'];
if ($case === 'stale') $cells[] = '2_2';
$state->battle = ['active' => $viewer, 'turn' => 1, 'pending_cell_marker_pick' => [
    'owner' => $viewer, 'card_id' => 1, 'label' => 'Разжигание костра', 'marker' => 'bonfire', 'cells' => $cells,
]];
if ($case === 'observer') $state->battle['pending_cell_marker_pick']['owner'] = $state->getOpponentKey($viewer);
$data = (new BattleScreen(new Template(__DIR__ . '/../templates')))->prepare($state, $viewer, $viewer, null, [
    'krondak' => ['name' => 'Крондак'], 'enemy' => ['name' => 'Карта <script>alert(1)</script>'],
])['data'];
$js = ($_GET['js'] ?? '') !== '0';
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cell marker picker browser fixture</title><link rel="stylesheet" href="/www/assets/css/global.css">
<?php if ($js): ?><script src="/www/assets/js/cell-marker-picker.js" defer></script><?php endif; ?>
<script src="/tools/test_cell_marker_picker_browser.js" defer></script>
</head><body data-test-case="<?=htmlspecialchars($case, ENT_QUOTES)?>">
<div class="battle-main"><div class="battle-left"><div class="battle-layout"><div class="battle-field"><?=$data['field_html']?></div></div></div></div>
<div class="info-panel"><?=$data['info_panel_html']?></div>
<section><button id="run-picker-tests" type="button">Run browser checks</button><pre id="picker-test-results">Not run</pre></section>
</body></html>