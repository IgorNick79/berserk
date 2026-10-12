<?php
// tools/test_ragnar.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\DamageResolver;
use Berserk\Core\Dice;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;
use Berserk\Core\ValhallaProcessor;
use Berserk\Core\ZoneManager;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function rgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rgRagnarProp(): array
{
    return [
        'zot' => true,
        'ova' => ['value' => 1],
        'ragnar_valhalla_double' => true,
        'ragnar_first_strike_unanswer' => true,
        'valhalla' => [[
            'type' => 'ragnar_first_strike_unanswer',
        ]],
    ];
}

function rgDamageValhallaProp(int $value = 1): array
{
    return [
        'valhalla' => [[
            'type' => 'damage',
            'value' => $value,
            'target' => 'own_creature',
        ]],
    ];
}

function rgModifierValhallaProp(int $value = 1): array
{
    return [
        'valhalla' => [[
            'type' => 'modifier',
            'stat' => 'ability_strike',
            'value' => $value,
            'expire' => 'end_of_turn',
            'target' => 'own_creature',
        ]],
    ];
}

function rgState(CardInstance ...$cards): GameState
{
    $state = new GameState(6301, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
        'hidden_row_revealed' => true,
    ];
    foreach ($cards as $card) {
        $state->addCard($card);
    }
    return $state;
}

function rgCard(array $o = []): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'] ?? 1,
        ukid: $o['ukid'] ?? ('card_' . ($o['instanceId'] ?? 1)),
        owner: $o['owner'] ?? GameState::PLAYER_HOST,
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'] ?? 3,
        col: $o['col'] ?? 3,
        hp: $o['hp'] ?? 8,
        hpMax: $o['hpMax'] ?? ($o['hp'] ?? 8),
        price: $o['price'] ?? 5,
        elite: $o['elite'] ?? false,
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        element: $o['element'] ?? 'mountains',
        classes: $o['classes'] ?? [],
        move: $o['move'] ?? 1,
        moveMax: $o['moveMax'] ?? 1,
        armor: $o['armor'] ?? 0,
        armorMax: $o['armorMax'] ?? 0,
        strikeWeak: $o['strikeWeak'] ?? 1,
        strikeMedium: $o['strikeMedium'] ?? 2,
        strikeStrong: $o['strikeStrong'] ?? 3,
        prop: $o['prop'] ?? [],
        modifiers: $o['modifiers'] ?? [],
        markers: $o['markers'] ?? [],
        flags: $o['flags'] ?? [],
        single: $o['single'] ?? false,
    );
}

function rgRagnar(array $o = []): CardInstance
{
    return rgCard(array_replace([
        'instanceId' => 63,
        'ukid' => 's1_63',
        'hp' => 10,
        'hpMax' => 10,
        'strikeWeak' => 2,
        'strikeMedium' => 3,
        'strikeStrong' => 5,
        'prop' => rgRagnarProp(),
        'single' => true,
        'classes' => ['Йордлинг'],
    ], $o));
}

function rgActiveValhalla(CardInstance $card): CardInstance
{
    $card->zone = CardInstance::ZONE_GRAVEYARD;
    $card->row = null;
    $card->col = null;
    $card->hp = 0;
    $card->closed = true;
    $card->flags['valhalla_active'] = true;
    $card->markers['valhalla'] = ['value' => 1];
    return $card;
}

function rgResolveValhalla(GameState $state, int $cardId, int ...$targetIds): void
{
    $card = $state->getCard($cardId);
    rgAssert($card !== null, 'Valhalla card should exist.');
    $vp = new ValhallaProcessor($state, new Engine());
    $pending = $vp->beginExecute($card, $card->owner);
    $lastTargetId = $targetIds[0] ?? 0;
    $pickIndex = 0;
    while ($pending || !empty($state->battle['pending_valhalla_pick'])) {
        $targetId = $targetIds[$pickIndex] ?? $lastTargetId;
        if (!isset($targetIds[$pickIndex]) && !in_array($targetId, $vp->collectCandidates($card, $card->owner), true)) {
            $targetId = $vp->collectCandidates($card, $card->owner)[0] ?? 0;
        }
        $lastTargetId = $targetId;
        $result = $vp->chooseTarget($card->owner, new Command('valhalla_pick', ['target_id' => $targetId]));
        rgAssert($result->success, $result->error ?? 'Valhalla target choice should resolve.');
        $pending = !empty($state->battle['pending_valhalla_pick']);
        $pickIndex++;
        rgAssert($pickIndex <= 3, 'Valhalla pick loop should finish.');
    }
}

function rgRolls(string $rolls): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['debug_roll'] = $rolls;
    Dice::init();
}

// 1. Рагнар на поле: первая Вальхалла без выбора цели разрешается дважды.
$fieldRagnar = rgRagnar(['instanceId' => 63, 'row' => 3, 'col' => 2]);
$graveRagnar = rgActiveValhalla(rgRagnar(['instanceId' => 163]));
$state = rgState($fieldRagnar, $graveRagnar);
rgResolveValhalla($state, 163);
rgAssert(($graveRagnar->flags['ragnar_valhalla_resolved_this_turn'] ?? 0) === 2, 'First no-target Valhalla should resolve twice with field Ragnar.');

// 2. Первая Вальхалла с целью: два последовательных выбора могут попасть в разные цели.
$fieldRagnar = rgRagnar(['instanceId' => 63, 'row' => 3, 'col' => 2]);
$deadShaman = rgActiveValhalla(rgCard(['instanceId' => 42, 'zone' => CardInstance::ZONE_GRAVEYARD, 'prop' => rgDamageValhallaProp(1)]));
$target = rgCard(['instanceId' => 10, 'row' => 3, 'col' => 3, 'hp' => 5, 'hpMax' => 5]);
$targetAlt = rgCard(['instanceId' => 110, 'row' => 3, 'col' => 4, 'hp' => 5, 'hpMax' => 5]);
$state = rgState($fieldRagnar, $deadShaman, $target, $targetAlt);
$vp = new ValhallaProcessor($state, new Engine());
rgAssert($vp->beginExecute($deadShaman, GameState::PLAYER_HOST), 'Targeted first Valhalla should request the first target.');
$result = $vp->chooseTarget(GameState::PLAYER_HOST, new Command('valhalla_pick', ['target_id' => 10]));
rgAssert($result->success, $result->error ?? 'First Valhalla target choice should resolve.');
rgAssert(!empty($state->battle['pending_valhalla_pick']), 'Doubled targeted Valhalla should request the second target.');
$result = $vp->chooseTarget(GameState::PLAYER_HOST, new Command('valhalla_pick', ['target_id' => 110]));
rgAssert($result->success, $result->error ?? 'Second Valhalla target choice should resolve.');
rgAssert(empty($state->battle['pending_valhalla_pick']), 'Doubled targeted Valhalla should finish after the second target.');
rgAssert($target->hp === 4 && $targetAlt->hp === 4, 'Targeted first Valhalla should allow different targets.');

// 3. Вторая Вальхалла в том же ходу не удваивается.
$deadSecond = rgActiveValhalla(rgCard(['instanceId' => 43, 'zone' => CardInstance::ZONE_GRAVEYARD, 'prop' => rgDamageValhallaProp(1)]));
$state->addCard($deadSecond);
rgResolveValhalla($state, 43, 10);
rgAssert($target->hp === 3 && $targetAlt->hp === 4, 'Second Valhalla in the same turn should resolve once.');

// 4. На следующем ходе первая Вальхалла снова удваивается.
(new TurnProcessor($state, new Engine()))->continueStartTurn(GameState::PLAYER_HOST);
$deadThird = rgActiveValhalla(rgCard(['instanceId' => 44, 'zone' => CardInstance::ZONE_GRAVEYARD, 'prop' => rgDamageValhallaProp(1)]));
$state->addCard($deadThird);
rgResolveValhalla($state, 44, 10);
rgAssert($target->hp === 1, 'Next turn first Valhalla should double again.');

// 5-7. No field Ragnar / graveyard Ragnar / two field Ragnars.
$dead = rgActiveValhalla(rgCard(['instanceId' => 45, 'zone' => CardInstance::ZONE_GRAVEYARD, 'prop' => rgDamageValhallaProp(1)]));
$target = rgCard(['instanceId' => 11, 'hp' => 5, 'hpMax' => 5]);
$state = rgState($dead, $target);
rgResolveValhalla($state, 45, 11);
rgAssert($target->hp === 4, 'Without field Ragnar, Valhalla should resolve once.');

$graveRagnarOnly = rgActiveValhalla(rgRagnar(['instanceId' => 263]));
$dead = rgActiveValhalla(rgCard(['instanceId' => 46, 'zone' => CardInstance::ZONE_GRAVEYARD, 'prop' => rgDamageValhallaProp(1)]));
$target = rgCard(['instanceId' => 12, 'hp' => 5, 'hpMax' => 5]);
$state = rgState($graveRagnarOnly, $dead, $target);
rgResolveValhalla($state, 46, 12);
rgAssert($target->hp === 4, 'Ragnar in graveyard should not double another Valhalla.');

$ragnarA = rgRagnar(['instanceId' => 63, 'row' => 3, 'col' => 2]);
$ragnarB = rgRagnar(['instanceId' => 64, 'row' => 3, 'col' => 4]);
$dead = rgActiveValhalla(rgCard(['instanceId' => 47, 'zone' => CardInstance::ZONE_GRAVEYARD, 'prop' => rgDamageValhallaProp(1)]));
$target = rgCard(['instanceId' => 13, 'hp' => 5, 'hpMax' => 5]);
$state = rgState($ragnarA, $ragnarB, $dead, $target);
rgResolveValhalla($state, 47, 13);
rgAssert($target->hp === 3, 'Two field Ragnars should still make Valhalla resolve exactly twice.');

// 8. Повтор команды после разрешения не применяет эффект повторно.
$repeat = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('valhalla_pick', ['target_id' => 13]));
rgAssert(!$repeat->success, 'Repeated Valhalla pick without pending should fail.');
rgAssert($target->hp === 3, 'Repeated Valhalla command should not apply damage again.');

// 9. Цель, погибшая от первого применения, не получает второе применение; второй выбор уходит в другую валидную цель.
$fieldRagnar = rgRagnar(['instanceId' => 63, 'row' => 3, 'col' => 2]);
$dead = rgActiveValhalla(rgCard(['instanceId' => 48, 'zone' => CardInstance::ZONE_GRAVEYARD, 'prop' => rgDamageValhallaProp(1)]));
$target = rgCard(['instanceId' => 14, 'hp' => 1, 'hpMax' => 1]);
$state = rgState($fieldRagnar, $dead, $target);
rgResolveValhalla($state, 48, 14, 63);
rgAssert($target->hp === 0 && $target->zone === CardInstance::ZONE_GRAVEYARD, 'Target killed by first Valhalla application should follow normal death lifecycle.');
rgAssert($fieldRagnar->hp === 9, 'Second doubled Valhalla choice should apply to another valid target.');

// 10. Вальхалла с модификатором применяет тот же выбранный target дважды.
$fieldRagnar = rgRagnar(['instanceId' => 63, 'row' => 3, 'col' => 2]);
$dead = rgActiveValhalla(rgCard(['instanceId' => 49, 'zone' => CardInstance::ZONE_GRAVEYARD, 'prop' => rgModifierValhallaProp(1)]));
$target = rgCard(['instanceId' => 15, 'hp' => 5, 'hpMax' => 5]);
$state = rgState($fieldRagnar, $dead, $target);
rgResolveValhalla($state, 49, 15);
$mods = array_values(array_filter($target->modifiers, static fn(array $m): bool => ($m['stat'] ?? '') === 'ability_strike'));
rgAssert(count($mods) === 2, 'Targeted modifier Valhalla should add two modifiers to the same target.');

// 11-15. Активная Вальхалла Рагнара делает первый обычный удар безответным, второй обычный — нет, следующий ход — снова да.
$graveRagnar = rgActiveValhalla(rgRagnar(['instanceId' => 163]));
$attackerA = rgCard(['instanceId' => 1, 'row' => 3, 'col' => 3, 'strikeWeak' => 1, 'strikeMedium' => 2, 'strikeStrong' => 3]);
$attackerB = rgCard(['instanceId' => 2, 'row' => 3, 'col' => 5, 'strikeWeak' => 1, 'strikeMedium' => 2, 'strikeStrong' => 3]);
$targetA = rgCard(['instanceId' => 101, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 20, 'hpMax' => 20]);
$targetB = rgCard(['instanceId' => 102, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 5, 'hp' => 20, 'hpMax' => 20]);
$defender = rgCard(['instanceId' => 103, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 20, 'hpMax' => 20]);
$state = rgState($graveRagnar, $attackerA, $attackerB, $targetA, $targetB, $defender);
rgRolls('4,1,6,1');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 1, 'target_id' => 101]));
rgAssert($result->success, $result->error ?? 'First strike should be accepted.');
rgAssert(($state->battle['strike']['state'] ?? '') !== 'waiting_defender', 'Ragnar unanswered strike should skip defender choice.');
rgAssert(empty($state->battle['strike']['defenders']), 'Ragnar unanswered strike should not offer defenders.');
rgAssert(($state->battle['strike']['defend_dice'] ?? null) === 0, 'Ragnar Valhalla should use existing unanswered one-die strike.');
rgAssert(!empty($state->battle['strike']['ragnar_unanswer']), 'Strike should record Ragnar unanswered source.');
$state->battle['strike'] = null;
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 2, 'target_id' => 102]));
rgAssert($result->success, $result->error ?? 'Second strike should be accepted.');
rgAssert(empty($state->battle['strike']['ragnar_unanswer']), 'Second ordinary strike in the same turn should not record Ragnar unanswered source.');
rgAssert(
    ($state->battle['strike']['state'] ?? '') === 'waiting_defender'
    || (int) ($state->battle['strike']['defend_dice'] ?? 0) > 0,
    'Second ordinary strike in the same turn should defend normally.'
);

$state->battle['strike'] = null;
(new TurnProcessor($state, new Engine()))->continueStartTurn(GameState::PLAYER_HOST);
unset($state->battle['turn_phase']);
$attackerC = rgCard(['instanceId' => 3, 'row' => 2, 'col' => 1, 'strikeWeak' => 1, 'strikeMedium' => 2, 'strikeStrong' => 3]);
$targetC = rgCard(['instanceId' => 104, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 1, 'hp' => 20, 'hpMax' => 20]);
$state->addCard($attackerC);
$state->addCard($targetC);
rgRolls('5,1');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 3, 'target_id' => 104]));
rgAssert($result->success, $result->error ?? 'Next turn strike should be accepted.');
rgAssert(($state->battle['strike']['defend_dice'] ?? null) === 0, 'Ragnar unanswered strike should reset next turn.');

// 16-17. No active marker / Ragnar on field only do not grant unanswered strike.
$graveInactive = rgRagnar(['instanceId' => 163, 'zone' => CardInstance::ZONE_GRAVEYARD, 'hp' => 0]);
$attacker = rgCard(['instanceId' => 1, 'row' => 3, 'col' => 3]);
$target = rgCard(['instanceId' => 101, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 20, 'hpMax' => 20]);
$state = rgState($graveInactive, $attacker, $target);
rgRolls('4,2');
(new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 1, 'target_id' => 101]));
rgAssert((int) ($state->battle['strike']['defend_dice'] ?? 0) > 0, 'Inactive graveyard Ragnar should not grant unanswered strike.');
rgAssert(empty($state->battle['strike']['ragnar_unanswer']), 'Inactive graveyard Ragnar should not record unanswered strike.');

$fieldRagnar = rgRagnar(['instanceId' => 63, 'row' => 2, 'col' => 3]);
$attacker = rgCard(['instanceId' => 1, 'row' => 3, 'col' => 3]);
$target = rgCard(['instanceId' => 101, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 20, 'hpMax' => 20]);
$state = rgState($fieldRagnar, $attacker, $target);
rgRolls('4,2');
(new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 1, 'target_id' => 101]));
rgAssert((int) ($state->battle['strike']['defend_dice'] ?? 0) > 0, 'Field Ragnar should not grant his Valhalla unanswered strike.');
rgAssert(empty($state->battle['strike']['ragnar_unanswer']), 'Field Ragnar should not record Ragnar Valhalla unanswered strike.');

// 18-21. Opponent/forced/already-unanswered strikes do not consume Ragnar's first-strike effect.
$graveRagnar = rgActiveValhalla(rgRagnar(['instanceId' => 163]));
$unanswerAttacker = rgCard(['instanceId' => 1, 'row' => 3, 'col' => 3, 'prop' => ['unanswer' => true]]);
$normalAttacker = rgCard(['instanceId' => 2, 'row' => 3, 'col' => 5]);
$targetA = rgCard(['instanceId' => 101, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 20, 'hpMax' => 20]);
$targetB = rgCard(['instanceId' => 102, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 5, 'hp' => 20, 'hpMax' => 20]);
$state = rgState($graveRagnar, $unanswerAttacker, $normalAttacker, $targetA, $targetB);
rgRolls('4,5,1');
(new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 1, 'target_id' => 101]));
rgAssert(empty($graveRagnar->flags['ragnar_unanswer_used_this_turn']), 'Already unanswered strike should not consume Ragnar effect.');
$state->battle['strike'] = null;
(new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 2, 'target_id' => 102]));
rgAssert(($state->battle['strike']['defend_dice'] ?? null) === 0, 'Next normal strike should still consume Ragnar effect.');

$graveRagnar = rgActiveValhalla(rgRagnar(['instanceId' => 163]));
$attacker = rgCard(['instanceId' => 1, 'row' => 3, 'col' => 3]);
$target = rgCard(['instanceId' => 101, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 20, 'hpMax' => 20]);
$state = rgState($graveRagnar, $attacker, $target);
$forced = (new StrikeResolver($state, new Engine()))->declareForcedAdjacent($attacker, $target);
rgAssert($forced->success, $forced->error ?? 'Forced adjacent strike should be declared.');
rgAssert(empty($graveRagnar->flags['ragnar_unanswer_used_this_turn']), 'Forced strike should not consume Ragnar effect.');

$graveRagnar = rgActiveValhalla(rgRagnar(['instanceId' => 163, 'owner' => GameState::PLAYER_PLAYER]));
$attacker = rgCard(['instanceId' => 1, 'row' => 3, 'col' => 3]);
$target = rgCard(['instanceId' => 101, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 20, 'hpMax' => 20]);
$state = rgState($graveRagnar, $attacker, $target);
rgRolls('4,2');
(new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 1, 'target_id' => 101]));
rgAssert(($state->battle['strike']['defend_dice'] ?? null) === 2, 'Opponent Ragnar Valhalla should not affect host strike.');
rgAssert(empty($graveRagnar->flags['ragnar_unanswer_used_this_turn']), 'Opponent strike should not consume Ragnar effect.');

// 22-23. Активность Вальхаллы возникает только от разрешённых причин смерти.
$ragnar = rgRagnar(['instanceId' => 63, 'hp' => 1, 'hpMax' => 1]);
$state = rgState($ragnar);
(new DamageResolver($state))->applyDamage($ragnar, 1, 'impact');
(new ZoneManager($state))->flushDying();
rgAssert(empty($ragnar->flags['valhalla_active']), 'Impact death should not activate Ragnar Valhalla.');

$ragnar = rgRagnar(['instanceId' => 63, 'hp' => 1, 'hpMax' => 1]);
$state = rgState($ragnar);
(new DamageResolver($state))->applyDamage($ragnar, 1, 'strike');
(new ZoneManager($state))->flushDying();
rgAssert(!empty($ragnar->flags['valhalla_active']), 'Strike death should activate Ragnar Valhalla.');

echo "Ragnar tests passed\n";
