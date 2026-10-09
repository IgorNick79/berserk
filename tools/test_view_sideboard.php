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
use Berserk\Core\Prepare\DraftCopyRules;
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
    array $modifiers = [],
    array $prop = []
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
        prop: $prop,
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

function systemViewState(array $hostDeckCards, int $gameId = 300): GameState
{
    $state = new GameState($gameId, 10, 20);
    $state->mode = GameSettings::MODE_SYSTEM;
    $state->status = 'deck';

    $otherDeckCards = [];
    for ($i = 0; $i < GameSettings::MIN_DECK_SIZE; $i++) {
        $otherDeckCards[] = testDeckCard('opponent_filler_' . $i, 1);
    }

    $result = (new PrepareProcessor($state))->selectDeck(GameState::PLAYER_HOST, new Command('select_deck', [
        'deck_id' => 42,
        'other_deck_id' => 43,
        'valid_deck_ids' => [42, 43],
        'deck_cards' => $hostDeckCards,
        'other_cards' => $otherDeckCards,
    ]));
    assertTrue($result->success, $result->error ?? 'System test deck should be selected');

    return $state;
}

function legacySystemViewState(array $hostDeckCards, int $gameId = 350): GameState
{
    $state = new GameState($gameId, 10, 20);
    $state->mode = GameSettings::MODE_SYSTEM;
    $state->status = 'view';
    $state->getPlayer(GameState::PLAYER_HOST)->deckId = 42;
    $state->getPlayer(GameState::PLAYER_HOST)->deckCards = $hostDeckCards;
    $state->getPlayer(GameState::PLAYER_PLAYER)->deckId = 43;
    $state->getPlayer(GameState::PLAYER_PLAYER)->deckCards = [];

    return $state;
}

function testDeckCard(string $ukid, int $count, array $prop = []): array
{
    return [
        'ukid' => $ukid,
        'count' => $count,
        'price' => 3,
        'health' => 5,
        'move' => 1,
        'strike_weak' => 1,
        'strike_medium' => 2,
        'strike_strong' => 3,
        'element' => 'neutral',
        'prop' => $prop,
        'type' => 'creature',
        'class' => '',
    ];
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

$sameDeckTemplate = [testDeckCard('same_system_deck_card', $minDeckSize)];
$sameDeckState = new GameState(9, 10, 20);
$sameDeckState->mode = GameSettings::MODE_SYSTEM;
$sameDeckState->status = 'deck';
$sameDeckResult = (new PrepareProcessor($sameDeckState))->selectDeck(GameState::PLAYER_HOST, new Command('select_deck', [
    'deck_mode' => 'manual',
    'other_deck_mode' => 'manual',
    'deck_id' => 42,
    'other_deck_id' => 42,
    'valid_deck_ids' => [42],
    'deck_cards' => $sameDeckTemplate,
    'other_cards' => $sameDeckTemplate,
]));
assertTrue($sameDeckResult->success, $sameDeckResult->error ?? 'System deck selection should allow the same deck for both sides');
assertTrue($sameDeckState->status === 'view', 'Same-deck system selection should advance to View');
assertTrue($sameDeckState->getPlayer(GameState::PLAYER_HOST)->deckId === 42, 'Host same-deck selection should be fixed');
assertTrue($sameDeckState->getPlayer(GameState::PLAYER_PLAYER)->deckId === 42, 'Opponent same-deck selection should be fixed');

foreach ([
    ['manual', 'random'],
    ['random', 'manual'],
    ['random', 'random'],
] as $i => [$hostMode, $opponentMode]) {
    $modeState = new GameState(20 + $i, 10, 20);
    $modeState->mode = GameSettings::MODE_SYSTEM;
    $modeState->status = 'deck';
    $modeResult = (new PrepareProcessor($modeState))->selectDeck(GameState::PLAYER_HOST, new Command('select_deck', [
        'deck_mode' => $hostMode,
        'other_deck_mode' => $opponentMode,
        'deck_id' => 42,
        'other_deck_id' => 42,
        'valid_deck_ids' => [42],
        'deck_cards' => $sameDeckTemplate,
        'other_cards' => $sameDeckTemplate,
    ]));
    assertTrue($modeResult->success, "System deck selection should accept {$hostMode}/{$opponentMode} after deck ids are resolved");
    assertTrue($modeState->status === 'view', "System deck selection {$hostMode}/{$opponentMode} should advance to View");
}

$missingHostDeckState = new GameState(11, 10, 20);
$missingHostDeckState->mode = GameSettings::MODE_SYSTEM;
$missingHostDeckState->status = 'deck';
$missingHostDeckResult = (new PrepareProcessor($missingHostDeckState))->selectDeck(GameState::PLAYER_HOST, new Command('select_deck', [
    'deck_mode' => 'manual',
    'other_deck_mode' => 'manual',
    'deck_id' => 0,
    'other_deck_id' => 42,
    'valid_deck_ids' => [42],
    'deck_cards' => [],
    'other_cards' => $sameDeckTemplate,
]));
assertTrue(!$missingHostDeckResult->success, 'Manual system selection should reject missing host deck');
assertTrue($missingHostDeckState->status === 'deck', 'Rejected missing host deck should not advance');

$missingOpponentDeckState = new GameState(12, 10, 20);
$missingOpponentDeckState->mode = GameSettings::MODE_SYSTEM;
$missingOpponentDeckState->status = 'deck';
$missingOpponentDeckResult = (new PrepareProcessor($missingOpponentDeckState))->selectDeck(GameState::PLAYER_HOST, new Command('select_deck', [
    'deck_mode' => 'manual',
    'other_deck_mode' => 'manual',
    'deck_id' => 42,
    'other_deck_id' => 0,
    'valid_deck_ids' => [42],
    'deck_cards' => $sameDeckTemplate,
    'other_cards' => [],
]));
assertTrue(!$missingOpponentDeckResult->success, 'Manual system selection should reject missing opponent deck');
assertTrue($missingOpponentDeckState->status === 'deck', 'Rejected missing opponent deck should not advance');

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

$copyLimitState = draftViewState($minDeckSize, 220);
foreach ($copyLimitState->cards as $id => $card) {
    unset($copyLimitState->cards[$id]);
}
for ($i = 0; $i < 4; $i++) {
    addViewCard($copyLimitState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'copy_limit_normal');
}
for ($i = 4; $i < $minDeckSize + 1; $i++) {
    addViewCard($copyLimitState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'normal_filler_' . $i);
}
$processor = new PrepareProcessor($copyLimitState);
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'Draft deck with four normal copies should be rejected on View confirm');
assertTrue(str_contains($result->error ?? '', 'Слишком много копий'), 'View confirm copy rejection should explain the exceeded limit');

$copyLimitCards = $copyLimitState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK);
$processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $copyLimitCards[0]->instanceId]));
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Moving extra normal copy to sideboard should allow View confirm');

$hordeState = draftViewState($minDeckSize, 221);
foreach ($hordeState->cards as $id => $card) {
    unset($hordeState->cards[$id]);
}
for ($i = 0; $i < DraftCopyRules::HORDE_DECK_LIMIT + 1; $i++) {
    addViewCard($hordeState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'copy_limit_horde', 'neutral', [], [], ['horde' => true]);
}
for ($i = DraftCopyRules::HORDE_DECK_LIMIT + 1; $i < $minDeckSize + 1; $i++) {
    addViewCard($hordeState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK, 'horde_filler_' . $i);
}
$processor = new PrepareProcessor($hordeState);
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'Draft deck with six horde copies should be rejected on View confirm');

$hordeCards = $hordeState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK);
$processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $hordeCards[0]->instanceId]));
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Five horde copies should be allowed on View confirm');

$systemTemplate = [testDeckCard('system_copy_normal', 4)];
for ($i = 0; $i < $minDeckSize - 3; $i++) {
    $systemTemplate[] = testDeckCard('system_filler_' . $i, 1);
}
$systemState = systemViewState($systemTemplate);
$systemBeforeDeckCards = $systemState->getPlayer(GameState::PLAYER_HOST)->deckCards;
$processor = new PrepareProcessor($systemState);
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'System deck with four normal copies should be rejected on View confirm');
assertTrue(str_contains($result->error ?? '', 'system_copy_normal'), 'System deck copy rejection should mention the card');
assertTrue(str_contains($result->error ?? '', '4 из 3'), 'System deck copy rejection should include count and limit');
assertTrue(!$systemState->getPlayer(GameState::PLAYER_HOST)->isConfirmed('view'), 'Rejected system View confirm should not set confirmation');
assertTrue($systemState->status === 'view', 'Rejected system View confirm should not advance stage');
assertTrue(countZone($systemState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $minDeckSize + 1, 'Rejected system View confirm should not mutate deck zone');
assertTrue(countZone($systemState, GameState::PLAYER_HOST, CardInstance::ZONE_SIDEBOARD) === 0, 'Rejected system View confirm should not mutate sideboard zone');

$systemCopies = array_values(array_filter(
    $systemState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK),
    fn(CardInstance $card) => $card->ukid === 'system_copy_normal'
));
$result = $processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $systemCopies[0]->instanceId]));
assertTrue($result->success, $result->error ?? 'System deck should allow moving extra copies to sideboard');
assertTrue(countZone($systemState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $minDeckSize, 'System sideboard move should leave deck at minimum');
assertTrue(countZone($systemState, GameState::PLAYER_HOST, CardInstance::ZONE_SIDEBOARD) === 1, 'System sideboard move should create sideboard entry');
$result = $processor->selectDeck(GameState::PLAYER_HOST, new Command('select_deck', [
    'deck_id' => 44,
    'other_deck_id' => 45,
    'valid_deck_ids' => [44, 45],
    'deck_cards' => [testDeckCard('replacement', $minDeckSize)],
    'other_cards' => [testDeckCard('replacement_opp', $minDeckSize)],
]));
assertTrue(!$result->success, 'selectDeck cannot be called again after View starts');
assertTrue(countZone($systemState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $minDeckSize, 'Rejected repeat selectDeck should not rematerialize deck');
assertTrue(countZone($systemState, GameState::PLAYER_HOST, CardInstance::ZONE_SIDEBOARD) === 1, 'Rejected repeat selectDeck should not lose sideboard edits');

$result = $processor->moveViewCardToDeck(GameState::PLAYER_HOST, new Command('view_to_deck', ['card_id' => $systemCopies[0]->instanceId]));
assertTrue($result->success, $result->error ?? 'System sideboard return should be allowed before final validation');
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'Returning extra system copy should make View confirm fail again');
$processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $systemCopies[0]->instanceId]));
$systemState->getPlayer(GameState::PLAYER_PLAYER)->confirm('view');
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Fixed system deck should confirm View');
assertTrue($systemState->status === 'turn', 'Fixed system deck should advance after both View confirmations');
assertTrue(countZone($systemState, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $minDeckSize, 'Fixed system deck composition should survive View transition');
assertTrue(countZone($systemState, GameState::PLAYER_HOST, CardInstance::ZONE_SIDEBOARD) === 1, 'System sideboard should survive View transition');
assertTrue($systemState->getPlayer(GameState::PLAYER_HOST)->deckCards === $systemBeforeDeckCards, 'System deck template should not be mutated by View sideboarding');
$result = $processor->confirmTurn(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Host should confirm turn after fixed system View');
$result = $processor->confirmTurn(GameState::PLAYER_PLAYER);
assertTrue($result->success, $result->error ?? 'Player should confirm turn after fixed system View');
$firstPlayer = $systemState->firstPlayer ?? GameState::PLAYER_HOST;
$result = $processor->chooseSide($firstPlayer, new Command('choose_side', ['side' => 1]));
assertTrue($result->success, $result->error ?? 'Fixed system deck should proceed to Deal');
$playableNormalCopies = 0;
foreach ($systemState->cards as $card) {
    if ($card->owner === GameState::PLAYER_HOST
        && $card->ukid === 'system_copy_normal'
        && in_array($card->zone, [CardInstance::ZONE_DECK, CardInstance::ZONE_HAND], true)) {
        $playableNormalCopies++;
    }
}
assertTrue($playableNormalCopies === DraftCopyRules::NORMAL_DECK_LIMIT, 'Deal should use fixed system deck zones instead of original template copies');
assertTrue(count(array_filter(
    $systemState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_SIDEBOARD),
    fn(CardInstance $card) => $card->ukid === 'system_copy_normal'
)) === 1, 'System sideboarded copy should stay outside Deal zones');

$systemHordeTemplate = [testDeckCard('system_copy_horde', DraftCopyRules::HORDE_DECK_LIMIT + 1, ['horde' => true])];
for ($i = 0; $i < $minDeckSize - DraftCopyRules::HORDE_DECK_LIMIT; $i++) {
    $systemHordeTemplate[] = testDeckCard('system_horde_filler_' . $i, 1);
}
$systemHordeState = systemViewState($systemHordeTemplate, 301);
$processor = new PrepareProcessor($systemHordeState);
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'System deck with six horde copies should be rejected on View confirm');
assertTrue(str_contains($result->error ?? '', '6 из 5'), 'System horde copy rejection should include count and limit');
$systemHordeCopies = array_values(array_filter(
    $systemHordeState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK),
    fn(CardInstance $card) => $card->ukid === 'system_copy_horde'
));
$processor->moveViewCardToSideboard(GameState::PLAYER_HOST, new Command('view_to_sideboard', ['card_id' => $systemHordeCopies[0]->instanceId]));
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Five system horde copies should be allowed on View confirm');

$legacyInvalid = legacySystemViewState($systemTemplate, 352);
$processor = new PrepareProcessor($legacyInvalid);
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'Legacy system View without runtime instances should not bypass copy limits');
assertTrue(str_contains($result->error ?? '', '4 из 3'), 'Legacy missing-runtime rejection should use copy-limit error');
assertTrue(countZone($legacyInvalid, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $minDeckSize + 1, 'Legacy missing-runtime confirm should materialize deck before validation');
assertTrue(!$legacyInvalid->getPlayer(GameState::PLAYER_HOST)->isConfirmed('view'), 'Legacy invalid system deck should remain unconfirmed');

$legacyValidTemplate = [testDeckCard('legacy_copy_normal', DraftCopyRules::NORMAL_DECK_LIMIT)];
for ($i = 0; $i < $minDeckSize - DraftCopyRules::NORMAL_DECK_LIMIT; $i++) {
    $legacyValidTemplate[] = testDeckCard('legacy_filler_' . $i, 1);
}
$legacyValid = legacySystemViewState($legacyValidTemplate, 353);
$processor = new PrepareProcessor($legacyValid);
$result = $processor->confirmView(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Legacy valid system View should materialize and confirm');
assertTrue(countZone($legacyValid, GameState::PLAYER_HOST, CardInstance::ZONE_DECK) === $minDeckSize, 'Legacy valid system View should keep materialized deck');

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
