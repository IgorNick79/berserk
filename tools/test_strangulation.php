<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{ActionResolver, Autoloader, BattleHelper, CardInstance, CardStats, Command, DamageResolver, Engine, GameState, Strangulation, StrikeResolver, ZoneManager};
use Berserk\Core\Choice\{ChoiceRegistry, StrangulationChoice};
use Berserk\Core\Movement\{ForcedMovementResolver, MovementResolver};
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
use Berserk\View\Ui\Badge;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
$checks = 0;
function check(bool $ok, string $why): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($why);
}
function card(int $id, string $owner, int $row, int $col, array $prop = []): CardInstance {
    return new CardInstance(instanceId: $id, ukid: 'test-' . $id, owner: $owner, zone: 'field',
        row: $row, col: $col, hp: 4, hpMax: 10, move: 2, moveMax: 2,
        strikeWeak: 1, strikeMedium: 2, strikeStrong: 3, prop: $prop);
}
function fixture(): GameState {
    $s = new GameState(987612, 101, 202);
    $s->status = 'battle';
    $s->battle = ['turn' => 1, 'active' => 'host', 'strike' => null, 'hidden_row_revealed' => true];
    $prop = ['deadeat' => true, 'zov' => true, 'actions' => [['key' => 'strangle', 'type' => 'strangle', 'name' => 'Удушить', 'tap' => true]]];
    $s->addCard(card(1, 'host', 3, 3, $prop));
    $s->addCard(card(2, 'player', 3, 4, ['actions' => [['key' => 'jump', 'type' => 'jump', 'range' => 3], ['key' => 'heal', 'type' => 'heal', 'self' => true, 'value' => 1]]]));
    $s->addCard(card(3, 'host', 2, 4, $prop));
    $s->addCard(card(4, 'player', 6, 5));
    return $s;
}
function openPick(GameState $s, int $source = 1): array {
    $s->battle['active'] = 'host'; $s->battle['strike'] = null; $s->getCard($source)->closed = false;
    $r = (new ActionResolver($s, new Engine()))->handle('host', new Command('action', ['card_id' => $source, 'action_key' => 'strangle']));
    check($r->success, 'pending opens: ' . ($r->error ?? ''));
    return $s->battle[Strangulation::PENDING];
}
function pick(GameState $s, array $p, int $target = 2): \Berserk\Core\Result {
    return Strangulation::choose($s, new Engine(), 'host', new Command('choose_strangulation', ['choice_id' => $p['id'], 'target_id' => $target]));
}
function marker(GameState $s, int $n): void { $s->getCard(2)->markers['strangulation'] = ['value' => $n]; }
function render(GameState $s, int $selected): array {
    $_GET = ['sel' => $selected]; $_SESSION = [];
    $info = [];
    foreach ($s->cards as $c) $info[$c->ukid] = ['name' => $c->ukid, 'health' => 10, 'element' => 'neutral'];
    return (new BattleScreen(new Template(__DIR__ . '/../templates')))->prepare($s, 'host', 'host', null, $info)['data'];
}
$s = fixture();
check(str_contains(render($s, 1)['panel_html'], 'Удушить'), 'button present');
$p = openPick($s);
check(!$s->getCard(1)->closed, 'opening does not spend action');
check($p['target_ids'] === [2], 'only adjacent enemy is a target');
check(ChoiceRegistry::current($s) instanceof StrangulationChoice, 'registered pending');
$wait = \Berserk\Core\BattleWaitResolver::current($s);
check($wait['kind'] === 'decision' && $wait['required_players'] === ['host'], 'pending is classified for timers and waiting');
$serialized = json_encode($s->toArray(), JSON_THROW_ON_ERROR);
$s = GameState::fromArray(json_decode($serialized, true, 512, JSON_THROW_ON_ERROR));
check($s->battle[Strangulation::PENDING] === $p, 'pending survives JSON reload');
$spec = (new StrangulationChoice())->spec($s, 'host', [], '?first&game=987612', 'host');
check($spec->form['hidden']['choice_id'] === $p['id'] && count($spec->form['items']) === 1, 'form carries token and legal targets');
check(!(new StrangulationChoice())->spec($s, 'player', [], '?second', 'player')->isMine, 'opponent waits');
$html = render($s, 2);
check(str_contains(implode('', array_filter($html, 'is_string')), 'attack-target'), 'pending highlights field target');
check(!$s->getCard(1)->closed && Strangulation::count($s->getCard(2)) === 0, 'render cannot apply marker');
foreach (['elite', 'own', 'far', 'noncreature', 'offfield', 'dead'] as $bad) {
    $t = $s->getCard(2); $original = $t->toArray();
    if ($bad === 'elite') $t->elite = true;
    if ($bad === 'own') $t->owner = 'host';
    if ($bad === 'far') $t->row = 6;
    if ($bad === 'noncreature') $t->type = 'artifact';
    if ($bad === 'offfield') $t->zone = 'graveyard';
    if ($bad === 'dead') $t->dying = true;
    check(!pick($s, $p)->success, 'reject ' . $bad);
    check(!$s->getCard(1)->closed && Strangulation::count($t) === 0, 'invalid target costs nothing: ' . $bad);
    $s->cards[2] = CardInstance::fromArray($original);
}
check(!Strangulation::choose($s, new Engine(), 'player', new Command('choose_strangulation', ['choice_id' => $p['id'], 'target_id' => 2]))->success, 'wrong actor rejected');
check(pick($s, $p)->success, 'first marker applies');
check($s->getCard(1)->closed && Strangulation::count($s->getCard(2)) === 1, 'close and first marker atomic');
check($s->getCard(1)->hp === 4, 'no heal on first marker');
check(!pick($s, $p)->success, 'duplicate confirmation rejected');
check(Strangulation::count($s->getCard(2)) === 1, 'duplicate adds no marker');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
check(Strangulation::count($s->getCard(2)) === 1, 'marker survives reload');
check(str_contains(Badge::forCard($s->getCard(2)), 'Удушение 1'), 'badge displays first count');
$s->battle['strike'] = null;
check(str_contains(render($s, 2)['panel_html'], 'Самостоятельное передвижение запрещено'), 'panel explains movement');
$s->battle['strike'] = null; $s->battle['active'] = 'player';
$m = new MovementResolver($s, new Engine());
foreach (['move', 'jump'] as $move) {
    check(!$m->$move('player', new Command($move, ['card_id' => 2, 'row' => 4, 'col' => 4]))->success, 'server blocks voluntary ' . $move);
}
check(!BattleHelper::getMoveCells($s, $s->getCard(2)), 'UI move destinations empty');
check(!BattleHelper::getJumpCells($s, $s->getCard(2)), 'UI jump destinations empty');
check(!CardStats::hasCannotAttack($s->getCard(2)), 'one marker permits attacks');
check((new StrikeResolver($s, new Engine()))->declare('player', new Command('strike', ['card_id' => 2, 'target_id' => 1]))->success, 'one marker permits direct strike');
$s->battle['strike'] = null;
$p = openPick($s, 3); check(pick($s, $p)->success, 'second strangler applies');
check(Strangulation::count($s->getCard(2)) === 2, 'different sources share count');
check($s->getCard(1)->hp === 4 && $s->getCard(3)->hp === 4, 'no heal on second marker');
$s->battle['strike'] = null; $s->battle['active'] = 'player';
check(!(new StrikeResolver($s, new Engine()))->declare('player', new Command('strike', ['card_id' => 2, 'target_id' => 1]))->success, 'two markers block direct strike');
foreach (['shot', 'throw', 'discharge', 'magic', 'cast', 'tap', 'uchr', 'execute', 'multi_discharge', 'blood_tap', 'self_wound_strike', 'attack_series', 'damage_ranged', 'row_spell'] as $type) {
    $s->getCard(2)->prop['actions'] = [['key' => $type, 'type' => $type, 'range' => 5, 'value' => 1]];
    check(!(new ActionResolver($s, new Engine()))->handle('player', new Command('action', ['card_id' => 2, 'action_key' => $type, 'target_id' => 1]))->success, 'server rejects attack ' . $type);
    check(!BattleHelper::getAttackTargets($s, $s->getCard(2), 'action:' . $type, 'player'), 'UI rejects attack ' . $type);
}
$s->getCard(2)->prop['actions'] = [['key' => 'heal', 'type' => 'heal', 'self' => true, 'value' => 1]];
check((new ActionResolver($s, new Engine()))->handle('player', new Command('action', ['card_id' => 2, 'action_key' => 'heal', 'target_id' => 2]))->success, 'two markers permit nonattacking heal');
$s->getCard(1)->prop['deadeat_hp_bonus'] = 2;
$s->getCard(2)->prop['on_death'] = [['type' => 'damage', 'target' => 'near', 'value' => 1]];
$s->getCard(2)->prop['valhalla'] = [['type' => 'heal', 'target' => 'self', 'value' => 1]];
$p = openPick($s, 1);
check(pick($s, $p)->success, 'third marker completes');
check($s->getCard(2)->zone === 'graveyard', 'third marker uses death lifecycle');
check($s->getCard(1)->hp === 12 && $s->getCard(1)->hpMax === 12, 'existing deadeat heals full and adds bonus');
check($s->getCard(3)->hp === 3, 'other strangler only takes death damage and is not healed');
check(!empty($s->getCard(2)->flags['valhalla_active']), 'specific melee death triggers Valhalla');
check($s->battle['strike']['destruction'] === ['source_id' => 1, 'target_id' => 2, 'cause' => 'strangulation'], 'destruction records exact source');
check(count($s->battle['strike']['deadeat']) === 1 && $s->battle['strike']['deadeat'][0]['heal'] === 9, 'one existing deadeat event after death effect');
check(empty($s->battle['strike']['deadeat_queue']) && empty($s->battle['strike']['attack_dice']), 'no pending heal or dice');
check(!pick($s, $p)->success, 'third confirmation cannot repeat');
(new Engine())->flushDeadeatQueue($s);
check($s->getCard(1)->hpMax === 12, 'no double bonus or heal');
// Cancellation, stale identity, newly opened pending, and stale source are independent checks.
$s = fixture(); $p = openPick($s);
check(Strangulation::choose($s, new Engine(), 'host', new Command('cancel_pending', ['choice_id' => $p['id']]))->success, 'cancel succeeds');
check(!$s->getCard(1)->closed && Strangulation::count($s->getCard(2)) === 0, 'cancel costs no action or marker');
$new = openPick($s); check(!pick($s, $p)->success, 'old token cannot affect new pending');
$s->getCard(1)->closed = true; check(!pick($s, $new)->success, 'stale closed source rejected');
// Position/zone/death hooks use the current owners and any neighbouring strangler.
$s = fixture(); marker($s, 2); $s->getCard(2)->markers['poison'] = ['value' => 1];
$zone = new ZoneManager($s); $zone->toField($s->getCard(1), 1, 1);
check(Strangulation::count($s->getCard(2)) === 2, 'one of two leaves, markers persist');
$zone->toField($s->getCard(3), 1, 2);
check(Strangulation::count($s->getCard(2)) === 0, 'last strangler leaves, all markers clear');
check($s->getCard(2)->markers['poison']['value'] === 1, 'other markers unaffected');
check(!CardStats::hasCannotAttack($s->getCard(2)) && BattleHelper::getMoveCells($s, $s->getCard(2)) !== [], 'movement and attacks return');
foreach (['death', 'hand', 'flying', 'remove'] as $exit) {
    $s = fixture(); unset($s->cards[3]); marker($s, 2);
    if ($exit === 'death') (new DamageResolver($s))->forceDeath($s->getCard(1), 'destroy');
    if ($exit === 'hand') (new ZoneManager($s))->toHand($s->getCard(1));
    if ($exit === 'flying') (new ZoneManager($s))->toFlying($s->getCard(1));
    if ($exit === 'remove') $s->removeCard(1);
    check(Strangulation::count($s->getCard(2)) === 0, 'source ' . $exit . ' clears markers');
}
$s = fixture(); marker($s, 2);
check((new ForcedMovementResolver($s, new Engine()))->move($s->getCard(2), 1, 1)->success, 'forced movement allowed');
check(Strangulation::count($s->getCard(2)) === 0, 'forced move out of adjacency clears');
$s = fixture(); marker($s, 2);
(new ZoneManager($s))->toField($s->getCard(2), 2, 5);
check(Strangulation::count($s->getCard(2)) === 2, 'external move near other strangler retains');
$s->getCard(3)->owner = 'player'; Strangulation::refresh($s);
check(Strangulation::count($s->getCard(2)) === 0, 'refresh uses current controller');
// Teleport command must permit another card but reject the victim moving itself.
foreach ([true, false] as $self) {
    $s = fixture(); marker($s, 1); $s->battle['active'] = 'player';
    $caster = $self ? $s->getCard(2) : $s->getCard(4);
    $caster->prop['actions'] = [['key' => 'teleport', 'type' => 'teleport_target']];
    $r = (new ActionResolver($s, new Engine()))->handle('player', new Command('action', ['card_id' => $caster->instanceId, 'action_key' => 'teleport', 'target_id' => 2]));
    check($r->success !== $self, 'self/external teleport start distinction');
    if (!$self) {
        $r = (new ActionResolver($s, new Engine()))->chooseTeleportTargetCell('player', new Command('choose_teleport_cell', ['row' => 6, 'col' => 1]));
        check($r->success && Strangulation::count($s->getCard(2)) === 0, 'external teleport frees victim');
    }
}
// Ordinary deadeat continues to use its original cause and calculation.
$s = fixture(); (new DamageResolver($s))->forceDeath($s->getCard(2), 'strike', $s->getCard(1));
check($s->getCard(1)->hp === 10, 'ordinary melee deadeat unchanged');
// Full Engine routing, identity validation, reload and serialized result acknowledgement.
$s = fixture(); $e = new Engine();
check($e->apply($s, 'host', new Command('action', ['card_id' => 1, 'action_key' => 'strangle']))->success, 'Engine starts choice');
$p = $s->battle[Strangulation::PENDING];
check($e->apply($s, 'host', new Command('choose_strangulation', ['choice_id' => $p['id'], 'target_id' => 2]))->success, 'Engine applies choice');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
check(str_contains(implode('', array_filter(render($s, 2), 'is_string')), 'Удушить'), 'ability result renders after reload');
$s->getCard(1)->prop['auto'] = [['type' => 'shot', 'value' => 2]];
$sr = new StrikeResolver($s, $e);
check($sr->confirmStrike('host', new Command('confirm_strike'))->success, 'first ability result acknowledgement');
check($sr->confirmStrike('player', new Command('confirm_strike'))->success, 'second ability result acknowledgement');
check(empty($s->battle['strike']) && empty($s->battle['turn_instant_stack']), 'ack completes without ordinary strike autos or instants');
$s->battle['active'] = 'host';
check(!pick($s, $p)->success, 'replay after acknowledgement cannot apply');
$s = fixture(); marker($s, 1);
check(!(new MovementResolver($s, new Engine()))->triggeredJumpCells($s->getCard(2), 3), 'own defensive jump also blocked');
$s = fixture(); marker($s, 2); $s->getCard(1)->prop['actions'][] = ['key' => 'jump', 'type' => 'jump', 'range' => 3];
unset($s->cards[3]);
check((new MovementResolver($s, new Engine()))->jump('host', new Command('jump', ['card_id' => 1, 'row' => 1, 'col' => 3]))->success, 'strangler can jump away');
check(Strangulation::count($s->getCard(2)) === 0, 'source jump clears markers immediately');
$s = fixture(); marker($s, 2); unset($s->cards[3]);
check((new MovementResolver($s, new Engine()))->move('host', new Command('move', ['card_id' => 1, 'row' => 3, 'col' => 2]))->success, 'strangler can walk away');
check(Strangulation::count($s->getCard(2)) === 0, 'source walk clears markers immediately');
$s = fixture(); marker($s, 2);
$s->getCard(1)->prop['deadeat_hp_bonus'] = 2;
$s->getCard(2)->prop['on_death'] = [['type' => 'damage', 'target' => 'near', 'value' => 10]];
$p = openPick($s); check(pick($s, $p)->success, 'lethal death reaction resolves');
check($s->getCard(1)->hp === 0 && $s->getCard(1)->hpMax === 10 && empty($s->battle['strike']['deadeat']), 'dead strangler is not healed or resurrected');
$s = fixture(); marker($s, 2); $p = openPick($s); $s->getCard(2)->zone = 'graveyard';
check(!pick($s, $p)->success && $s->getCard(1)->hp === 4, 'no actual destruction means no deadeat');
$s = fixture(); marker($s, 2); $p = openPick($s);
$s->getCard(2)->prop['on_death'] = [['type' => 'damage', 'target' => 'choice', 'value' => 1]];
check(pick($s, $p)->success && !empty($s->battle['strike']['pending_choice']), 'third marker retains mandatory death choice');
check(!(new StrikeResolver($s, new Engine()))->continueAfterStrikeResultAck($s->battle['strike'])->success, 'ack cannot discard unresolved death choice');
$prop = json_decode(file_get_contents(__DIR__ . '/../docs/strangulation-prop.json'), true, 512, JSON_THROW_ON_ERROR);
check($prop['deadeat'] === true && $prop['zov'] === true && $prop['actions'][0]['type'] === 'strangle', 'delivery prop is valid and enables mechanism');
$s = fixture(); marker($s, 2); $s->battle['active'] = 'player';
$s->getCard(2)->prop['forced_strike'] = true; $s->getCard(1)->closed = true;
check(CardStats::getForcedStrikeTarget($s, $s->getCard(2)) === null, 'blocked mandatory attack does not lock the turn');
check((new ActionResolver($s, new Engine()))->handle('player', new Command('action', ['card_id' => 2, 'action_key' => 'heal', 'target_id' => 2]))->success, 'nonattack remains available despite mandatory attack property');
echo "Strangulation: $checks checks passed.\n";
