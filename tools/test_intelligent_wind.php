<?php

declare(strict_types=1);

require __DIR__ . '/test_symbiotes.php';

use Berserk\Core\{CardInstance, CardStats, Engine, GameState, SymbioteProcessor};
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

$prop = json_decode(file_get_contents(__DIR__ . '/../docs/cards/s2_4.prop.json'), true, 512, JSON_THROW_ON_ERROR);
$state = syState();
$state->battle = ['active' => 'host', 'turn' => 1];
$symbiote = syAdd($state, 30);
$symbiote->prop = $prop;
$host = $state->getCard(10);
$enemy = $state->getCard(20);
$engine = new Engine();

syCheck($host->effectiveMove() === 2, 'unattached movement');
syCheck(CardStats::getDamageReduction($state, $enemy, $host, 'shot') === 0, 'unattached protection');
syCheck((new SymbioteProcessor($state, $engine))->begin(), 'seating opens');
syChoose($state, 10);
syCheck($host->effectiveMove() === 3 && $host->move === 3, 'native attachment grants movement');
foreach (['shot', 'throw', 'discharge', 'strike', 'tap', 'uchr', 'magic'] as $type) {
    $expected = (int) in_array($type, ['shot', 'throw', 'discharge'], true);
    syCheck(CardStats::getDamageReduction($state, $enemy, $host, $type) === $expected, 'host defense ' . $type);
}

$host->move = 2;
$state = GameState::fromArray(json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
$host = $state->getCard(10);
$symbiote = $state->getCard(30);
$enemy = $state->getCard(20);
for ($i = 0; $i < 3; $i++) {
    SymbioteProcessor::refresh($state, $engine);
    syCheck($host->move === 2 && $host->effectiveMove() === 3 && count($host->modifiers) === 2, 'refresh does not refill or duplicate');
}

syCheck(CardStats::isIncorporeal($symbiote), 'standard incorporeal flag');
$info = [];
foreach ($state->cards as $card) {
    $info[$card->ukid] = ['name' => $card->ukid, 'price' => 1];
}
$screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
$panel = $screen->prepare($state, 'host', 'host', null, $info, ['sel' => $symbiote->instanceId])['data']['panel_html'];
syCheck(str_contains($panel, '/assets/images/card/incorpeal@2x.png'), 'selected card shows the incorporeal icon');

foreach (['strike', 'answer', 'tap', 'uchr', 'shot', 'throw', 'discharge', 'magic', 'cast'] as $type) {
    $symbiote->hp = 8;
    $engine->applyDamage($state, $symbiote, 4, $type, $enemy);
    $expected = in_array($type, ['discharge', 'magic', 'cast'], true) ? 4 : 7;
    syCheck($symbiote->hp === $expected, 'damage ' . $type);
}

$engine->applyDamage($state, $symbiote, 4, 'magic', $enemy);
syCheck($symbiote->zone === CardInstance::ZONE_EXILE && !$symbiote->dying, 'immediate exile after lethal damage');
syCheck($host->move === 1 && $host->effectiveMove() === 2 && $host->modifiers === [], 'host effects removed');
syCheck(CardStats::getDamageReduction($state, $enemy, $host, 'shot') === 0, 'protection removed');
echo "Intelligent Wind: attachment, damage, reload and exile checks passed.\n";
