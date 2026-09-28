<?php
// tools/test_view_sideboard.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\DraftProcessor;
use Berserk\Core\Prepare\PrepareProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function addViewCard(GameState $state, string $owner, string $zone, string $ukid, array $flags = [], array $modifiers = []): CardInstance
{
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

$limit = GameSettings::DECK_LIMIT;
$state = new GameState(1, 10, 20);
$state->mode = GameSettings::MODE_DRAFT;
$state->status = 'view';
$state->getPlayer(GameState::PLAYER_HOST)->deckId = 0;
$state->getPlayer(GameState::PLAYER_PLAYER)->deckId = 0;

$hostCards = [];
for ($i = 0; $i < $limit + 2; $i++) {
    $hostCards[] = addViewCard($state, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'host_' . $i);
}
for ($i = 0; $i < $limit; $i++) {
    addViewCard($state, GameState::PLAYER_PLAYER, CardInstance::ZONE_DECK, 'player_' . $i);
}

$processor = new PrepareProcessor($state);
$oversizedConfirm = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue(!$oversizedConfirm->success, 'Oversized draft deck should not confirm View');
assertTrue(!$state->getPlayer(GameState::PLAYER_HOST)->isConfirmed('view'), 'Rejected View confirm should not mutate confirmation state');

$marked = $hostCards[0];
$trackedInstanceId = $marked->instanceId;
$marked->flags['kept_flag'] = true;
$marked->modifiers[] = ['stat' => 'move', 'value' => 1, 'source' => 'test'];

$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $marked->instanceId]));
assertTrue($result->success, $result->error ?? 'First extra card should move to sideboard');
assertTrue($marked->instanceId === $trackedInstanceId, 'Sideboard move should keep the same tracked instance id');
assertTrue($marked->zone === CardInstance::ZONE_SIDEBOARD, 'Moved card should be in sideboard');
assertTrue(($marked->flags['kept_flag'] ?? false) === true, 'Sideboard move should preserve flags');
assertTrue(!empty($marked->modifiers), 'Sideboard move should preserve modifiers');

$secondSideboard = $hostCards[1];
$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $secondSideboard->instanceId]));
assertTrue($result->success, $result->error ?? 'Second extra card should move to sideboard');
assertTrue(countZone($state, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $limit, 'Deck should reach configured limit after moving extras');
assertTrue(countZone($state, GameState::PLAYER_HOST, CardInstance::ZONE_SIDEBOARD) === 2, 'Sideboard should contain moved extras');

$blockedAtLimit = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $hostCards[2]->instanceId]));
assertTrue(!$blockedAtLimit->success, 'Deck at limit should not move another card to sideboard');
assertTrue($hostCards[2]->zone === CardInstance::ZONE_DECK, 'Rejected sideboard move should leave card in deck');

$result = $processor->moveViewCardToDeck(GameState::PLAYER_HOST, new Command('view_to_deck', ['card_id' => $marked->instanceId]));
assertTrue($result->success, $result->error ?? 'Sideboard card should return to deck');
assertTrue($marked->instanceId === $trackedInstanceId, 'Sideboard return should keep the same tracked instance id');
assertTrue($marked->zone === CardInstance::ZONE_DECK, 'Returned card should be in deck');
assertTrue(countZone($state, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $limit + 1, 'Returning sideboard card may oversize deck again');

$replacement = $hostCards[2];
$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $replacement->instanceId]));
assertTrue($result->success, $result->error ?? 'Replacement card should move to sideboard');
assertTrue($replacement->zone === CardInstance::ZONE_SIDEBOARD, 'Replacement card should be in sideboard');
assertTrue($marked->zone === CardInstance::ZONE_DECK, 'Returned card should stay in deck after replacement');

$restored = GameState::fromArray($state->toArray());
$restoredMarked = $restored->getCard($marked->instanceId);
assertTrue($restoredMarked !== null, 'Sideboard-capable state should deserialize moved card');
assertTrue($restoredMarked->instanceId === $trackedInstanceId, 'Tracked instance id should survive serialization');
assertTrue($restoredMarked->zone === CardInstance::ZONE_DECK, 'Returned card zone should survive serialization');
assertTrue(($restoredMarked->flags['kept_flag'] ?? false) === true, 'Returned card flags should survive serialization');
assertTrue(!empty($restoredMarked->modifiers), 'Returned card modifiers should survive serialization');

foreach ($state->cards as $card) {
    if ($card->owner === GameState::PLAYER_HOST
        && $card->instanceId !== $trackedInstanceId
        && $card->zone === CardInstance::ZONE_DECK) {
        $card->zone = CardInstance::ZONE_SIDEBOARD;
    }
}
assertTrue(countZone($state, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === 1, 'Test setup should keep only tracked instance in deck for deterministic Deal hand check');

$processor = new PrepareProcessor($state);
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Host should confirm View at deck limit');
$result = $processor->confirmView(GameState::PLAYER_PLAYER);
assertTrue($result->success, $result->error ?? 'Player should confirm View at deck limit');
assertTrue($state->status === 'turn', 'Both View confirmations should advance to turn');

$processor = new PrepareProcessor($state);
$processor->confirmTurn(GameState::PLAYER_HOST);
$processor->confirmTurn(GameState::PLAYER_PLAYER);
assertTrue($state->status === 'side', 'Both turn confirmations should advance to side choice');

$firstPlayer = $state->firstPlayer ?? GameState::PLAYER_HOST;
$result = $processor->chooseSide($firstPlayer, new Command('choose_side', ['side' => 1]));
assertTrue($result->success, $result->error ?? 'Side choice should deal cards');
assertTrue($state->status === 'deal', 'Side choice should advance to Deal');
assertTrue($marked->instanceId === $trackedInstanceId, 'Deal generation should not replace the tracked instance');
assertTrue($marked->zone === CardInstance::ZONE_HAND, 'Tracked deck instance should move to Deal hand without recreation');

$hostPlayable = countZone($state, GameState::PLAYER_HOST, CardInstance::ZONE_DECK)
    + countZone($state, GameState::PLAYER_HOST, CardInstance::ZONE_HAND)
    + countZone($state, GameState::PLAYER_HOST, CardInstance::ZONE_SQUAD);
assertTrue($hostPlayable === 1, 'Deal should include only current deck cards, not sideboard');
assertTrue($replacement->zone === CardInstance::ZONE_SIDEBOARD, 'Sideboard replacement should not enter Deal zones');
assertTrue($secondSideboard->zone === CardInstance::ZONE_SIDEBOARD, 'Sideboard extra should not enter Deal zones');
$sideboardInstanceIds = [$replacement->instanceId, $secondSideboard->instanceId];

$state->getPlayer(GameState::PLAYER_HOST)->resources['gold'] = 3;
$result = $processor->reshuffle(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Draft reshuffle should succeed with available gold');
assertTrue($marked->instanceId === $trackedInstanceId, 'Reshuffle should not replace the tracked instance');
assertTrue($marked->zone === CardInstance::ZONE_HAND, 'Tracked instance should still be dealt from deck after reshuffle');
foreach ($sideboardInstanceIds as $sideboardId) {
    $sideboardCard = $state->getCard($sideboardId);
    assertTrue($sideboardCard !== null, 'Sideboard card should keep its instance after reshuffle');
    assertTrue($sideboardCard->zone === CardInstance::ZONE_SIDEBOARD, 'Sideboard instance should survive reshuffle outside hand/squad/deck');
}

foreach ([$limit - 1, $limit] as $initialCount) {
    $smallState = new GameState(100 + $initialCount, 10, 20);
    $smallState->mode = GameSettings::MODE_DRAFT;
    $smallState->status = 'view';
    $smallState->getPlayer(GameState::PLAYER_HOST)->deckId = 0;
    for ($i = 0; $i < $initialCount; $i++) {
        addViewCard($smallState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'small_' . $initialCount . '_' . $i);
    }

    $smallProcessor = new PrepareProcessor($smallState);
    $smallResult = $smallProcessor->confirmView(GameState::PLAYER_HOST);
    assertTrue($smallResult->success, "Initial draft deck count {$initialCount} should confirm View without sideboard");
    assertTrue($smallState->getPlayer(GameState::PLAYER_HOST)->isConfirmed('view'), "Initial draft deck count {$initialCount} should use old View confirmation flow");
}

echo "View sideboard tests passed.\n";
