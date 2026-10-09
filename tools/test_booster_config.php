<?php
// tools/test_booster_config.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\BoosterSettings;
use Berserk\Core\Prepare\BoosterGenerator;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function boosterConfigAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rarityRows(string $rarity, int $count): array
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'ukid' => $rarity . '_' . $i,
            'prop' => [],
        ];
    }
    return $rows;
}

function fakeGenerator(array $config, int $cardsPerRarity = 80): BoosterGenerator
{
    $ref = new ReflectionClass(BoosterGenerator::class);
    /** @var BoosterGenerator $generator */
    $generator = $ref->newInstanceWithoutConstructor();

    $configError = BoosterSettings::validate($config);
    if ($configError !== null) {
        throw new InvalidArgumentException($configError);
    }

    $configProp = $ref->getProperty('config');
    $configProp->setAccessible(true);
    $configProp->setValue($generator, BoosterSettings::normalize($config));

    $cardsProp = $ref->getProperty('cardsByRarity');
    $cardsProp->setAccessible(true);
    $cardsProp->setValue($generator, [
        'common' => rarityRows('common', $cardsPerRarity),
        'uncommon' => rarityRows('uncommon', $cardsPerRarity),
        'rare' => rarityRows('rare', $cardsPerRarity),
        'ultrarare' => rarityRows('ultrarare', $cardsPerRarity),
    ]);

    return $generator;
}

function assertBoosterCounts(array $booster, array $expected): void
{
    boosterConfigAssert(count($booster) === BoosterSettings::BOOSTER_SIZE, 'Booster should contain 12 cards');
    boosterConfigAssert(count($booster) === count(array_unique($booster)), 'Booster should not contain duplicates');

    $actual = ['common' => 0, 'uncommon' => 0, 'rare' => 0, 'ultrarare' => 0];
    foreach ($booster as $ukid) {
        $rarity = explode('_', (string) $ukid, 2)[0];
        $actual[$rarity] = ($actual[$rarity] ?? 0) + 1;
    }

    foreach ($expected as $rarity => $count) {
        boosterConfigAssert(
            ($actual[$rarity] ?? 0) === $count,
            "Expected {$count} {$rarity} cards, got " . ($actual[$rarity] ?? 0)
        );
    }
}

boosterConfigAssert(BoosterSettings::defaults() === [
    'common' => 8,
    'uncommon' => 3,
    'rare_slots' => 1,
    'ultra_rare_chance' => 10,
], 'Default booster settings should be 8-3-1 with 10% ultra rare chance');
boosterConfigAssert(BoosterSettings::validate([
    'common' => 8,
    'uncommon' => 3,
    'rare_slots' => 1,
    'ultra_rare_chance' => str_repeat('9', 100),
]) !== null, 'Huge integer-like rarity chance should be rejected before integer cast');
boosterConfigAssert(BoosterSettings::rareChanceToUltraChance('90') === 10, 'Rare chance should convert to ultra chance');
boosterConfigAssert(BoosterSettings::rareChanceToUltraChance(str_repeat('9', 100)) === null, 'Huge rare chance should be rejected');
boosterConfigAssert(BoosterSettings::rareChanceToUltraChance(-10) === null, 'Negative rare chance should be rejected');
boosterConfigAssert(BoosterSettings::rareChanceToUltraChance(110) === null, 'Rare chance above 100 should be rejected');
boosterConfigAssert(BoosterSettings::rareChanceToUltraChance(95) === null, 'Rare chance must use 10% step');

assertBoosterCounts(fakeGenerator(array_merge(BoosterSettings::defaults(), ['ultra_rare_chance' => 0]))->generate(), [
    'common' => 8,
    'uncommon' => 3,
    'rare' => 1,
    'ultrarare' => 0,
]);
assertBoosterCounts(fakeGenerator(['common' => 12, 'uncommon' => 0, 'rare_slots' => 0, 'ultra_rare_chance' => 0])->generate(), [
    'common' => 12,
    'uncommon' => 0,
    'rare' => 0,
    'ultrarare' => 0,
]);
assertBoosterCounts(fakeGenerator(['common' => 0, 'uncommon' => 12, 'rare_slots' => 0, 'ultra_rare_chance' => 0])->generate(), [
    'common' => 0,
    'uncommon' => 12,
    'rare' => 0,
    'ultrarare' => 0,
]);
assertBoosterCounts(fakeGenerator(['common' => 0, 'uncommon' => 0, 'rare_slots' => 12, 'ultra_rare_chance' => 100])->generate(), [
    'common' => 0,
    'uncommon' => 0,
    'rare' => 0,
    'ultrarare' => 12,
]);
assertBoosterCounts(fakeGenerator(['common' => 0, 'uncommon' => 0, 'rare_slots' => 12, 'ultra_rare_chance' => 0])->generate(), [
    'common' => 0,
    'uncommon' => 0,
    'rare' => 12,
    'ultrarare' => 0,
]);

$poolGenerator = fakeGenerator(['common' => 12, 'uncommon' => 0, 'rare_slots' => 0, 'ultra_rare_chance' => 0], 80);
$pool = $poolGenerator->generatePool(5);
boosterConfigAssert(count($pool) === 60, 'Five configured boosters should produce 60 cards');

$atomicGenerator = fakeGenerator(['common' => 12, 'uncommon' => 0, 'rare_slots' => 0, 'ultra_rare_chance' => 0], 10);
try {
    $atomicGenerator->generate();
    boosterConfigAssert(false, 'Insufficient unique cards should fail');
} catch (RuntimeException $e) {
    boosterConfigAssert(str_contains($e->getMessage(), 'Недостаточно карт'), 'Insufficient cards should report generation failure');
}

echo "Booster config tests passed.\n";
