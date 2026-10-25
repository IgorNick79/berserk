<?php
declare(strict_types=1);
require __DIR__ . '/test_effective_features.php';

use Berserk\Core\{BattleHelper, CardStats, Command, EffectiveFeatures, Engine, GameState,
    InformationalWaitManager, MemoryLoss, PermanentFeatures, Result, TurnProcessor, ZoneManager};

$kingBefore = $checks;
function kpProp(): array {
    return json_decode(file_get_contents(__DIR__ . '/../docs/king-priest.prop.json'), true, 512, JSON_THROW_ON_ERROR);
}
function kpState(): GameState {
    $s = efState(); $s->battle['hidden_row_revealed'] = true;
    $s->getCard(2)->prop = kpProp(); $s->getCard(2)->coins = 3;
    return $s;
}
function kpCommand(GameState $s, string $type, array $payload = [], string $owner = 'host'): Result {
    return (new Engine())->apply($s, $owner, new Command($type, $payload));
}
function kpAction(GameState $s, string $key = 'memory_loss', int $target = 3, int $source = 2): Result {
    return kpCommand($s, 'action', ['card_id' => $source, 'action_key' => $key, 'target_id' => $target]);
}
function kpAck(GameState &$s): void {
    $s = efRoundtrip($s);
    foreach (['host', 'player'] as $owner) {
        $r = kpCommand($s, 'confirm_strike', ['wait_id' => InformationalWaitManager::token($s)], $owner);
        efCheck($r->success, 'normal result ACK ' . $owner . ': ' . $r->error);
    }
    efCheck(empty($s->battle['strike']), 'ACK finalizes result');
}

$s = kpState(); $s->getCard(2)->coins = 1;
$s->getCard(3)->prop = ['unique' => true];
efCheck(isset(BattleHelper::getAttackTargets($s, $s->getCard(2), 'action:memory_loss', 'host')[3]), 'ordinary impact targeting');
$s = efRoundtrip($s); // Browser selection holds IDs; final action is revalidated after reload.
efCheck(kpAction($s)->success, 'one coin activation after selection/reload');
efCheck($s->getCard(2)->coins === 0 && $s->getCard(2)->closed, 'exactly one coin and tap');
efCheck(EffectiveFeatures::suppressed($s->getCard(3)) && !empty($s->getCard(2)->flags[MemoryLoss::USED]), 'link and instance usage installed');
efCheck(!kpAction($s)->success && $s->getCard(2)->coins === 0, 'retry during ACK cannot pay again');
kpAck($s); (new Engine())->openCard($s->getCard(2)); $s->getCard(2)->coins = 3;
efCheck(!kpAction($s)->success && $s->getCard(2)->coins === 3 && !$s->getCard(2)->closed, 'once per battle survives ACK/open/reload');
efCheck(kpAction($s, 'heal')->success, 'used memory does not disable healing');
efCheck(!kpAction($s)->success, 'common closure blocks second ability');

foreach (['no_coins', 'closed', 'web', 'dead', 'dying', 'off_field', 'used', 'suppressed', 'target_dead', 'target_dying', 'target_item', 'target_hand', 'defense', 'missing'] as $case) {
    $s = kpState(); $c = $s->getCard(2); $t = $s->getCard(3);
    switch ($case) {
        case 'no_coins': $c->coins = 0; break;
        case 'closed': $c->closed = true; break;
        case 'web': $c->markers['spider_web'] = true; break;
        case 'dead': $c->hp = 0; break;
        case 'dying': $c->dying = true; break;
        case 'off_field': (new ZoneManager($s))->toHand($c); break;
        case 'used': $c->flags[MemoryLoss::USED] = true; break;
        case 'suppressed': EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $c); break;
        case 'target_dead': $t->hp = 0; break;
        case 'target_dying': $t->dying = true; break;
        case 'target_item': $t->type = 'artifact'; break;
        case 'target_hand': (new ZoneManager($s))->toHand($t); break;
        case 'defense': $t->prop = ['stamina' => true]; break;
    }
    $s = efRoundtrip($s); $c = $s->getCard(2);
    $before = [$c->coins, $c->closed, $c->flags, $s->getCard(3)->flags];
    efCheck(!kpAction($s, 'memory_loss', $case === 'missing' ? 999 : 3)->success, 'reject before payment ' . $case);
    efCheck([$c->coins, $c->closed, $c->flags, $s->getCard(3)->flags] === $before, 'no resources/effect changed ' . $case);
}

// The active action installs exactly the accepted effective-feature projection.
$s = kpState(); $c = $s->getCard(3);
$c->prop = array_fill_keys(PermanentFeatures::KEYS, true) + ['actions' => [['key' => 'heal', 'type' => 'heal', 'value' => 8]],
    'zoa' => true, 'on_death' => [['type' => 'damage', 'value' => 2]], 'ability' => ['value' => 9], 'granted_unique' => true];
$c->modifiers = [['stat' => 'ability_strike', 'value' => 7], ['stat' => 'ova', 'value' => 2]];
$c->flags['granted_action:gift'] = ['key' => 'gift', 'type' => 'heal', 'value' => 3];
$raw = [$c->prop, $c->modifiers]; $base = [$c->hp, $c->hpMax, $c->moveMax, $c->strikeWeak, $c->strikeMedium, $c->strikeStrong];
// Stamina is a real impact protection: the fixture uses the existing ignore rule.
$s->getCard(2)->prop['ignore'] = ['stamina', 'zoa'];
efCheck(kpAction($s)->success, 'activation uses existing defenses/ignore');
foreach (PermanentFeatures::KEYS as $key) efCheck(isset($c->effectiveProp()[$key]), 'active impact preserves ' . $key);
efCheck(count($c->effectiveProp()) === 13 && count($c->effectiveModifiers()) === 1, 'active/passive/protection/granted unique projection');
efCheck([$c->prop, $c->modifiers] === $raw && [$c->hp, $c->hpMax, $c->moveMax, $c->strikeWeak, $c->strikeMedium, $c->strikeStrong] === $base, 'raw data and basic stats retained');

foreach (['toHand', 'toDeck', 'toSquad', 'toSideboard', 'toGraveyard', 'toExile'] as $exit) {
    $s = kpState(); efCheck(kpAction($s)->success, 'activate before ' . $exit); kpAck($s);
    $zone = new ZoneManager($s); $c = $s->getCard(2); $id = $c->instanceId;
    $zone->$exit($c);
    efCheck(!EffectiveFeatures::suppressed($s->getCard(3)), 'actual source exit releases ' . $exit);
    $zone->toField($c, 3, 3); $c->hp = 20; $c->dying = false; $c->coins = 3; (new Engine())->openCard($c);
    $s = efRoundtrip($s);
    efCheck($s->getCard(2)->instanceId === $id && !kpAction($s)->success, 'same instance return retains usage ' . $exit);
}
$s = kpState(); efCheck(kpAction($s)->success, 'target exit fixture'); kpAck($s);
(new ZoneManager($s))->toHand($s->getCard(3));
efCheck(!EffectiveFeatures::suppressed($s->getCard(3)) && !empty($s->getCard(2)->flags[MemoryLoss::USED]), 'target exit releases link but not usage');

$s = kpState(); $s->getCard(1)->prop = kpProp(); $s->getCard(1)->coins = 2;
efCheck(kpAction($s)->success, 'first instance activation'); kpAck($s);
efCheck(kpAction($s, 'memory_loss', 3, 1)->success, 'second instance independent activation'); kpAck($s);
$s->getCard(2)->dying = true;
efCheck(EffectiveFeatures::suppressed($s->getCard(3)), 'dying is not departure');
(new ZoneManager($s))->toHand($s->getCard(2));
efCheck(EffectiveFeatures::suppressed($s->getCard(3)), 'remaining source holds suppression');
(new ZoneManager($s))->toHand($s->getCard(1));
efCheck(!EffectiveFeatures::suppressed($s->getCard(3)), 'last source departure restores');

foreach ([0, 1, 2, 7] as $wounds) {
    $s = kpState(); $s->getCard(2)->coins = 0; $s->getCard(3)->hp -= $wounds;
    $s = efRoundtrip($s);
    efCheck(kpAction($s, 'heal')->success, 'heal without coins, wounds=' . $wounds);
    efCheck($s->getCard(3)->hp === 20 - max(0, $wounds - 2), 'actual heal capped at wounds');
    efCheck($s->getCard(2)->closed && $s->getCard(2)->coins === 0, 'heal taps without cost');
    $hp = $s->getCard(3)->hp;
    efCheck(!kpAction($s, 'heal')->success && $s->getCard(3)->hp === $hp, 'heal retry during ACK');
    kpAck($s); efCheck(!kpAction($s, 'heal')->success, 'heal retry after ACK while closed');
    (new Engine())->openCard($s->getCard(2));
    efCheck(kpAction($s, 'heal')->success, 'heal repeats after normal opening');
}
$s = kpState(); EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $s->getCard(2)); $s = efRoundtrip($s);
efCheck(!kpAction($s, 'heal')->success && !kpAction($s)->success, 'both actions suppressed after selection/reload');
(new ZoneManager($s))->toHand($s->getCard(1));
efCheck(kpAction($s, 'heal')->success, 'restored source can heal');
$s = kpState(); $s->getCard(3)->hp = 15; efCheck(kpAction($s)->success, 'suppressed target healing fixture'); kpAck($s);
(new Engine())->openCard($s->getCard(2));
efCheck(kpAction($s, 'heal')->success && $s->getCard(3)->hp === 17, 'suppressed target can be healed');

// Two complete turn transitions use real phase ACKs and the existing coin rules.
$s = kpState(); efCheck(kpAction($s)->success, 'turn lifecycle activation'); kpAck($s);
foreach (['host', 'player'] as $owner) {
    $r = kpCommand($s, 'end_turn', [], $owner);
    efCheck($r->success, 'end turn ' . $owner . ': ' . $r->error);
    for ($i = 0; $i < 20 && !empty($s->battle['turn_phase']); $i++) {
        $s = efRoundtrip($s);
        $r = kpCommand($s, 'turn_ack', [], $s->battle['active']);
        efCheck($r->success, 'turn phase ACK: ' . $r->error);
    }
    efCheck(empty($s->battle['turn_phase']), 'turn phases completed');
}
efCheck($s->battle['active'] === 'host' && !$s->getCard(2)->closed, 'next own turn opens king');
efCheck($s->getCard(2)->coins === 2 && !empty($s->getCard(2)->flags[MemoryLoss::USED]), 'save_coins and usage survive real turns');
efCheck(EffectiveFeatures::suppressed($s->getCard(3)) && !kpAction($s)->success, 'link/limit survive turns');
efCheck(kpCommand($s, 'move', ['card_id' => 2, 'row' => 3, 'col' => 2])->success, 'normal source movement');
efCheck(EffectiveFeatures::suppressed($s->getCard(3)), 'source movement preserves impact');
efCheck(kpAction($s, 'heal')->success && $s->getCard(2)->coins === 2, 'next turn heal leaves saved coins');

// Existing full-health healing reactions retain their own source and pending.
$s = kpState(); $s->getCard(3)->prop = ['on_heal_instead_open' => ['once_per_turn' => true]];
$s->getCard(3)->closed = true;
efCheck(kpAction($s, 'heal')->success && !$s->getCard(3)->closed, 'existing heal-instead-open reaction');
foreach ([false, true] as $suppressed) {
    $s = kpState(); $s->getCard(3)->prop = ['on_heal_instead_wound' => ['uses_per_turn' => 3]];
    efCheck(kpAction($s, 'heal')->success && isset($s->battle['pending_heal_instead_wound']), 'existing heal reaction pending');
    if ($suppressed) EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $s->getCard(3));
    $s = efRoundtrip($s);
    $r = kpCommand($s, 'choose_heal_instead_wound', ['target_id' => 2], 'player');
    efCheck($r->success && empty($s->battle['pending_heal_instead_wound']), 'reaction continues safely after reload');
    efCheck($s->getCard(2)->hp === ($suppressed ? 20 : 18), 'suppressed reaction cannot execute cached pending');
    efCheck($s->getCard(2)->closed && $s->getCard(2)->coins === 3, 'reaction preserves paid tap without refund or coins');
    efCheck(!kpCommand($s, 'choose_heal_instead_wound', ['target_id' => 2], 'player')->success, 'reaction retry cannot execute twice');
}

foreach (['creature', 'fly'] as $type) {
    $s = kpState(); efCheck(kpAction($s)->success, 'revival fixture activation'); kpAck($s);
    $s->getCard(2)->type = $type;
    (new ZoneManager($s))->toGraveyard($s->getCard(2));
    $s->getCard(1)->prop = ['actions' => [['key' => 'revive', 'type' => 'revive']]];
    efCheck(kpAction($s, 'revive', 0, 1)->success, 'real revival declared ' . $type);
    $s = efRoundtrip($s);
    efCheck(kpCommand($s, 'choose_revive_target', ['target_id' => 2])->success, 'real revival card selected');
    if ($type === 'creature') efCheck(kpCommand($s, 'choose_revive_cell', ['row' => 1, 'col' => 2])->success, 'real revival cell selected');
    $s = efRoundtrip($s);
    efCheck(EffectiveFeatures::onBattlefield($s->getCard(2)) && !empty($s->getCard(2)->flags[MemoryLoss::USED]), 'revival resets retain usage ' . $type);
    (new Engine())->openCard($s->getCard(2)); $s->getCard(2)->coins = 3;
    efCheck(!kpAction($s)->success, 'revival does not renew memory loss ' . $type);

    $s = kpState(); efCheck(kpAction($s)->success, 'incarnation fixture activation'); kpAck($s);
    $s->getCard(2)->prop['incarnation'] = ['turns' => 1, 'open' => true, 'type' => $type];
    (new ZoneManager($s))->toGraveyard($s->getCard(2));
    (new TurnProcessor($s, new Engine()))->processIncarnation('host');
    $s = efRoundtrip($s);
    if ($type === 'creature') efCheck(kpCommand($s, 'choose_incarnation_cell', ['row' => 1, 'col' => 2])->success, 'real incarnation cell selected');
    efCheck(EffectiveFeatures::onBattlefield($s->getCard(2)) && !empty($s->getCard(2)->flags[MemoryLoss::USED]), 'incarnation resets retain usage ' . $type);
    $s->getCard(2)->coins = 3;
    efCheck(!kpAction($s)->success && !EffectiveFeatures::suppressed($s->getCard(3)), 'incarnation neither renews usage nor restores old link');
}

echo 'King-priest: ', $checks - $kingBefore, " new checks passed\n";
