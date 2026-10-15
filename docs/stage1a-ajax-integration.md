# Stage 1A integration with AJAX — 2026-10-15

## Baseline and isolation

- Local main: 327ee42bc474ab04d48d3c0fb0b2238a9f6c07e4 (AJAX merge).
- Stage 1A: b3b95e22c3c13d857db42f5d9da8639e2b8ee754. Already committed; exactly four agreed files, no additional uncommitted Stage 1A changes found.
- feature/bulbagur: 9924c5ac2cfa64b618a6208bc7d70ef487ce3d34, initially clean. Its checkout and branch reference are unchanged.
- Integration uses a separate worktree at C:/Users/mi/Documents/Berserk/stage1a-integration, checking out main. No push.
- Existing audit-stage worktree was clean. Unrelated audit-greed worktree had modifications to DamageResolver and four tests; left untouched.

## Integration and conflict resolution

Non-fast-forward merge of codex/battle-layout-stage-1a into main. Two conflicts resolved semantically:

- templates/screen/battle.tpl: combine battle-shell and battle-sync-root on the same root; preserve all six unique data-battle-fragment containers inside that root. Keep selected card in its original separate column. Place pile reveal and existing debug inside the left scrollport. Burger remains disabled.
- www/index.php: retain scriptsHtml from AJAX and add battle-only page_class from Stage 1A.

Other merged files: templates/page.tpl and www/assets/css/global.css. Scope remains battle-page, with 100vh fallback and 100dvh. AJAX notices are absolutely positioned within the shell so an error or command-in-progress message cannot create an extra grid item and displace the two columns.

Added tools/test_battle_stage1a_ajax_layout.php to check the combined DOM contract for both viewers, the separate selected-card panel, retained scripts, and nonbattle page isolation. This report is the sixth changed file.

Core, GameState, pending, client controllers, AJAX protocol and command routes are unchanged. No Stage 1B work.

## Automated verification

Passed PHP syntax checks for www/index.php and the new PHP test. Passed JS syntax checks for battle-sync.js and cell-marker-picker.js (source piped to node --check to avoid the sandbox's Node realpath EPERM).

Passed existing PHP scenarios:

- battle_snapshot_renderer
- battle_coordinates_ui
- battle_result_coordinates_ui
- battle_defender_ui
- battle_auto_target_ui
- battle_death_choice_ui
- battle_redirect_ui
- battle_ally_modifier_ui
- battle_card_visual_ui
- battle_cancel_action_ui
- cell_marker_cancel

Passed new battle_stage1a_ajax_layout regression test. git diff --check passed.

battle_sync_endpoint and battle_command_endpoint tests explicitly skipped because the isolated worktree has no config/db.php. No production configuration was copied and no database games were accessed or modified.

## Browser verification at 1600 x 700

Used a temporary PHP session fixture in this isolated worktree, synthetic game -1919191919, genuine BattleScreen/BattleSnapshotRenderer, merged page templates, unchanged battle-sync.js/cell-marker-picker.js, and real Engine commands. The fixture supplies the existing snapshot/command response shape over HTTP without database persistence. It is not a test of www/index.php's DB-backed endpoint or command journal.

Both viewers: AJAX action opens Krondak pending, compact picker initializes, selection creates the fire marker, closes the source card and shows the result. Host additionally: side/row switches, cancellation, reopening; own confirmation changes to waiting and passive viewer can confirm. Selected-card AJAX navigation changes panel contents. AJAX move changes card position from system row 2 to 3 and Move from 1/1 to 0/1.

Both viewers without scripts: 28 fallback links remain usable and selection produces an actual Engine result. Selected card remains outside InfoPanel, pending/result remains visible in InfoPanel. No trial-click claims.

Measurements (CSS px):

| Element | scrollWidth | clientWidth | scrollHeight | clientHeight |
| --- | ---: | ---: | ---: | ---: |
| html | 1600 | 1600 | 700 | 700 |
| body | 1600 | 1600 | 700 | 700 |

These document dimensions remained unchanged during pending/results, commands and fallback. Region rectangles:

| Region | x | y | width | height |
| --- | ---: | ---: | ---: | ---: |
| shell | 0 | 0 | 1600 | 700 |
| left scrollport | 48 | 8 | 852 | 684 |
| selected-card panel | 912 | 8 | 320 | 684 |
| InfoPanel | 1244 | 8 | 348 | 684 |
| battlefield | 70 | 8 | 588 | 670 |

Long InfoPanel: clientHeight 682, scrollHeight 4798, overflow auto. Fallback keyboard focus moved InfoPanel scrollTop to 981 while document scrollTop remained 0. AJAX error/recovery notices during fixture setup also left document dimensions unchanged.

## Limits and review status

- DB-backed www/index.php, command journal and stale-response races were not exercised end-to-end; DB endpoint suites skipped. Client AJAX code and protocol are unchanged, and the six-fragment contract is tested.
- Nonbattle isolation is checked at template/class level; preparation and game-over were not browser-tested with real DB state.
- Card image files are ignored assets absent from the new worktree. Placeholder/name/stat/action rendering was exercised; production artwork was not visually checked.
- No new mobile redesign, Debug Overlay, permanent Header/Footer or selected-card transfer.

Temporary server was stopped and test session/log files removed before commit. The resulting merge commit records both parent SHAs and this report. Final main worktree is clean; feature/bulbagur remains unchanged. Ready for code review with the verification limits above; not a claim of Project Lead acceptance.
