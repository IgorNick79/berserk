# Set 2 / Stage 2: artifact foundation

Branch: feature/set2-terrain, based on a6b4810. No main merge, live DB updates, new cards or abilities.

## Audit and reuse
CardInstance already preserves arbitrary type and prop in serialization. No new type registry or zone is required: artifacts use field. ZoneManager and PrepareProcessor already provide ordinary ground placement. DamageResolver already handles HP, wounds, dying, and deferred removal with finalizeDying. No damage/death changes were needed. DamageResolver::hasCreaturesOnField counts only creature/fly, so artifacts already cannot prevent defeat.

Existing BattleScreen/LiveBattleCard/PreGameCard render field cards and descriptions. Standard ActionResolver accepts field sources without requiring creature type, allowing own abilities. Creature-specific examples were checked: heal and memory/prophecy-wound targets explicitly require creature/fly in BattleHelper and ActionResolver; enemy_open_creature instant selection also checks those types. Existing card-target attack paths can target artifacts. No blanket target filter was added, and creature predicates were not broadened to include artifact.

## Minimal changes
- CardStats::hasAnyStrike excludes artifact before copied/numeric strike properties.
- BattleHelper::abilities hides UCHR for artifact; getMoveCells returns no basic movement destinations.
- MovementResolver::move rejects artifact on the server. Special/forced movement paths and placement are not disabled, leaving room for approved future rules without implementing artifact_movable.
- StrikeResolver::declare and declareForcedAdjacent reject artifact basic strikes, including order paths that bypass normal strike checks.
- ActionResolver::uchr rejects artifact, including delegation from cmd=action.
- BattleScreen and PreGameCard keep HP and ability information while omitting basic movement/strike stats for artifacts. Existing ability controls are retained.

## Tests
- tools/test_artifact.php: 27 assertions, plus reused 186 LuckTest assertions. Covers ground placement, HP and wounds, dying/finalization, server move/strike/UCHR rejection without action consumption, forced-strike bypass, cmd=action UCHR bypass, own standard heal ability, creature-only heal rejection, artifact as a target of ordinary strike, rendering/selection/HP/own ability controls, roundtrip, elimination and first-set movement/strike.
- Terrain: 58 checks; terrain history: 11 checks; set-2 availability/artwork: 79 checks passed.
- Damage lifecycle regression passed; flying preparation: 22 checks passed.
- Changed PHP lint and git diff --check passed.
- Tests use process-local fixtures, not the live DB. No browser testing was performed. The two tests creating a temporary auth configuration must run sequentially; the history suite passed after sequential rerun.

## Scope limits
No concrete artifact cards, new effects, third-set movement, artifact_movable, companions, symbiotes or incorporeal rules. Existing effect-specific targeting remains authoritative; this is not an exhaustive certification of every first-set ability against future artifact cards. Such interactions require tests when the specific artifact/effect is introduced. Set 2 remains blocked outside explicit authorized Debug LuckTest entries. Terrain support is retained.

## Files
src/Core/ActionResolver.php; src/Core/BattleHelper.php; src/Core/CardStats.php;
src/Core/Movement/MovementResolver.php; src/Core/StrikeResolver.php;
src/View/Screen/BattleScreen.php; src/View/Ui/PreGameCard.php;
tools/test_artifact.php; docs/set2-artifact-stage2.md.

## Bot settings error reporting follow-up
The submitted Debug JSON for s2_24 contained a trailing comma after the card object. Strict server parsing correctly rejects it; no lenient parser or silent input repair was added. The corrected JSON is {"cards":[{"owner":"host","ukid":"s2_24"}]}.
The bot settings catch handler previously wrote errors to the bottom of the form. It now uses existing BerserkDialogs.alert, as do the wizard create/invite/withdraw error handlers. The lobby script cache version is bumped. No new dialog component or styles.
tools/test_lobby_wizard_alerts.cjs exercises the actual bot submit handler with a mock DOM/fetch: server/network alert, unchanged JSON, no inline error, retry, successful redirect and duplicate-submit guard. tools/test_artifact_debug.php passes 7 checks (plus 186 reused LuckTest checks), reproducing trailing-comma rejection and corrected s2_24 preparation with Recorder and a synthetic catalog. No full browser or live bot-match/DB test was performed.

## Bot ACK after receiving flight
The Lord of Skies action creates strike.kind=become_fly with a result ACK. LegalActions previously excluded that kind, causing the existing bot planner to stop. Added become_fly to the existing confirmed-result whitelist; no new ability activation strategy or combat behavior. tools/test_bot_become_fly.php reproduced the failure before the fix and passes 22 checks afterward: actual action, both confirmation orders, reload, planner/legal/Engine path, stale and duplicate ACKs, preservation of flight and action payment, and retained blocking for unknown kinds/pending wounds. Dynamic flying lifecycle tests pass.
