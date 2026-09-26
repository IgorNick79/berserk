<?php
// tools/test_draft_selection_evaluator.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Prepare\DraftAutoPicker;
use Berserk\Core\Prepare\DraftSelectionDataProvider;
use Berserk\Core\Prepare\DraftSelectionEvaluator;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function card(array $overrides): array
{
    return array_merge([
        'ukid' => 'x',
        'power' => 3,
        'single' => 0,
        'price' => 4,
        'health' => 4,
        'elite' => 0,
        'type' => 'creature',
        'strike_weak' => 0,
        'strike_medium' => 1,
        'strike_strong' => 2,
        'prop' => [],
    ], $overrides);
}

$cards = [
    'p2' => card(['ukid' => 'p2', 'power' => 2]),
    'p5' => card(['ukid' => 'p5', 'power' => 5]),
    'mira' => card([
        'ukid' => 'mira',
        'power' => 2,
        'price' => 6,
        'prop' => ['actions' => [['type' => 'shot']]],
    ]),
    'front' => card([
        'ukid' => 'front',
        'power' => 3,
        'health' => 7,
        'strike_medium' => 2,
        'strike_strong' => 3,
    ]),
    'healer' => card([
        'ukid' => 'healer',
        'power' => 3,
        'prop' => ['actions' => [['type' => 'heal']]],
    ]),
    'fly' => card(['ukid' => 'fly', 'power' => 4, 'type' => 'fly']),
    'single' => card(['ukid' => 'single', 'power' => 4, 'single' => 1]),
    'expensive' => card(['ukid' => 'expensive', 'power' => 4, 'price' => 7]),
    'cheap' => card(['ukid' => 'cheap', 'power' => 3, 'price' => 3]),
    'syn_a' => card(['ukid' => 'syn_a', 'power' => 3]),
    'syn_b' => card(['ukid' => 'syn_b', 'power' => 3]),
    'ping' => card([
        'ukid' => 'ping',
        'power' => 3,
        'prop' => ['actions' => [['type' => 'shot']]],
    ]),
    'no_ping' => card(['ukid' => 'no_ping', 'power' => 3]),
];
for ($i = 1; $i <= 8; $i++) {
    $ukid = 'ping_' . $i;
    $cards[$ukid] = card([
        'ukid' => $ukid,
        'power' => 3,
        'prop' => ['actions' => [['type' => 'shot']]],
    ]);
}

$evaluator = new DraftSelectionEvaluator($cards);

assertTrue(
    $evaluator->evaluate([], ['p5']) > $evaluator->evaluate([], ['p2']),
    'Higher power should increase score.'
);

assertTrue(
    $evaluator->evaluate([], ['mira']) > $evaluator->evaluate(['mira', 'mira', 'mira', 'mira', 'mira'], ['mira']),
    'Pinger deficit should matter more than adding another pinger to a saturated draft.'
);

assertTrue(
    $evaluator->evaluate([], ['mira']) > $evaluator->evaluate([], ['p2']),
    'Power 2 pinger should gain meaningful value when pingers are missing.'
);

assertTrue(
    $evaluator->evaluate(['fly', 'fly', 'fly', 'fly', 'fly'], ['fly'])
        < $evaluator->evaluate([], ['fly']),
    'Flyer saturation should reduce later flyer score.'
);

assertTrue(
    $evaluator->evaluate(['single'], ['single']) < $evaluator->evaluate([], ['single']),
    'Duplicate single cards should receive a soft penalty.'
);

assertTrue(
    $evaluator->evaluate(['expensive', 'expensive', 'expensive'], ['expensive'])
        < $evaluator->evaluate(['cheap', 'cheap', 'cheap'], ['expensive']),
    'Expensive cards should become less attractive when average cost is already high.'
);

assertTrue(
    $evaluator->evaluate([], ['mira', 'front', 'healer'])
        === $evaluator->evaluate([], ['healer', 'mira', 'front']),
    'Candidate order should not affect score.'
);

$triplePinger = $evaluator->evaluate([], ['mira', 'mira', 'mira']);
$singlePinger = $evaluator->evaluate([], ['mira']);
assertTrue(
    $triplePinger < ($singlePinger * 3),
    'Three pingers should not get three full independent deficit bonuses.'
);

$synergyPair = "syn_a\0syn_b";
$withSynergy = new DraftSelectionEvaluator($cards, [
    $synergyPair => ['draft' => 8],
]);

assertTrue(
    $withSynergy->evaluate(['syn_a'], ['syn_b']) > $evaluator->evaluate(['syn_a'], ['syn_b']),
    'Existing synergy should increase score.'
);

$standardSynergy = new DraftSelectionEvaluator($cards, [$synergyPair => ['standard' => 1]]);
$sealedSynergy = new DraftSelectionEvaluator($cards, [$synergyPair => ['sealed' => 1]]);
$draftSynergy = new DraftSelectionEvaluator($cards, [$synergyPair => ['draft' => 1]]);
assertTrue(
    $standardSynergy->evaluate(['syn_a'], ['syn_b']) > $sealedSynergy->evaluate(['syn_a'], ['syn_b']),
    'Standard synergy should have more weight than sealed at the same count.'
);
assertTrue(
    $sealedSynergy->evaluate(['syn_a'], ['syn_b']) > $draftSynergy->evaluate(['syn_a'], ['syn_b']),
    'Sealed synergy should have more weight than draft at the same count.'
);

$mappedSynergy = DraftSelectionDataProvider::buildSynergyByPair([
    's1_1' => card(['ind' => 1, 'ukid' => 's1_1']),
    's1_2' => card(['ind' => 2, 'ukid' => 's1_2']),
], [
    ['deck_id' => 10, 'type' => 'standard', 'card_id' => 1],
    ['deck_id' => 10, 'type' => 'standard', 'card_id' => 2],
]);
assertTrue(isset($mappedSynergy["s1_1\0s1_2"]['standard']), 'Synergy pairs should be keyed by ukid after ind mapping.');
assertTrue(!isset($mappedSynergy['1' . "\0" . '2']), 'Synergy pairs must not be keyed by numeric card_id strings.');

$fivePingers = ['ping_1', 'ping_2', 'ping_3', 'ping_4', 'ping_5'];
assertTrue(
    $evaluator->evaluate($fivePingers, ['ping']) > $evaluator->evaluate($fivePingers, ['no_ping']),
    'The sixth pinger should still receive role benefit before target 8.'
);

$eightPingers = ['ping_1', 'ping_2', 'ping_3', 'ping_4', 'ping_5', 'ping_6', 'ping_7', 'ping_8'];
assertTrue(
    $evaluator->evaluate($eightPingers, ['ping']) === $evaluator->evaluate($eightPingers, ['no_ping']),
    'An extra pinger at target 8 should not receive more deficit bonus than a matching non-pinger.'
);

assertTrue(
    $evaluator->weight([], ['p5']) > $evaluator->weight([], ['p2']),
    'Weights should preserve score ordering.'
);

$picker = new DraftAutoPicker($evaluator);
$selections = [
    ['positions' => [0, 1, 2], 'cards' => ['p5', 'p5', 'p5']],
    ['positions' => [3, 4, 5], 'cards' => ['p2', 'p2', 'p2']],
    ['positions' => [6, 7, 8], 'cards' => ['p2', 'p2', 'p2']],
];
$strongRow = 0;
$weakRow = 0;
$otherSelections = 0;
for ($i = 0; $i < 800; $i++) {
    $selection = $picker->pick($selections, []);
    if (($selection['positions'] ?? []) === [0, 1, 2]) {
        $strongRow++;
    } elseif (($selection['positions'] ?? []) === [3, 4, 5]) {
        $weakRow++;
    } else {
        $otherSelections++;
    }
}

assertTrue($strongRow > $weakRow, 'Weighted random should prefer the stronger row over many rolls.');
assertTrue($weakRow > 0 || $otherSelections > 0, 'Weighted random should not always choose only the best row.');

echo "Draft selection evaluator tests passed.\n";
