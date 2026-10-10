<?php
// tools/test_forced_strike_targets.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\BattleHelper;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function forcedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function forcedCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? 'card_' . $overrides['instanceId'],
        owner: $overrides['owner'],
        zone: CardInstance::ZONE_FIELD,
        row: $overrides['row'],
        col: $overrides['col'],
        hp: $overrides['hp'] ?? 3,
        hpMax: $overrides['hpMax'] ?? 3,
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        prop: $overrides['prop'] ?? [],
    );
}

function forcedState(bool $withClosedTarget): GameState
{
    $state = new GameState(808, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];

    $state->addCard(forcedCard([
        'instanceId' => 1,
        'ukid' => 's1_basarg',
        'owner' => GameState::PLAYER_HOST,
        'row' => 3,
        'col' => 3,
        'prop' => ['forced_strike' => true],
    ]));
    $state->addCard(forcedCard([
        'instanceId' => 2,
        'ukid' => 'open_enemy',
        'owner' => GameState::PLAYER_PLAYER,
        'row' => 3,
        'col' => 4,
        'closed' => false,
    ]));
    $state->addCard(forcedCard([
        'instanceId' => 3,
        'ukid' => 'closed_enemy',
        'owner' => GameState::PLAYER_PLAYER,
        'row' => 4,
        'col' => 3,
        'closed' => $withClosedTarget,
    ]));
    $state->addCard(forcedCard([
        'instanceId' => 4,
        'ukid' => 'far_closed_enemy',
        'owner' => GameState::PLAYER_PLAYER,
        'row' => 6,
        'col' => 5,
        'closed' => true,
    ]));

    return $state;
}

function forcedRender(GameState $state): array
{
    $_GET = ['sel' => 1, 'mode' => 'strike'];
    $_SESSION = [];

    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
    return $screen->prepare($state, GameState::PLAYER_HOST, 'host', null, [
        's1_basarg' => ['name' => 'Гном-басаарг', 'health' => 3],
        'open_enemy' => ['name' => 'Открытая цель', 'health' => 3],
        'closed_enemy' => ['name' => 'Закрытая цель', 'health' => 3],
        'far_closed_enemy' => ['name' => 'Дальняя закрытая цель', 'health' => 3],
    ])['data'];
}

$forced = forcedState(true);
$targets = BattleHelper::getAttackTargets($forced, $forced->getCard(1), 'strike', GameState::PLAYER_HOST);
forcedAssert(isset($targets[3]), 'Forced strike should keep adjacent closed enemy target available.');
forcedAssert(!isset($targets[2]), 'Forced strike should hide adjacent open enemy target while closed target exists.');
forcedAssert(!isset($targets[4]), 'Forced strike should not include non-adjacent closed enemy target.');

$html = forcedRender($forced)['field_html'];
forcedAssert(str_contains($html, 'cmd=strike&card_id=1&target_id=3'), 'Battlefield should link adjacent closed forced target.');
forcedAssert(!str_contains($html, 'cmd=strike&card_id=1&target_id=2'), 'Battlefield should not link adjacent open target during forced strike.');
forcedAssert(!str_contains($html, 'cmd=strike&card_id=1&target_id=4'), 'Battlefield should not link far closed target during forced strike.');

$rejected = (new Engine())->apply($forced, GameState::PLAYER_HOST, new Command('strike', [
    'card_id' => 1,
    'target_id' => 2,
]));
forcedAssert(!$rejected->success, 'Server should reject adjacent open target while forced closed target exists.');

$withoutClosed = forcedState(false);
$targets = BattleHelper::getAttackTargets($withoutClosed, $withoutClosed->getCard(1), 'strike', GameState::PLAYER_HOST);
forcedAssert(isset($targets[2]), 'Open adjacent enemy target should be available when no adjacent closed target exists.');
forcedAssert(isset($targets[3]), 'Former closed target should be available as a normal open adjacent target.');

$accepted = (new Engine())->apply($withoutClosed, GameState::PLAYER_HOST, new Command('strike', [
    'card_id' => 1,
    'target_id' => 2,
]));
forcedAssert($accepted->success, $accepted->error ?? 'Server should accept open adjacent target when no forced closed target exists.');

echo "Forced strike target tests passed.\n";
