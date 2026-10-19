# Match menu and per-player sound

Branch: `codex/battle-menu-sound`. No migrations or new dependencies.

## Storage and command

The existing player objects in match JSON now serialize a boolean:

```json
{"players":{"host":{"sound_enabled":true},"player":{"sound_enabled":false}}}
```

Other existing player fields are unchanged. Missing or malformed legacy values deserialize as true. Each new match starts enabled for both players. Public `player_settings: {sound_enabled: boolean}` contains only the current viewer's setting.

`set_sound_enabled` accepts an explicit boolean (HTTP `0` / `1`, also `true` / `false`). The server uses its existing request actor; payload `player_key` cannot change the target. It uses the existing journal identity, payload matching, transaction locks, persistence version and CAS. Repeating an ID follows existing journal recovery, never a toggle. A conflict is displayed and followed by the existing authoritative sync; no automatic preference retry is added.

Engine dispatches this command before clock reads, pause/gameplay dispatch, ACK or continuation processing. Only that player's preference and the normal state version can change. The ordinary battle pause guard remains mandatory for game commands.

The preference HTTP response and journal status response skip automatic progress. Subsequent synchronization uses the ordinary battle_sync path and performs normal deadline/ACK/auto-end processing. The former settings_sync_id mechanism was removed from the server; historical, repeated, foreign and unrelated IDs have no effect. The client no longer sends that parameter and strips legacy occurrences from its sync URL. No polling loop or preference retry was added.


## Menu and synchronization

The menu lives outside replaceable battle fragments. Its checkbox is rendered from the audio controller's authoritative setting; while a command is pending it shows Saving and disables changes. Initial HTML, snapshots, no_change and journal recovery supply the same viewer-only setting. Versions older than the last settings sample are ignored. Refresh and reconnect read persisted state; there is no localStorage source of truth.

The menu supports its toggle button, outside pointer, Escape with focus restoration, arrows, Home/End and Tab. Soon items are disabled. Debug reuses the existing server-provided overlay and debug=1 link, including focus preservation after AJAX replacement. During requested/active pause Debug is disabled and its overlay remains hidden; the sound setting stays available above the opaque mask. Without JavaScript the menu button remains disabled.

## Existing audio owner

`battle-turn-timer.js` owns the only sound flag. Both ordinary turn notifications and server-confirmed pause-resume notifications already call its notification function. Its file playback and two-tone fallback both check this flag. Muting also pauses an existing audio file; each actual setting change invalidates unfinished audio attempts. A late rejected play promise can invoke fallback only in the same settings generation and for the latest notification attempt, so mute/unmute and a newer notification cannot revive an old fallback. Event/period deduplication continues even when muted, so enabling does not replay missed events. No assets, audio manager or resume lifecycle changes were added.

Source scan found no other production game audio sources. Browser gesture/autoplay rules remain unchanged. Browser/OS mute and autoplay restrictions can still prevent otherwise enabled sound; errors remain best effort and cannot stop sync. Tabs of the same player converge via existing polling, so another tab may use the previous preference until its next server sample.

## Identity limitation

The prototype identifies players through its existing first/second request context and fixed participant IDs. This task does not turn that model into authenticated accounts. The setting cannot target an opponent through payload fields, but the pre-existing ability to select another role through that prototype context remains an application-level authorization limitation.

## Regression entry points

- `tools/test_match_player_settings.php`: defaults, legacy serialization, sender isolation, pure dispatch, paused game-command guard and projection.
- `tools/test_match_player_settings_http.php`: inherits exact disposable source/datadir/loopback/InnoDB guards; journal replay, CAS, all pause modes, full HTML/no_change/snapshot/status and purity of preference POST/status followed by ordinary deadline progress.
- `tools/test_match_player_settings_client.cjs`: shared audio mute, file/fallback/autoplay, stale versions, no catch-up, independent clients, actual resume flow and lost-response journal recovery.
- `tools/test_battle_menu_sound_browser.cjs`: guards disposable DB and verifies HTTP source before writes; 1600/800/375 px, keyboard/outside/Escape, authoritative checkbox, refresh, Debug replacement focus and both paused states.

Integration entry points must run only in the disposable source copy with database `berserk_turn_timer_t2_test` and verified datadir. Never run against the working application's DB configuration.

## Implemented files

| File | Purpose |
| --- | --- |
| `src/Core/PlayerState.php` | Per-match player JSON field and legacy default. |
| `src/Core/MatchPlayerSettings.php` | Explicit sender-only validation, application and projection. |
| `src/Core/Engine.php` | Dispatch settings before clocks and combat processing. |
| `src/View/Screen/BattleScreen.php` | Authoritative initial checkbox and existing Debug URL. |
| `src/View/Screen/BattleSnapshotRenderer.php` | Viewer-only settings projection. |
| `www/index.php` | Boot/no_change/snapshot/status settings; pure preference response and ordinary subsequent sync. |
| `templates/screen/battle.tpl` | Permanent accessible dropdown with disabled placeholders. |
| `www/assets/css/global.css` | Menu placement, narrow sizing, focus and disabled styles. |
| `www/assets/js/battle-menu.js` | Menu, confirmed checkbox, keyboard/focus, existing Debug overlay. |
| `www/assets/js/battle-ui.js` | Moves only old burger/Debug ownership into the menu controller. |
| `www/assets/js/battle-match-pause.js` | Keeps menu available while retaining Debug/board blocking. |
| `www/assets/js/battle-sync.js` | Settings samples, menu lifecycle and existing command recovery. |
| `www/assets/js/battle-turn-timer.js` | Single mute flag for all existing audio paths. |
| `tools/test_match_player_settings.php` | 55 DB-free assertions. |
| `tools/test_match_player_settings_http.php` | 49 guarded HTTP/InnoDB assertions. |
| `tools/test_match_player_settings_client.cjs` | 36 audio/recovery client assertions. |
| `tools/test_battle_menu_sound_browser.cjs` | 67 real-browser menu checks across three widths. |
| `tools/test_match_pause_fault.php` | Adds preference lost-COMMIT/read-failure child mode, without runtime fault hooks. |
| `tools/test_turn_timer_client.js` | Adds optional settings boot fixture to existing client harness. |
| `docs/battle-menu-sound.md` | Storage, behavior, constraints and test report. |

## Verification results

New suites: 55 unit, 49 HTTP/InnoDB, 36 client audio/recovery, 67 browser checks passed.

Existing suites passed: Match Pause unit 119, server UI 18, countdown 44, shared resume sound 84, HTTP/InnoDB 45 and Stage 1.1 42 additional, HTML resign journal 51, two-player pause browser 109, UI polish at 1600/800/375, turn timer client, turn timer unit, informational wait unit, auto-end unit, guarded timer/ACK/auto-end HTTP/concurrency/unknown-outcome regressions, command endpoint and snapshot renderer. PHP lint and Node syntax checks passed for all changed PHP/JS files.

`test_battle_stage1b_ui.php` stops at its pre-existing `One visible resign control` assertion (line 37). The same failure was reproduced on an untouched archive of baseline main `390ea68`. Its DOM-only count includes the existing noscript surrender control. That older suite was not changed or reported as passing. Actual JS/no-JS surrender coverage passes in the existing pause browser/HTML suites.

All integration writes ran in the verified disposable source tree and MySQL/InnoDB datadir on loopback ports 33387/33388, never through the working DB configuration. No application DB schema or configuration changes. No commit, push, merge or deployment.

## Final review corrections (P2 / P3)

P2: removed the server's settings_sync_id branch altogether. An ordinary battle_sync always follows normal automatic progress. The settings POST and its journal-status read remain pure, including CAS conflicts and unknown-outcome recovery. The subsequent forced snapshot is an ordinary synchronization and can process due events. No TTL, recovery token, new server state or retry policy is needed.

P3: each actual accepted sound-value change increments soundGeneration. Each notification increments audioAttempt. A delayed fallback carries both identifiers and validates them immediately before starting the tones; only the current generation and latest attempt can play. Duplicate unchanged settings samples do not cancel a valid notification. Existing turn/resume event deduplication is unchanged.

Files touched by these corrections (all prior uncommitted feature work retained):

- www/index.php: remove sync bypass branch.
- www/assets/js/battle-sync.js: stop sending settings_sync_id; strip legacy occurrences.
- www/assets/js/battle-turn-timer.js: invalidate stale/concurrent audio attempts.
- tools/test_match_player_settings_http.php: expect normal sync to handle due deadlines.
- tools/test_match_player_settings_progress_http.php: NEW regression matrix for historical own/opponent/different-type/missing IDs, repeated sync, requested/active deadlines, auto-ACK, auto-end and CAS.
- tools/test_match_player_settings_fallback.cjs: NEW deferred-promise regression for current/stale/muted/concurrent fallbacks.
- tools/test_turn_timer_client.js: expose requested URLs in the existing test harness.
- tools/test_match_player_settings_client.cjs: ensure journal recovery sends ordinary sync without bypass parameters.
- docs/battle-menu-sound.md: update behavior and verification report.

New regressions: 66 guarded HTTP/InnoDB checks and 16 deferred-fallback checks passed. Settings suites: 55 unit, 49 HTTP/InnoDB, 36 client and 67 browser checks passed. Existing Match Pause unit 119, UI 18, countdown 44, resume sound 84, HTTP/race 45, Stage 1.1 recovery 42, turn timer unit/client and ACK/auto-end/command endpoint suites passed, including unknown COMMIT outcomes. Browser pause/surrender/polish results are recorded after their complete runs below. PHP lint (10 changed PHP files) and Node syntax (9 changed JS files) passed.

The inherited first/second identity model and browser autoplay/OS mute constraints remain. No new authorization system, dependencies, schema/configuration changes or gameplay/pause state-machine changes were made by this correction. No commit, push or merge.

Browser regressions completed: Match Pause 109, HTML resign journal 51, and UI polish at 1600/800/375 px all PASS. Isolated MySQL and HTTP servers stopped; ports verified closed.

Final git diff --check: PASS (empty output).

Git status --short:

```text
 M src/Core/Engine.php
 M src/Core/PlayerState.php
 M src/View/Screen/BattleScreen.php
 M src/View/Screen/BattleSnapshotRenderer.php
 M templates/screen/battle.tpl
 M tools/test_match_pause_fault.php
 M tools/test_turn_timer_client.js
 M www/assets/css/global.css
 M www/assets/js/battle-match-pause.js
 M www/assets/js/battle-sync.js
 M www/assets/js/battle-turn-timer.js
 M www/assets/js/battle-ui.js
 M www/index.php
?? docs/battle-menu-sound.md
?? src/Core/MatchPlayerSettings.php
?? tools/test_battle_menu_sound_browser.cjs
?? tools/test_match_player_settings.php
?? tools/test_match_player_settings_client.cjs
?? tools/test_match_player_settings_fallback.cjs
?? tools/test_match_player_settings_http.php
?? tools/test_match_player_settings_progress_http.php
?? www/assets/js/battle-menu.js
```
