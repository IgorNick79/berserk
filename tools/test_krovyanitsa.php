<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, ActionResolver, BattleHelper, CardInstance, CardStats, Command, Engine, GameState};
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function bloodAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function bloodCard(int $id, int $row, int $col, array $extra = []): CardInstance {
    return new CardInstance(instanceId:$id, ukid:'test_' . $id, owner:$extra['owner'] ?? GameState::PLAYER_PLAYER,
        zone:$extra['zone'] ?? CardInstance::ZONE_FIELD, row:$row, col:$col,
        hp:$extra['hp'] ?? 5, hpMax:5, type:$extra['type'] ?? 'creature', dying:$extra['dying'] ?? false,
        prop:$extra['prop'] ?? [], markers:$extra['markers'] ?? []);
}
$action = ['key'=>'blood_execute', 'name'=>'Добивание', 'type'=>'execute', 'value_from'=>'poisoned_neighbors_plus_one'];
$state = new GameState(1, 101, 202);
$state->status = 'battle';
$state->battle = ['turn'=>1, 'active'=>GameState::PLAYER_HOST, 'hidden_row_revealed'=>true];
$attacker = bloodCard(1, 3, 3, ['owner'=>GameState::PLAYER_HOST, 'prop'=>['actions'=>[$action]]]);
$target = bloodCard(2, 4, 3, ['hp'=>3, 'markers'=>['poison'=>['value'=>7]]]);
$state->addCard($attacker); $state->addCard($target);
bloodAssert(CardStats::getExecuteValue($state,$attacker,$target,$action) === 1, 'Poisoned target itself must not count.');
$poison = ['markers'=>['poison'=>['value'=>3]]];
$state->addCard(bloodCard(3,4,4,$poison));
$state->addCard(bloodCard(4,5,2,$poison + ['owner'=>GameState::PLAYER_HOST]));
$state->addCard(bloodCard(5,6,3,$poison));
$state->addCard(bloodCard(6,3,2,$poison + ['zone'=>CardInstance::ZONE_GRAVEYARD]));
$state->addCard(bloodCard(7,5,4,$poison + ['dying'=>true]));
$state->addCard(bloodCard(8,3,4,$poison + ['hp'=>0]));
$state->addCard(bloodCard(9,3,2,$poison + ['zone'=>CardInstance::ZONE_FLYING, 'type'=>'fly']));
bloodAssert(CardStats::getExecuteValue($state,$attacker,$target,$action) === 3, 'Count living adjacent poisoned creatures of both owners once each.');
$targets = BattleHelper::getAttackTargets($state,$attacker,'action:blood_execute',$attacker->owner);
bloodAssert(isset($targets[2]), 'HP equal to X+1 must be selectable.');
$target->hp = 4;
$result = (new ActionResolver($state,new Engine()))->handle($attacker->owner,new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'blood_execute']));
bloodAssert(!$result->success && !$attacker->closed, 'HP above X+1 must reject without tapping.');
$target->hp = 3; $target->prop['stamina'] = true;
bloodAssert(!isset(BattleHelper::getAttackTargets($state,$attacker,'action:blood_execute',$attacker->owner)[2]), 'Stamina excludes target.');
$target->prop = []; $target->owner = $attacker->owner;
$result = (new ActionResolver($state,new Engine()))->handle($attacker->owner,new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'blood_execute']));
bloodAssert($result->success && $target->dying && $attacker->closed, 'Friendly execution at X+1 must kill target and tap Krovyanitsa.');
echo "Krovyanitsa regression tests passed.\n";
