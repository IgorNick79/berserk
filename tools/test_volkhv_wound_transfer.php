<?php
// tools/test_volkhv_wound_transfer.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\BattleHelper;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\WoundTransferProcessor;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function vwAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function vwCard(array $o): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'] ?? ('card_' . $o['instanceId']),
        owner: $o['owner'] ?? GameState::PLAYER_HOST,
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'] ?? 3,
        col: $o['col'] ?? 3,
        hp: $o['hp'] ?? 8,
        hpMax: $o['hpMax'] ?? ($o['hp'] ?? 8),
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
        prop: $o['prop'] ?? [],
        coins: $o['coins'] ?? 0,
    );
}

function vwState(CardInstance ...$cards): GameState
{
    $state = new GameState(9701, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'hidden_row_revealed' => true,
    ];
    foreach ($cards as $card) {
        $state->addCard($card);
    }
    return $state;
}

function vwVolkhvProp(): array
{
    return [
        'actions' => [[
            'key' => 'volkhvovanie',
            'name' => 'Волхвование при луне',
            'type' => 'wound_transfer',
            'coins' => 1,
            'on_finish' => 'main_phase',
            'action_type' => 'cast',
            'donor_filter' => 'wounded',
            'max_transfer' => 2,
            'target_filter' => 'enemy',
        ]],
        'save_coins' => true,
    ];
}

function vwCardsInfo(): array
{
    return [
        's1_3' => ['name' => 'Волхв'],
        'hydra' => ['name' => 'Гидра'],
        'donor' => ['name' => 'Раненый союзник'],
    ];
}

$volkhv = vwCard([
    'instanceId' => 1,
    'ukid' => 's1_3',
    'owner' => GameState::PLAYER_HOST,
    'coins' => 1,
    'prop' => vwVolkhvProp(),
]);
$donor = vwCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 4,
    'hp' => 6,
    'hpMax' => 8,
]);
$hydra = vwCard([
    'instanceId' => 3,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
    'hp' => 8,
    'hpMax' => 8,
    'prop' => ['zoz' => true],
]);
$state = vwState($volkhv, $donor, $hydra);

$targets = BattleHelper::getAttackTargets($state, $volkhv, 'action:volkhvovanie', GameState::PLAYER_HOST);
vwAssert($targets === [], 'Volkhv wound transfer should not highlight enemy cards as direct targets.');

$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'volkhvovanie',
    'target_id' => 1,
]));
vwAssert($result->success, $result->error ?? 'Volkhv action should open wound transfer pending.');
vwAssert(!empty($state->battle['pending_wound_transfer']), 'Volkhv action should create wound transfer pending.');
vwAssert($volkhv->coins === 0, 'Volkhv should spend the coin when the pending starts.');

$proc = new WoundTransferProcessor($state, new Engine());
vwAssert($proc->chooseSource(GameState::PLAYER_HOST, new Command('wt_source', ['donor_id' => 2]))->success, 'Wounded ally should be selectable as donor.');
vwAssert($proc->chooseAmount(GameState::PLAYER_HOST, new Command('wt_amount', ['amount' => 1]))->success, 'One wound should be selectable.');
vwAssert($proc->chooseTarget(GameState::PLAYER_HOST, new Command('wt_target', ['target_id' => 3]))->success, 'Enemy with ZOZ can be selected before final resolution.');
vwAssert($proc->chooseTargetAmount(GameState::PLAYER_HOST, new Command('wt_target_amount', ['amount' => 1]))->success, 'Transfer attempt should resolve.');

vwAssert(empty($state->battle['pending_wound_transfer']), 'Volkhv pending should close after resolution.');
vwAssert($donor->hp === 6, 'Blocked cast transfer should not heal the donor.');
vwAssert($hydra->hp === 8, 'ZOZ should block cast-class wound transfer damage.');
vwAssert($volkhv->closed, 'Volkhv should close after the cast attempt.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    vwCardsInfo(),
    '/battle?game=9701'
);
vwAssert(str_contains($html, 'цель защищена от заклинания'), 'Blocked Volkhv transfer should be explained in InfoPanel.');

$volkhv = vwCard([
    'instanceId' => 11,
    'ukid' => 's1_3',
    'owner' => GameState::PLAYER_HOST,
    'coins' => 1,
    'prop' => vwVolkhvProp(),
]);
$fullAlly = vwCard([
    'instanceId' => 12,
    'ukid' => 'donor',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 4,
    'hp' => 8,
    'hpMax' => 8,
]);
$enemy = vwCard(['instanceId' => 13, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4]);
$state = vwState($volkhv, $fullAlly, $enemy);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 11,
    'action_key' => 'volkhvovanie',
    'target_id' => 11,
]));
vwAssert(!$result->success, 'Volkhv action without wounded allies should not start pending.');
vwAssert(empty($state->battle['pending_wound_transfer']), 'Volkhv action without wounded allies should leave no pending.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    vwCardsInfo(),
    '/battle?game=9701'
);
vwAssert(str_contains($html, 'нет раненых союзников'), 'Volkhv without donors should be explained in InfoPanel.');

// The Volkhv is also a creature in its owner's squad and can donate its own wounds.
$self = vwCard(['instanceId'=>21,'ukid'=>'s1_3','hp'=>5,'hpMax'=>8,'coins'=>1,'prop'=>vwVolkhvProp()]);
$enemy = vwCard(['instanceId'=>22,'owner'=>GameState::PLAYER_PLAYER,'row'=>4,'hp'=>8,'hpMax'=>8]);
$state = vwState($self,$enemy);$engine=new Engine();
vwAssert($engine->apply($state,'host',new Command('action',['card_id'=>21,'action_key'=>'volkhvovanie']))->success,'Wounded Volkhv can start with no other wounded ally');
vwAssert(($state->battle['pending_wound_transfer']['donors'][21]??0)===2,'Self wounds retain the two-wound limit');
foreach ([['wt_source',['donor_id'=>21]],['wt_amount',['amount'=>2]],['wt_target',['target_id'=>22]],['wt_target_amount',['amount'=>2]]] as [$type,$payload]) {
    vwAssert($engine->apply($state,'host',new Command($type,$payload))->success,'Self transfer step '.$type);
}
vwAssert($self->hp===7 && $enemy->hp===6,'Self wounds are healed and transferred to enemy');
vwAssert($self->coins===0 && $self->closed,'Self transfer keeps coin cost and closing');
vwAssert(empty($state->battle['pending_wound_transfer']),'Self transfer completes');
// The shared processor must not broaden the Hermit donor rule.
$hermit=vwCard(['instanceId'=>31,'hp'=>5,'hpMax'=>8]);$state=vwState($hermit);
vwAssert(!(new WoundTransferProcessor($state,new Engine()))->start('host',$hermit,['kind'=>'hermit','donor_filter'=>'wounded'])->success,'Hermit self exclusion unchanged');

echo "Volkhv wound transfer tests passed\n";
