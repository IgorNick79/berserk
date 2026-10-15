<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\{Autoloader, CardInstance, CardStats, Command, Dice, Engine, GameState, TurnPhaseProcessor, ZoneManager};
use Berserk\Core\Choice\{CellEffectDirectionChoice, ChoiceRegistry};
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
$checks = 0;
function bbAssert(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function bbProp(): array {
    return json_decode(file_get_contents(__DIR__ . '/../docs/cards/s1_119.prop.json'), true, 512, JSON_THROW_ON_ERROR);
}
function bbCard(int $id, string $owner, int $row, int $col, array $prop = []): CardInstance {
    return new CardInstance(instanceId: $id, ukid: $id === 1 ? 's1_119' : 'fixture_' . $id,
        owner: $owner, zone: CardInstance::ZONE_FIELD, row: $row, col: $col, hp: 30, hpMax: 30,
        move: 1, moveMax: 1, strikeWeak: 2, strikeMedium: 2, strikeStrong: 2, prop: $prop);
}
function bbState(): GameState {
    $s = new GameState(119, 101, 202);
    $s->status = 'battle';
    $s->firstPlayer = 'host';
    $s->battle = ['turn' => 2, 'active' => 'player', 'strike' => null, 'hidden_row_revealed' => true];
    $s->addCard(bbCard(1, 'host', 3, 1, bbProp()));
    $s->addCard(bbCard(2, 'player', 3, 2));
    return $s;
}
function bbDrain(GameState $s, Engine $e): void {
    for ($i = 0; $i < 30 && !empty($s->battle['turn_phase']); $i++) {
        if (!empty($s->battle['pending_cell_effect_direction'])) return;
        $tp = $s->battle['turn_phase'];
        if (!empty($tp['pending_ack'])) {
            $r = $e->apply($s, $tp[$tp['side'] . '_key'], new Command('turn_ack'));
        } else {
            $q = $tp[$tp['side'] . '_queue'];
            if (!$q) { (new TurnPhaseProcessor($s, $e))->resume(); continue; }
            $r = $e->apply($s, $tp[$tp['side'] . '_key'], new Command('turn_task', ['task_id' => $q[0]['id']]));
        }
        bbAssert($r->success, 'Turn phase must progress');
    }
}
function bbLight(GameState $s, Engine $e): void {
    (new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
    bbAssert(!empty($s->battle['pending_cell_effect_direction']), 'Opponent start creates direction choice');
    bbAssert($e->apply($s, 'host', new Command('choose_cell_effect_direction', ['direction' => 'right']))->success, 'Choose right');
    bbDrain($s, $e);
}
$e = new Engine();
bbAssert(!isset(bbProp()['ova']) && bbProp()['regeneration'] === 1 && bbProp()['zoal'] === true, 'Production prop replaces ova');

// Every board cell, every offered direction: exact contiguous ray and bounded cells.
$seen = [];
for ($row = 1; $row <= 6; $row++) for ($col = 1; $col <= 5; $col++) {
    $c = bbCard(9, 'host', $row, $col);
    foreach (CellEffectDirectionChoice::options($c, 3) as $direction => $option) {
        $seen[$direction] = true;
        bbAssert(count($option['cells']) === 3, 'Exactly three cells');
        [$r1, $c1] = array_map('intval', explode('_', $option['cells'][0]));
        foreach ($option['cells'] as $i => $key) {
            [$r, $cc] = array_map('intval', explode('_', $key));
            bbAssert($r === $row + ($r1 - $row) * ($i + 1) && $cc === $col + ($c1 - $col) * ($i + 1), 'Contiguous straight ray');
            bbAssert($r >= 1 && $r <= 6 && $cc >= 1 && $cc <= 5 && $key !== "{$row}_{$col}", 'Bounds and no source cell');
        }
    }
}
bbAssert(count($seen) === 8, 'All eight directions supported across the board');
bbAssert(CellEffectDirectionChoice::options(bbCard(9, 'host', 3, 3), 3) === ['down' => ['label' => 'ВНИЗ', 'cells' => ['4_3', '5_3', '6_3']]], 'Central cell filters all impossible rays');
bbAssert(CellEffectDirectionChoice::options(bbCard(9, 'host', 1, 1), 7) === [], 'No valid direction is safe');

$s = bbState();
(new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
$p = $s->battle['pending_cell_effect_direction'];
bbAssert($p['owner'] === 'host', 'Source owner chooses during enemy turn');
bbAssert(!$e->apply($s, 'player', new Command('choose_cell_effect_direction', ['direction' => 'right']))->success, 'Opponent cannot choose');
bbAssert(!$e->apply($s, 'host', new Command('choose_cell_effect_direction', ['direction' => 'left']))->success, 'Invalid boundary choice rejected');
$restored = GameState::fromArray($s->toArray());
(new TurnPhaseProcessor($restored, $e))->resume();
bbAssert($restored->battle['pending_cell_effect_direction'] === $p, 'Resume/reload preserves pending without replay');
bbAssert($e->apply($restored, 'host', new Command('choose_cell_effect_direction', ['direction' => 'right']))->success, 'Restored choice resolves');
bbDrain($restored, $e);
bbAssert(array_keys($restored->cell_markers) === ['3_2', '3_3', '3_4'], 'Occupied and empty cells receive markers');
bbAssert(!$restored->getCard(1)->closed && $restored->getCard(1)->coins === 0, 'No closing or coin cost');
bbAssert(!$e->apply($restored, 'host', new Command('choose_cell_effect_direction', ['direction' => 'right']))->success, 'Duplicate command cannot create more light');
bbAssert(count(ZoneManager::markersAt($restored, '3_2')) === 1, 'Only one marker per event');

// Real melee lifecycle: attacker and answering defender use the same wound hook.
foreach (['host', 'player'] as $active) {
    $s = GameState::fromArray($restored->toArray());
    $s->battle['active'] = $active;
    $_SESSION = ['debug_roll' => '4,2'];
    Dice::init();
    $attackerId = $active === 'host' ? 1 : 2;
    $targetId = $active === 'host' ? 2 : 1;
    bbAssert($e->apply($s, $active, new Command('strike', ['card_id' => $attackerId, 'target_id' => $targetId]))->success, 'Real strike declared');
    if (($s->battle['strike']['state'] ?? '') === 'waiting_defender') {
        bbAssert($e->apply($s, $s->getOpponentKey($active), new Command('choose_defender', ['defender_id' => 0]))->success, 'Defender window resolves');
    }
    if (($s->battle['strike']['state'] ?? '') === 'waiting_choice') {
        bbAssert($e->apply($s, $active, new Command('choose_strike_mode', ['mode' => 'normal']))->success, 'Keep both strikes');
    }
    bbAssert(($s->battle['strike']['state'] ?? '') === 'results', 'Real strike reaches results');
    bbAssert($s->getCard(2)->hp === 27, 'Source ' . ($active === 'host' ? 'strike' : 'answer') . ' adds exactly one wound');
    bbAssert($s->getCard(1)->hp === 28, 'Unlit source receives base damage');
    bbAssert($s->getCard(1)->strikeMedium === 2 && Dice::remaining() === [], 'Printed strength and dice consumption unchanged');
}
$_SESSION = []; Dice::init();
$s = GameState::fromArray($restored->toArray());
$s->battle['active'] = 'host';
$shooter = bbCard(3, 'host', 1, 1, ['actions' => [['key' => 'shot', 'type' => 'shot', 'value' => 2, 'range' => 6]]]);
$s->addCard($shooter);
bbAssert($e->apply($s, 'host', new Command('action', ['card_id' => 3, 'target_id' => 2, 'action_key' => 'shot']))->success, 'Real ranged action declared');
bbAssert($s->getCard(2)->hp === 27, 'Real ranged action applies bonus once');
$s = GameState::fromArray($restored->toArray());
$s->getCard(2)->hp = 3;
$e->applyDamage($s, $s->getCard(2), 2, 'poison');
bbAssert($s->getCard(2)->zone === CardInstance::ZONE_GRAVEYARD, 'Bonus lethal wound runs existing death lifecycle');

// Wounds are computed at receipt, without card-stat or poison-status mutation.
foreach (['shot', 'throw', 'discharge', 'poison', 'strike', 'answer', 'tap', 'magic'] as $type) {
    $s = GameState::fromArray($restored->toArray());
    $target = $s->getCard(2);
    $e->applyDamage($s, $target, 2, $type, $s->getCard(1));
    bbAssert($target->hp === 27, $type . ' adds one actual wound');
    bbAssert(($target->flags['damage_taken_this_turn'] ?? 0) === 3, 'Lifecycle sees actual wounds');
    bbAssert($s->getCard(1)->strikeWeak === 2 && empty($target->modifiers), 'Strike values and creature modifiers unchanged');
}
foreach (['strike', 'answer', 'tap', 'magic', 'cast', 'impact', 'self_wound'] as $type) {
    $s = GameState::fromArray($restored->toArray());
    $other = bbCard(3, 'host', 2, 2);
    $s->addCard($other);
    $e->applyDamage($s, $s->getCard(2), 2, $type, $other);
    bbAssert($s->getCard(2)->hp === 28, 'Other source / non-ranged ' . $type . ' has no bonus');
}
$s = GameState::fromArray($restored->toArray());
$target = $s->getCard(2);
$target->col = 5;
$e->applyDamage($s, $target, 2, 'shot', $s->getCard(1));
bbAssert($target->hp === 28, 'Leaving cell removes bonus');
$new = bbCard(3, 'host', 3, 2);
$s->addCard($new);
$e->applyDamage($s, $new, 2, 'poison');
bbAssert($new->hp === 27, 'Another friendly creature entering cell receives bonus');
$target->col = 2;
$target->armor = 2;
$e->applyDamage($s, $target, 2, 'shot', $s->getCard(1));
bbAssert($target->hp === 28 && $target->armor === 0, 'Fully absorbed event creates no wound');
$target->modifiers[] = ['stat' => 'shield_light'];
$e->applyDamage($s, $target, 2, 'shot', $s->getCard(1));
bbAssert($target->hp === 28, 'Wound prevention respected');
$target->modifiers = [];
$target->prop['block_strike_answer'] = true;
$e->applyDamage($s, $target, 2, 'answer', $s->getCard(1));
bbAssert($target->hp === 28, 'Answer block respected');
$e->applyDamage($s, $target, 0, 'poison');
bbAssert($target->hp === 28, 'Zero damage does not create bonus wounds');

// Poison phase deals bonus on wounds, not on status application.
$s = GameState::fromArray($restored->toArray());
$s->getCard(1)->prop = [];
$e->applyPoison($s->getCard(2), 2, 'host');
bbAssert($s->getCard(2)->hp === 30 && $s->getCard(2)->markers['poison']['value'] === 2, 'Poison application unchanged');
(new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
bbAssert($s->getCard(2)->hp === 27 && $s->getCard(2)->markers['poison']['value'] === 2, 'Poison turn task applies wounds once');
bbDrain($s, $e);

// Own start: regeneration and existing anti-fly defense, no direction trigger.
$s = bbState();
$s->battle['active'] = 'host';
$s->getCard(1)->hp = 27;
(new TurnPhaseProcessor($s, $e))->beginStartPhase('host');
bbAssert(empty($s->battle['pending_cell_effect_direction']) && $s->getCard(1)->hp === 28, 'Own turn heals one without triggering lantern');
$fly = bbCard(7, 'player', 2, 1);
$fly->type = 'fly';
bbAssert(CardStats::hasDefense($s, $s->getCard(1), 'strike', $fly), 'Existing zoal defense works');

// Both complete turns: source movement/closing do not remove markers; expiration uses owner boundary.
$s = GameState::fromArray($restored->toArray());
$s->getCard(1)->row = 2;
$s->getCard(1)->closed = true;
bbAssert($e->apply($s, 'player', new Command('end_turn'))->success, 'Enemy turn ends');
bbDrain($s, $e);
bbAssert(count($s->cell_markers) === 3 && $s->battle['active'] === 'host', 'Light survives enemy end and owner start');
$e->applyDamage($s, $s->getCard(2), 2, 'poison');
bbAssert($s->getCard(2)->hp === 27, 'Light remains active on owner turn after source movement');
bbAssert($e->apply($s, 'host', new Command('end_turn'))->success, 'Owner turn ends');
bbAssert(empty($s->cell_markers), 'Light expires at owner end before next direction selection');

// Two source instances choose independently. Unrelated markers survive overlap and expiry.
$s = bbState();
$s->addCard(bbCard(3, 'host', 3, 5, bbProp()));
ZoneManager::addMarker($s, '3_3', ['type' => 'bomb', 'source' => 'host']);
(new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
$tasks = $s->battle['turn_phase']['passive_queue'];
bbAssert(count($tasks) === 2, 'Two source tasks independently queued');
bbAssert($e->apply($s, 'host', new Command('turn_task', ['task_id' => $tasks[0]['id']]))->success, 'First task starts');
bbAssert($e->apply($s, 'host', new Command('choose_cell_effect_direction', ['direction' => 'right']))->success, 'First direction resolves');
bbAssert(($s->battle['pending_cell_effect_direction']['source_id'] ?? 0) === 3, 'Second source starts only after first choice');
bbAssert($e->apply($s, 'host', new Command('choose_cell_effect_direction', ['direction' => 'left']))->success, 'Second direction resolves');
bbDrain($s, $e);
bbAssert(count(ZoneManager::markersAt($s, '3_3')) === 3, 'Overlapping lights coexist with bomb');
$s->getCard(2)->col = 3;
$e->applyDamage($s, $s->getCard(2), 2, 'shot', $s->getCard(1));
bbAssert($s->getCard(2)->hp === 27, 'Overlapping same effect does not multiply wounds');
$e->applyDamage($s, $s->getCard(2), 2, 'strike', $s->getCard(3));
bbAssert($s->getCard(2)->hp === 24, 'Matching second source strike receives bonus');

// No direction available: task acknowledges cleanly, without an unresolvable pending.
$s = bbState();
$s->getCard(1)->prop['opponent_turn_start'][0]['range'] = 7;
(new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
bbAssert(empty($s->battle['pending_cell_effect_direction']), 'No options creates no pending');
bbDrain($s, $e);
bbAssert(empty($s->battle['turn_phase']), 'No-options phase finishes');

// Render both views after serialization: three overlays on occupied/empty cells and text-only choice.
$s = GameState::fromArray($restored->toArray());
$info = ['s1_119' => ['name' => "Бул'Багур", 'health' => 30], 'fixture_2' => ['name' => 'Цель', 'health' => 30]];
$screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
$_SESSION = []; $_GET = ['sel' => 1];
foreach (['host', 'player'] as $viewer) {
    $data = $screen->prepare($s, $viewer, $viewer, null, $info)['data'];
    $html = implode('', array_filter($data, 'is_string'));
    bbAssert(substr_count($html, 'cell-marker-lantern_light') === 3, 'Three markers visible to ' . $viewer);
    bbAssert(str_contains($html, 'Регенерация 1') && str_contains($html, 'ЗОАЛ') && str_contains($html, 'три последовательные клетки'), 'Selected card full ability text');
}
$choiceState = bbState();
(new TurnPhaseProcessor($choiceState, $e))->beginStartPhase('player');
$handler = ChoiceRegistry::current($choiceState);
$spec = $handler->spec($choiceState, 'host', $info, '?game=119', 'host');
bbAssert($spec->cellPicker === null && count($spec->buttons) === count($choiceState->battle['pending_cell_effect_direction']['options']), 'Text buttons only for valid directions');
bbAssert(!$handler->spec($choiceState, 'player', $info, '?game=119', 'player')->isMine, 'Opponent sees waiting panel');
$css = file_get_contents(__DIR__ . '/../www/assets/css/global.css');
bbAssert(str_contains($css, '.cell-marker-lantern_light') && str_contains($css, 'pointer-events: none'), 'Placeholder belongs to existing nonblocking overlay');
// Regression: documented Girrit shot path, base one wound, Minotaur at the lit cell.
// No database dependency: exercise the real Engine -> ActionResolver -> DamageResolver chain.
foreach ([[true, 0, 2], [false, 0, 1], [true, 1, 0]] as [$lit, $armor, $expected]) {
    $s = GameState::fromArray($restored->toArray());
    $s->battle['active'] = 'host';
    if (!$lit) $s->cell_markers = [];
    $girrit = bbCard(3, 'host', 1, 2, ['actions' => [[
        'key' => 'shot', 'type' => 'shot', 'value' => 1, 'range' => 4,
    ]]]);
    $girrit->ukid = 's1_21'; // hunt scenario source; no runtime ukid conditions.
    $s->addCard($girrit);
    $minotaur = $s->getCard(2);
    $minotaur->ukid = 's1_34';
    $minotaur->armor = $armor;
    $before = $minotaur->hp;
    bbAssert($e->apply($s, 'host', new Command('action', ['card_id' => 3, 'target_id' => 2, 'action_key' => 'shot']))->success, 'Girrit shot resolves');
    bbAssert($s->battle['strike']['kind'] === 'shot', 'Girrit action type stays shot');
    bbAssert($before - $minotaur->hp === $expected, 'Girrit actual wounds: lit=' . (int) $lit . ', armor=' . $armor);
    bbAssert($s->battle['strike']['damage_total'] === $expected, 'Displayed wounds must include lantern and armor result');
    $panel = (new \Berserk\View\Screen\Battle\InfoPanel(new Template(__DIR__ . '/../templates')))->render(
        $s, 'host', 'host', ['s1_21' => ['name' => 'Гиррит'], 's1_34' => ['name' => 'Минотавр']], '?game=119'
    );
    bbAssert(str_contains($panel, '(' . $expected . ' урона)'), 'InfoPanel reports actual shot wounds');
    bbAssert($minotaur->armor === 0, 'Armor absorption unchanged');
}
$s = GameState::fromArray($restored->toArray());
$minotaur = $s->getCard(2);
$minotaur->armor = 5;
$e->applyPoison($minotaur, 1, 'host');
$s->getCard(1)->prop = [];
(new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
bbAssert($minotaur->hp === 28 && $minotaur->armor === 5, 'Poison phase ignores armor and adds lantern wound');

// Closed source is eligible for this passive only, at queue, execution and confirmation.
foreach ([false, true] as $closed) {
    $s = bbState();
    $s->getCard(1)->closed = $closed;
    (new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
    bbAssert(!empty($s->battle['pending_cell_effect_direction']), 'Open/closed source creates pending: ' . (int) $closed);
    $s = GameState::fromArray($s->toArray());
    (new TurnPhaseProcessor($s, $e))->resume();
    bbAssert($e->apply($s, 'host', new Command('choose_cell_effect_direction', ['direction' => 'right']))->success, 'Open/closed source choice resolves');
    bbAssert(count($s->cell_markers) === 3 && $s->getCard(1)->closed === $closed, 'Open/closed source creates exactly three markers without changing closed state');
    bbDrain($s, $e);
    (new TurnPhaseProcessor($s, $e))->resume();
    bbAssert(empty($s->battle['pending_cell_effect_direction']), 'Resume never repeats resolved open/closed source pending');
    bbAssert(count(ZoneManager::markersAt($s, '3_2')) === 1, 'Resume never duplicates markers');
}

// A closed source with mixed effects must not activate an unrelated opponent-start ability.
$s = bbState();
$s->getCard(1)->closed = true;
$s->getCard(1)->prop['opponent_turn_start'][] = ['type' => 'modifier', 'stat' => 'ova', 'value' => 9, 'expire' => 'end_of_turn'];
(new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
bbAssert(!empty($s->battle['pending_cell_effect_direction']), 'Only lantern survives closed-source task filtering');
bbAssert($e->apply($s, 'host', new Command('choose_cell_effect_direction', ['direction' => 'right']))->success, 'Mixed source lantern resolves');
bbDrain($s, $e);
bbAssert(empty($s->getCard(1)->modifiers), 'Other closed-source passive stays disabled');
bbAssert(CardStats::isDisabled($s->getCard(1)), 'Global closed-card disability is unchanged');
$s = bbState();
$s->getCard(1)->prop['opponent_turn_start'][] = ['type' => 'modifier', 'stat' => 'ova', 'value' => 9, 'expire' => 'end_of_turn'];
(new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
$tasks = $s->battle['turn_phase']['passive_queue'];
$lightTask = array_values(array_filter($tasks, fn($task) => ($task['payload']['effect'] ?? '') === 'lantern_light'))[0];
$s->getCard(1)->closed = true;
bbAssert($e->apply($s, 'host', new Command('turn_task', ['task_id' => $lightTask['id']]))->success, 'Queued light can start after source closes');
bbAssert(!empty($s->battle['pending_cell_effect_direction']), 'Execution ignores closing after queue collection');
$s = bbState();
$s->getCard(1)->closed = true;
$s->getCard(1)->prop = ['opponent_turn_start' => [['type' => 'modifier', 'stat' => 'ova', 'value' => 9, 'expire' => 'end_of_turn']]];
(new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
bbAssert(empty($s->battle['turn_phase']) && empty($s->getCard(1)->modifiers), 'Unrelated closed opponent-start effect remains filtered');
$s = bbState();
$s->getCard(1)->markers['spider_web'] = ['value' => 1];
(new TurnPhaseProcessor($s, $e))->beginStartPhase('player');
bbAssert(empty($s->battle['pending_cell_effect_direction']), 'Existing spider-web restriction is preserved');
echo "OK bulbagur: {$checks} checks\n";
