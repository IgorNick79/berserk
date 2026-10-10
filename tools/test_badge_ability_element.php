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

$conditionalWithoutState = Badge::forCard(badgeCard([
    'ability' => ['value' => 1, 'element' => 'mountains'],
]), null);
badgeAssert(str_contains($conditionalWithoutState, 'marker-conditional-strike'), 'Conditional element ability should render without GameState');
badgeAssert(str_contains($conditionalWithoutState, 'удар +1'), 'Conditional element ability without GameState should keep its value');
badgeAssert(!str_contains($conditionalWithoutState, 'marker-strike'), 'Conditional element ability without GameState should not create a permanent strike badge');
badgeAssert(!str_contains($conditionalWithoutState, 'удар -1'), 'Conditional element ability without GameState should not create a false negative strike badge');

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

$mixedWithoutState = Badge::forCard(badgeCard([
    'ability' => ['value' => 1, 'element' => 'mountains'],
], [
    ['stat' => 'ability_strike', 'value' => 3],
]), null);
badgeAssert(str_contains($mixedWithoutState, 'marker-conditional-strike'), 'Mixed no-state card should keep conditional badge');
badgeAssert(str_contains($mixedWithoutState, 'marker-strike'), 'Mixed no-state card should keep applied strike modifier');
badgeAssert(str_contains($mixedWithoutState, 'удар +3'), 'Mixed no-state card should not subtract conditional prop from applied modifier');
badgeAssert(!str_contains($mixedWithoutState, 'удар +2'), 'Mixed no-state card should not turn +3 modifier into +2');

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

$levelRestricted = Badge::forCard(badgeCard([
    'ability' => ['value' => 1, 'element' => 'mountains', 'level' => ['strong']],
]), $state);
badgeAssert(!str_contains($levelRestricted, 'marker-strike'), 'Level-restricted element ability should not render as permanent strike');
badgeAssert(!str_contains($levelRestricted, 'marker-conditional-strike'), 'Level-restricted element ability needs level-specific UX before rendering as conditional badge');

$mixedLevelRestricted = Badge::forCard(badgeCard([
    'ability' => [
        ['value' => 1, 'element' => 'mountains', 'level' => ['strong']],
        ['value' => 2],
    ],
]), $state);
badgeAssert(str_contains($mixedLevelRestricted, 'marker-strike'), 'Mixed level-restricted card should keep unconditional strike badge');
badgeAssert(str_contains($mixedLevelRestricted, 'удар +2'), 'Mixed level-restricted card should not include level-restricted value in permanent badge');
badgeAssert(!str_contains($mixedLevelRestricted, 'marker-conditional-strike'), 'Mixed level-restricted card should not render incomplete conditional badge');

echo "Badge ability element tests passed\n";
