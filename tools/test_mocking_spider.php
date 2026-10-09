<?php
// tools/test_mocking_spider.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\BattleHelper;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Dice;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;
use Berserk\Core\Movement\MovementResolver;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function msAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function msSpiderProp(): array
{
    return [
        'air_intercept' => true,
        'actions' => [
            [
                'key' => 'throw_web',
                'name' => 'Бросок сети',
                'type' => 'apply_delayed_marker',
                'range' => 2,
                'target' => 'enemy_non_flying',
                'marker' => [
                    'type' => 'spider_web',
                    'activate' => 'end_of_current_turn',
                    'expire' => 'start_of_source_owner_next_turn',
                ],
            ],
        ],
    ];
}

function msShotProp(string $type = 'shot', int $value = 3): array
{
    return [
        'actions' => [
            [
                'key' => $type,
                'name' => $type,
                'type' => $type,
                'value' => $value,
                'range' => 4,
                'near_shot' => true,
            ],
        ],
    ];
}

function msImpactProp(): array
{
    return [
        'actions' => [
            [
                'key' => 'impact',
                'name' => 'Воздействие',
                'type' => 'impact',
                'target' => 'all_near',
                'filter' => 'enemy',
                'value' => 3,
            ],
        ],
    ];
}

function msCard(array $o): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'] ?? ('card_' . $o['instanceId']),
        owner: $o['owner'] ?? GameState::PLAYER_HOST,
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'] ?? 3,
        col: $o['col'] ?? 3,
        slot: $o['slot'] ?? 0,
        hp: $o['hp'] ?? 10,
        hpMax: $o['hpMax'] ?? ($o['hp'] ?? 10),
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        move: $o['move'] ?? 1,
        moveMax: $o['moveMax'] ?? 1,
        strikeWeak: $o['strikeWeak'] ?? 3,
        strikeMedium: $o['strikeMedium'] ?? 3,
        strikeStrong: $o['strikeStrong'] ?? 3,
        coins: $o['coins'] ?? 0,
        prop: $o['prop'] ?? [],
        markers: $o['markers'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

function msSpider(array $o = []): CardInstance
{
    return msCard(array_merge([
        'instanceId' => 1,
        'ukid' => 's1_77',
        'owner' => GameState::PLAYER_HOST,
        'row' => 3,
        'col' => 3,
        'hp' => 6,
        'hpMax' => 6,
        'prop' => msSpiderProp(),
    ], $o));
}

function msState(CardInstance ...$cards): GameState
{
    $state = new GameState(77, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 2,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
        'hidden_row_revealed' => true,
    ];
    foreach ($cards as $card) {
        $state->cards[$card->instanceId] = $card;
    }
    return $state;
}

function msConfirm(GameState $state): void
{
    $engine = new Engine();
    $r = $engine->apply($state, GameState::PLAYER_HOST, new Command('confirm_strike'));
    msAssert($r->success, $r->error ?? 'Host confirm failed.');
    $r = $engine->apply($state, GameState::PLAYER_PLAYER, new Command('confirm_strike'));
    msAssert($r->success, $r->error ?? 'Player confirm failed.');
}

function msThrowWeb(GameState $state, int $targetId, bool $confirm = true): void
{
    $r = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
        'card_id' => 1,
        'target_id' => $targetId,
        'action_key' => 'throw_web',
    ]));
    msAssert($r->success, $r->error ?? 'Throw web failed.');
    if ($confirm) {
        msConfirm($state);
    }
}

function msActivateWeb(GameState $state, string $endingKey = GameState::PLAYER_HOST, string $nextKey = GameState::PLAYER_PLAYER): void
{
    (new TurnProcessor($state, new Engine()))->afterEndPhase($endingKey, $nextKey);
}

function msStrikeAndConfirm(GameState $state, string $playerKey, int $attackerId, int $targetId): array
{
    $_SESSION['debug_roll'] = '6,1';
    unset($_SESSION['debug_roll_state']);
    Dice::init();
    $resolver = new StrikeResolver($state, new Engine());
    $r = $resolver->declare($playerKey, new Command('strike', [
        'card_id' => $attackerId,
        'target_id' => $targetId,
    ]));
    msAssert($r->success, $r->error ?? 'Strike failed.');
    $snapshot = (array) ($state->battle['strike'] ?? []);
    $r = $resolver->confirmStrike(GameState::PLAYER_HOST, new Command('confirm_strike'));
    msAssert($r->success, $r->error ?? 'Host strike confirm failed.');
    $r = $resolver->confirmStrike(GameState::PLAYER_PLAYER, new Command('confirm_strike'));
    msAssert($r->success, $r->error ?? 'Player strike confirm failed.');
    return $snapshot;
}

function msAction(GameState $state, string $playerKey, int $cardId, int $targetId, string $key): void
{
    $r = (new Engine())->apply($state, $playerKey, new Command('action', [
        'card_id' => $cardId,
        'target_id' => $targetId,
        'action_key' => $key,
    ]));
    msAssert($r->success, $r->error ?? "Action {$key} failed.");
}

// Target validation.
$state = msState(
    msSpider(),
    msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]),
    msCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 3]),
    msCard(['instanceId' => 4, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'type' => 'fly', 'zone' => CardInstance::ZONE_FLYING]),
    msCard(['instanceId' => 5, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 4])
);
$targets = BattleHelper::getAttackTargets($state, $state->getCard(1), 'action:throw_web', GameState::PLAYER_HOST);
msAssert(isset($targets[2]), 'Spider can target enemy non-flying creature in range 2.');
msAssert(!isset($targets[3]), 'Spider cannot target out-of-range creature.');
msAssert(!isset($targets[4]), 'Spider cannot target flying creature.');
msAssert(!isset($targets[5]), 'Spider cannot target own creature.');

// Delayed activation and same-turn attack does not consume web.
$spider = msSpider();
$target = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 10]);
$allyAttacker = msCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 2]);
$state = msState($spider, $target, $allyAttacker);
msThrowWeb($state, 2);
msAssert($spider->closed, 'Spider closes after throwing web.');
msAssert(!isset($target->markers['spider_web']), 'Spider web marker is not active immediately.');
msAssert(!empty($state->battle['scheduled_card_markers']), 'Throw web creates scheduled marker state.');
msStrikeAndConfirm($state, GameState::PLAYER_HOST, 3, 2);
msAssert($target->hp === 7, 'Same-turn strike before activation deals normal damage.');
msAssert(!isset($state->battle['strike']['spider_web_block']), 'Same-turn strike before activation is not web-blocked.');
msActivateWeb($state);
msAssert(isset($target->markers['spider_web']), 'Scheduled web activates at source end turn.');

// Target dies before activation.
$spider = msSpider();
$target = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$state = msState($spider, $target);
msThrowWeb($state, 2);
$target->hp = 0;
$target->zone = CardInstance::ZONE_GRAVEYARD;
msActivateWeb($state);
msAssert(!isset($target->markers['spider_web']), 'Dead target should not receive activated web.');
msAssert(empty($state->battle['scheduled_card_markers'] ?? []), 'Scheduled web should be cleared when target is dead.');

// Active restriction: movement, strike, actions, coin gain, but still targetable.
$webbed = msCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
    'closed' => false,
    'prop' => msShotProp('shot', 3) + ['save_coins' => true, 'coins' => ['max_value' => 3]],
    'markers' => ['spider_web' => ['source' => GameState::PLAYER_HOST, 'timing' => 'source_next_turn_start']],
]);
$enemyTarget = msCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$state = msState($webbed, $enemyTarget);
$state->battle['active'] = GameState::PLAYER_PLAYER;
$r = (new MovementResolver($state, new Engine()))->move(GameState::PLAYER_PLAYER, new Command('move', [
    'card_id' => 2,
    'row' => 4,
    'col' => 4,
]));
msAssert(!$r->success && str_contains($r->error ?? '', 'сети'), 'Webbed card cannot move and error mentions web.');
$r = (new Engine())->apply($state, GameState::PLAYER_PLAYER, new Command('strike', [
    'card_id' => 2,
    'target_id' => 3,
]));
msAssert(!$r->success && str_contains($r->error ?? '', 'сети'), 'Webbed card cannot strike.');
$r = (new Engine())->apply($state, GameState::PLAYER_PLAYER, new Command('action', [
    'card_id' => 2,
    'target_id' => 3,
    'action_key' => 'shot',
]));
msAssert(!$r->success && str_contains($r->error ?? '', 'сети'), 'Webbed card cannot use active action.');
$r = (new Engine())->apply($state, GameState::PLAYER_PLAYER, new Command('gain_coin', ['card_id' => 2]));
msAssert(!$r->success && str_contains($r->error ?? '', 'сети'), 'Webbed card cannot gain coin.');
msAssert($webbed->closed === false, 'Spider web does not close the card.');
$targets = BattleHelper::getAttackTargets($state, $enemyTarget, 'strike', GameState::PLAYER_HOST);
msAssert(isset($targets[2]), 'Webbed card remains a valid attack target.');

// Enemy strike removes web and blocks damage; next strike works normally.
$target = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 10, 'markers' => ['spider_web' => ['source' => GameState::PLAYER_HOST, 'timing' => 'source_next_turn_start']]]);
$attacker = msCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$secondAttacker = msCard(['instanceId' => 4, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 2]);
$state = msState($target, $attacker, $secondAttacker);
$strikeResult = msStrikeAndConfirm($state, GameState::PLAYER_HOST, 3, 2);
msAssert($target->hp === 10, 'First strike on webbed target deals 0 damage.');
msAssert(isset($strikeResult['spider_web_block']), 'First strike records spider web block.');
msAssert(!isset($target->markers['spider_web']), 'First strike removes spider web.');
msAssert($attacker->closed || (int) ($attacker->flags['attacks_used_this_turn'] ?? 0) > 0, 'Attacker spends attack normally.');
$state->battle['strike'] = null;
msStrikeAndConfirm($state, GameState::PLAYER_HOST, 4, 2);
msAssert($target->hp === 7, 'Next strike after web removal deals normal damage.');

// Friendly strike removes web and frees target for same-turn actions.
$target = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 3, 'hp' => 10, 'markers' => ['spider_web' => ['source' => GameState::PLAYER_PLAYER, 'timing' => 'source_next_turn_start']]]);
$friend = msCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 2]);
$enemy = msCard(['instanceId' => 4, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 3]);
$state = msState($target, $friend, $enemy);
msStrikeAndConfirm($state, GameState::PLAYER_HOST, 3, 2);
msAssert($target->hp === 10, 'Friendly strike on webbed target deals 0 damage.');
msAssert(!isset($target->markers['spider_web']), 'Friendly strike removes spider web.');
msAssert($friend->closed || (int) ($friend->flags['attacks_used_this_turn'] ?? 0) > 0, 'Friendly attacker spends attack normally.');
msAssert(!empty(BattleHelper::getMoveCells($state, $target)), 'Freed target can move later that turn if otherwise allowed.');

// Other damage attacks block and remove web.
foreach (['shot', 'magic', 'discharge'] as $type) {
    $target = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 10, 'markers' => ['spider_web' => ['source' => GameState::PLAYER_HOST, 'timing' => 'source_next_turn_start']]]);
    $attacker = msCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => msShotProp($type, 3)]);
    $state = msState($target, $attacker);
    msAction($state, GameState::PLAYER_HOST, 3, 2, $type);
    msAssert($target->hp === 10, "{$type} should be blocked by web.");
    msAssert(!isset($target->markers['spider_web']), "{$type} should remove web.");
    msAssert(isset($state->battle['strike']['spider_web_block']), "{$type} should record web block.");
}

// Non-attacks do not remove web.
$target = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 10, 'markers' => ['spider_web' => ['source' => GameState::PLAYER_HOST, 'timing' => 'source_next_turn_start']]]);
$attacker = msCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 2, 'prop' => msImpactProp()]);
$state = msState($target, $attacker);
msAction($state, GameState::PLAYER_HOST, 3, 3, 'impact');
msAssert($target->hp === 7, 'Impact still deals damage.');
msAssert(isset($target->markers['spider_web']), 'Impact does not remove spider web.');
(new Engine())->applyDamage($state, $target, 2, 'poison', $attacker);
msAssert($target->hp === 5, 'Poison damage still applies.');
msAssert(isset($target->markers['spider_web']), 'Poison does not remove spider web.');
$r = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 3,
    'target_id' => 2,
    'action_key' => 'execute',
]));
msAssert(!$r->success || isset($target->markers['spider_web']), 'Execute is not treated as web-breaking attack.');

// Lifetime: web survives target owner turn and expires at source owner next turn.
$target = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'markers' => ['spider_web' => ['source' => GameState::PLAYER_HOST, 'timing' => 'source_next_turn_start']]]);
$state = msState($target);
(new TurnProcessor($state, new Engine()))->continueStartTurn(GameState::PLAYER_PLAYER);
msAssert(isset($target->markers['spider_web']), 'Web remains during target owner turn.');
(new TurnProcessor($state, new Engine()))->continueStartTurn(GameState::PLAYER_HOST);
msAssert(!isset($target->markers['spider_web']), 'Web expires at source owner next turn start.');

// Source death does not cancel scheduled or active web.
$spider = msSpider();
$target = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$state = msState($spider, $target);
msThrowWeb($state, 2);
$spider->hp = 0;
$spider->zone = CardInstance::ZONE_GRAVEYARD;
msActivateWeb($state);
msAssert(isset($target->markers['spider_web']), 'Scheduled web activates even if source died.');
$spider->zone = CardInstance::ZONE_GRAVEYARD;
msAssert(isset($target->markers['spider_web']), 'Source death does not remove active web.');

// Duplicate scheduled/active web does not stack.
$spider = msSpider();
$target = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$state = msState($spider, $target);
msThrowWeb($state, 2);
$spider->closed = false;
msThrowWeb($state, 2);
msAssert(count($state->battle['scheduled_card_markers'] ?? []) === 1, 'Duplicate scheduled web is replaced, not stacked.');
msActivateWeb($state);
$target->markers['spider_web'] = ['source' => GameState::PLAYER_HOST, 'timing' => 'source_next_turn_start', 'value' => 3];
$spider->closed = false;
msStrikeAndConfirm($state, GameState::PLAYER_HOST, 1, 2);
msAssert(!isset($target->markers['spider_web']), 'One attack removes active web regardless of prior value.');

// Air intercept regression.
$spider = msSpider(['instanceId' => 1, 'row' => 3, 'col' => 3]);
$flyer = msCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'zone' => CardInstance::ZONE_FLYING, 'type' => 'fly', 'row' => 5, 'col' => 3]);
$otherTarget = msCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 5, 'col' => 4]);
$state = msState($spider, $flyer, $otherTarget);
$state->battle['active'] = GameState::PLAYER_PLAYER;
$r = (new Engine())->apply($state, GameState::PLAYER_PLAYER, new Command('strike', [
    'card_id' => 2,
    'target_id' => 3,
]));
msAssert(!$r->success && str_contains($r->error ?? '', 'Паука'), 'Air intercept still forces flying attacker to target Mocking Spider.');

echo "Mocking spider tests passed\n";
