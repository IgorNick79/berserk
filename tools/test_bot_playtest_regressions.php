<?php
declare(strict_types=1);

use Berserk\Core\{Autoloader, CardInstance, Command, Engine, GameState, TurnPhaseProcessor, ZoneManager};
use Berserk\Core\Combat\CombatDecisionPlanner;
use Berserk\Core\Legal\LegalActions;
use Berserk\Core\Observation\PlayerObservation;

require __DIR__.'/../src/Core/Autoloader.php';
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__.'/../src/');

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function play(Engine $engine, GameState $state, string $side, string $type, array $payload): void
{
    $result = $engine->apply($state, $side, new Command($type, $payload));
    check($result->success, "$side $type: ".$result->error);
}

function bot(Engine $engine, GameState $state, string $side, string $expected): void
{
    $plan = CombatDecisionPlanner::decide(PlayerObservation::build($state, $side), LegalActions::build($state, $side, $engine->serverNow()));
    $action = $plan['action'] ?? [];
    check(($action['type'] ?? null) === $expected, "Expected $expected, got ".json_encode($plan));
    play($engine, $state, $side, $expected, $action['payload']);
}

$engine = new Engine(clock: static fn(): int => 100);
$state = new GameState(-533, 1, 2);
$state->status = 'battle';
$state->persistenceVersion = 1;
$state->battle = ['active'=>'host', 'turn'=>4, 'hidden_row_revealed'=>true];
$state->addCard(new CardInstance(1, 'borg', 'host', 'field', row:3, col:2, hp:8, hpMax:8,
    prop:['actions'=>[['key'=>'special', 'type'=>'tap', 'value'=>2]]]));
$state->addCard(new CardInstance(2, 'target', 'player', 'field', row:4, col:2, hp:8, hpMax:8));
play($engine, $state, 'host', 'action', ['card_id'=>1, 'target_id'=>2, 'action_key'=>'special']);
check(($state->battle['strike']['kind'] ?? null) === 'tap', 'Native special strike publishes tap results');
foreach (['uchr', 'heal', 'multi_heal', 'poison_target', 'damage_poisoned'] as $kind) {
    $resultState = unserialize(serialize($state));
    $resultState->battle['strike']['kind'] = $kind;
    bot($engine, $resultState, 'host', 'confirm_strike');
    bot($engine, $resultState, 'player', 'confirm_strike');
    check(empty($resultState->battle['strike']), "$kind result completes");
}
bot($engine, $state, 'host', 'confirm_strike');
bot($engine, $state, 'player', 'confirm_strike');
check(empty($state->battle['strike']), 'Both ACKs complete combat with a fixed clock');

$state->battle['pending_coin_ability'] = [
    'kind' => 'gain', 'owner' => 'host', 'id' => 'coin-offer', 'card_id' => 1, 'value' => 1,
];
bot($engine, $state, 'host', 'choose_coin_ability');
check($state->getCard(1)->coins === 1 && empty($state->battle['pending_coin_ability']), 'Bot accepts optional coin');

foreach (['host', 'player'] as $owner) {
    $state = new GameState(-533, 1, 2);
    $opponent = $state->getOpponentKey($owner);
    $state->status = 'battle';
    $state->persistenceVersion = 1;
    $state->battle = ['active'=>$opponent, 'turn'=>2, 'hidden_row_revealed'=>true];
    $demon = new CardInstance(1, 'demon', $owner, 'field', row:3, col:3, hp:8, hpMax:8, closed:true,
        prop:['opponent_turn_start'=>[['type'=>'place_gates', 'count'=>3]],
              'opponent_turn_end'=>[['type'=>'greed_teleport', 'damage'=>2, 'self_damage'=>3]]]);
    $state->addCard($demon);
    $state->addCard(new CardInstance(2, 'target', $opponent, 'field', row:4, col:3, hp:8, hpMax:8));
    $phase = new TurnPhaseProcessor($state, $engine);
    for ($turn = 0; $turn < 2; $turn++) {
        $state->battle['active'] = $opponent;
        $phase->beginStartPhase($opponent);
        for ($gate = 0; $gate < 3; $gate++) bot($engine, $state, $owner, 'choose_gate');
        check(count($state->cell_markers) === 3, 'Three gates placed by closed Demon');
        $target = $state->getCard(2);
        [$target->row, $target->col] = array_map('intval', explode('_', array_key_first($state->cell_markers)));
        play($engine, $state, $opponent, 'end_turn', []);
        check(count($state->battle['pending_greed_teleport']['free_gates'] ?? []) === 2,
            'One occupied gate leaves two legal destinations');
        $before = [$demon->row, $demon->col];
        bot($engine, $state, $owner, 'choose_greed_teleport');
        check([$demon->row, $demon->col] !== $before && !$state->cell_markers, 'Teleport moves and consumes gates');
        bot($engine, $state, $owner, 'turn_ack');
        $demon->closed = true;
    }
    $phase->beginStartPhase($opponent);
    for ($gate = 0; $gate < 3; $gate++) bot($engine, $state, $owner, 'choose_gate');
    ZoneManager::addMarker($state, '6_5', ['type'=>'gate', 'source'=>$owner]); // Legacy save.
    ZoneManager::addMarker($state, '6_5', ['type'=>'gate', 'source'=>$opponent]);
    ZoneManager::addMarker($state, '6_5', ['type'=>'bonfire', 'source'=>$owner]);
    $engine->forceDeath($state, $demon, 'test');
    $engine->finalizeDying($state);
    check(array_keys($state->cell_markers) === ['6_5'], 'Death removes linked and legacy gates');
    check(array_column(ZoneManager::markersAt($state, '6_5'), 'type') === ['gate', 'bonfire'], 'Unrelated markers survive');
}

echo "PASS special strike ACK and Greed gate lifecycle\n";
