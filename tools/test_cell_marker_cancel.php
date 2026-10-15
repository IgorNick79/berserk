<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, CardInstance, Command, Engine, GameState, ZoneManager};
use Berserk\Core\Choice\{CellMarkerChoice, ChoiceRegistry, AfterStrikeExecuteChoice};
use Berserk\View\Ui\CellMarkerPicker;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
function markerCancelAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function markerCancelState(string $owner): GameState {
    // Public Engine::apply writes logs; use synthetic IDs and run suites in an isolated copy.
    $state = new GameState(-random_int(1000000000, 2000000000), 101, 202);
    $state->status = 'battle';
    $state->battle = ['active' => $owner, 'turn' => 1];
    $state->addCard(new CardInstance(instanceId: 1, ukid: 's1_175', owner: $owner, zone: CardInstance::ZONE_FIELD,
        row: $owner === 'host' ? 2 : 5, col: 2, hp: 7, hpMax: 7, coins: 2, move: 1, moveMax: 1,
        prop: ['actions' => [['key' => 'bonfire', 'name' => 'Разжигание костра', 'type' => 'place_cell_marker', 'marker' => 'bonfire', 'duration' => 'end_of_opponent_turn']]]));
    return $state;
}
function markerCancelOpen(Engine $engine, GameState $state, string $owner): void {
    $r = $engine->apply($state, $owner, new Command('action', ['card_id' => 1, 'action_key' => 'bonfire']));
    markerCancelAssert($r->success && isset($state->battle['pending_cell_marker_pick']), 'Ability must open through Engine');
}
$engine = new Engine();
foreach (['host', 'player'] as $owner) {
    $state = markerCancelState($owner);
    $card = $state->getCard(1);
    $initialCard = serialize($card);
    markerCancelOpen($engine, $state, $owner);
    $pending = $state->battle['pending_cell_marker_pick'];
    $version = $state->version;
    $other = $state->getOpponentKey($owner);
    $r = $engine->apply($state, $other, new Command('cancel_pending'));
    markerCancelAssert(!$r->success && $state->battle['pending_cell_marker_pick'] === $pending && $state->version === $version, 'Foreign cancel must not resolve pending');
    markerCancelAssert(serialize($card) === $initialCard && $state->cell_markers === [], 'Foreign cancel changed card/markers');
    $base = ($owner === 'host' ? '?first' : '?second') . '&game=' . $state->gameId;
    $spec = (new CellMarkerChoice())->spec($state, $owner, [], $base, $owner);
    CellMarkerPicker::decorate($spec, $state, $owner, $base);
    $cancelUrl = end($spec->buttons)['url'];
    markerCancelAssert($cancelUrl === $base . '&cmd=cancel_pending', 'Original cancellation URL changed');
    parse_str((string) parse_url($cancelUrl, PHP_URL_QUERY), $params);
    $r = $engine->apply($state, $owner, new Command($params['cmd'], $params));
    markerCancelAssert($r->success && $r->events === ['cancelled:cell_marker_pick'], 'Owner cancel must route through existing resolver');
    markerCancelAssert(!isset($state->battle['pending_cell_marker_pick']) && $state->version === $version + 1, 'Cancel must remove pending and bump version once');
    markerCancelAssert(serialize($card) === $initialCard && $state->cell_markers === [] && !isset($state->battle['strike']), 'Cancel must leave card/effects unchanged');
    $version = $state->version;
    $r = $engine->apply($state, $owner, new Command('cancel_pending'));
    markerCancelAssert(!$r->success && $state->version === $version, 'Repeated cancel must not mutate game');
    markerCancelOpen($engine, $state, $owner);
    $r = $engine->apply($state, $owner, new Command('choose_cell_marker', ['row' => 3, 'col' => 2]));
    markerCancelAssert($r->success && $r->events === ['cell_marker:3_2'], 'Reopening and original cell command must work');
    markerCancelAssert($card->closed && !isset($state->battle['pending_cell_marker_pick']), 'Cell selection must close source and finish pending');
    markerCancelAssert(ZoneManager::hasMarker($state, '3_2') && $state->battle['strike']['cell_marker']['row'] === 3 && $state->battle['strike']['cell_marker']['col'] === 2, 'System coordinates/effects changed');
    $afterChoice = serialize($state->toArray());
    $r = $engine->apply($state, $owner, new Command('cancel_pending'));
    markerCancelAssert(!$r->success && serialize($state->toArray()) === $afterChoice, 'Stale cancel after selection must not undo effect');

    $mandatory = markerCancelState($owner);
    $mandatory->battle['pending_forced_strike'] = ['owner' => $owner, 'attacker_id' => 1];
    $before = serialize($mandatory->toArray());
    $r = $engine->apply($mandatory, $owner, new Command('cancel_pending'));
    markerCancelAssert(!$r->success && serialize($mandatory->toArray()) === $before, 'Mandatory pending must still reject cancellation');

    $overlap = markerCancelState($owner);
    markerCancelOpen($engine, $overlap, $owner);
    $overlap->battle['pending_forced_strike'] = ['owner' => $owner, 'attacker_id' => 1];
    $before = serialize($overlap->toArray());
    $r = $engine->apply($overlap, $owner, new Command('cancel_pending'));
    markerCancelAssert(!$r->success && serialize($overlap->toArray()) === $before, 'Existing forced strike guard must protect overlapping pending');

    $overlap = markerCancelState($owner);
    markerCancelOpen($engine, $overlap, $owner);
    $overlap->battle['pending_after_strike_execute'] = ['source_id' => 1, 'value' => 1];
    markerCancelAssert(ChoiceRegistry::current($overlap) instanceof AfterStrikeExecuteChoice, 'Registry priority changed');
    $before = serialize($overlap->toArray());
    $r = $engine->apply($overlap, $owner, new Command('cancel_pending'));
    markerCancelAssert(!$r->success && serialize($overlap->toArray()) === $before, 'Earlier mandatory chooser must not be bypassed');
}
echo "Cell marker Engine cancellation tests passed.\n";