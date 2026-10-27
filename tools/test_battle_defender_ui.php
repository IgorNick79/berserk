<?php
// tools/test_battle_defender_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Dice;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function defenderUiHighlights(string $html,string $class): int {
    $dom=new DOMDocument();@$dom->loadHTML($html);$x=new DOMXPath($dom);
    return $x->query('//*[contains(concat(" ",normalize-space(@class)," ")," field-cell ") and contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]')->length;
}
function defenderUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function defenderUiCard(int $id, string $ukid, string $owner, int $row, int $col): CardInstance
{
    return new CardInstance(
        instanceId: $id,
        ukid: $ukid,
        owner: $owner,
        zone: CardInstance::ZONE_FIELD,
        row: $row,
        col: $col,
        hp: 3,
        hpMax: 3,
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
    );
}

function defenderUiState(string $attackerOwner, array $defenders): GameState
{
    $state = new GameState(77, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => $attackerOwner,
        'strike' => [
            'kind' => 'strike',
            'state' => 'waiting_defender',
            'attacker_id' => 1,
            'target_id' => 2,
            'defender_id' => null,
            'defenders' => array_map(static fn(CardInstance $c): int => $c->instanceId, $defenders),
        ],
    ];

    $opponent = $state->getOpponentKey($attackerOwner);
    $state->addCard(defenderUiCard(1, 'attacker', $attackerOwner, 2, 2));
    $state->addCard(defenderUiCard(2, 'target', $opponent, 3, 3));
    foreach ($defenders as $card) {
        $state->addCard($card);
    }

    return $state;
}

function defenderUiRender(GameState $state, string $viewerKey, string $role, array $cardsInfo): array
{
    $_GET = [];
    $_SESSION = [];
    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));

    return $screen->prepare($state, $viewerKey, $role, null, $cardsInfo)['data'];
}

$cardsInfo = [
    'attacker' => ['name' => 'Атакующий'],
    'target' => ['name' => 'Цель'],
    'def_a' => ['name' => 'Защитник A'],
    'def_b' => ['name' => 'Защитник B'],
    'def_c' => ['name' => 'Защитник C'],
    'mage' => ['name' => 'Бешеный маг'],
    'redirect_ally' => ['name' => 'Новая цель'],
];

if (($argv[1] ?? '') === '--fixture') {
    $viewer = ($argv[2] ?? 'player') === 'host' ? 'host' : 'player';
    $state = defenderUiState($viewer === 'host' ? 'player' : 'host', [
        defenderUiCard(3, 'def_a', $viewer, $viewer === 'host' ? 2 : 4, 2),
        defenderUiCard(4, 'def_b', $viewer, $viewer === 'host' ? 4 : 3, 4),
    ]);
    echo json_encode(defenderUiRender($state, $viewer, $viewer, $cardsInfo), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

$playerState = defenderUiState(GameState::PLAYER_HOST, [
    defenderUiCard(3, 'def_a', GameState::PLAYER_PLAYER, 3, 2),
    defenderUiCard(4, 'def_b', GameState::PLAYER_PLAYER, 4, 4),
    defenderUiCard(5, 'def_c', GameState::PLAYER_PLAYER, 6, 5),
]);
$playerHtml = defenderUiRender($playerState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

defenderUiAssert(
    preg_match('/Защитник A<\/span><span class="coords coords--enemy"[^>]*>.*?\[I:4\]/s', $playerHtml['info_panel_html']) === 1,
    'Defender label should use colored Roman rows and viewer-relative column.'
);
defenderUiAssert(
    preg_match('/Защитник B<\/span><span class="coords coords--own"[^>]*>.*?\[I:2\]/s', $playerHtml['info_panel_html']) === 1,
    'Second defender should use player-relative coordinates.'
);
defenderUiAssert(
    preg_match('/Защитник C<\/span><span class="coords coords--own"[^>]*>.*?\[III:1\]/s', $playerHtml['info_panel_html']) === 1,
    'Third defender should use player-relative coordinates.'
);
defenderUiAssert(
    defenderUiHighlights($playerHtml['field_html'], 'pending-defender-target') === 3,
    'All three player defender candidates should be highlighted on battlefield.'
);
defenderUiAssert(
    str_contains($playerHtml['field_html'], 'inspect=4') && !str_contains($playerHtml['field_html'], 'cmd=choose_defender'),
    'Battlefield defender click inspects; the existing radio executes defender choice.'
);

$hostState = defenderUiState(GameState::PLAYER_PLAYER, [
    defenderUiCard(3, 'def_a', GameState::PLAYER_HOST, 2, 5),
]);
$hostHtml = defenderUiRender($hostState, GameState::PLAYER_HOST, 'host', $cardsInfo);

defenderUiAssert(
    preg_match('/Защитник A<\/span><span class="coords coords--own"[^>]*>.*?\[II:5\]/s', $hostHtml['info_panel_html']) === 1,
    'Host defender label should use game row and host column.'
);
defenderUiAssert(
    defenderUiHighlights($hostHtml['field_html'], 'pending-defender-target') === 1,
    'Single host defender candidate should be highlighted on battlefield.'
);

$twoDefenderState = defenderUiState(GameState::PLAYER_HOST, [
    defenderUiCard(3, 'def_a', GameState::PLAYER_PLAYER, 3, 2),
    defenderUiCard(4, 'def_b', GameState::PLAYER_PLAYER, 4, 4),
]);
$twoDefenderHtml = defenderUiRender($twoDefenderState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

defenderUiAssert(
    substr_count($twoDefenderHtml['info_panel_html'], 'name="choice"') === 2,
    'Only creature options should be radios; skip is a separate button.'
);
defenderUiAssert(
    str_contains($twoDefenderHtml['info_panel_html'], 'name="cmd" value="choose_defender_option"'),
    'Defender pending panel should submit through the radio option dispatcher.'
);
defenderUiAssert(
    str_contains($twoDefenderHtml['info_panel_html'], 'value="defender:3"')
        && str_contains($twoDefenderHtml['info_panel_html'], 'value="defender:4"'),
    'Defender radio options should encode defender ids.'
);
defenderUiAssert(
    str_contains($twoDefenderHtml['info_panel_html'], 'cmd=choose_defender&amp;defender_id=0" class="button skip">Пропустить</a>')
        && !str_contains($twoDefenderHtml['info_panel_html'], 'value="defender:0"'),
    'Skip should immediately use the existing server command without a radio selection.'
);

foreach (['host', 'player'] as $viewer) {
    $emptyState = defenderUiState($viewer === 'host' ? 'player' : 'host', []);
    $emptyHtml = defenderUiRender($emptyState, $viewer, $viewer, $cardsInfo)['info_panel_html'];
    defenderUiAssert(str_contains($emptyHtml, '>Пропустить</a>'), 'Skip is available even with no defenders.');
    defenderUiAssert(!str_contains($emptyHtml, 'name="choice"') && !str_contains($emptyHtml, '>Выбрать</button>'), 'No empty radio form submission.');
    $other = $emptyState->getOpponentKey($viewer);
    $waitHtml = defenderUiRender($emptyState, $other, $other, $cardsInfo)['info_panel_html'];
    defenderUiAssert(!str_contains($waitHtml, 'cmd=choose_defender'), 'Attacker cannot see a defender skip control.');
}

// Own defenders crossing the centre retain the field half color and viewer orientation.
foreach (['host', 'player'] as $viewer) {
    $row = $viewer === 'host' ? 4 : 3;
    $crossed = defenderUiState($viewer === 'host' ? 'player' : 'host', [
        defenderUiCard(3, 'def_a', $viewer, $row, 2),
        defenderUiCard(4, 'def_b', $viewer, $row, 4),
    ]);
    $crossedHtml = defenderUiRender($crossed, $viewer, $viewer, $cardsInfo)['info_panel_html'];
    $column = $viewer === 'host' ? 2 : 4;
    defenderUiAssert(preg_match('/Защитник A<\/span><span class="coords coords--enemy"[^>]*>.*?\[I:' . $column . '\]/s', $crossedHtml) === 1, 'Crossed defender uses opposing half color and viewer-relative column.');
    defenderUiAssert(str_contains($crossedHtml, 'value="defender:3"') && str_contains($crossedHtml, 'value="defender:4"'), 'Coordinate presentation preserves defender ids.');
}

$unsafeInfo = $cardsInfo;
$unsafeInfo['def_a']['name'] = '<script>alert("defender")</script>';
$unsafeHtml = defenderUiRender($hostState, 'host', 'host', $unsafeInfo)['info_panel_html'];
defenderUiAssert(str_contains($unsafeHtml, '&lt;script&gt;') && !str_contains($unsafeHtml, '<script>'), 'Card labels remain escaped while coordinates render as HTML.');

foreach (['host', 'player'] as $viewer) {
    $skipState = defenderUiState($viewer === 'host' ? 'player' : 'host', []);
    $_SESSION = ['debug_roll' => '4,4'];
    Dice::init();
    $resolver = new StrikeResolver($skipState, new Engine());
    $wrong = $resolver->chooseDefender($skipState->getOpponentKey($viewer), new Command('choose_defender', ['defender_id' => 0]));
    defenderUiAssert(!$wrong->success && $skipState->battle['strike']['state'] === 'waiting_defender', 'Skip still enforces server ownership.');
    $skip = $resolver->chooseDefender($viewer, new Command('choose_defender', ['defender_id' => 0]));
    defenderUiAssert($skip->success && $skipState->battle['strike']['defender_id'] === null && $skipState->battle['strike']['state'] !== 'waiting_defender', 'Skip advances the original strike without assigning a defender.');
}

$mageState = defenderUiState(GameState::PLAYER_HOST, []);
$mage = defenderUiCard(6, 'mage', GameState::PLAYER_PLAYER, 6, 4);
$mage->prop = ['instants' => [[
    'key' => 'magic_trick',
    'name' => 'Магический трюк',
    'effect' => ['type' => 'redirect_strike'],
    'target' => 'adjacent_ally',
    'trigger' => 'combat',
    'phase' => 'redirect',
]]];
$redirectAlly = defenderUiCard(7, 'redirect_ally', GameState::PLAYER_PLAYER, 4, 4);
$mageState->addCard($mage);
$mageState->addCard($redirectAlly);
$mageHtml = defenderUiRender($mageState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

defenderUiAssert(
    str_contains($mageHtml['info_panel_html'], 'Магический трюк') && str_contains($mageHtml['info_panel_html'], 'data-instant-control'),
    'Defender window should render Mage redirect in the common instant control.'
);
defenderUiAssert(
    str_contains($mageHtml['info_panel_html'], 'choice=mage%3A6%3A7'),
    'Mage redirect link should encode source and target.'
);

$zomMageState = defenderUiState(GameState::PLAYER_HOST, []);
$zomMage = defenderUiCard(8, 'mage', GameState::PLAYER_PLAYER, 6, 4);
$zomMage->prop = $mage->prop;
$zomRedirectAlly = defenderUiCard(9, 'redirect_ally', GameState::PLAYER_PLAYER, 4, 4);
$zomRedirectAlly->prop = ['zom' => true];
$zomMageState->addCard($zomMage);
$zomMageState->addCard($zomRedirectAlly);
$zomMageHtml = defenderUiRender($zomMageState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

defenderUiAssert(
    !str_contains($zomMageHtml['info_panel_html'], 'choice=mage'),
    'Defender window should hide Mage redirect when every redirect target has zom.'
);

echo "Battle defender UI tests passed.\n";
