# Fixed flying zones and client pagination

Branch: `codex/fly-zone-pagination`, based on `main` (`53070a0`).

## Implementation

- `src/View/Screen/BattleScreen.php`: preserve the existing `ksort(slot)` order and card/link/target rendering. Three permanent `.fly-slot` containers per side hold page 1 positions 1–3 and page 2 positions 4–6, including empty positions.
- `templates/includes/battle/fly_pagination.tpl`: two accessible button bullets, only when count exceeds three.
- `www/assets/js/battle-ui.js`: closure-local `{own: 0, opp: 0}` state, outside replaced HTML. Observe child replacement of the existing `fly_zones_html` host and reapply visibility and `aria-pressed`. Count <=3 resets that side to page one. Button clicks are consumed before the game handlers. No server state or second snapshot transport.
- `www/assets/css/global.css`: shared 102px cells and 4px gaps inherited from `.battle-layout`; three fixed rows per zone. A spacer matches the existing column header height and field padding. Bullets are absolutely positioned and do not change zone height. Existing battlefield row dimensions remain unchanged.
- `templates/screen/battle.tpl`: bump battle UI script cache version.
- `tools/test_fly_pagination.php`: server rendering regression checks and browser fixture.
- `tools/test_fly_pagination_browser.cjs`: headless browser regression checks against real templates, styles and the existing `applySnapshot` implementation (test exposes this closure function without changing production code).

## Verification

Passed with PHP 8.1:

```
php tools/test_fly_pagination.php
php tools/test_battle_stage1b_ui.php
php tools/test_battle_card_visual_ui.php
php tools/test_battle_stage1a_ajax_layout.php
```

Snapshot renderer tests run as part of the above. JS syntax and `git diff --check` passed.

Browser checks passed in headless Edge at 1600, 800 and 375 px: 0–6 cards; exactly three slots per side; pixel alignment of all six slots with corresponding field rows; unchanged document and board scroll heights; independent sides; snapshot replacement; visible HP update while on page two; one/two cards and empty positions on page two; 4 -> 3 -> 4 reset.

Run browser tests with Playwright available as `playwright` (or `PLAYWRIGHT_MODULE` pointing at it), `PHP_BINARY` pointing at PHP 8.1+, and optional `BROWSER_EXECUTABLE` and `FLY_SCREENSHOT_DIR`:

```
node tools/test_fly_pagination_browser.cjs
```

## Limits and unverified scenarios

No live production game actions, deaths or flight transitions were executed manually. Tests simulate the resulting server snapshots; existing target and command rendering code remains unchanged. Browser checks exercise real snapshot application but do not send engine commands over HTTP.

Two pages deliberately cover positions 1–6 as specified. The engine does not enforce a six-card maximum; if more than six flying units must be supported, a separate UI decision is necessary (extra pages are explicitly excluded by this task). With JavaScript disabled only the initial three positions are visible.
