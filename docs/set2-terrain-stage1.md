# Set 2 / Stage 1: terrain foundation

Base: origin/main 79c783e. Implementation isolated in feature/set2-terrain; the dirty OSPanel worktree is unchanged.

## Audit
- The current main LuckTest catalog already selects all cards (PrepareProcessor::startLuckTest); the older OSPanel branch still filters release_id=1. No global catalog or booster filter was changed.
- LuckTestScenario resolves ukid against that catalog, without a set prefix whitelist. Unknown IDs fail; type is retained by DraftDeckBuilder and CardInstance. Synthetic s2 fixtures exercise this path. Live DB contents were not queried or changed.
- The checked-in cards schema uses release_id, VARCHAR type and nullable health (tmp/cards.sql). No schema migration is required for this representation; live schema was not inspected.
- Existing terrain support was a side_zones.tpl visual placeholder and a SquadRules TODO. There was no terrain zone constant or transition.
- DamageResolver::hasCreaturesOnField already counts only creature/fly. Terrain and future artifacts already cannot prevent elimination. This logic was preserved.

## Implementation
- CardInstance: terrain zone, normalize creature-only health/move/basic-strike fields to zero for compatible numeric storage. Zero is absence of health here, not a damage budget; no invulnerability property is introduced.
- ZoneManager: one-owner terrain slot, no row/column; terrain cannot enter a field cell.
- SquadRules: one terrain across squad/deployed cards, reused by recruitment and preparation; existing unique/flying rules retained.
- PrepareProcessor: terrain automatically enters its zone at placement entry (ordinary recruitment and LuckTest), with confirmation fallback for older preparation snapshots. It does not consume a field cell. LuckTest validation accepts it.
- LuckTestGenerator: enforces terrain limit for fixed entries, initial generation and later budget adjustments. Reserve/deck contents are not restricted to one terrain; the restriction applies to the army.
- LuckTestScenario rejects terrain HP/field coordinates and verifies its dedicated zone.
- DamageResolver ignores damage to terrain; CardStats does not advertise a basic strike.
- BattleScreen and existing side_zones template show a selectable name in the existing terrain cell, with the existing card information panel. No extra visual zone or styles.
- PlaceScreen displays terrain using the existing miniature and information components; it is not draggable, not counted among unplaced field cards, and has no field placement options.
- LiveBattleCard/PreGameCard and battle information omit terrain HP/movement/basic-strike statistics.
- No cards, ability definitions, DB changes, new combat handlers, JS or CSS.

## Validation
- tools/test_terrain.php: isolated in-memory catalog adapter, set-2 IDs, fixed terrain, generator limits, normal recruitment, placement/Quick Game, duplicate rejection without mutation, damage, movement/strike rejection, slot/panel/AJAX snapshot, state roundtrip and elimination (including future artifact).
- Includes existing tools/test_lucktest.php: 186 checks passed.
- New terrain checks: 58 passed in the recorded run (count varies with randomly generated terrain occurrences).
- tools/test_damage_lifecycle.php passed.
- tools/test_flying_preparation.php: 22 checks passed.
- PHP lint for all changed PHP files and git diff --check passed.
- tools/test_lucktest_manual.php and test_lucktest_quick.php stop on the existing assertion at test_lucktest_manual.php:116, which expects the obsolete prepare-card-link--squad class. Replacing PlaceScreen with the unmodified origin/main version reproduces the same failure. These suites are not claimed as passed.
- No browser test, live HTTP command, live DB modification or deployment was performed.

## Deferred rules
No concrete terrain cards or new abilities are implemented. Specific ability execution, removal, replacement, revival, control changes, and interactions with special effects require individual approved rules/cards. Existing ability descriptions are displayed through the existing information components; a terrain-specific ability engine was not introduced.

## Changed files
src/Core/CardInstance.php; src/Core/CardStats.php; src/Core/DamageResolver.php;
src/Core/Prepare/LuckTestGenerator.php; src/Core/Prepare/LuckTestScenario.php;
src/Core/Prepare/PrepareProcessor.php; src/Core/Prepare/SquadRules.php;
src/Core/ZoneManager.php; src/View/Screen/BattleScreen.php;
src/View/Screen/PlaceScreen.php; src/View/Ui/LiveBattleCard.php;
src/View/Ui/PreGameCard.php; templates/includes/battle/side_zones.tpl;
tools/test_terrain.php; docs/set2-terrain-stage1.md.
