<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, CardInstance, GameState};
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;
use Berserk\View\Ui\Coords;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
function resultCoordsAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function resultCoordsState(string $kind, mixed $row, mixed $col, string $action = 'Действие'): GameState {
    $s = new GameState(0, 101, 202);
    $s->status = 'battle';
    foreach ([1 => 'host', 2 => 'player'] as $id => $owner) {
        $s->addCard(new CardInstance(instanceId: $id, ukid: 'card' . $id, owner: $owner, zone: CardInstance::ZONE_FIELD,
            row: $id === 1 ? 2 : 5, col: 3, hp: 7, hpMax: 7, strikeWeak: 1, strikeMedium: 2, strikeStrong: 3));
    }
    $strike = ['kind' => $kind, 'action_name' => $action, 'state' => 'results', 'attacker_id' => 1, 'target_id' => 2,
        'defender_id' => null, 'attack_dice' => 0, 'defend_dice' => 0, 'attack_mod' => 0, 'defend_mod' => 0,
        'result' => ['attack' => '', 'defend' => '', 'winner' => ''], 'final' => ['attack' => '', 'defend' => '', 'decreased' => false],
        'damage' => 2, 'damage_total' => 2, 'confirmed' => []];
    if ($kind === 'cell_marker') $strike['cell_marker'] = ['row' => $row, 'col' => $col, 'type' => 'bonfire'];
    if ($kind === 'teleport_target') $strike['teleport'] = ['target_id' => 2, 'new_row' => $row, 'new_col' => $col];
    if ($kind === 'bomb_shot') $strike['bomb'] = ['row' => $row, 'col' => $col];
    if ($kind === 'strike') $strike['push_result'] = ['type' => 'moved', 'new_row' => $row, 'new_col' => $col];
    $s->battle = ['active' => 'host', 'turn' => 1, 'strike' => $strike];
    return $s;
}
function resultCoordsRender(GameState $s, string $viewer, array $info = []): string {
    $_GET = $_SESSION = [];
    $before = serialize($s->toArray());
    $html = (new InfoPanel(new Template(__DIR__ . '/../templates')))->render($s, $viewer, $viewer,
        $info ?: ['card1' => ['name' => 'Источник'], 'card2' => ['name' => 'Цель']], '?game=0&' . ($viewer === 'host' ? 'first' : 'second'));
    resultCoordsAssert(serialize($s->toArray()) === $before, 'Rendering changed persistent state');
    return $html;
}
function resultCoordsBadges(string $html): array {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    resultCoordsAssert($xpath->query('//script | //*[@onerror]')->length === 0, 'Unsafe HTML in result');
    $badges = [];
    foreach ($xpath->query('//span[contains(concat(" ",normalize-space(@class)," ")," coords ")]') as $node) {
        $badges[] = [$node->textContent, $node->getAttribute('class')];
    }
    return $badges;
}
$rows = [1 => 'III', 2 => 'II', 3 => 'I', 4 => 'I', 5 => 'II', 6 => 'III'];
foreach (['host', 'player'] as $viewer) {
    foreach ($rows as $row => $roman) {
        for ($col = 1; $col <= 5; $col++) {
            $side = (($row <= 3 ? 'host' : 'player') === $viewer) ? 'own' : 'enemy';
            $label = '# [' . $roman . ':' . ($viewer === 'host' ? $col : 6 - $col) . ']';
            $expected = [[$label, 'coords coords--' . $side]];
            resultCoordsAssert(resultCoordsBadges(Coords::renderResult($row, $col, $viewer)) === $expected, 'Result formatter conversion/color wrong');
            foreach (['cell_marker', 'teleport_target', 'bomb_shot', 'strike'] as $kind) {
                $html = resultCoordsRender(resultCoordsState($kind, $row, $col), $viewer);
                resultCoordsAssert(resultCoordsBadges($html) === $expected, 'Structured result does not use viewer-relative badge: ' . $kind);
                resultCoordsAssert(!str_contains($html, ': клетка ') && !str_contains($html, 'на клетку ('), 'Old coordinate prefix remains');
                if ($kind === 'teleport_target') resultCoordsAssert(str_contains($html, 'Цель перемещён на '), 'Teleport meaning lost');
                if ($kind === 'bomb_shot') resultCoordsAssert(str_contains($html, 'Цель получает 2 урона. Бомба поставлена на '), 'Bomb meaning lost');
                if ($kind === 'strike') resultCoordsAssert(str_contains($html, 'Карта перемещена на '), 'Push meaning lost');
            }
        }
    }
    foreach ([[null, 2], [3, null], [0, 1], [7, 1], [1, 0], [1, 6], ['3', 2], [true, 2], [[], 2], ['<script>', 2]] as [$row, $col]) {
        resultCoordsAssert(Coords::renderResult($row, $col, $viewer) === '', 'Invalid coordinates became a real cell');
        foreach (['cell_marker', 'teleport_target', 'bomb_shot', 'strike'] as $kind) {
            resultCoordsAssert(resultCoordsBadges(resultCoordsRender(resultCoordsState($kind, $row, $col), $viewer)) === [], 'Invalid structured coordinate badge rendered');
        }
    }
    foreach (['cell_marker', 'teleport_target', 'bomb_shot', 'strike'] as $kind) {
        $unsafe = '<script>alert("x")</script> & "название"';
        $html = resultCoordsRender(resultCoordsState($kind, 3, 2, $unsafe), $viewer, ['card1' => ['name' => $unsafe], 'card2' => ['name' => $unsafe]]);
        resultCoordsBadges($html);
        resultCoordsAssert(str_contains($html, '&lt;script&gt;'), 'Action/card names must be escaped');
    }
    $s = resultCoordsState('teleport_target', 3, 2);
    $s->battle['strike']['push_result'] = ['type' => 'moved', 'new_row' => 4, 'new_col' => 5];
    $expected = $viewer === 'host'
        ? [['# [I:2]', 'coords coords--own'], ['# [I:5]', 'coords coords--enemy']]
        : [['# [I:4]', 'coords coords--enemy'], ['# [I:1]', 'coords coords--own']];
    resultCoordsAssert(resultCoordsBadges(resultCoordsRender($s, $viewer)) === $expected, 'Multiple coordinate results must have independent sides');
    $s = resultCoordsState('impact', null, null, 'Обычный результат');
    $html = resultCoordsRender($s, $viewer);
    resultCoordsAssert(resultCoordsBadges($html) === [] && str_contains($html, 'Обычный результат'), 'Result without coordinates changed');
    $s = resultCoordsState('bomb_shot', null, null);
    unset($s->battle['strike']['bomb']);
    resultCoordsAssert(resultCoordsBadges(resultCoordsRender($s, $viewer)) === [], 'Missing result payload became a cell');
}
resultCoordsAssert(Coords::renderResult(3, 2, '<script>') === '', 'Invalid viewer accepted');
resultCoordsAssert(Coords::render(3, 2, 'host') === '<span class="coords coords--own">Свой [1:2]</span>', 'Legacy render changed');
resultCoordsAssert(Coords::render(3, 2, 'player') === '<span class="coords coords--enemy">Вражеский [1:4]</span>', 'Legacy viewer render changed');
echo "Battle result coordinate UI tests passed.\n";