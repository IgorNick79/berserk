<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\{Autoloader, CardInstance, CardStats, Command, DamageResolver, EffectiveFeatures,
    Engine, GameState, PermanentFeatures, ZoneManager, BattleHelper, ActionResolver,
    DegascaProcessor, FirstStrikeJump, RangedNeighborTeleport, Strangulation, InstantProcessor};

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
set_error_handler(static function(int $n, string $m, string $f, int $l): bool {
    if (error_reporting() & $n) throw new ErrorException($m, 0, $n, $f, $l);
    return false;
});
$checks = 0;
function efCheck(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function efState(): GameState {
    $s = new GameState(-22001, 101, 202);
    $s->status = 'battle';
    $s->battle = ['active' => 'host', 'turn' => 1, 'strike' => null];
    foreach ([1 => ['host', 2, 2], 2 => ['host', 3, 3], 3 => ['player', 4, 3], 4 => ['player', 6, 5]] as $id => [$owner, $r, $c]) {
        $s->addCard(new CardInstance(instanceId: $id, ukid: 'ef_' . $id, owner: $owner, zone: 'field',
            row: $r, col: $c, hp: 20, hpMax: 20, move: 1, moveMax: 1,
            strikeWeak: 1, strikeMedium: 2, strikeStrong: 3));
    }
    return $s;
}
function efRoundtrip(GameState $s): GameState {
    return GameState::fromArray(json_decode(json_encode($s->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
}

// Classification depends on keys, never on value shape or activation conditions.
foreach ([true, 2, ['value' => 2, 'line' => true, 'condition' => 'unrelated']] as $value) {
    $s = efState(); $c = $s->getCard(2);
    $c->prop = array_fill_keys(PermanentFeatures::KEYS, $value) + ['zoa' => $value, 'zoan' => $value,
        'zoda' => $value, 'actions' => [['type' => 'heal', 'value' => 2]], 'custom' => $value];
    $raw = $c->prop;
    efCheck(EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $c), 'apply infrastructure effect');
    foreach (PermanentFeatures::KEYS as $key) efCheck(($c->effectiveProp()[$key] ?? null) === $value, 'permanent: ' . $key);
    efCheck(count($c->effectiveProp()) === 13, 'exactly thirteen keys survive');
    efCheck($c->prop === $raw, 'raw properties unchanged');
}

$s = efState(); $c = $s->getCard(2); $source = $s->getCard(1);
$c->prop = ['zoa' => true, 'zom' => true, 'ability' => ['value' => 5], 'regeneration' => 2,
    'actions' => [['key' => 'heal', 'type' => 'heal', 'value' => 2, 'target' => 'ally']]];
$c->modifiers = [['stat' => 'ova', 'value' => 3], ['stat' => 'ability_strike', 'value' => 7],
    ['stat' => 'zoan', 'value' => 1], ['stat' => 'ovs', 'value' => 2], ['stat' => 'stamina', 'value' => 1]];
$c->flags['granted_action:gift'] = ['key' => 'gift', 'type' => 'heal', 'value' => 2];
$rawModifiers = $c->modifiers;
efCheck(CardStats::getAbilityBonus($s, $c, $s->getCard(3), 'strike') === 12, 'baseline prop and modifier bonuses');
EffectiveFeatures::applyMemoryLoss($s, $source, $c);
efCheck(CardStats::getAbilityBonus($s, $c, $s->getCard(3), 'strike') === 0, 'unique combat bonuses disabled');
efCheck(CardStats::getOva($s, $c) === 3 && CardStats::getStat($c, 'ovs') === 2, 'permanent modifiers preserved');
efCheck(CardStats::hasDefense($s, $c, 'magic', $s->getCard(3)), 'permanent magical protection');
efCheck(!CardStats::hasDefense($s, $c, 'strike', $s->getCard(3)), 'unique protections disabled');
efCheck(CardStats::hasStamina($s, $c), 'stamina modifier preserved');
efCheck($c->modifiers === $rawModifiers, 'raw modifiers preserved');
$r = (new ActionResolver($s, new Engine()))->handle('host', new Command('action', ['card_id' => 2, 'target_id' => 1, 'action_key' => 'heal']));
efCheck(!$r->success && !$c->closed, 'unique action rejected without closing');
$r = (new ActionResolver($s, new Engine()))->handle('host', new Command('action', ['card_id' => 2, 'target_id' => 1, 'action_key' => 'gift']));
efCheck(!$r->success, 'flag-granted action rejected');
efCheck(BattleHelper::getAttackTargets($s, $c, 'action:gift', 'host') === [], 'granted action targeting disabled');
$c->prop['new_unique'] = true; // Same storage path as grant_prop.
efCheck(!isset($c->effectiveProp()['new_unique']), 'property granted during suppression remains inactive');
$c->modifiers[] = ['stat' => 'ability_strike', 'value' => 99];
efCheck(CardStats::getAbilityBonus($s, $c, $s->getCard(3), 'strike') === 0, 'new modifier remains inactive');
$c->hp = 10;
(new Engine())->applyRegeneration($c);
efCheck($c->hp === 12, 'permanent regeneration executes');
(new Engine())->applyInstantEffect($s, ['type' => 'heal', 'value' => 5], $c, $c, 'host');
efCheck($c->hp === 12, 'cached unique instant cannot execute');
(new Engine())->applyStrikeEffects($c, $s->getCard(3), 'strong', [['poison' => 4]]);
efCheck(empty($s->getCard(3)->markers['poison']), 'cached strike effect cannot execute');

// Dying is not departure. Movement, closing and a field/flying transition keep the link.
$source->row = 1; $source->closed = true;
(new ZoneManager($s))->toFlying($source);
efCheck(EffectiveFeatures::suppressed($c), 'movement/closing/flying keep effect');
$s->battle['strike'] = ['state' => 'results', 'attacker_id' => 1, 'target_id' => 3];
(new DamageResolver($s))->forceDeath($source, 'strike', $s->getCard(3));
efCheck($source->dying && EffectiveFeatures::suppressed($c), 'effect remains during source dying window');
$s = efRoundtrip($s); $c = $s->getCard(2);
efCheck(EffectiveFeatures::suppressed($c), 'dying link survives JSON reload');
(new ZoneManager($s))->toGraveyard($s->getCard(1));
efCheck(!EffectiveFeatures::suppressed($c) && isset($c->effectiveProp()['new_unique']), 'actual departure restores properties');
efCheck(CardStats::getAbilityBonus($s, $c, $s->getCard(3), 'strike') === 111, 'existing and new modifiers restored');

// Multiple independent sources, target exit, source return, new target instance.
foreach (['toHand', 'toDeck', 'toSquad', 'toSideboard', 'toGraveyard', 'toExile'] as $exit) {
    $s = efState(); $c = $s->getCard(2); $c->prop = ['unique_test' => true];
    EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $c);
    EffectiveFeatures::applyMemoryLoss($s, $s->getCard(3), $c);
    $s = efRoundtrip($s); $zone = new ZoneManager($s); $c = $s->getCard(2);
    $zone->$exit($s->getCard(1));
    efCheck(EffectiveFeatures::suppressed($c), 'second source survives ' . $exit);
    $zone->toField($s->getCard(1), 1, 1);
    $zone->$exit($s->getCard(3));
    efCheck(!EffectiveFeatures::suppressed($c), 'returning source does not reattach ' . $exit);
    EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $c);
    $zone->$exit($c);
    $zone->toField($c, 3, 3);
    efCheck(!EffectiveFeatures::suppressed($c), 'target return cleans link ' . $exit);
}

// Unique death reaction is tested before the actual target zone transition.
$s = efState(); $c = $s->getCard(2);
$c->prop = ['on_death' => [['type' => 'damage', 'value' => 4, 'target' => 'enemy']]];
EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $c);
$s->battle['strike'] = ['state' => 'results', 'attacker_id' => 3, 'target_id' => 2];
(new DamageResolver($s))->forceDeath($c, 'strike', $s->getCard(3));
efCheck($c->dying && empty($s->battle['strike']['pending_choice']), 'on_death disabled while target still on battlefield');
(new ZoneManager($s))->toGraveyard($c);
efCheck(!isset($c->flags[EffectiveFeatures::MEMORY_LOSS]), 'target departure removes effect');

// Previously created choice resolves without its effect, preserving the battle frame.
$s = efState(); $c = $s->getCard(2); $c->hp = 8;
$s->battle['pending_kobold_heal'] = ['owner' => 'host', 'card_id' => 2, 'value' => 5];
$s->battle['strike'] = ['state' => 'results', 'attacker_id' => 2, 'target_id' => 3, 'confirmed' => []];
EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $c);
$s = efRoundtrip($s); $before = $s->battle['strike'];
$r = (new ActionResolver($s, new Engine()))->chooseKoboldHeal('host', new Command('choose_kobold_heal'));
efCheck($r->success && $s->getCard(2)->hp === 8, 'cached pending cannot heal');
efCheck(!isset($s->battle['pending_kobold_heal']) && $s->battle['strike'] === $before, 'technical ACK frame preserved');

// Specialized gameplay predicates and delayed jump path.
$s = efState(); $c = $s->getCard(2);
$c->prop = ['actions' => [['key' => 'strangle', 'type' => 'strangle']],
    'strike_effects' => [['type' => 'degasca_sequence', 'levels' => ['strong']]],
    'first_strike_jump' => ['range' => 2]];
efCheck(Strangulation::isStrangler($c) && DegascaProcessor::isDegasca($c), 'specialized baseline');
EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $c);
efCheck(!Strangulation::isStrangler($c) && !DegascaProcessor::isDegasca($c), 'specialized traits suppressed');
efCheck(!(new FirstStrikeJump($s, new Engine()))->openAfterCombat($c, ['range' => 2]), 'cached first strike jump suppressed');

echo "Effective features: {$checks} checks passed\n";
