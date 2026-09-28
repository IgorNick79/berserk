<?php
// tools/test_view_sideboard.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\DeckView;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\DraftProcessor;
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\View\Screen\ViewScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function addViewCard(
    GameState $state,
    string $owner,
    string $zone,
    string $ukid,
    string $element = 'neutral',
    array $flags = [],
    array $modifiers = []
): CardInstance {
    $card = new CardInstance(
        instanceId: $state->nextInstanceId(),
        ukid: $ukid,
        owner: $owner,
        zone: $zone,
        hp: 5,
        hpMax: 5,
        price: 3,
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
        element: $element,
        flags: $flags,
        modifiers: $modifiers,
    );
    $state->addCard($card);
    return $card;
}

function countZone(GameState $state, string $owner, string $zone): int
{
    return count($state->getCardsInZone($owner, $zone));
}

function draftViewState(int $deckCount, int $gameId = 1, string $owner = GameState::PLAYER_HOST): GameState
{
    $state = new GameState($gameId, 10, 20);
    $state->mode = GameSettings::MODE_DRAFT;
    $state->status = 'view';
    $state->getPlayer(GameState::PLAYER_HOST)->deckId = 0;
    $state->getPlayer(GameState::PLAYER_PLAYER)->deckId = 0;

    for ($i = 0; $i < $deckCount; $i++) {
        $element = $i % 2 === 0 ? 'plains' : 'forests';
        addViewCard($state, $owner, CardInstance::ZONE_DECK, $owner . '_' . $i, $element);
    }

    return $state;
}

function createDraftInstancesForTest(GameState $state, string $owner, array $deckCards): void
{
    $ref = new ReflectionClass(DraftProcessor::class);
    $processor = $ref->newInstanceWithoutConstructor();
    $stateProp = $ref->getProperty('state');
    $stateProp->setAccessible(true);
    $stateProp->setValue($processor, $state);

    $method = $ref->getMethod('createDraftDeckInstances');
    $method->setAccessible(true);
    $method->invoke($processor, $owner, $deckCards);
}

function dummyDeckView(): DeckView
{
    return (new ReflectionClass(DeckView::class))->newInstanceWithoutConstructor();
}

function viewScreenData(GameState $state, array $cardsInfo, array $elementLabels): array
{
    $tpl = new Template(__DIR__ . '/../templates/');
    return (new ViewScreen(dummyDeckView(), $tpl))->prepare(
        $state,
        GameState::PLAYER_HOST,
        'host',
        null,
        $cardsInfo,
        $elementLabels
    )['data'];
}

$minDeckSize = GameSettings::MIN_DECK_SIZE;
$maxDeckSize = GameSettings::MAX_DECK_SIZE;

$draftFinalized = new GameState(10, 10, 20);
$draftFinalized->mode = GameSettings::MODE_DRAFT;
$draftFinalized->status = 'view';
$draftFinalized->getPlayer(GameState::PLAYER_HOST)->deckId = 0;
createDraftInstancesForTest($draftFinalized, GameState::PLAYER_HOST, [[
    'ukid' => 'tracked_finalized',
    'count' => 1,
    'price' => 3,
    'health' => 5,
    'move' => 1,
    'elite' => false,
    'element' => 'neutral',
    'strike_weak' => 1,
    'strike_medium' => 2,
    'strike_strong' => 3,
]]);
$finalizedCards = $draftFinalized->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK);
assertTrue(count($finalizedCards) === 1, 'Draft finalization should create one deck instance per drafted card copy');
assertTrue($finalizedCards[0]->ukid === 'tracked_finalized', 'Draft-created instance should keep card ukid');

$minState = draftViewState($minDeckSize, 20);
$minCards = $minState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK);
$processor = new PrepareProcessor($minState);
$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $minCards[0]->instanceId]));
assertTrue(!$result->success, 'Deck at minimum should reject moving a card to sideboard');
assertTrue(countZone($minState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $minDeckSize, 'Rejected minimum sideboard move should keep deck count');
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Deck at minimum should confirm View without sideboard');

$minPlusOneState = draftViewState($minDeckSize + 1, 21);
$minPlusOneCards = $minPlusOneState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK);
$sideboardCandidate = $minPlusOneCards[0];
$processor = new PrepareProcessor($minPlusOneState);
$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $sideboardCandidate->instanceId]));
assertTrue($result->success, $result->error ?? 'Deck above minimum should allow moving one card to sideboard');
assertTrue(countZone($minPlusOneState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $minDeckSize, 'Moving one from min+1 should leave deck at minimum');
$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $minPlusOneCards[1]->instanceId]));
assertTrue(!$result->success, 'Deck back at minimum should reject another sideboard move');

$optionalState = draftViewState($minDeckSize + 5, 22);
$processor = new PrepareProcessor($optionalState);
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Deck above minimum should confirm View without mandatory sideboard');

$maxState = draftViewState($maxDeckSize, 23);
$processor = new PrepareProcessor($maxState);
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Deck at maximum should confirm View without sideboard');

$partialState = draftViewState($maxDeckSize, 24);
$partialCards = $partialState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK);
$processor = new PrepareProcessor($partialState);
for ($i = 0; $i < 3; $i++) {
    $result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $partialCards[$i]->instanceId]));
    assertTrue($result->success, $result->error ?? 'Partial sideboard from maximum should be accepted');
}
assertTrue(countZone($partialState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $maxDeckSize - 3, 'Partial sideboard should leave max-3 in deck');
$returning = $partialCards[0];
$returningId = $returning->instanceId;
$result = $processor->moveViewCardToDeck(GameState::PLAYER_HOST, new Command('view_to_deck', ['card_id' => $returningId]));
assertTrue($result->success, $result->error ?? 'Sideboard card should return to deck');
assertTrue($returning->instanceId === $returningId && $returning->zone === CardInstance::ZONE_DECK, 'Sideboard return should preserve instance id');

$elementState = new GameState(25, 10, 20);
$elementState->mode = GameSettings::MODE_DRAFT;
$elementState->status = 'view';
$elementState->getPlayer(GameState::PLAYER_HOST)->deckId = 0;
$plainsCard = addViewCard($elementState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'plain_a', 'plains');
addViewCard($elementState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'plain_b', 'plains');
for ($i = 0; $i < $minDeckSize - 2; $i++) {
    addViewCard($elementState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'forest_' . $i, 'forests');
}
addViewCard($elementState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'extra_forest', 'forests');

$cardsInfo = [
    'plain_a' => ['name' => 'Plain A', 'price' => 1, 'health' => 5, 'move' => 1, 'elite' => false, 'element' => 'Степи', 'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1]],
    'plain_b' => ['name' => 'Plain B', 'price' => 1, 'health' => 5, 'move' => 1, 'elite' => false, 'element' => 'Степи', 'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1]],
];
for ($i = 0; $i < $minDeckSize - 2; $i++) {
    $cardsInfo['forest_' . $i] = ['name' => 'Forest ' . $i, 'price' => 1, 'health' => 5, 'move' => 1, 'elite' => false, 'element' => 'Леса', 'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1]];
}
$cardsInfo['extra_forest'] = ['name' => 'Extra Forest', 'price' => 1, 'health' => 5, 'move' => 1, 'elite' => false, 'element' => 'Леса', 'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1]];
$elementLabels = ['plains' => 'Степи', 'forests' => 'Леса'];

$data = viewScreenData($elementState, $cardsInfo, $elementLabels);
assertTrue(str_contains($data['bottom_panel_html'], 'Степи: <b>2</b>'), 'View bottom panel should count plains in current deck');
$processor = new PrepareProcessor($elementState);
$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $plainsCard->instanceId]));
assertTrue($result->success, $result->error ?? 'Element counter setup should allow sideboard move');
$data = viewScreenData($elementState, $cardsInfo, $elementLabels);
assertTrue(str_contains($data['bottom_panel_html'], 'Степи: <b>1</b>'), 'View element count should decrease after deck to sideboard');
$result = $processor->moveViewCardToDeck(GameState::PLAYER_HOST, new Command('view_to_deck', ['card_id' => $plainsCard->instanceId]));
assertTrue($result->success, $result->error ?? 'Element counter setup should allow sideboard return');
$data = viewScreenData($elementState, $cardsInfo, $elementLabels);
assertTrue(str_contains($data['bottom_panel_html'], 'Степи: <b>2</b>'), 'View element count should increase after sideboard to deck');
assertTrue(!str_contains($data['bottom_panel_html'], 'plains'), 'View element badges should use labels, not internal codes');

$state = draftViewState($minDeckSize + 2, 130);
$hostCards = $state->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK);
$marked = $hostCards[0];
$trackedInstanceId = $marked->instanceId;
$marked->flags['kept_flag'] = true;
$marked->modifiers[] = ['stat' => 'move', 'value' => 1, 'source' => 'test'];

$processor = new PrepareProcessor($state);
$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $trackedInstanceId]));
assertTrue($result->success, $result->error ?? 'Tracked card should move to sideboard');
assertTrue($marked->instanceId === $trackedInstanceId && $marked->zone === CardInstance::ZONE_SIDEBOARD, 'Sideboard move should keep tracked instance id');
assertTrue(($marked->flags['kept_flag'] ?? false) === true, 'Sideboard move should preserve flags');
assertTrue(!empty($marked->modifiers), 'Sideboard move should preserve modifiers');
$result = $processor->moveViewCardToDeck(GameState::PLAYER_HOST, new Command('view_to_deck', ['card_id' => $trackedInstanceId]));
assertTrue($result->success, $result->error ?? 'Tracked card should return to deck');
assertTrue($marked->instanceId === $trackedInstanceId && $marked->zone === CardInstance::ZONE_DECK, 'Sideboard return should keep tracked instance id');

$sideboardCard = $hostCards[1];
$sideboardId = $sideboardCard->instanceId;
$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $sideboardId]));
assertTrue($result->success, $result->error ?? 'Extra card should remain in sideboard for Deal isolation');

$restored = GameState::fromArray($state->toArray());
$restoredMarked = $restored->getCard($trackedInstanceId);
assertTrue($restoredMarked !== null, 'Sideboard-capable state should deserialize tracked card');
assertTrue($restoredMarked->instanceId === $trackedInstanceId, 'Tracked instance id should survive serialization');
assertTrue($restoredMarked->zone === CardInstance::ZONE_DECK, 'Returned card zone should survive serialization');
assertTrue(($restoredMarked->flags['kept_flag'] ?? false) === true, 'Returned card flags should survive serialization');
assertTrue(!empty($restoredMarked->modifiers), 'Returned card modifiers should survive serialization');

$state->status = 'side';
$state->firstPlayer = GameState::PLAYER_HOST;
$state->getPlayer(GameState::PLAYER_HOST)->side = null;
$state->getPlayer(GameState::PLAYER_PLAYER)->side = null;
$result = $processor->chooseSide(GameState::PLAYER_HOST, new Command('choose_side', ['side' => 1]));
assertTrue($result->success, $result->error ?? 'Side choice should deal draft deck instances');
assertTrue($state->status === 'deal', 'Side choice should advance to Deal');
assertTrue($marked->instanceId === $trackedInstanceId, 'Deal generation should not replace the tracked instance');
assertTrue(in_array($marked->zone, [CardInstance::ZONE_DECK, CardInstance::ZONE_HAND], true), 'Tracked instance should stay in playable draft zones after Deal');
assertTrue(($state->getCard($sideboardId)?->zone) === CardInstance::ZONE_SIDEBOARD, 'Sideboard instance should not enter Deal zones');

$state->getPlayer(GameState::PLAYER_HOST)->resources['gold'] = 3;
$result = $processor->reshuffle(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Draft reshuffle should succeed with available gold');
assertTrue($marked->instanceId === $trackedInstanceId, 'Reshuffle should not replace the tracked instance');
assertTrue(in_array($marked->zone, [CardInstance::ZONE_DECK, CardInstance::ZONE_HAND], true), 'Tracked instance should remain in playable draft zones after reshuffle');
assertTrue(($state->getCard($sideboardId)?->zone) === CardInstance::ZONE_SIDEBOARD, 'Sideboard instance should survive reshuffle outside hand/squad/deck');

echo "View sideboard tests passed.\n";
