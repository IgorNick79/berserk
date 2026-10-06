<?php
// tools/test_forrendor_watch.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\ZoneManager;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function fwAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fwProp(): array
{
    return [
        'actions' => [[
            'key' => 'shot',
            'name' => 'Выстрел',
            'type' => 'shot',
            'value' => 1,
            'range' => 6,
            'target_modifier' => [
                'condition' => 'target_flying',
                'value' => 1,
            ],
        ]],
        'on_any_death' => [
            'key' => 'forrendor_watch_flying_death',
            'condition' => 'dead_creature_flying',
            'side' => 'any',
            'optional' => true,
            'once_per_battle' => true,
            'title' => 'Дозор Форрендора: получить полёт и +1 к удару до конца боя?',
            'accept_label' => 'Получить полёт',
            'decline_label' => 'Закрыть',
            'result_message' => 'Дозор Форрендора получает полёт и +1 к удару до конца боя',
            'effects' => [
                ['type' => 'gain_flight'],
                ['type' => 'grant_modifier', 'stat' => 'strike', 'value' => 1, 'expire' => 'end_of_battle'],
            ],
        ],
    ];
}

function fwCard(array $o): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'] ?? ('card_' . $o['instanceId']),
        owner: $o['owner'] ?? GameState::PLAYER_HOST,
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'] ?? 3,
        col: $o['col'] ?? 3,
        slot: $o['slot'] ?? 0,
        hp: $o['hp'] ?? 9,
        hpMax: $o['hpMax'] ?? ($o['hp'] ?? 9),
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        move: $o['move'] ?? 1,
        moveMax: $o['moveMax'] ?? 1,
        strikeWeak: $o['strikeWeak'] ?? 1,
        strikeMedium: $o['strikeMedium'] ?? 1,
        strikeStrong: $o['strikeStrong'] ?? 2,
        prop: $o['prop'] ?? [],
        modifiers: $o['modifiers'] ?? [],
        markers: $o['markers'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

function fwState(CardInstance ...$cards): GameState
{
    $state = new GameState(18301, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];
    foreach ($cards as $card) {
        $state->addCard($card);
    }
    return $state;
}

function fwWatch(int $id = 1, string $owner = GameState::PLAYER_HOST, int $row = 3, int $col = 3): CardInstance
{
    return fwCard([
        'instanceId' => $id,
        'ukid' => 's1_183',
        'owner' => $owner,
        'row' => $row,
        'col' => $col,
        'prop' => fwProp(),
    ]);
}

function fwVictim(int $id, string $owner, bool $flying, int $row = 4, int $col = 3): CardInstance
{
    return fwCard([
        'instanceId' => $id,
        'ukid' => $flying ? 'flyer' . $id : 'ground' . $id,
        'owner' => $owner,
        'row' => $row,
        'col' => $col,
        'hp' => 3,
        'hpMax' => 3,
        'type' => $flying ? 'fly' : 'creature',
        'zone' => $flying ? CardInstance::ZONE_FLYING : CardInstance::ZONE_FIELD,
        'slot' => $flying ? $id : 0,
    ]);
}

function fwCardsInfo(): array
{
    return [
        's1_183' => ['name' => 'Дозор Форрендора'],
        'flyer2' => ['name' => 'Летун 2'],
        'flyer3' => ['name' => 'Летун 3'],
        'flyer4' => ['name' => 'Летун 4'],
        'flyer5' => ['name' => 'Летун 5'],
        'ground2' => ['name' => 'Наземный 2'],
        'ground3' => ['name' => 'Наземный 3'],
        'ground4' => ['name' => 'Наземный 4'],
    ];
}

function fwKill(GameState $state, CardInstance $target, ?CardInstance $source = null, string $cause = 'destroy'): void
{
    (new Engine())->forceDeath($state, $target, $cause, $source);
}

function fwPendingCount(GameState $state): int
{
    return count($state->battle['pending_any_death'] ?? []);
}

function fwAccept(GameState $state, string $player = GameState::PLAYER_HOST): void
{
    $result = (new Engine())->apply($state, $player, new Command('choose_any_death_target', [
        'target_id' => (int) (($state->battle['pending_any_death'][0]['source_id'] ?? 0)),
    ]));
    fwAssert($result->success, $result->error ?? 'Accept should succeed.');
}

function fwDecline(GameState $state, string $player = GameState::PLAYER_HOST): void
{
    $result = (new Engine())->apply($state, $player, new Command('choose_any_death_target', [
        'target_id' => 0,
    ]));
    fwAssert($result->success, $result->error ?? 'Decline should succeed.');
}

// Shot value: ground target = 1.
$watch = fwWatch();
$ground = fwVictim(2, GameState::PLAYER_PLAYER, false, 3, 5);
$state = fwState($watch, $ground);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'shot',
    'target_id' => 2,
]));
fwAssert($result->success, $result->error ?? 'Watch shot against ground should succeed.');
fwAssert(($state->battle['strike']['damage_total'] ?? null) === 1, 'Watch shot against ground creature should be 1.');

// Shot value: native flying target = 2.
$watch = fwWatch();
$flyer = fwVictim(2, GameState::PLAYER_PLAYER, true);
$state = fwState($watch, $flyer);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'shot',
    'target_id' => 2,
]));
fwAssert($result->success, $result->error ?? 'Watch shot against flyer should succeed.');
fwAssert(($state->battle['strike']['damage_total'] ?? null) === 2, 'Watch shot against flying creature should be 2.');

// Shot value: dynamically flying target = 2.
$watch = fwWatch();
$dynamic = fwVictim(2, GameState::PLAYER_PLAYER, false);
$state = fwState($watch, $dynamic);
$dynamic->type = 'fly';
(new ZoneManager($state))->toFlying($dynamic);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'shot',
    'target_id' => 2,
]));
fwAssert($result->success, $result->error ?? 'Watch shot against dynamically flying creature should succeed.');
fwAssert(($state->battle['strike']['damage_total'] ?? null) === 2, 'Watch shot against dynamically flying creature should be 2.');

// Enemy flyer death creates pending and UI buttons.
$watch = fwWatch();
$flyer = fwVictim(2, GameState::PLAYER_PLAYER, true);
$state = fwState($watch, $flyer);
fwKill($state, $flyer, $watch);
fwAssert(fwPendingCount($state) === 1, 'Enemy flyer death should create pending.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates')))->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    fwCardsInfo(),
    '?first&game=' . $state->gameId
);
fwAssert(str_contains($html, 'Получить полёт') && str_contains($html, 'Закрыть'), 'InfoPanel should render accept and close buttons.');

// Own flyer death creates pending.
$watch = fwWatch();
$flyer = fwVictim(2, GameState::PLAYER_HOST, true);
$state = fwState($watch, $flyer);
fwKill($state, $flyer, $watch);
fwAssert(fwPendingCount($state) === 1, 'Own flyer death should create pending.');

// Ground creature death does not create pending.
$watch = fwWatch();
$ground = fwVictim(2, GameState::PLAYER_PLAYER, false);
$state = fwState($watch, $ground);
fwKill($state, $ground, $watch);
fwAssert(fwPendingCount($state) === 0, 'Ground creature death should not create pending.');

// Destroy and dynamic flying death count as flying death.
$watch = fwWatch();
$dynamic = fwVictim(2, GameState::PLAYER_PLAYER, false);
$state = fwState($watch, $dynamic);
$dynamic->type = 'fly';
(new ZoneManager($state))->toFlying($dynamic);
fwKill($state, $dynamic, $watch, 'destroy');
fwAssert(fwPendingCount($state) === 1, 'Destroying dynamically flying creature should create pending.');

// Decline does not change Watch and does not spend once-per-battle.
$watch = fwWatch();
$flyer = fwVictim(2, GameState::PLAYER_PLAYER, true);
$state = fwState($watch, $flyer);
fwKill($state, $flyer, $watch);
fwDecline($state);
fwAssert(fwPendingCount($state) === 0, 'Decline should clear current pending.');
fwAssert($watch->zone === CardInstance::ZONE_FIELD && $watch->type === 'creature', 'Decline should not grant flight.');
fwAssert(CardStats::getStrikeValue($state, $watch, $watch, 'weak') === 1, 'Decline should not add strike.');
fwAssert($watch->closed === false, 'Decline should not close/tap Watch.');
fwAssert(empty($watch->flags['any_death_used_once_per_battle']), 'Decline should not spend once-per-battle.');
$lateAccept = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_any_death_target', ['target_id' => 1]));
fwAssert(!$lateAccept->success, 'Cannot accept after declined pending is gone.');

// Another death after decline offers again; repeated declines are allowed.
$flyer2 = fwVictim(3, GameState::PLAYER_PLAYER, true);
$state->addCard($flyer2);
fwKill($state, $flyer2, $watch);
fwAssert(fwPendingCount($state) === 1, 'Next flyer death after decline should create pending again.');
fwDecline($state);
$flyer3 = fwVictim(4, GameState::PLAYER_PLAYER, true);
$state->addCard($flyer3);
fwKill($state, $flyer3, $watch);
fwAssert(fwPendingCount($state) === 1, 'Repeated decline should still allow later flyer death.');

// Accept grants flight and +1 strike until end_of_battle, spends once-per-battle, and suppresses later triggers.
fwAccept($state);
fwAssert($watch->zone === CardInstance::ZONE_FLYING && $watch->type === 'fly', 'Accept should grant flight through flying zone mechanism.');
fwAssert(CardStats::getStrikeValue($state, $watch, $watch, 'weak') === 2, 'Weak strike should become 2.');
fwAssert(CardStats::getStrikeValue($state, $watch, $watch, 'medium') === 2, 'Medium strike should become 2.');
fwAssert(CardStats::getStrikeValue($state, $watch, $watch, 'strong') === 3, 'Strong strike should become 3.');
fwAssert(!empty(array_filter($watch->modifiers, fn($m) => ($m['stat'] ?? '') === 'ability_strike' && ($m['expire'] ?? '') === 'end_of_battle')), 'Strike modifier should expire end_of_battle.');
fwAssert(!empty($watch->flags['any_death_used_once_per_battle']['forrendor_watch_flying_death']), 'Accept should spend once-per-battle.');
$flyer4 = fwVictim(5, GameState::PLAYER_PLAYER, true);
$state->addCard($flyer4);
fwKill($state, $flyer4, $watch);
fwAssert(fwPendingCount($state) === 0, 'After accept, later flyer death should not create pending for same Watch.');

$messageState = fwState(fwWatch(), fwVictim(2, GameState::PLAYER_PLAYER, false));
$messageState->battle['strike'] = [
    'kind' => 'strike',
    'attacker_id' => 1,
    'target_id' => 2,
    'defender_id' => null,
    'state' => 'results',
    'attack_dice' => 2,
    'defend_dice' => 0,
    'result' => ['attack' => 'weak', 'defend' => '', 'winner' => 'attack'],
    'final' => ['attack' => 'weak', 'defend' => '', 'decreased' => false],
    'damage' => 1,
    'damage_total' => 1,
    'confirmed' => [],
];
$messageState->battle['any_death_messages'][] = [
    'source_id' => 1,
    'message' => 'Дозор Форрендора получает полёт и +1 к удару до конца боя',
];
$html = (new InfoPanel(new Template(__DIR__ . '/../templates')))->render(
    $messageState,
    GameState::PLAYER_HOST,
    'host',
    fwCardsInfo(),
    '?first&game=' . $messageState->gameId
);
fwAssert(str_contains($html, 'Дозор Форрендора получает полёт') && str_contains($html, 'cmd=confirm_strike'), 'Accept result message should not block strike confirmation.');

// Two flyer deaths stay queued: decline first, accept second.
$watch = fwWatch();
$flyerA = fwVictim(2, GameState::PLAYER_PLAYER, true);
$flyerB = fwVictim(3, GameState::PLAYER_PLAYER, true);
$state = fwState($watch, $flyerA, $flyerB);
fwKill($state, $flyerA, $watch);
fwKill($state, $flyerB, $watch);
fwAssert(fwPendingCount($state) === 2, 'Two flyer deaths should create two queued opportunities.');
fwDecline($state);
fwAssert(fwPendingCount($state) === 1, 'Declining first queued death should preserve second event.');
fwAccept($state);
fwAssert($watch->zone === CardInstance::ZONE_FLYING, 'Watch should be able to accept the second queued death.');

// Accept first queued death removes later queued events for the same used source.
$watch = fwWatch();
$flyerA = fwVictim(2, GameState::PLAYER_PLAYER, true);
$flyerB = fwVictim(3, GameState::PLAYER_PLAYER, true);
$state = fwState($watch, $flyerA, $flyerB);
fwKill($state, $flyerA, $watch);
fwKill($state, $flyerB, $watch);
fwAccept($state);
fwAssert(fwPendingCount($state) === 0, 'Accepting first queued death should prune later once-per-battle events.');

// Multiple Watch instances are independent.
$watchA = fwWatch(1, GameState::PLAYER_HOST, 3, 3);
$watchB = fwWatch(6, GameState::PLAYER_HOST, 3, 4);
$flyer = fwVictim(2, GameState::PLAYER_PLAYER, true);
$state = fwState($watchA, $watchB, $flyer);
fwKill($state, $flyer, $watchA);
fwAssert(fwPendingCount($state) === 2, 'Two Watch instances should each queue their own pending.');
fwAccept($state);
fwAssert(!empty($watchA->flags['any_death_used_once_per_battle']) xor !empty($watchB->flags['any_death_used_once_per_battle']), 'Only one Watch should be used after first accept.');
fwAssert(fwPendingCount($state) === 1, 'Second Watch pending should remain after first Watch accepts.');
fwAccept($state);
fwAssert(!empty($watchA->flags['any_death_used_once_per_battle']) && !empty($watchB->flags['any_death_used_once_per_battle']), 'Second Watch should accept independently.');

// If Watch dies before trigger processing, pending is pruned and cannot resolve.
$watch = fwWatch();
$flyer = fwVictim(2, GameState::PLAYER_PLAYER, true);
$state = fwState($watch, $flyer);
fwKill($state, $flyer, $watch);
fwAssert(fwPendingCount($state) === 1, 'Pending exists before Watch dies.');
fwKill($state, $watch, $flyer);
fwAssert(fwPendingCount($state) === 0, 'Dead Watch should not keep pending.');
fwAssert($watch->zone !== CardInstance::ZONE_FLYING, 'Dead Watch should not receive flight.');

echo "Forrendor Watch tests passed.\n";
