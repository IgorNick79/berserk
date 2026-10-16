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
