<?php
// tools/test_oyuun_holvert.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\BattleHelper;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnPhaseProcessor;
use Berserk\Core\TurnProcessor;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function ohAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function oyuunProp(): array
{
    return [
        'zov' => true,
        'actions' => [[
            'key' => 'gain_zoal',
            'name' => 'Получить ЗОАЛ',
            'type' => 'grant_prop',
            'prop' => 'zoal',
            'self' => true,
        ]],
        'instants' => [[
            'key' => 'battle_frenzy',
            'name' => 'Боевое исступление',
            'trigger' => 'turn',
            'target' => 'ally',
            'uses_per_turn' => 2,
            'effect' => [
                'type' => 'open',
                'condition' => 'target_closed',
                'damage' => 1,
            ],
        ]],
    ];
}

function holvertProp(): array
{
    return [
        'has_line' => true,
        'on_successful_strike' => [
            'key' => 'holvert_open',
            'type' => 'open_line_ally_cannot_attack',
            'uses_per_turn' => 1,
        ],
    ];
}

function ohState(CardInstance ...$cards): GameState
{
    $state = new GameState(315, 101, 202);
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

function ohCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 'card_' . ($overrides['instanceId'] ?? 1),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 8,
        hpMax: $overrides['hpMax'] ?? 8,
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        armor: $overrides['armor'] ?? 0,
        armorMax: $overrides['armorMax'] ?? 0,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        prop: $overrides['prop'] ?? [],
        modifiers: $overrides['modifiers'] ?? [],
        markers: $overrides['markers'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function oyuun(array $overrides = []): CardInstance
{
    return ohCard(array_replace([
        'instanceId' => 31,
        'ukid' => 's1_31',
        'row' => 3,
        'col' => 3,
        'hp' => 7,
        'hpMax' => 7,
        'prop' => oyuunProp(),
    ], $overrides));
}

function holvert(array $overrides = []): CardInstance
{
    return ohCard(array_replace([
        'instanceId' => 15,
        'ukid' => 's1_15',
        'row' => 3,
        'col' => 3,
        'hp' => 10,
        'hpMax' => 10,
        'strikeWeak' => 2,
        'strikeMedium' => 3,
        'strikeStrong' => 4,
        'prop' => holvertProp(),
    ], $overrides));
}

function ohEnemy(int $id, int $row = 4, int $col = 3, array $overrides = []): CardInstance
{
    return ohCard(array_replace([
        'instanceId' => $id,
        'owner' => GameState::PLAYER_PLAYER,
        'row' => $row,
        'col' => $col,
        'hp' => 10,
        'hpMax' => 10,
        'strikeWeak' => 1,
        'strikeMedium' => 2,
        'strikeStrong' => 3,
    ], $overrides));
}

function ohApply(GameState $state, string $playerKey, Command $cmd): \Berserk\Core\Result
{
    return (new Engine())->apply($state, $playerKey, $cmd);
}

function ohStrikeApply(GameState $state, int $attackerId, int $targetId, string $attack, string $defend = ''): void
{
    $state->battle['strike'] = [
        'attacker_id' => $attackerId,
        'target_id' => $targetId,
        'defender_id' => null,
        'state' => 'results',
        'attack_dice' => 6,
        'defend_dice' => 0,
        'confirmed' => [],
    ];
    (new StrikeResolver($state, new Engine()))->apply([
        'attack' => $attack,
        'defend' => $defend,
        'winner' => $attack !== '' ? 'attack' : 'defend',
    ], false);
}

function ohCardsInfo(): array
{
    return [
        's1_31' => ['name' => 'Ойуун'],
        's1_15' => ['name' => 'Рубаки Холверта'],
        'card_2' => ['name' => 'Союзник A'],
        'card_3' => ['name' => 'Союзник B'],
        'card_4' => ['name' => 'Дальний строй'],
        'card_5' => ['name' => 'Враг'],
        'card_6' => ['name' => 'Враг 2'],
    ];
}

$openTarget = ohCard([
    'instanceId' => 2,
    'closed' => true,
    'flags' => ['attacks_used_this_turn' => 1, 'shot_used_this_turn' => true],
    'modifiers' => [['stat' => 'cannot_attack', 'value' => 1, 'expire' => 'end_of_turn']],
]);
$state = ohState($openTarget, ohEnemy(5));
(new Engine())->openCard($openTarget);
ohAssert(!$openTarget->closed, 'openCard should open the card.');
ohAssert((int) ($openTarget->flags['attacks_used_this_turn'] ?? -1) === 0, 'openCard should reset attack usage.');
ohAssert(empty($openTarget->flags['shot_used_this_turn']), 'openCard should reset shot usage.');
ohAssert(CardStats::hasCannotAttack($openTarget), 'openCard should not clear cannot_attack.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 2, 'target_id' => 5]));
ohAssert(!$result->success, 'cannot_attack should block initiating strike.');

$state = ohState(oyuun(), ohEnemy(5, 4, 3, ['prop' => ['zov' => true]]));
$oyuun = $state->getCard(31);
ohAssert(!empty($oyuun->prop['zov']), 'Oyuun should have ZOV.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('action', ['card_id' => 31, 'action_key' => 'gain_zoal']));
ohAssert($result->success, $result->error ?? 'Oyuun grant zoal action should resolve.');
ohAssert(!empty($oyuun->prop['zoal']), 'Oyuun tap action should grant ZOAL.');
ohAssert($oyuun->closed, 'Oyuun grant zoal action should close Oyuun.');

$state = ohState(
    oyuun(['instanceId' => 31, 'col' => 2]),
    oyuun(['instanceId' => 32, 'ukid' => 's1_31', 'col' => 4]),
    ohCard(['instanceId' => 2, 'ukid' => 'card_2', 'closed' => true, 'hp' => 3, 'hpMax' => 3, 'flags' => ['attacks_used_this_turn' => 1]]),
    ohEnemy(5)
);
$instants = (new InstantProcessor($state, new Engine()))->getInstants(GameState::PLAYER_HOST, 'before');
ohAssert(($instants[0]['payload']['trigger'] ?? null) === 'turn', 'Oyuun battle frenzy should be a turn instant.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('open_turn_instants'));
ohAssert($result->success, $result->error ?? 'Opening turn instants should work.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 31, 'instant_key' => 'battle_frenzy']));
ohAssert($result->success, $result->error ?? 'Oyuun should open instant target picker.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render($state, GameState::PLAYER_HOST, 'host', ohCardsInfo(), '/battle?game=315&first=');
ohAssert(str_contains($html, 'Союзник A'), 'Oyuun picker should include own closed creature.');
ohAssert(!str_contains($html, 'Враг'), 'Oyuun picker should not include enemy creatures.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));
ohAssert($result->success, $result->error ?? 'Oyuun instant target picker should be cancellable.');
ohAssert(empty($state->battle['pending_instant_pick']), 'Oyuun cancel should clear instant target pending.');
ohAssert(!$state->getCard(31)->closed, 'Oyuun cancel should not close Oyuun.');
ohAssert(empty($state->getCard(31)->flags['instant_uses_this_turn']['battle_frenzy']), 'Oyuun cancel should not spend uses_per_turn.');
ohAssert($state->getCard(2)->closed && $state->getCard(2)->hp === 3, 'Oyuun cancel should not open or wound target.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('open_turn_instants'));
ohAssert($result->success, $result->error ?? 'Oyuun instant window should reopen after cancel.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 31, 'instant_key' => 'battle_frenzy']));
ohAssert($result->success, $result->error ?? 'Oyuun should be playable again after cancel.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 2]));
ohAssert($result->success, $result->error ?? 'Oyuun battle frenzy should resolve.');
ohAssert($state->getCard(31)->closed, 'Oyuun should close as instant cost.');
ohAssert(!$state->getCard(2)->closed, 'Oyuun target should open.');
ohAssert($state->getCard(2)->hp === 2, 'Oyuun target should take 1 wound.');
ohAssert((int) ($state->getCard(2)->flags['attacks_used_this_turn'] ?? -1) === 0, 'Oyuun open should reset target usage.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render($state, GameState::PLAYER_HOST, 'host', ohCardsInfo(), '/battle?game=315&first=');
ohAssert(substr_count($html, 'открыта и получает 1 рану') === 1, 'Oyuun wound message should appear once after one use.');
unset($state->battle['pending_turn_instants']);
$result = ohApply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 2, 'target_id' => 5]));
ohAssert($result->success, $result->error ?? 'Oyuun-opened target should be able to attack without restrictions.');
ohAssert(empty($state->battle['instant_result']), 'Oyuun instant result should be cleared by the next independent command.');

$state->battle['strike'] = null;
$state->getCard(2)->closed = true;
$result = ohApply($state, GameState::PLAYER_HOST, new Command('open_turn_instants'));
ohAssert($result->success, $result->error ?? 'Oyuun should be able to open turn instants again after being opened.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 32, 'instant_key' => 'battle_frenzy']));
ohAssert($result->success, $result->error ?? 'Second Oyuun should target first Oyuun.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 31]));
ohAssert($result->success, $result->error ?? 'Second Oyuun should open first Oyuun.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('open_turn_instants'));
ohAssert($result->success, $result->error ?? 'First Oyuun should still have second use available.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 31, 'instant_key' => 'battle_frenzy']));
ohAssert($result->success, $result->error ?? 'First Oyuun second use should be allowed.');
$state->battle['pending_instant_pick'] = null;
(new Engine())->openCard($state->getCard(31));
$result = ohApply($state, GameState::PLAYER_HOST, new Command('open_turn_instants'));
if ($result->success) {
    $third = ohApply($state, GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 31, 'instant_key' => 'battle_frenzy']));
    ohAssert(!$third->success, 'First Oyuun third use should be forbidden.');
}
(new TurnProcessor($state, new Engine()))->continueStartTurn(GameState::PLAYER_HOST);
ohAssert(empty($state->getCard(31)->flags['instant_uses_this_turn']), 'Oyuun instant use counter should reset on next turn start.');

$state = ohState(
    oyuun(),
    ohCard(['instanceId' => 2, 'ukid' => 'card_2', 'closed' => true, 'hp' => 1, 'hpMax' => 1]),
    ohEnemy(5)
);
ohApply($state, GameState::PLAYER_HOST, new Command('open_turn_instants'));
ohApply($state, GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 31, 'instant_key' => 'battle_frenzy']));
$result = ohApply($state, GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 2]));
ohAssert($result->success, $result->error ?? 'Lethal Oyuun frenzy should resolve.');
ohAssert($state->getCard(2)->zone === CardInstance::ZONE_GRAVEYARD, 'Lethal Oyuun wound should send target to graveyard.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render($state, GameState::PLAYER_HOST, 'host', ohCardsInfo(), '/battle?game=315&first=');
ohAssert(substr_count($html, 'получает 1 рану и погибает') === 1, 'Lethal Oyuun wound message should appear once.');

$state = ohState(holvert(), ohEnemy(5));
ohStrikeApply($state, 15, 5, 'strong');
ohAssert(empty($state->battle['pending_holvert_open']), 'Holvert should not create pending with only self in line group.');
ohAssert(empty($state->battle['strike']['holvert_open']), 'Holvert should not open himself.');

$state = ohState(
    holvert(),
    ohCard(['instanceId' => 2, 'ukid' => 'card_2', 'row' => 3, 'col' => 4, 'closed' => true, 'prop' => ['has_line' => true], 'flags' => ['attacks_used_this_turn' => 1]]),
    ohCard(['instanceId' => 4, 'ukid' => 'card_4', 'row' => 5, 'col' => 5, 'closed' => true, 'prop' => ['has_line' => true]]),
    ohEnemy(5)
);
ohStrikeApply($state, 15, 5, 'strong');
ohAssert(!$state->getCard(2)->closed, 'Holvert single line target should be auto-opened.');
ohAssert((int) ($state->getCard(2)->flags['attacks_used_this_turn'] ?? -1) === 0, 'Holvert open should reset target usage.');
ohAssert(CardStats::hasCannotAttack($state->getCard(2)), 'Holvert target should receive cannot_attack.');
ohAssert($state->getCard(4)->closed, 'Separate line should not be affected by Holvert.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 2, 'target_id' => 5]));
ohAssert(!$result->success, 'Holvert cannot_attack should block target strike.');
$state->getCard(2)->closed = false;
$result = ohApply($state, GameState::PLAYER_HOST, new Command('gain_coin', ['card_id' => 2]));
ohAssert(!$result->success || $result->error !== 'Карта не может атаковать до конца хода', 'cannot_attack should not be a blanket non-attack action block.');

$state = ohState(
    holvert(),
    ohCard(['instanceId' => 2, 'ukid' => 'card_2', 'row' => 3, 'col' => 4, 'closed' => true, 'prop' => ['has_line' => true]]),
    ohEnemy(5, 4, 3, ['prop' => ['damage_reduction' => [['types' => ['strike'], 'value' => 99]]]])
);
ohStrikeApply($state, 15, 5, 'strong');
ohAssert($state->getCard(2)->closed, 'Holvert should not trigger on 0 actual wounds.');

$state = ohState(
    holvert(['instanceId' => 15, 'row' => 4, 'col' => 3]),
    ohCard(['instanceId' => 2, 'ukid' => 'card_2', 'row' => 4, 'col' => 4, 'closed' => true, 'prop' => ['has_line' => true]]),
    ohEnemy(5, 3, 3, ['strikeStrong' => 1])
);
$state->battle['active'] = GameState::PLAYER_PLAYER;
ohStrikeApply($state, 5, 15, 'weak', 'strong');
ohAssert(!$state->getCard(2)->closed, 'Holvert should trigger from a successful answer strike on opponent turn.');

$state = ohState(
    holvert(),
    ohCard(['instanceId' => 2, 'ukid' => 'card_2', 'row' => 3, 'col' => 4, 'closed' => true, 'prop' => ['has_line' => true]]),
    ohCard(['instanceId' => 3, 'ukid' => 'card_3', 'row' => 2, 'col' => 3, 'closed' => true, 'prop' => ['has_line' => true]]),
    ohEnemy(5)
);
ohStrikeApply($state, 15, 5, 'strong');
ohAssert(!empty($state->battle['pending_holvert_open']), 'Holvert should open mandatory pending with multiple line targets.');
ohAssert(!in_array(15, $state->battle['pending_holvert_open']['candidate_ids'] ?? [], true), 'Holvert pending should not include Holvert himself.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render($state, GameState::PLAYER_HOST, 'host', ohCardsInfo(), '/battle?game=315&first=');
ohAssert(str_contains($html, 'выберите существо в строю'), 'Holvert pending should explain line target choice.');
ohAssert(!str_contains($html, 'cancel_pending'), 'Holvert mandatory pending should not show cancel.');
$cancel = ohApply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));
ohAssert(!$cancel->success, 'Holvert mandatory pending should reject cancel_pending.');
$result = ohApply($state, GameState::PLAYER_HOST, new Command('choose_holvert_open', ['target_id' => 3]));
ohAssert($result->success, $result->error ?? 'Holvert choice should resolve.');
ohAssert(!$state->getCard(3)->closed && $state->getCard(2)->closed, 'Holvert should open only chosen instance.');
ohStrikeApply($state, 15, 5, 'strong');
ohAssert(empty($state->battle['pending_holvert_open']), 'Holvert trigger should be limited to once per turn.');

$state->getCard(3)->closed = true;
(new Engine())->openCard($state->getCard(3));
ohAssert(CardStats::hasCannotAttack($state->getCard(3)), 'Oyuun/openCard should not clear Holvert cannot_attack.');
(new TurnProcessor($state, new Engine()))->afterEndPhase(GameState::PLAYER_HOST, GameState::PLAYER_PLAYER);
ohAssert(!CardStats::hasCannotAttack($state->getCard(3)), 'cannot_attack expire=end_of_turn should clear at end of current turn.');

$state = ohState(
    oyuun(['owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]),
    ohCard(['instanceId' => 2, 'ukid' => 'card_2', 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'closed' => true, 'hp' => 3, 'hpMax' => 3])
);
(new TurnPhaseProcessor($state, new Engine()))->beginStartPhase(GameState::PLAYER_HOST);
ohAssert(($state->battle['turn_phase']['sub']['parent_type'] ?? null) === 'instants', 'Passive player should get start-phase instant subwindow.');
$result = ohApply($state, GameState::PLAYER_PLAYER, new Command('turn_sub', ['sub_id' => 'instant_31_battle_frenzy']));
ohAssert($result->success, $result->error ?? 'Passive player should be able to declare start-phase instant.');
ohAssert(!empty($state->battle['pending_instant_pick']), 'Passive start-phase instant should open target picker.');
$result = ohApply($state, GameState::PLAYER_PLAYER, new Command('cancel_pending'));
ohAssert($result->success, $result->error ?? 'Passive start-phase instant target picker should be cancellable.');
ohAssert(empty($state->battle['pending_instant_pick']), 'Passive cancel should clear instant picker.');
ohAssert(!$state->getCard(31)->closed && $state->getCard(2)->closed && $state->getCard(2)->hp === 3, 'Passive cancel should not pay costs or apply effect.');
$result = ohApply($state, GameState::PLAYER_PLAYER, new Command('turn_sub', ['sub_id' => 'instant_31_battle_frenzy']));
ohAssert($result->success, $result->error ?? 'Passive player should be able to redeclare after cancel.');
$result = ohApply($state, GameState::PLAYER_PLAYER, new Command('choose_instant_pick', ['target_id' => 2]));
ohAssert($result->success, $result->error ?? 'Passive start-phase instant should resolve.');
ohAssert($state->getCard(31)->closed && !$state->getCard(2)->closed && $state->getCard(2)->hp === 2, 'Passive start-phase instant should close source, open target, and wound it.');

$state = ohState(
    oyuun(['owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]),
    ohCard(['instanceId' => 2, 'ukid' => 'card_2', 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'closed' => true, 'hp' => 3, 'hpMax' => 3])
);
(new TurnPhaseProcessor($state, new Engine()))->beginEndPhase(GameState::PLAYER_HOST);
ohAssert(($state->battle['turn_phase']['sub']['parent_type'] ?? null) === 'instants', 'Passive player should get end-phase instant subwindow.');
$result = ohApply($state, GameState::PLAYER_PLAYER, new Command('turn_sub', ['sub_id' => 'instant_31_battle_frenzy']));
ohAssert($result->success, $result->error ?? 'Passive player should be able to declare end-phase instant.');
$result = ohApply($state, GameState::PLAYER_PLAYER, new Command('choose_instant_pick', ['target_id' => 2]));
ohAssert($result->success, $result->error ?? 'Passive end-phase instant should resolve.');
ohAssert(!$state->getCard(2)->closed, 'Passive end-phase instant should open target.');
ohAssert($state->getCard(2)->hp === 2, 'Passive end-phase instant should wound target.');
ohAssert(empty($state->battle['turn_phase']), 'Passive end-phase instant should let the end phase complete without a hanging pending.');

echo "Oyuun and Holvert regression tests passed.\n";
