<?php
// tools/test_sealed_flow.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\BoosterSettings;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\PrepareProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function sealedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sealedApply(GameState $state, Engine $engine, string $type, array $payload = []): void
{
    $result = $engine->apply($state, GameState::PLAYER_HOST, new Command($type, $payload));
    sealedAssert($result->success, $result->error ?? ('Command failed: ' . $type));
}

function sealedApplyAs(GameState $state, Engine $engine, string $playerKey, string $type, array $payload = []): void
{
    $result = $engine->apply($state, $playerKey, new Command($type, $payload));
    sealedAssert($result->success, $result->error ?? ('Command failed: ' . $type));
}

function sealedCard(string $ukid, int $count, array $prop = []): array
{
    return [
        'ukid' => $ukid,
        'count' => $count,
        'price' => 3,
        'elite' => false,
        'single' => false,
        'element' => 'neutral',
        'health' => 5,
        'move' => 1,
        'strike_weak' => 1,
        'strike_medium' => 2,
        'strike_strong' => 3,
        'prop' => $prop,
        'type' => 'creature',
        'class' => '',
    ];
}

function sealedUkids(int $count): array
{
    return array_map(fn(int $i) => 'pool_' . $i, range(1, $count));
}

function invokePreparePrivate(PrepareProcessor $processor, string $method, array $args = []): mixed
{
    $ref = new ReflectionClass(PrepareProcessor::class);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($processor, $args);
}

$default = GameSettings::defaults();
sealedAssert($default->sealedBoosters() === GameSettings::SEALED_BOOSTERS_MAX, 'Default Sealed booster count should be 4');
sealedAssert($default->validateSealedSettings() === null, 'Default Sealed settings should validate');
foreach ([3, 4] as $boosters) {
    sealedAssert(
        GameSettings::fromArray(['sealed' => ['boosters' => $boosters]])->validateSealedSettings() === null,
        "Sealed boosters={$boosters} should validate"
    );
}
foreach ([0, 2, 5, -1, '3abc'] as $badBoosters) {
    sealedAssert(
        GameSettings::fromArray(['sealed' => ['boosters' => $badBoosters]])->validateSealedSettings() !== null,
        'Invalid Sealed booster count should be rejected'
    );
}

$engine = new Engine();
$state = new GameState(600, 1, 2);
sealedApply($state, $engine, 'choose_mode', ['mode' => GameSettings::MODE_SEALED]);
sealedAssert($state->mode === GameSettings::MODE_SEALED, 'Sealed mode should be stored');
sealedAssert($state->status === 'settings', 'Sealed mode should transition to settings');

foreach ([0, 2, 5, -1, '3abc'] as $i => $badBoosters) {
    $badState = new GameState(610 + $i, 1, 2);
    sealedApply($badState, $engine, 'choose_mode', ['mode' => GameSettings::MODE_SEALED]);
    $before = $badState->toArray();
    $result = $engine->apply($badState, GameState::PLAYER_HOST, new Command('confirm_settings', [
        'sealed_boosters' => $badBoosters,
    ]));
    sealedAssert(!$result->success, 'Invalid Sealed booster payload should fail');
    sealedAssert($badState->toArray() === $before, 'Invalid Sealed settings should not mutate state');
}

foreach ([3, 4] as $boosters) {
    $noDbState = new GameState(630 + $boosters, 1, 2);
    sealedApply($noDbState, $engine, 'choose_mode', ['mode' => GameSettings::MODE_SEALED]);
    $before = $noDbState->toArray();
    $result = $engine->apply($noDbState, GameState::PLAYER_HOST, new Command('confirm_settings', [
        'sealed_boosters' => $boosters,
    ]));
    sealedAssert(!$result->success, 'Sealed without Db should fail before startup');
    sealedAssert($noDbState->toArray() === $before, 'Failed Sealed startup should not mutate state');
}

$processor = new PrepareProcessor(new GameState(650, 1, 2));
$parsedSettings = invokePreparePrivate($processor, 'settingsFromCommand', [GameSettings::defaults(), new Command('confirm_settings', [
    'sealed_boosters' => 3,
])]);
sealedAssert($parsedSettings instanceof GameSettings, 'settingsFromCommand should return GameSettings');
sealedAssert($parsedSettings->sealedBoosters() === 3, 'sealed_boosters=3 should be preserved from command payload');
foreach ([3, 4] as $boosters) {
    $expected = $boosters * BoosterSettings::BOOSTER_SIZE;
    sealedAssert(
        invokePreparePrivate($processor, 'sealedDeckCountError', [$boosters, sealedUkids($expected), [sealedCard('all_' . $boosters, $expected)]]) === null,
        "Sealed {$boosters}-booster pool should accept exactly {$expected} cards"
    );
    sealedAssert(
        invokePreparePrivate($processor, 'sealedDeckCountError', [$boosters, sealedUkids($expected - 1), [sealedCard('short_' . $boosters, $expected - 1)]]) !== null,
        "Sealed {$boosters}-booster pool should reject generator result with one card missing"
    );
    sealedAssert(
        invokePreparePrivate($processor, 'sealedDeckCountError', [$boosters, sealedUkids($expected + 1), [sealedCard('long_' . $boosters, $expected + 1)]]) !== null,
        "Sealed {$boosters}-booster pool should reject generator result with one extra card"
    );
    sealedAssert(
        invokePreparePrivate($processor, 'sealedDeckCountError', [$boosters, sealedUkids($expected), [sealedCard('builder_short_' . $boosters, $expected - 1)]]) !== null,
        "Sealed {$boosters}-booster pool should reject builder result with one card missing"
    );
    sealedAssert(
        invokePreparePrivate($processor, 'sealedDeckCountError', [$boosters, sealedUkids($expected), [sealedCard('builder_long_' . $boosters, $expected + 1)]]) !== null,
        "Sealed {$boosters}-booster pool should reject builder result with one extra card"
    );
}
sealedAssert(invokePreparePrivate($processor, 'isDeckBuildable', [[sealedCard('a', 10), sealedCard('b', 10), sealedCard('c', 10)]]) === false, 'Three non-horde copies should contribute only nine playable cards');
sealedAssert(invokePreparePrivate($processor, 'isDeckBuildable', [array_map(fn(int $i) => sealedCard('u' . $i, 1), range(1, 30))]) === true, 'Thirty unique cards should be buildable');
sealedAssert(invokePreparePrivate($processor, 'isDeckBuildable', [[sealedCard('h1', 5, ['horde' => true]), sealedCard('h2', 5, ['horde' => true]), sealedCard('h3', 5, ['horde' => true]), sealedCard('h4', 5, ['horde' => true]), sealedCard('h5', 5, ['horde' => true]), sealedCard('h6', 5, ['horde' => true])]]) === true, 'Horde cards should use five-card deck limit for buildability');
sealedAssert(invokePreparePrivate($processor, 'isDeckBuildable', [array_fill(0, 10, sealedCard('dup', 3))]) === false, 'Duplicate deckCards rows for one ukid should share one copy limit');

$sealedState = new GameState(700, 1, 2);
$sealedState->mode = GameSettings::MODE_SEALED;
$sealedState->status = 'view';
$hostCards = array_map(fn(int $i) => sealedCard('host_' . $i, 1), range(1, 36));
$playerCards = array_map(fn(int $i) => sealedCard('player_' . $i, 1), range(1, 48));
$sealedState->getPlayer(GameState::PLAYER_HOST)->deckId = 0;
$sealedState->getPlayer(GameState::PLAYER_PLAYER)->deckId = 0;
$sealedState->getPlayer(GameState::PLAYER_HOST)->deckCards = $hostCards;
$sealedState->getPlayer(GameState::PLAYER_PLAYER)->deckCards = $playerCards;
$sealedProcessor = new PrepareProcessor($sealedState);
invokePreparePrivate($sealedProcessor, 'createDeckInstances', [GameState::PLAYER_HOST, $hostCards]);
invokePreparePrivate($sealedProcessor, 'createDeckInstances', [GameState::PLAYER_PLAYER, $playerCards]);
sealedAssert(count($sealedState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK)) === 36, 'Host should have 36 Sealed deck instances');
sealedAssert(count($sealedState->getCardsInZone(GameState::PLAYER_PLAYER, CardInstance::ZONE_DECK)) === 48, 'Player should have 48 Sealed deck instances');
foreach ($sealedState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK) as $card) {
    sealedAssert(str_starts_with($card->ukid, 'host_'), 'Host Sealed card should not leak from player pool');
}
foreach ($sealedState->getCardsInZone(GameState::PLAYER_PLAYER, CardInstance::ZONE_DECK) as $card) {
    sealedAssert(str_starts_with($card->ukid, 'player_'), 'Player Sealed card should not leak from host pool');
}
$ids = array_keys($sealedState->cards);
sealedAssert(count($ids) === count(array_unique($ids)), 'Sealed instance ids should be unique');

$sideboardCandidate = $sealedState->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK)[0] ?? null;
sealedAssert($sideboardCandidate instanceof CardInstance, 'Host should have a card to move to sideboard');
$sideboardId = $sideboardCandidate->instanceId;
$sideboardUkid = $sideboardCandidate->ukid;
sealedApplyAs($sealedState, $engine, GameState::PLAYER_HOST, 'view_to_sideboard', ['card_id' => $sideboardId]);
sealedAssert(($sealedState->getCard($sideboardId)?->zone ?? null) === CardInstance::ZONE_SIDEBOARD, 'Sealed card should move to sideboard by instance id');
sealedApplyAs($sealedState, $engine, GameState::PLAYER_HOST, 'view_to_deck', ['card_id' => $sideboardId]);
sealedAssert(($sealedState->getCard($sideboardId)?->zone ?? null) === CardInstance::ZONE_DECK, 'Sealed card should return to deck by same instance id');
sealedApplyAs($sealedState, $engine, GameState::PLAYER_HOST, 'view_to_sideboard', ['card_id' => $sideboardId]);
sealedApplyAs($sealedState, $engine, GameState::PLAYER_HOST, 'confirm_view');
sealedApplyAs($sealedState, $engine, GameState::PLAYER_PLAYER, 'confirm_view');
sealedAssert(($sealedState->getCard($sideboardId)?->zone ?? null) === CardInstance::ZONE_SIDEBOARD, 'View confirmation should preserve Sealed sideboard instance');
sealedApplyAs($sealedState, $engine, GameState::PLAYER_HOST, 'confirm_turn');
sealedApplyAs($sealedState, $engine, GameState::PLAYER_PLAYER, 'confirm_turn');
sealedAssert($sealedState->firstPlayer !== null, 'Dice roll should choose first player');
sealedApplyAs($sealedState, $engine, $sealedState->firstPlayer, 'choose_side', ['side' => 1]);
sealedAssert(($sealedState->getCard($sideboardId)?->zone ?? null) === CardInstance::ZONE_SIDEBOARD, 'Deal should preserve Sealed sideboard instance');
foreach ([CardInstance::ZONE_HAND, CardInstance::ZONE_SQUAD, CardInstance::ZONE_DECK] as $zone) {
    foreach ($sealedState->getCardsInZone(GameState::PLAYER_HOST, $zone) as $card) {
        sealedAssert($card->instanceId !== $sideboardId, 'Sideboard instance should not re-enter active deck zones during Deal');
    }
}
sealedAssert(($sealedState->getCard($sideboardId)?->ukid ?? null) === $sideboardUkid, 'Sideboard card should keep same ukid across View and Deal');

$restored = GameState::fromArray($sealedState->toArray());
sealedAssert($restored->mode === GameSettings::MODE_SEALED, 'Restored state should keep Sealed mode');
sealedAssert($restored->status === 'deal', 'Restored Sealed state should keep Deal status');
sealedAssert(($restored->getCard($sideboardId)?->zone ?? null) === CardInstance::ZONE_SIDEBOARD, 'Restored Sealed sideboard card should survive serialization');
sealedAssert(count($restored->getCardsInZone(GameState::PLAYER_PLAYER, CardInstance::ZONE_DECK)) >= 30, 'Restored player Sealed cards should survive serialization');

echo "Sealed flow tests passed.\n";
