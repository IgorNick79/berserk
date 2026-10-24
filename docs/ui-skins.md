# Battle UI skins

Four account themes: grimoire (default), stone, leather, parchment. The menu and coordinate badges keep their bronze style across themes. End-turn image controls remain unchanged.

## Deployment

Before enabling theme saving, apply `tools/migrations/20261024_account_ui_preferences.sql` **once to the auth database** specified by `config/auth.local.php`, not automatically to the game database. It adds `account_ui_preferences` with an FK to `accounts`; existing account/game data is not rewritten. Runtime code never runs DDL.

`GET /panel-theme.php` loads the authenticated account preference and CSRF token. `POST` accepts only `theme` and `csrf`, derives identity from AuthSession, validates the fixed theme catalog, and uses prepared queries. It never constructs a game, calls Engine, changes a match version or settles a timer. The same account preference is used across matches and browsers.

If the table or connection is unavailable, the battle still uses the default theme. The picker reports the save failure and does not claim persistence. Failed writes leave the displayed theme unchanged.

## Other changes

- Closed mini-cards use the requested transform, muted art and light veil; no hover zoom, including a card closed by a new AJAX snapshot. Tooltips remain available.
- Coordinate badges use a crosshair, Roman row and viewer-relative column. Known creatures use their actual owner for the border. Cell references retain their original field-side meaning. Journal badges format only existing historical coordinate text and never consult current card positions.
- InfoPanel die values show the stored raw roll, a separate signed modifier and total. Zero/no-roll is a dash; values outside 1–6 remain numeric. Engine calculations and journal dice formatting are unchanged.

## Validation

- `php tools/test_ui_skins.php`
- `node tools/test_ui_skins_browser.cjs` (Playwright/Edge; supports SKIN_ARTIFACT_DIR)
- `php tools/test_battle_coordinates_ui.php`
- `php tools/test_battle_result_coordinates_ui.php`
- `php tools/test_battle_defender_ui.php`
- `php tools/test_battle_redirect_ui.php`
- `php tools/test_combat_instant_ui.php`
- `node tools/test_mini_card_stage4_browser.cjs`
- `node tools/test_battle_journal_gap_ui.cjs`
- With a disposable MySQL on a non-default loopback port: set THEME_TEST_MYSQL_PORT and run `php tools/test_account_ui_preferences.php` and `node tools/test_panel_theme_http.cjs`. Each uses a newly named temporary schema and removes only that schema. The HTTP harness copies a minimal isolated site, uses test-only login fixtures and kills its own PHP server.

## Follow-up polish

Closed cards now use 90% scale and a white 30% veil. Both dice layouts, the top timer and pile browser follow the selected theme. The theme picker close button is right-aligned; missing-schema responses explicitly identify the required auth migration.

During battle, chat and journal reuse their live DOM inside InfoPanel, preserving listeners, history and drafts across snapshots. Headers follow the panel theme. A new mandatory event takes priority; an unsent chat draft keeps the chat open and invokes the existing sound notification (respecting account sound settings). Opening an already-present event's chat/log remains possible. Game-over popups keep their existing behavior.
