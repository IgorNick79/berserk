<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Core\GameState;
use Berserk\View\Ui\BattlefieldPosition;
use Berserk\View\Ui\Coords;
use Berserk\View\Ui\CellMarkerPicker;
use Berserk\View\Ui\Panel;
use Berserk\View\Ui\PanelSpec;
use Berserk\View\Ui\TaskCard;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
function coordsAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$_GET = $_SESSION = [];
$rows = [1 => 3, 2 => 2, 3 => 1, 4 => 1, 5 => 2, 6 => 3];
foreach (['host', 'player'] as $viewer) {
    $cells = [];
    foreach ($rows as $row => $gameRow) {
        for ($col = 1; $col <= 5; $col++) {
            $pos = BattlefieldPosition::display($row, $col, $viewer);
            $side = (($row <= 3) === ($viewer === 'host')) ? 'own' : 'enemy';
            coordsAssert($pos === ['row' => $gameRow, 'col' => $viewer === 'host' ? $col : 6 - $col, 'side' => $side], 'Wrong conversion');
            coordsAssert(str_contains(Coords::render($row, $col, $viewer), 'coords--' . $side), 'Wrong coordinate color');
            $cells[] = $row . '_' . $col;
        }
    }
    $state = new GameState(77, 101, 202);
    $state->status = 'battle';
    $state->battle = ['active' => $viewer, 'turn' => 1, 'pending_cell_marker_pick' => [
        'owner' => $viewer, 'card_id' => 0, 'label' => 'Костёр <script>', 'marker' => 'bonfire', 'cells' => $cells,
    ]];
    $baseUrl = $viewer === 'host' ? '?first&game=77' : '?second&game=77';
    $targets = CellMarkerPicker::targets($state, $viewer, $baseUrl);
    coordsAssert(count($targets) === 30, 'Candidate set changed');
    foreach ($targets as $key => $target) {
        [$r, $c] = explode('_', $key);
        coordsAssert(str_ends_with($target['url'], '&row=' . $r . '&col=' . $c), 'System command coordinates changed');
    }
    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
    $data = $screen->prepare($state, $viewer, $viewer, null, [])['data'];
    $field = $data['field_html'];
    coordsAssert(substr_count($field, 'class="battle-columns"') === 1, 'Column scale must be single');
    coordsAssert(substr_count($field, 'class="battle-row-number coords--own"') === 3, 'Own row numbers missing');
    coordsAssert(substr_count($field, 'class="battle-row-number coords--enemy"') === 3, 'Enemy row numbers missing');
    coordsAssert(substr_count($field, 'cell-marker-target') === 30, 'Legal cells not clickable');
    coordsAssert(substr_count($data['info_panel_html'], 'cell-marker-choice') === 30, 'Fallback buttons missing');
    coordsAssert(str_contains($data['info_panel_html'], '🔥 Свой 1-й ряд · столбец 3'), 'Game coordinates missing from buttons');
    coordsAssert(str_contains($data['info_panel_html'], 'Костёр &lt;script&gt;'), 'Title not escaped');
    coordsAssert(!str_contains($data['info_panel_html'], '<script>'), 'HTML injection');
    coordsAssert(str_contains($field, 'cmd=choose_cell_marker&amp;row=6&amp;col=5'), 'Escaped system URL missing');
    coordsAssert($screen->prepare($state, $viewer, $viewer, null, [])['data']['field_html'] === $field, 'Repeated render duplicates or loses axes');
    $opponent = $state->getOpponentKey($viewer);
    $other = $screen->prepare($state, $opponent, $opponent, null, [])['data'];
    coordsAssert(!str_contains($other['field_html'], 'cell-marker-target'), 'Opponent can choose a cell');
    coordsAssert(!str_contains($other['info_panel_html'], 'choose_cell_marker'), 'Opponent sees choice buttons');
    unset($state->battle['pending_cell_marker_pick']);
    coordsAssert(!str_contains($screen->prepare($state, $viewer, $viewer, null, [])['data']['field_html'], 'cell-marker-target'), 'Completed choice stays clickable');
}
foreach ([[null, 1], [1, null], [0, 1], [7, 1], [1, 0], [1, 6], [-1, -1]] as [$row, $col]) {
    coordsAssert(BattlefieldPosition::display($row, $col, 'host') === null, 'Invalid coordinate accepted');
    coordsAssert(Coords::render($row, $col, 'host') === '', 'Invalid coordinate rendered');
}
coordsAssert(Coords::render(1, 1, '<script>') === '', 'Invalid viewer accepted');
$task = TaskCard::render(['label' => '<script>', 'row' => 6, 'col' => 5], '?second&x="', [], true, 'player');
coordsAssert(str_contains($task, 'Свой [3:1]') && str_contains($task, '&lt;script&gt;') && str_contains($task, '&quot;'), 'Task coordinates or escaping wrong');
$state = new GameState(77, 101, 202);
$state->battle['pending_cell_marker_pick'] = ['owner' => 'host', 'cells' => ['0_1', '1_6', '<script>', '3_5'], 'marker' => 'bonfire'];
$spec = new PanelSpec(title: '<script>');
CellMarkerPicker::decorate($spec, $state, 'host', '?first&bad="');
$html = Panel::render($spec);
coordsAssert(count($spec->buttons) === 2, 'Malformed coordinates not rejected safely');
coordsAssert(str_contains($html, '&lt;script&gt;') && str_contains($html, '&quot;'), 'Panel escaping failed');
echo "Battle coordinates UI tests passed.\n";