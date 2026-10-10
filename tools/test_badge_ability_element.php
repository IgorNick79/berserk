<?php
// tools/test_badge_ability_element.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\View\Ui\Badge;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function badgeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function badgeState(): GameState
{
    return new GameState(1, 1, 2);
}

function badgeCard(array $prop, array $modifiers = []): CardInstance
{
    return new CardInstance(
        instanceId: 1,
        ukid: 'test',
        owner: GameState::PLAYER_HOST,
        zone: CardInstance::ZONE_FIELD,
        hp: 5,
        hpMax: 5,
        prop: $prop,
        modifiers: $modifiers,
    );
}

$state = badgeState();

$conditional = Badge::forCard(badgeCard([
    'ability' => ['value' => 1, 'element' => 'mountains'],
]), $state);
badgeAssert(str_contains($conditional, 'marker-conditional-strike'), 'Conditional element ability should use a conditional strike badge');
badgeAssert(str_contains($conditional, 'удар +1'), 'Conditional element ability should show the strike value');
badgeAssert(str_contains($conditional, '/assets/images/element-mountains.png'), 'Conditional element ability should show the element icon');
badgeAssert(str_contains($conditional, 'alt="по горным"'), 'Conditional element ability should label the element icon');
badgeAssert(!str_contains($conditional, 'marker-strike'), 'Conditional element ability should not also render as a permanent strike badge');

$unconditional = Badge::forCard(badgeCard([
    'ability' => ['value' => 2],
]), $state);
badgeAssert(str_contains($unconditional, 'marker-strike'), 'Unconditional ability should keep the permanent strike badge');
badgeAssert(str_contains($unconditional, 'удар +2'), 'Unconditional ability should keep its value');
badgeAssert(!str_contains($unconditional, 'marker-conditional-strike'), 'Unconditional ability should not use conditional strike badge');

$mixed = Badge::forCard(badgeCard([
    'ability' => [
        ['value' => 1, 'element' => 'mountains'],
        ['value' => 2],
    ],
]), $state);
badgeAssert(substr_count($mixed, 'marker marker-conditional-strike') === 1, 'Mixed abilities should render one conditional badge');
badgeAssert(str_contains($mixed, 'marker-strike'), 'Mixed abilities should keep the unconditional strike badge');
badgeAssert(str_contains($mixed, 'удар +2'), 'Mixed abilities should not subtract the unconditional bonus');

$modifier = Badge::forCard(badgeCard([], [
    ['stat' => 'ability_strike', 'value' => 3],
]), $state);
badgeAssert(str_contains($modifier, 'marker-strike'), 'Applied strike modifiers should keep the permanent strike badge');
badgeAssert(str_contains($modifier, 'удар +3'), 'Applied strike modifiers should keep their value');

$unknown = Badge::forCard(badgeCard([
    'ability' => ['value' => 1, 'element' => 'unknown_element'],
]), $state);
badgeAssert(str_contains($unknown, 'по unknown_element'), 'Unknown elements should fall back to visible text');
badgeAssert(!str_contains($unknown, '<img'), 'Unknown elements should not render broken images');

echo "Badge ability element tests passed\n";
