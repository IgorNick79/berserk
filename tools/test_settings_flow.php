<?php
// tools/test_settings_flow.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Command;
use Berserk\Core\Db;
use Berserk\Core\Engine;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\DraftCopyRules;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function apply(GameState $state, Engine $engine, string $player, string $type, array $payload = []): void
{
    $result = $engine->apply($state, $player, new Command($type, $payload));
    assertTrue($result->success, $result->error ?? ('Command failed: ' . $type));
}

function assertDraftDeckCopyLimits(array $deckCards, string $message): void
{
    foreach ($deckCards as $card) {
        $count = (int) ($card['count'] ?? 0);
        $limit = DraftCopyRules::deckLimit($card);
        assertTrue($count <= $limit, $message . ': ' . (string) ($card['ukid'] ?? '?'));
    }
}

// mode -> deck (system has no extra settings step)
$state = new GameState(1, 1, 2);
$engine = new Engine();

apply($state, $engine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_SYSTEM]);
assertTrue($state->mode === GameSettings::MODE_SYSTEM, 'System mode was not stored');
assertTrue($state->status === 'deck', 'System mode did not transition directly to deck');
assertTrue($state->draft === null, 'System mode should not initialize draft');

// mode -> settings (draft), without starting draft yet
$state = new GameState(2, 1, 2);
apply($state, $engine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
assertTrue($state->mode === GameSettings::MODE_DRAFT, 'Draft mode was not stored');
assertTrue($state->status === 'settings', 'Draft mode did not transition to settings');
assertTrue($state->draft === null, 'Draft should not start before settings confirmation');

// Serialization round-trip preserves status/mode/settings.
$state->settings = GameSettings::fromArray([
    'draft' => [
        'type'            => GameSettings::DRAFT_TYPE_GRID,
        'grid_size'       => 3,
        'boosters'        => 5,
        'booster_profile' => GameSettings::BOOSTER_PROFILE_DEFAULT,
    ],
]);
$restored = GameState::fromArray($state->toArray());
assertTrue($restored->status === 'settings', 'Round-trip lost status');
assertTrue($restored->mode === GameSettings::MODE_DRAFT, 'Round-trip lost mode');
assertTrue($restored->settings->draftBoosters() === 5, 'Round-trip lost draft boosters');
assertTrue($restored->settings->draftGridSize() === 3, 'Round-trip lost draft grid size');
assertTrue($restored->settings->draftPickMode() === GameSettings::DRAFT_PICK_MODE_MANUAL, 'Default draft pick mode should be manual');
assertTrue($restored->settings->draftAutoSide() === GameSettings::DRAFT_AUTO_SIDE_BOTH, 'Default draft auto side should be both');

$randomSettings = GameSettings::fromArray([
    'draft' => [
        'pick_mode' => GameSettings::DRAFT_PICK_MODE_RANDOM,
        'auto_side' => GameSettings::DRAFT_AUTO_SIDE_PLAYER,
    ],
]);
assertTrue($randomSettings->draftPickMode() === GameSettings::DRAFT_PICK_MODE_RANDOM, 'Random draft pick mode was not stored');
assertTrue($randomSettings->draftAutoSide() === GameSettings::DRAFT_AUTO_SIDE_PLAYER, 'Random draft auto side was not stored');
$randomRestored = GameSettings::fromArray($randomSettings->toArray());
assertTrue($randomRestored->draftPickMode() === GameSettings::DRAFT_PICK_MODE_RANDOM, 'Round-trip lost random draft pick mode');
assertTrue($randomRestored->draftAutoSide() === GameSettings::DRAFT_AUTO_SIDE_PLAYER, 'Round-trip lost random draft auto side');

$discreteSettings = GameSettings::fromArray([
    'draft' => [
        'grid_mode' => GameSettings::DRAFT_GRID_MODE_DISCRETE,
    ],
]);
assertTrue($discreteSettings->draftGridMode() === GameSettings::DRAFT_GRID_MODE_DISCRETE, 'Discrete grid mode was not stored');
assertTrue($discreteSettings->validateDraftSettings() === null, 'Default draft settings should validate');

// Unsupported draft grid size is rejected before DB access.
$badGrid = new GameState(3, 1, 2);
apply($badGrid, $engine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
$result = $engine->apply(
    $badGrid,
    GameState::PLAYER_HOST,
    new Command('confirm_settings', ['grid_size' => 4])
);
assertTrue(!$result->success, 'Unsupported grid size should be rejected');
assertTrue($badGrid->status === 'settings', 'Rejected draft settings should stay in settings');
assertTrue($badGrid->draft === null, 'Rejected draft settings should not initialize runtime draft');

// Unknown draft pick mode is rejected before DB access.
$badPickMode = new GameState(8, 1, 2);
apply($badPickMode, $engine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
$result = $engine->apply(
    $badPickMode,
    GameState::PLAYER_HOST,
    new Command('confirm_settings', ['draft_pick_mode' => 'bogus'])
);
assertTrue(!$result->success, 'Unknown draft pick mode should be rejected');
assertTrue($badPickMode->status === 'settings', 'Rejected draft pick mode should stay in settings');
assertTrue($badPickMode->settings->draftPickMode() === 'bogus', 'Rejected draft pick mode should still be visible in attempted settings');

// Unknown draft auto side is rejected before DB access.
$badAutoSide = new GameState(9, 1, 2);
apply($badAutoSide, $engine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
$result = $engine->apply(
    $badAutoSide,
    GameState::PLAYER_HOST,
    new Command('confirm_settings', [
        'draft_pick_mode' => GameSettings::DRAFT_PICK_MODE_RANDOM,
        'draft_auto_side' => 'bogus',
    ])
);
assertTrue(!$result->success, 'Unknown draft auto side should be rejected');
assertTrue($badAutoSide->status === 'settings', 'Rejected draft auto side should stay in settings');

// Draft booster count is server-side restricted to the canonical 5-8 range.
foreach ([4, 9] as $badBoosters) {
    $badBoostersState = new GameState(70 + $badBoosters, 1, 2);
    apply($badBoostersState, $engine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
    $result = $engine->apply(
        $badBoostersState,
        GameState::PLAYER_HOST,
        new Command('confirm_settings', ['boosters' => $badBoosters])
    );
    assertTrue(!$result->success, "Draft boosters={$badBoosters} should be rejected");
    assertTrue($badBoostersState->status === 'settings', 'Rejected booster count should stay in settings');
}

foreach ([GameSettings::DRAFT_BOOSTERS_MIN, GameSettings::DRAFT_BOOSTERS_MAX] as $goodBoosters) {
    $customBoosters = new GameState(80 + $goodBoosters, 1, 2);
    apply($customBoosters, $engine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
    $result = $engine->apply(
        $customBoosters,
        GameState::PLAYER_HOST,
        new Command('confirm_settings', ['boosters' => $goodBoosters])
    );
    assertTrue(!$result->success, 'Draft without Db should not start in this test section');
    assertTrue($customBoosters->settings->draftBoosters() === $goodBoosters, 'Valid booster payload was not applied to settings');
}

$autoManualState = new GameState(90, 1, 2);
apply($autoManualState, $engine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
$result = $engine->apply(
    $autoManualState,
    GameState::PLAYER_HOST,
    new Command('confirm_settings', [
        'boosters' => 5,
        'draft_pick_mode' => GameSettings::DRAFT_PICK_MODE_MANUAL,
        'draft_auto_side' => GameSettings::DRAFT_AUTO_SIDE_PLAYER,
        'draft_grid_mode' => GameSettings::DRAFT_GRID_MODE_DISCRETE,
    ])
);
assertTrue(!$result->success, 'Draft without Db should not start while checking settings payload');
assertTrue($autoManualState->settings->draftPickMode() === GameSettings::DRAFT_PICK_MODE_MANUAL, 'Manual mode should remain manual even with auto side payload');
assertTrue($autoManualState->settings->draftAutoSide() === GameSettings::DRAFT_AUTO_SIDE_PLAYER, 'Auto side payload should persist without forcing random mode');
assertTrue($autoManualState->settings->draftGridMode() === GameSettings::DRAFT_GRID_MODE_DISCRETE, 'Grid mode payload was not applied');

$customTimer = GameSettings::fromArray(['draft' => [
    'timer_mode' => GameSettings::DRAFT_TIMER_CUSTOM,
    'timer_total' => GameSettings::DRAFT_TIMER_CUSTOM_TOTAL_MIN_SECONDS,
    'timer_action' => GameSettings::DRAFT_TIMER_CUSTOM_ACTION_MIN_SECONDS,
]]);
assertTrue($customTimer->validateDraftSettings() === null, 'Custom timer lower bounds should validate');
$badTimerStep = GameSettings::fromArray(['draft' => [
    'timer_mode' => GameSettings::DRAFT_TIMER_CUSTOM,
    'timer_total' => GameSettings::DRAFT_TIMER_CUSTOM_TOTAL_MIN_SECONDS + 1,
    'timer_action' => GameSettings::DRAFT_TIMER_CUSTOM_ACTION_MIN_SECONDS,
]]);
assertTrue($badTimerStep->validateDraftSettings() !== null, 'Custom timer bad total step should be rejected');
$unlimitedTimer = GameSettings::fromArray(['draft' => [
    'timer_mode' => GameSettings::DRAFT_TIMER_UNLIMITED,
    'timer_total' => 1,
    'timer_action' => 1,
]]);
assertTrue($unlimitedTimer->validateDraftSettings() === null, 'Unlimited timer should not require custom values');

// Settings command outside settings stage is invalid.
$invalidStage = new GameState(4, 1, 2);
$result = $engine->apply($invalidStage, GameState::PLAYER_HOST, new Command('confirm_settings'));
assertTrue(!$result->success, 'confirm_settings outside settings should fail');

// Older serialized state without mode/settings still loads with safe defaults.
$old = GameState::fromArray([
    'game_id' => 5,
    'host_id' => 1,
    'player_id' => 2,
    'status' => 'deck',
    'players' => [
        'host' => ['user_id' => 1],
        'player' => ['user_id' => 2],
    ],
]);
assertTrue($old->status === 'deck', 'Old state status did not load');
assertTrue($old->mode === null, 'Old state should not invent mode');
assertTrue($old->settings->systemDeckSelection() === GameSettings::SYSTEM_DECK_SELECTION_MANUAL, 'Old state did not get system defaults');
assertTrue($old->settings->draftBoosters() === 5, 'Old state did not get draft defaults');

// DB-backed default draft initialization, when local DB config is usable.
$configPath = __DIR__ . '/../config/db.php';
if (is_file($configPath)) {
    $db = new Db(require $configPath);
    foreach ([GameSettings::DRAFT_BOOSTERS_MIN, GameSettings::DRAFT_BOOSTERS_MAX] as $boosters) {
        $draftState = new GameState(6 + $boosters, 1, 2);
        $draftEngine = new Engine($db);

        apply($draftState, $draftEngine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
        apply($draftState, $draftEngine, GameState::PLAYER_HOST, 'confirm_settings', ['boosters' => $boosters]);

        assertTrue($draftState->status === 'draft', 'Draft settings did not transition to draft');
        assertTrue($draftState->settings->draftBoosters() === $boosters, 'Draft booster count was not stored');
        assertTrue(is_array($draftState->draft), 'Draft runtime state was not initialized');
        assertTrue(count($draftState->draft['grid'] ?? []) === 9, 'Default draft grid is not 3x3');
    }

    $randomDraft = new GameState(20, 1, 2);
    $draftEngine = new Engine($db);
    apply($randomDraft, $draftEngine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
    apply($randomDraft, $draftEngine, GameState::PLAYER_HOST, 'confirm_settings', [
        'boosters' => 5,
        'draft_pick_mode' => GameSettings::DRAFT_PICK_MODE_RANDOM,
    ]);

    assertTrue($randomDraft->status === 'view', 'Random draft should transition directly to view');
    assertTrue($randomDraft->draft === null, 'Random draft should not leave runtime draft state');
    assertTrue($randomDraft->getPlayer(GameState::PLAYER_HOST)->deckId === 0, 'Random draft host deck id should be 0');
    assertTrue($randomDraft->getPlayer(GameState::PLAYER_PLAYER)->deckId === 0, 'Random draft player deck id should be 0');
    assertTrue(count($randomDraft->getPlayer(GameState::PLAYER_HOST)->deckCards) > 0, 'Random draft should build host deck cards');
    assertTrue(count($randomDraft->getPlayer(GameState::PLAYER_PLAYER)->deckCards) > 0, 'Random draft should build player deck cards');
    assertDraftDeckCopyLimits($randomDraft->getPlayer(GameState::PLAYER_HOST)->deckCards, 'Continuous random draft host deck should respect copy limits');
    assertDraftDeckCopyLimits($randomDraft->getPlayer(GameState::PLAYER_PLAYER)->deckCards, 'Continuous random draft player deck should respect copy limits');

    $discreteRandomDraft = new GameState(22, 1, 2);
    apply($discreteRandomDraft, $draftEngine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
    apply($discreteRandomDraft, $draftEngine, GameState::PLAYER_HOST, 'confirm_settings', [
        'boosters' => 5,
        'draft_pick_mode' => GameSettings::DRAFT_PICK_MODE_RANDOM,
        'draft_grid_mode' => GameSettings::DRAFT_GRID_MODE_DISCRETE,
    ]);

    assertTrue($discreteRandomDraft->status === 'view', 'Discrete random draft should transition directly to view');
    assertTrue($discreteRandomDraft->draft === null, 'Discrete random draft should not leave runtime draft state');
    assertTrue(count($discreteRandomDraft->getPlayer(GameState::PLAYER_HOST)->deckCards) > 0, 'Discrete random draft should build host deck cards');
    assertTrue(count($discreteRandomDraft->getPlayer(GameState::PLAYER_PLAYER)->deckCards) > 0, 'Discrete random draft should build player deck cards');
    assertDraftDeckCopyLimits($discreteRandomDraft->getPlayer(GameState::PLAYER_HOST)->deckCards, 'Discrete random draft host deck should respect copy limits');
    assertDraftDeckCopyLimits($discreteRandomDraft->getPlayer(GameState::PLAYER_PLAYER)->deckCards, 'Discrete random draft player deck should respect copy limits');

    $smallRandomDraft = new GameState(21, 1, 2);
    apply($smallRandomDraft, $draftEngine, GameState::PLAYER_HOST, 'choose_mode', ['mode' => GameSettings::MODE_DRAFT]);
    $result = $draftEngine->apply(
        $smallRandomDraft,
        GameState::PLAYER_HOST,
        new Command('confirm_settings', [
            'boosters' => 1,
            'draft_pick_mode' => GameSettings::DRAFT_PICK_MODE_RANDOM,
        ])
    );
    assertTrue(!$result->success, 'Random draft should reject invalid booster count before startup');
    assertTrue($smallRandomDraft->status === 'settings', 'Rejected random draft should stay in settings');
    assertTrue($smallRandomDraft->draft === null, 'Rejected random draft should not initialize draft runtime state');
} else {
    echo "Skipping DB-backed draft initialization check: config/db.php not found\n";
}

echo "Settings flow tests passed.\n";
