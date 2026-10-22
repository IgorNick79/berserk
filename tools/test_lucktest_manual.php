<?php
declare(strict_types=1);

// Reuse the isolated catalog/CAS fixture and Stage 1 generator regression suite, never a live DB.
require __DIR__ . '/test_lucktest.php';

use Berserk\Core\{CardInstance, Command, Dice, Engine, GameRepository, GameSettings, GameState, ProphecyProcessor, StaleStateException, ZoneManager};
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\View\Template;
use Berserk\View\Screen\{PlaceScreen, SideScreen, TurnScreen};
use Berserk\Lobby\RoomSetup;

$manualStart = $checks;
function manualRolls(string $queue): void
{
    $_SESSION['debug_roll'] = $queue;
    unset($_SESSION['debug_roll_state']);
    Dice::init();
}
function manualCopy(GameState $state): GameState
{
    $restored = GameState::fromArray(json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR), true));
    check($restored->toArray() === $state->toArray(), 'reload preserves complete preparation state');
    return $restored;
}
function manualApply(GameState $state, string $owner, string $type, array $payload = [], bool $ok = true): \Berserk\Core\Result
{
    $before = $state->toArray();
    $result = (new Engine())->apply($state, $owner, new Command($type, $payload));
    check($result->success === $ok, $type . ': ' . ($result->error ?? 'unexpected success'));
    if (!$ok) check($state->toArray() === $before, 'rejected command leaves preparation unchanged: ' . $type);
    return $result;
}
function manualGenerated(int $count, array $prop = [], bool $quick = true): GameState
{
    $state = fresh();
    manualRolls('4,4,6,1'); // Tie rerolls BOTH dice using the same rollDice implementation.
    $result = (new Engine(fixture([card('army', 7, 'creature', $prop)])))->apply($state, 'host', new Command('confirm_settings', [
        'lucktest_card_count' => (string) $count, 'lucktest_average_cost' => '6.5', 'lucktest_quick_game' => '0',
    ]));
    // Emulate already saved Stage 2 preparations with either historical flag value.
    $state->settings->lucktest['quick_game'] = $quick;
    check($result->success && $state->status === 'turn', 'generation enters Turn');
    check(in_array('stage_changed:turn', $result->events, true)
        && in_array('dice_rolled:host=6,player=1', $result->events, true), 'shared Turn events');
    check($state->firstPlayer === 'host' && $state->getPlayer('host')->dice === 6 && $state->getPlayer('player')->dice === 1
        && Dice::remaining() === [], 'tie consumed and winner stored');
    check($state->settings->lucktest['quick_game'] === $quick && $state->getPlayer('host')->confirmed === []
        && $state->getPlayer('player')->confirmed === [], 'quick_game has no automatic confirmations');
    foreach (['host', 'player'] as $owner) {
        check(count($state->getCardsInZone($owner, CardInstance::ZONE_SQUAD)) === $count
            && count($state->getCardsInZone($owner, CardInstance::ZONE_DECK)) === 30 - $count, '30 cards split into army and prophecy reserve');
        foreach ($state->getCardsInZone($owner, CardInstance::ZONE_DECK) as $c) {
            check(($c->flags['lucktest_reserve'] ?? false) === true && $c->order === null, 'reserve tagged; native instanceId deck order');
        }
    }
    return $state;
}
function manualToPlace(GameState $state, int $side): GameState
{
    $state = manualCopy($state);
    manualApply($state, 'host', 'confirm_turn');
    manualApply($state, 'host', 'confirm_turn', [], false);
    check($state->status === 'turn', 'one confirmation waits on Turn');
    $state = manualCopy($state);
    $result = manualApply($state, 'player', 'confirm_turn');
    check($state->status === 'side' && in_array('stage_changed:side', $result->events, true), 'both confirm Turn');
    $state = manualCopy($state);
    manualApply($state, 'player', 'choose_side', ['side' => 1], false);
    manualApply($state, 'host', 'choose_side', ['side' => 0], false);
    manualApply($state, 'host', 'choose_side', ['side' => '1bad'], false);
    manualApply($state, 'host', 'choose_side', ['side' => 1.5], false);
    $cards = array_map(fn($c) => $c->toArray(), $state->cards);
    $result = manualApply($state, 'host', 'choose_side', ['side' => $side]);
    check($state->status === 'place' && in_array('stage_changed:place', $result->events, true)
        && !in_array('stage_changed:deal', $result->events, true), 'Side goes directly to Place');
    check($state->getPlayer('host')->side === $side && $state->getPlayer('player')->side === 3 - $side
        && $state->firstPlayer === 'host', 'dice winner is distinct from chosen starting side');
    check(array_map(fn($c) => $c->toArray(), $state->cards) === $cards
        && $state->getPlayer('host')->resources === ['gold' => 0, 'silver' => 0], 'Side changes neither cards nor economy');
    manualApply($state, 'host', 'choose_side', ['side' => $side], false);
    return manualCopy($state);
}
function manualPlaceAll(GameState $state, string $owner): void
{
    foreach ($state->getCardsInZone($owner, CardInstance::ZONE_SQUAD) as $card) {
        $found = false;
        foreach (array_merge(...ZoneManager::cellLevels($owner)) as $cell) {
            [$row, $col] = array_map('intval', explode('_', $cell));
            $zone = new ZoneManager($state);
            if (!$zone->isCellAllowed($owner, $row, $col) || $zone->isFieldOccupied($row, $col)) continue;
            manualApply($state, $owner, 'place_card', ['card_id' => $card->instanceId, 'row' => $row, 'col' => $col]);
            $found = true;
            break;
        }
        check($found, 'a legal cell exists for each army card');
    }
}

$tpl = new Template(__DIR__ . '/../templates');
foreach ([3, 10] as $count) {
    foreach ([1, 2] as $side) {
        $state = manualGenerated($count, [], $side === 1);
        $turn = (new TurnScreen())->prepare($state, 'host', 'host', null)['data'];
        check($turn['my_dice'] === 6 && $turn['opp_dice'] === 1 && !isset($turn['deck_html']), 'native Turn screen with empty deckData');
        manualApply($state, 'host', 'confirm_settings', [], false);
        $state = manualToPlace($state, $side);
        $sideView = (new SideScreen())->prepare($state, 'host', 'host', null)['data'];
        check(!isset($sideView['deck_html']), 'Side screen needs no View data');

        $info = ['army' => ['name' => 'Army', 'health' => 8, 'price' => 7, 'type' => 'creature',
            'element' => 'forests', 'strike' => ['weak' => 1, 'medium' => 3, 'strong' => 5], 'descr' => 'test']];
        $screen = (new PlaceScreen($tpl))->prepare($state, 'host', 'host', null, $info)['data'];
        check($screen['squad_count'] === $count && substr_count($screen['squad_html'], 'prepare-card-link--squad') === $count, 'Place lists entire ready army');
        $first = $state->getCardsInZone('host', CardInstance::ZONE_SQUAD)[0];
        $_GET['card'] = $first->instanceId;
        $before = $state->toArray();
        $screen = (new PlaceScreen($tpl))->prepare($state, 'host', 'host', null, $info)['data'];
        check($screen['place_view'] === 'card' && $state->toArray() === $before, 'Place inspection does not place or change composition');
        unset($_GET['card']);
        manualApply($state, 'host', 'confirm_place', [], false);
        manualApply($state, 'player', 'place_card', ['card_id' => $first->instanceId, 'row' => 4, 'col' => 1], false);
        manualApply($state, 'host', 'place_card', ['card_id' => $first->instanceId, 'row' => 3, 'col' => 1], false);
        $reserve = $state->getCardsInZone('host', CardInstance::ZONE_DECK)[0];
        manualApply($state, 'host', 'place_card', ['card_id' => $reserve->instanceId, 'row' => 1, 'col' => 2], false);
        manualApply($state, 'host', 'place_card', ['card_id' => $first->instanceId, 'row' => 1, 'col' => 2]);
        manualApply($state, 'host', 'unplace_card', ['card_id' => $first->instanceId]);
        check($first->zone === CardInstance::ZONE_SQUAD, 'unplace returns existing instance to ready army');
        manualPlaceAll($state, 'host');
        manualPlaceAll($state, 'player');
        $state = manualCopy($state);
        manualApply($state, 'host', 'confirm_place');
        check($state->status === 'place', 'one Place confirmation cannot start battle');
        manualApply($state, 'host', 'confirm_place', [], false);
        manualApply($state, 'host', 'unplace_card', ['card_id' => $first->instanceId], false);
        $state = manualCopy($state);
        $result = manualApply($state, 'player', 'confirm_place');
        check($state->status === 'battle' && in_array('stage_changed:battle', $result->events, true)
            && $state->battle['active'] === ($side === 1 ? 'host' : 'player'), 'native Engine starts battle for selected side');
        check(count($state->cards) === 60 && $state->getPlayer('host')->confirmed === [], 'battle does not duplicate instances');
        manualApply($state, 'player', 'confirm_place', [], false);
        $state = manualCopy($state);
        $peek = (new ProphecyProcessor($state, new Engine()))->peek('host', 2);
        check($peek !== null && count($peek['cards']) === 2, 'native prophecy reads generated reserve');
        $nextId = $peek['cards'][1]->instanceId;
        (new ProphecyProcessor($state, new Engine()))->commit('host', $state->getCardsInZone('host', CardInstance::ZONE_FIELD)[0],
            ['cards' => [$peek['cards'][0]], 'ukids' => [$peek['ukids'][0]], 'meta' => $peek['meta']], 'test', 'test', []);
        check((new ProphecyProcessor($state, new Engine()))->peek('host', 1)['cards'][0]->instanceId === $nextId,
            'native prophecy rotates first reserve card to bottom');
    }
}

// Completeness and final revalidation use a known legal, fully placed board.
$placed = manualToPlace(manualGenerated(3), 1);
manualPlaceAll($placed, 'host'); manualPlaceAll($placed, 'player');
$recorded = manualCopy($placed);
manualApply($recorded, 'host', 'confirm_place');
$recorded->historyRecorder = new \Berserk\Core\BattleHistory\Recorder(123, 1, [
    'army' => ['revision' => 'test', 'name' => 'Army', 'image' => 'test/army.jpg', 'description' => 'fixture',
        'type' => 'creature', 'element' => 'forests', 'classes' => ['Воин'], 'price' => 7],
], []);
manualApply($recorded, 'player', 'confirm_place');
$batch = $recorded->historyRecorder->batch($recorded)->data();
$initialCaptures = array_values(array_filter($batch['captures'], fn($c) => $c['initial']));
check(count($initialCaptures) === 1 && count($initialCaptures[0]['state']['cards']) === 60, 'native history records one initial snapshot including reserves');
foreach (['public', 'host', 'player'] as $view) {
    check(count(array_filter($initialCaptures[0][$view]['cards'], fn($c) => $c['zone'] === 'deck')) === 0, 'history projection conceals reserve from ' . $view);
}
$recorded->historyRecorder = null;
foreach (['missing_army', 'extra_army', 'hand', 'sideboard', 'wrong_owner', 'invalid_owner', 'extra_reserve', 'missing_reserve',
    'reserve_on_field', 'illegal_cell', 'duplicate_cell', 'bad_id'] as $failure) {
    $bad = manualCopy($placed);
    $army = $bad->getCardsInZone('host', CardInstance::ZONE_FIELD);
    $reserve = $bad->getCardsInZone('host', CardInstance::ZONE_DECK);
    switch ($failure) {
        case 'missing_army': unset($bad->cards[$army[0]->instanceId]); break;
        case 'extra_army': $c = clone $army[0]; $c->instanceId = $bad->nextInstanceId(); $bad->addCard($c); break;
        case 'hand': $army[0]->zone = CardInstance::ZONE_HAND; break;
        case 'sideboard': $army[0]->zone = CardInstance::ZONE_SIDEBOARD; break;
        case 'wrong_owner': $army[0]->owner = 'player'; break;
        case 'invalid_owner': $army[0]->owner = 'unknown'; break;
        case 'extra_reserve': $c = clone $reserve[0]; $c->instanceId = $bad->nextInstanceId(); $bad->addCard($c); break;
        case 'missing_reserve': unset($bad->cards[$reserve[0]->instanceId]); break;
        case 'reserve_on_field': $reserve[0]->zone = CardInstance::ZONE_FIELD; $reserve[0]->row = 2; $reserve[0]->col = 2; break;
        case 'illegal_cell': $army[0]->row = 3; $army[0]->col = 1; break;
        case 'duplicate_cell': $army[0]->row = $army[1]->row; $army[0]->col = $army[1]->col; break;
        case 'bad_id': $army[0]->instanceId = 9999; break;
    }
    manualApply($bad, 'host', 'confirm_place', [], false);
    check($bad->status === 'place', 'invalid ' . $failure . ' cannot enter battle');
}
$bad = manualCopy($placed);
manualApply($bad, 'host', 'confirm_place');
$hostReserve = $bad->getCardsInZone('host', CardInstance::ZONE_DECK)[0];
unset($bad->cards[$hostReserve->instanceId]);
manualApply($bad, 'player', 'confirm_place', [], false);
check($bad->status === 'place' && !$bad->getPlayer('player')->isConfirmed('place'), 'second confirmation revalidates already-confirmed opponent');

// Native start effects and mandatory pending are initialized once and left for the player.
$state = manualToPlace(manualGenerated(3, ['start' => ['type' => 'get_coins', 'coins' => 2],
    'pre_turn_start' => ['options' => [['name' => 'Choice', 'modifiers' => [['stat' => 'move', 'value' => 1]]]]]]), 1);
manualPlaceAll($state, 'host'); manualPlaceAll($state, 'player');
manualApply($state, 'host', 'confirm_place'); manualApply($state, 'player', 'confirm_place');
check($state->status === 'battle' && !empty($state->battle['pending_card_choice']), 'mandatory start choice retained');
foreach ($state->getCardsInZone('host', CardInstance::ZONE_FIELD) as $c) check($c->coins === 2, 'native start effect applied once');
manualApply($state, 'player', 'confirm_place', [], false);
foreach ($state->getCardsInZone('host', CardInstance::ZONE_FIELD) as $c) check($c->coins === 2, 'retry cannot repeat start effect');
foreach ($state->getCardsInZone('host', CardInstance::ZONE_DECK) as $c) check($c->coins === 0, 'reserve cards do not receive battlefield start effects');

// An infinite forced die is rejected without changing either squad, IDs, confirmations or persisted state.
$state = fresh(); $before = $state->toArray(); manualRolls('6*');
$result = (new PrepareProcessor($state, fixture([card('only')])))->confirmSettings('host', new Command('confirm_settings'));
check(!$result->success && $state->toArray() === $before, 'infinite debug tie is controlled and atomic');
manualRolls('6,1');
check((new PrepareProcessor($state, fixture([card('only')])))->confirmSettings('host', new Command('confirm_settings'))->success, 'retry after debug fix succeeds');

// Existing Sealed View uses the same entry operation, with an atomic diagnostic guard.
$viewState = new GameState(123, 11, 22); $viewState->mode = GameSettings::MODE_SEALED; $viewState->status = 'view';
$materialize = new ReflectionMethod(PrepareProcessor::class, 'createDeckInstances'); $materialize->setAccessible(true);
foreach (['host', 'player'] as $owner) {
    $pool = array_map(fn($i) => card('view' . $i), range(1, 30));
    $viewState->getPlayer($owner)->deckId = 0; $viewState->getPlayer($owner)->deckCards = $pool;
    $materialize->invoke(new PrepareProcessor($viewState), $owner, $pool);
}
manualApply($viewState, 'host', 'confirm_view');
manualRolls('6*'); manualApply($viewState, 'player', 'confirm_view', [], false);
manualRolls('4,4,1,6'); $result = manualApply($viewState, 'player', 'confirm_view');
check($viewState->status === 'turn' && $viewState->firstPlayer === 'player'
    && in_array('dice_rolled:host=1,player=6', $result->events, true), 'existing View keeps finite tie rerolls and shared events');

// A saved Stage 1 boundary resumes the original army, adds its reserve once, and enters Turn.
$legacy = fresh(); $db = fixture([card('old')]);
$legacy->getPlayer('host')->deckCards = [card('old')]; $legacy->getPlayer('host')->deckCards[0]['count'] = 5;
$legacy->getPlayer('player')->deckCards = $legacy->getPlayer('host')->deckCards;
foreach (['host', 'player'] as $owner) {
    foreach (range(1, 5) as $unused) {
        $legacy->addCard(new CardInstance(instanceId: $legacy->nextInstanceId(), ukid: 'old', owner: $owner,
            zone: CardInstance::ZONE_SQUAD, hp: 8, hpMax: 8, price: 3));
    }
}
$legacyIds = array_keys($legacy->cards); manualRolls('6,1');
check((new PrepareProcessor($legacy, $db))->confirmSettings('host', new Command('confirm_settings'))->success, 'legacy Stage 1 resume');
check($legacy->status === 'turn' && count($legacy->cards) === 60, 'legacy reserve and Turn complete');
foreach ($legacyIds as $id) check(isset($legacy->cards[$id]) && $legacy->cards[$id]->zone === CardInstance::ZONE_SQUAD, 'legacy army IDs retained');
manualApply($legacy, 'host', 'confirm_settings', [], false);

manualRolls('1,6'); $lobby = new GameState(333, 11, 22);
RoomSetup::apply($lobby, RoomSetup::parse('lucktest', ['lucktest_card_count' => '10', 'lucktest_quick_game' => '0']), fixture([card('lobby')]));
check($lobby->status === 'turn' && $lobby->firstPlayer === 'player' && count($lobby->cards) === 60, 'Lobby acceptance enters Turn for opposite dice winner');
manualApply($lobby, 'host', 'confirm_turn'); manualApply($lobby, 'player', 'confirm_turn');
manualApply($lobby, 'player', 'choose_side', ['side' => 2]);
check($lobby->getFirstPlayerKey() === 'host' && $lobby->firstPlayer === 'player' && $lobby->status === 'place', 'opposite winner can select opponent as first');

// CAS protects duplicate Turn and final Place commands, including the start-effects result.
foreach (['turn', 'side', 'place'] as $stage) {
    $initial = $stage === 'place' ? manualCopy($placed) : manualGenerated(3);
    if ($stage === 'side') {
        manualApply($initial, 'host', 'confirm_turn'); manualApply($initial, 'player', 'confirm_turn');
    }
    $command = match ($stage) { 'turn' => 'confirm_turn', 'side' => 'choose_side', default => 'confirm_place' };
    $actor = $stage === 'side' ? 'host' : 'player';
    $payload = $stage === 'side' ? ['side' => 2] : [];
    if ($stage === 'place') manualApply($initial, 'host', 'confirm_place');
    $db = fixture([card('unused')]);
    $db->game = ['ind' => 123, 'host_id' => 11, 'player_id' => 22, 'status' => $stage, 'version' => 4, 'json' => json_encode($initial->toArray())];
    $repo = new GameRepository($db); $a = $repo->findById(123); $b = $repo->findById(123);
    foreach ([$a, $b] as $s) manualApply($s, $actor, $command, $payload);
    $repo->save($a); $committed = $db->game;
    try { $repo->save($b); check(false, 'stale final command persisted'); } catch (StaleStateException) { check(true, 'CAS rejects concurrent ' . $command); }
    check($db->game === $committed && count($repo->findById(123)->cards) === 60, 'one durable complete result for ' . $command);
    $loaded = $repo->findById(123); manualApply($loaded, $actor, $command, $payload, false);
}

// Router explicitly avoids View; existing modes keep the shared read-only presentation.
$router = file_get_contents(__DIR__ . '/../www/index.php');
check(substr_count($router, '$deckData = $state->mode === \\Berserk\\Core\\GameSettings::MODE_LUCKTEST ? []') === 2, 'Turn/Side router guards View for LuckTest');
check(str_contains($router, "\$state->status === 'place'") && str_contains($router, '->isLuckTestQuickStart($cmd)')
    && str_contains($router, '$repo->executeLockedCommand'), 'manual Place retains existing history transaction boundary');
unset($_SESSION['debug_roll'], $_SESSION['debug_roll_state']); Dice::init();
echo 'LuckTest manual: ' . ($checks - $manualStart) . " checks passed (no live database).\n";
