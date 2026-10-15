# Stage 1B: Debug Overlay — focus and DOM observation

## Scope

Base: b466ab103a8dc77678d940539744bf146fa61954, branch codex/battle-layout-stage-1b, initially clean. No merge/push.

Changed files:

- www/assets/js/battle-ui.js — focus lifecycle and scoped MutationObserver.
- tools/test_battle_stage1b_browser.js — browser regressions for replacement focus, outside focus, explicit blur, observer filtering and debug availability.
- docs/stage1b-debug-focus.md — this report.

AJAX protocol, fragment keys, battle-sync.js, InfoPanel structure, Core, GameState, Choice, CSS and command routing are unchanged.

## Confirmed defects and fix

The old controller reapplied overlay visibility after AJAX replacement but did not restore keyboard focus. The new regression fails against the original controller at `Focus restored inside replacement overlay`.

The controller now retains whether focus was inside the current overlay. When an open overlay is detached, the next synchronization focuses the replacement overlay with preventScroll, provided focus has fallen back to the document and has not moved to an outside element. Explicit focus outside the overlay is respected.

Chromium can fire focusout before innerHTML has detached the old node. For focusout without relatedTarget, a microtask checks whether that node remained connected. This distinguishes removal from explicit blur. Escape closes the overlay and focuses the burger with preventScroll.

One MutationObserver watches only direct children of the stable info_panel_html wrapper and the subtree of the current overlay. It reconnects to the new overlay when necessary. Direct wrapper changes unrelated to the overlay are filtered; battlefield/card changes are outside its observation targets. Header child changes no longer trigger synchronization. The test counts aria-expanded writes during unrelated mutations and requires zero.

Repeated script loading is still guarded by data-battle-ui-ready; the guard is set only after the burger and fragment wrapper have been found. Missing debug disables the burger and closes the overlay; restored debug enables it without opening it automatically.

## Browser verification

Actual www/index.php, battle-sync.js, AJAX endpoints and database-backed snapshots were used in a separate application copy with a fresh local MariaDB datadir on 127.0.0.1:33872 and database berserk_stage1b_debug_focus. Real configuration/games/logs were not changed. The browser harness only selects cards and requests snapshots; it sends no game commands.

Red/green verification: the updated browser test failed on the original controller; after installing the fix, all 23 assertions passed for host and all 23 for player. Checks include:

- focus a descendant of the open overlay, replace it via AJAX, restore focus in the new overlay;
- keep focus on the outside burger during another AJAX replacement;
- explicitly blur before a later replacement and ensure focus is not recaptured;
- open state, aria-expanded and one debug instance survive AJAX;
- unrelated field/card/header mutations produce zero debug synchronizations;
- Escape closes and returns focus to the burger;
- loading battle-ui.js again does not duplicate handlers;
- missing/restored debug updates disabled state correctly;
- battlefield size remains 588 x 670 CSS px;
- at 1600 x 700, both html and body have scrollWidth=clientWidth=1600 and scrollHeight=clientHeight=700.

A separate full-page request with debug=0 confirmed disabled=true, aria-expanded=false and no server debug-panel.

## Existing tests

All 20 existing Stage 1B PHP scenarios passed: stage1b_ui (40 view combinations), stage1a_ajax_layout, snapshot_renderer, coordinates_ui, result_coordinates_ui, defender_ui, auto_target_ui, death_choice_ui, redirect_ui, ally_modifier_ui, card_visual_ui, cancel_action_ui, cell_marker_cancel, resign_pending, whip_cancel, forced_directional_movement, turn_instant_stack, combat_instant_ui, battle_sync_endpoint and battle_command_endpoint. The two endpoint suites ran against the isolated DB and were not skipped.

node --check passed for both changed JavaScript files. git diff --check passed. The isolated HTTP server and DB were shut down and temporary data removed after validation.

## Limits and handoff

Browser verification used the built-in Chromium browser; Firefox/Safari were not tested. As required by the existing renderer, the outer info_panel_html wrapper remains stable while its children are replaced. No new fragments or lifecycle protocol were introduced.

Before adding this report, the code/test diff is 80 insertions and 8 deletions across two files. The full change is recorded in the follow-up commit on codex/battle-layout-stage-1b. Final SHA and diff statistics are reported in the handoff message. Working tree is clean after commit. Ready for another code review; no merge/push performed.
