# Battle UI polish

Branch: `codex/battle-ui-polish`, based on `main` (`887f253`).

## Changed files

- `src/View/Screen/Battle/InfoPanel.php`: replace surrender text with its supplied icon, preserve the existing anchor URL, confirmation and availability checks; add inert `data-action="battle-log"` button with `aria-disabled="true"`.
- `templates/includes/battle/control_icon.tpl`: reusable decorative image and animated tooltip markup.
- `templates/screen/battle.tpl`: battle UI script cache version 3.
- `www/assets/css/global.css`: center flying bullets relative to each zone; battle-only cover texture; 40px icons with 200ms ease-out 1.2x image transform; tooltips, keyboard focus and reduced-motion support; local selection suppression with editable/copy exceptions.
- `www/assets/js/battle-ui.js`: one delegated contextmenu handler on the existing battle root; preserve native menus for inputs, textarea, select, editable content, `.debug-panel` and `[data-allow-copy]`.
- `tools/test_battle_ui_polish_browser.cjs`: browser regression checks, building on real PHP fixtures and the existing snapshot application.
- `docs/battle-ui-polish.md`: this report.
- Three supplied image assets (copied without alteration; SHA256 matches attachments):
  - `www/assets/images/battle/berserk_background.webp`
  - `www/assets/images/battle/game_log_160.png`
  - `www/assets/images/battle/surrender_160.png`

## Verification

Passed with PHP 8.1:

- `tools/test_battle_stage1b_ui.php` (40 combinations).
- `tools/test_fly_pagination.php` (0–6 cards, both viewers).
- `tools/test_battle_stage1a_ajax_layout.php`.
- `tools/test_battle_card_visual_ui.php`.
- `tools/test_resign_pending.php` (48 surrender cases plus guards). This test emitted warnings about unavailable sandbox game-log paths; its assertions passed.
- PHP and JS syntax checks, `git diff --check`.

Headless Edge browser tests passed at 1600, 800 and 375px:

- Exact alignment of six flying slots with field rows, constant document/board scroll heights for 0–6 cards.
- Pagination survives snapshot replacement and HP updates; independent sides and reset on shrink/regrowth remain intact.
- Bullets centered on each zone.
- Image dimensions 40x40 before hover and 48x48 on hover; icon boxes/footer geometry unchanged.
- Tooltips visible, inside viewport and not clipped by footer.
- Correct `cover`, centered, non-repeating background; supplied icons load.
- Selection and contextmenu suppressed on cards, preserved on input/textarea/select/editable/debug/copy elements; input remains editable.
- Log pointer/keyboard activation sends no requests.
- Original surrender confirmation cancellation sends no request; acceptance sends the existing `cmd=resign` through the existing battle command handler (mock fetch only).

Run the browser test with Playwright available as `playwright` or via `PLAYWRIGHT_MODULE`, `PHP_BINARY` pointing to PHP 8.1+, optional `BROWSER_EXECUTABLE` and `POLISH_SCREENSHOT_DIR`:

```
node tools/test_battle_ui_polish_browser.cjs
```

## Scope and remaining manual checks

No live game or production HTTP commands were executed. Testing used actual rendered fixtures, CSS and client handlers, with mock network requests. Touch-device long presses, Safari/Firefox and real production sessions were not tested manually.

Game logic, routing, command URLs, confirmation behavior, database structure, `applySnapshot`, snapshot transport and flying pagination state logic are unchanged. The green end-turn command is unchanged. No journal functionality has been implemented.

Per the user's clarification, push only this feature branch; do not merge into main.

## Follow-up: columns and landscape art

- Repeat the same viewer-oriented column labels below the field.
- Preview lookup now uses `/assets/cards/s1/<ukid>.webp` (without `preview/`).
- Popup uses `/assets/cards/s1/original/<ukid>.png`, with pointer cursor on the preview and responsive maximum dimensions of 800x500, preserving intrinsic proportions.
- Background stays unchanged.
- PHP preview, coordinate, Stage 1B, AJAX layout and card visual checks passed. Browser checks at 1600/800/375px cover matching lower labels, existing alignment/pagination, pointer cursor, a synthetic landscape image at the PNG URL with 8:5 proportions, 800x500 bounds and all three dismissal methods.
- Production card assets are ignored and absent locally; no actual card artwork was moved or replaced. Place the real WebP directly in s1/ and the PNG in original/ on the server.


## Pagination chips

Replace plain dots with supplied `page_active.png` (blue) and `page_inactive.png` (dark) in `www/assets/images/battle/`. The chip artwork is 20x20 inside the existing fixed 24x22 button, preserving zone geometry; hover/keyboard focus scales only the artwork by 1.2 over 200ms ease-out, matching the surrender/log animation. Reduced-motion preference is respected. Active state continues to follow existing aria-pressed; no pagination JS changes.

## Playmat, auxiliary zones and end-turn artwork

- `www/assets/images/battle/playmat_default.webp`: supplied default playmat; applied only to `.battle-layout`, centered in the available battlefield viewport with automatic margins. Padding is 28px vertically and 26px horizontally. Narrow/short viewports retain the existing local scrollport instead of clipping the board. The CSS `--battle-playmat` variable is ready for a future user-selected image; no settings implementation was added.
- `www/assets/images/battle/flying_empty.png`: supplied centered icon on empty flying page positions, hidden with its page and absent on occupied positions.
- `templates/includes/battle/side_zones.tpl` and `BattleScreen::buildPiles`: three separate row-aligned slots per side. Terrain is permanently empty with `terrain_empty.webp`; graveyard and exile have text labels, actual existing counts and existing pile-view URLs labelled Подробнее. No terrain rules were added.
- `InfoPanel.php`: only an end-turn CSS class was added; the command URL, anchor, disabled button and availability conditions are unchanged.
- Supplied `end_turn_normal.png`, `end_turn_hover.png`, `end_turn_disabled.png`: backgrounds for enabled, hover/focus and disabled end-turn states.
- `global.css`: local playmat, auxiliary zone and end-turn styling.
- `tools/test_battle_ui_polish_browser.cjs`: added exact centering checks where the board fits, six right-slot alignments, terrain/details controls, empty flying art and three end-turn states. Passed in headless Edge at 1600/800/375px alongside existing pagination, popup, controls and editable-exception tests.

The supplied six files were copied without image editing. No gameplay, persistence, snapshot protocol or user settings logic changed. Real production actions and mobile Safari remain untested.


## End-turn button rollback

Restored the standard green end-turn button at user request. Removed the artwork class and CSS; the supplied three image assets remain unused. Playmat, auxiliary zones, icons and other UI polish remain unchanged.
