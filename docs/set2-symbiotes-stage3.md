# Set 2 / Stage 3: symbiote seating and cell presentation

## Base, rules and audit
Branch feature/set2-terrain; accepted artifact/base commit 385584e. No main or working DB changes.
Rules consulted: https://proberserk.ru/rule/185 (211.1–211.11). Ground-host eligibility, occupancy limit, reveal seating, unseated death and linked movement come from that text. The ordering FIRST player then SECOND player is the Project Lead's explicit decision in this chat; the cited section does not specify the inter-player ordering. A player chooses which of several eligible symbiotes to seat next; a host is never automatically selected, even when only one exists.

The local catalog audit found only creature/fly/artifact/terrain type values, with no structured symbiote subtype. Existing deal_linked_recruit companion roles belong to Linnet recruitment and are not symbiote subtypes. They are preserved. New structural metadata is prop.symbiote = companion or parasite on type=creature. No concrete card definitions or live records were changed. The prop.incorporeal flag is checked only for host eligibility (no live catalog entries with it were found); no incorporeal movement, damage or co-occupancy rules were added.

Before Stage 3, BattleScreen already rendered independent data-cell-occupant variants, battle-mini-card.js already switched them and retained instance IDs across AJAX, and cell markers already had badge styling. The old counter was current/total and touch swipe was absent. These existing components were extended rather than replaced.

## Mechanics
SymbioteProcessor owns only reveal seating, the host relationship and lifecycle integration. SymbioteSeatingChoice plugs into ChoiceRegistry, BattleWaitResolver, PanelSpec, CreatureChoice::confirmed, CardSelectionSpec and Panel::renderForm. choose_symbiote_host is a mandatory-choice command, not a separate combat action. There is no cancel action.

A symbiote starts in its own normal field cell. In the initial reveal boundary all symbiotes are revealed, including hidden-row ones, and the normal first-turn continuation pauses. Eligible hosts are living field creatures, excluding flyers, artifacts, terrain, other symbiotes and hosts with another live symbiote. Companion ownership and parasite opponent/front-row-or-invaded-half restrictions are checked on the server. Corporeal followers cannot use incorporeal hosts. Choice IDs, side, source instance, current eligibility and occupancy are revalidated. When several sources exist the player first chooses the source, then the host; a single source still requires explicit host confirmation.

The link is flags.symbiote_host_id on the individual instance. Normal GameState serialization preserves it and pending state. Owner, HP, abilities and IDs remain independent; attachment does not transfer control. After both players finish, unassigned symbiotes die through Engine::forceDeath/finalizeDying, then the same initial turn/history/start-effect path continues.

ZoneManager transitions, DamageResolver death and the normal Engine completion hook check invalid hosts and destroy followers through the existing death path. ZoneManager follows coordinates; MovementEffectResolver also applies the native forced-position-change effects to the follower, without spending its ordinary movement. Move/jump/forced-movement and generic target-teleport entry points reject separate symbiote movement. Existing rooted follower restrictions block host movement. No alternate movement/death engine was created. Flight-zone rendering, slot allocation and first-set recruitment linkage are preserved.

## UI and AJAX
The existing host/symbiote pair is ordered host-first only for visual display; unrelated cards retain instance-based ordering and do not acquire a gameplay main-card flag. N occupants produce a badge N before existing cell indicators/markers; zero/one produces no badge or arrows. Desktop arrows flank the card and cycle the existing variants. The counter is no longer current/total or +N.

At tablet widths arrows are hidden. Touch horizontal swipes use the existing cell variants, suppress the synthesized click, and issue no command. Vertical touch stays native via pan-y. The standard card links still address the displayed instance. Existing per-cell presentation state is retained by ID across fragment reorder, moved stacks and deletion. Right-panel selection remains the existing sel/inspect path. Strike/UCHR target selection is unchanged.

## Validation
- tools/test_symbiotes.php: 48 in-memory checks: mandatory single/multiple source/host selection, first/second side ordering, stale/opponent/repeated commands, serialization, occupancy limit, eligibility, parasite geometry, no-host death, ordinary/forced lifecycle, rooted follower, movement effects, independent ownership, right panel, total count and initial history continuation.
- tools/test_symbiote_cells.cjs: real headless Edge at 1600 and 800 px, with isolated rendered fixtures and mocked artwork/fragment updates. Checks cyclic arrows, exact displayed-instance link, total N, single-card absence, AJAX reorder/moved-ID/removal, horizontal CDP touch swipe, vertical gesture, no switching commands and no browser JS errors. It does not exercise a live server or real match.
- Screenshots inspected: C:/Users/mi/Documents/Berserk/stage3-review/cells-1600.png and cells-800.png (synthetic artwork fixtures).
- Existing regressions pass: dynamic flying lifecycle, damage lifecycle, artifact 27, terrain 58, terrain history 11, set-2 availability/artwork 79, bot flight ACK 22, flying preparation 22, lobby alert JS; fixture suites also run the 186 LuckTest assertions.
- Changed PHP lint, JS syntax and git diff --check pass.

## Scope and limits
No concrete symbiote cards, card-specific abilities, host switching, new control mechanics, corporeality engine, new flight system or DB migration. This stage does not certify every future card-specific movement restriction or ability interaction. Resurrection/summoning symbiotes onto a host and attacks between cards sharing a host require their own approved card/effect integration; no new combat-target exception is silently introduced here. Bot strategy for selecting symbiote hosts is not added. The initial seat choice is manual through the existing mandatory-choice UI. Set 2 remains blocked outside explicit authorized Debug LuckTest entries.

## Changed files
src/Core/SymbioteProcessor.php; src/Core/Choice/SymbioteSeatingChoice.php;
src/Core/Choice/ChoiceRegistry.php; src/Core/BattleWaitResolver.php;
src/Core/TurnProcessor.php; src/Core/Engine.php; src/Core/ZoneManager.php;
src/Core/DamageResolver.php; src/Core/BattleHelper.php; src/Core/ActionResolver.php;
src/Core/Movement/MovementResolver.php; src/Core/Movement/ForcedMovementResolver.php;
src/Core/Movement/MovementEffectResolver.php; src/View/Screen/BattleScreen.php;
www/assets/js/battle-mini-card.js; www/assets/css/battle-mini-card.css;
templates/screen/battle.tpl; tools/test_symbiotes.php; tools/test_symbiote_cells.cjs;
docs/set2-symbiotes-stage3.md.
