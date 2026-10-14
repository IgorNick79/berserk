<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\Autoloader;
use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\Choice\CellMarkerChoice;
use Berserk\View\Ui\CardButton;
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
            $sides = $viewer === 'host'
                ? [1 => 'own', 2 => 'own', 3 => 'own', 4 => 'enemy', 5 => 'enemy', 6 => 'enemy']
                : [1 => 'enemy', 2 => 'enemy', 3 => 'enemy', 4 => 'own', 5 => 'own', 6 => 'own'];
            $side = $sides[$row];
            coordsAssert($pos === ['row' => $gameRow, 'col' => $viewer === 'host' ? $col : 6 - $col, 'side' => $side], 'Wrong conversion');
            coordsAssert(str_contains(Coords::render($row, $col, $viewer), 'coords--' . $side), 'Wrong coordinate color');
            foreach (['host', 'player'] as $cardOwner) {
                $card = new CardInstance(instanceId: 1, ukid: 'test', owner: $cardOwner, zone: CardInstance::ZONE_FIELD, row: $row, col: $col);
                $label = ($side === 'own' ? 'Свой' : 'Вражеский') . ' [' . $gameRow . ':' . ($viewer === 'host' ? $col : 6 - $col) . ']';
                $button = CardButton::battle(new Template(__DIR__ . '/../templates'), $card, ['name' => 'Карта'], '?cmd=test', $viewer);
                coordsAssert(str_contains($button, 'Карта ' . $label), 'CardButton side must follow field half, not card owner');
            }
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
    $spec = (new CellMarkerChoice())->spec($state, $viewer, [], $baseUrl, $viewer);
    $originalButtons = $spec->buttons;
    $originalTitle = $spec->title;
    CellMarkerPicker::decorate($spec, $state, $viewer, $baseUrl);
    coordsAssert(array_column($spec->buttons, 'url') === array_column($originalButtons, 'url'), 'Choice action URLs or order changed');
    coordsAssert(end($spec->buttons) === end($originalButtons), 'Cancellation action changed');
    coordsAssert($spec->title === $originalTitle && $spec->isMine, 'Choice metadata changed');
    coordsAssert(count($spec->cellPicker['targets']) === 30, 'Compact picker lost valid cells');
    $groups = [];
    foreach ($spec->cellPicker['targets'] as $target) {
        $expectedSide = ($target['row'] <= 3 ? 'host' : 'player') === $viewer ? 'own' : 'enemy';
        coordsAssert($target['side'] === $expectedSide && $target['game_row'] === $rows[$target['row']], 'Compact grouping disagrees with field');
        coordsAssert($target['game_col'] === ($viewer === 'host' ? $target['col'] : 6 - $target['col']), 'Compact columns not viewer-relative');
        $groups[$target['side'] . ':' . $target['game_row']][] = $target['game_col'];
    }
    coordsAssert(count($groups) === 6, 'All six groups required');
    $compactHtml = Panel::render($spec);
    coordsAssert(str_contains($compactHtml, 'data-cell-marker-compact hidden'), 'Compact UI must start hidden');
    coordsAssert(substr_count($compactHtml, 'data-cell-marker-fallback') === 30, 'No-JS fallback missing');
    coordsAssert(!str_contains($compactHtml, 'data-cell-marker-fallback hidden'), 'Fallback hidden before initialization');
    coordsAssert(substr_count($compactHtml, 'data-picker-column=') === 30, 'Column links missing');
    coordsAssert(str_contains($field, '>III</span>') && str_contains($field, '>II</span>') && str_contains($field, '>I</span>'), 'Field rows must be Roman');
    coordsAssert(str_contains($field, 'data-cell-marker-field="' . $spec->cellPicker['id'] . '"'), 'Panel/field snapshot identity mismatch');
    $extra = ['label' => 'Другой вариант', 'url' => $baseUrl . '&cmd=custom&value=1', 'class' => 'extra'];
    $spec->buttons[] = $extra;
    $beforeRepeat = $spec->buttons;
    CellMarkerPicker::decorate($spec, $state, $viewer, $baseUrl);
    coordsAssert(end($spec->buttons) === $extra, 'Additional action lost');
    coordsAssert($spec->buttons === $beforeRepeat, 'Repeated decoration must not duplicate CSS classes');

    // Occupied cells on both halves retain card links; only the 28 legal empty cells are choices.
    $state->addCard(new CardInstance(instanceId: 1, ukid: 'own', owner: $viewer, zone: CardInstance::ZONE_FIELD, row: 2, col: 2, hp: 3, hpMax: 3));
    $state->addCard(new CardInstance(instanceId: 2, ukid: 'enemy', owner: $opponent, zone: CardInstance::ZONE_FIELD, row: 5, col: 4, hp: 3, hpMax: 3));
    $state->battle['pending_cell_marker_pick']['cells'] = array_values(array_diff($cells, ['2_2', '5_4']));
    $occupiedData = $screen->prepare($state, $viewer, $viewer, null, ['own' => ['name' => 'Своя карта'], 'enemy' => ['name' => 'Чужая карта']])['data'];
    coordsAssert(substr_count($occupiedData['field_html'], 'cell-marker-target') === 28, 'Occupied cells received choice targets');
    coordsAssert(substr_count($occupiedData['info_panel_html'], 'cell-marker-choice') === 28, 'Occupied cells received choice buttons');
    foreach (['row=2&amp;col=2', 'row=5&amp;col=4'] as $suffix) {
        coordsAssert(!str_contains($occupiedData['field_html'], 'cmd=choose_cell_marker&amp;' . $suffix), 'Choice link overlays occupied cell');
    }
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $occupiedData['field_html']);
    $xpath = new DOMXPath($dom);
    foreach ([1, 2] as $id) {
        $links = $xpath->query('//a[contains(@class,"card-link") and contains(@href,"&sel=' . $id . '")]');
        coordsAssert($links->length === 1, 'Card selection link lost');
        $cell = $links->item(0)->parentNode;
        coordsAssert(!str_contains($cell->getAttribute('class'), 'cell-marker-target'), 'Card cell highlighted as a marker choice');
        coordsAssert($xpath->query('.//a[contains(@href,"choose_cell_marker")]', $cell)->length === 0, 'Marker link overlaps card interaction');
    }
    // Even a stale pending list cannot replace card links with marker links on the field.
    $state->battle['pending_cell_marker_pick']['cells'] = $cells;
    $stale = $screen->prepare($state, $viewer, $viewer, null, [])['data']['field_html'];
    coordsAssert(substr_count($stale, 'cell-marker-target') === 28, 'Stale pending list overwrites occupied cells');
    $state->battle['pending_cell_marker_pick']['cells'] = array_values(array_diff($cells, ['2_2', '5_4']));
    $state->cell_markers['1_1'] = ['type' => 'bonfire'];
    $state->battle['pending_cell_marker_pick']['cells'] = array_values(array_diff($cells, ['2_2', '5_4', '1_1']));
    $marked = $screen->prepare($state, $viewer, $viewer, null, [])['data'];
    coordsAssert(str_contains($marked['field_html'], 'class="cell-markers"') && str_contains($marked['field_html'], 'cell-marker-bonfire'), 'Marker overlays removed');
    coordsAssert(!str_contains($marked['field_html'], 'data-cell-marker-link="1_1"'), 'Rule-excluded marked cell got a choice link');
    coordsAssert(substr_count($marked['info_panel_html'], 'data-picker-column=') === 27, 'Rule-excluded cell appears in compact groups');
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
$spec = new PanelSpec(title: '<script>', buttons: [
    ['label' => '(3,5)', 'url' => '?first&bad="&cmd=choose_cell_marker&row=3&col=5'],
    ['label' => 'Отмена', 'url' => '?first&bad="&cmd=cancel_pending', 'class' => 'skip'],
]);
coordsAssert(count(CellMarkerPicker::targets($state, 'host', '?first&bad="')) === 1, 'Malformed targets not rejected');
CellMarkerPicker::decorate($spec, $state, 'host', '?first&bad="');
$html = Panel::render($spec);
coordsAssert(count($spec->buttons) === 2, 'Malformed coordinates not rejected safely');
coordsAssert(str_contains($html, '&lt;script&gt;') && str_contains($html, '&quot;'), 'Panel escaping failed');
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
coordsAssert($xpath->query('//script')->length === 0, 'Picker HTML injected a script element');
coordsAssert($xpath->query('//a[@data-picker-column]')->item(0)->getAttribute('href') === '?first&bad="&cmd=choose_cell_marker&row=3&col=5', 'Compact URL attributes not safely escaped');
foreach ([['6_5'], ['4_1', '4_3'], ['1_1', '2_5', '3_4']] as $subset) {
    foreach (['host', 'player'] as $viewer) {
        $state = new GameState(77, 101, 202);
        $state->battle['pending_cell_marker_pick'] = ['owner' => $viewer, 'card_id' => 0, 'label' => 'Костёр', 'marker' => 'bonfire', 'cells' => $subset];
        $base = $viewer === 'host' ? '?first&game=77' : '?second&game=77';
        $spec = (new CellMarkerChoice())->spec($state, $viewer, [], $base, $viewer);
        CellMarkerPicker::decorate($spec, $state, $viewer, $base);
        coordsAssert(count($spec->cellPicker['targets']) === count($subset), 'Sparse pending targets changed');
        coordsAssert(count($spec->buttons) === count($subset) + 1, 'Sparse fallback or cancellation missing');
        $dom = new DOMDocument();
        @$dom->loadHTML(Panel::render($spec));
        $xpath = new DOMXPath($dom);
        coordsAssert($xpath->query('//a[@data-picker-column]')->length === count($subset), 'Unavailable columns invented');
        $state->battle['pending_cell_marker_pick']['marker'] = 'other_marker';
        CellMarkerPicker::decorate($spec, $state, $viewer, $base);
        coordsAssert($spec->cellPicker === null, 'Other marker choices migrated unexpectedly');
    }
}
coordsAssert(Coords::rowLabel(0) === '' && Coords::rowLabel(4) === '', 'Invalid Roman row');
echo "Battle coordinates UI tests passed.\n";