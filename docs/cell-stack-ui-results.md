# Cell markers and creature switch — 2026-10-25

Implemented only the view: stable instanceId sorting and grouping, independent cell markers, cyclic local switch, 16px footer, 42px pointer-transparent bonfire. Only miniatures in cells with a footer scale uniformly to .87; frame, HP and status structure remain intact. Zoom copies retain this scale. Flying/pile cards retain their dimensions. Confirmed marker types only: bomb, reduce_attack, row_spell, bonfire, gate, lantern_light. Lantern uses the existing PNG; flowering runes use a rune glyph. No tremor/rot mapping was added.

Changed: BattleScreen.php; battle-mini-card.css; battle-mini-card.js; .gitignore (whitelist this one debug scenario); debug/scenarios/ui_cell_stack.json; tools/test_cell_stack.php; tools/test_cell_stack.cjs. Screenshot: docs/evidence/cell-stack/cell-stack-tested.png.

The JSON uses the existing seed schema. IDs are allocated separately by nextInstanceId(), not read from JSON. Two definitions s1_55 and s1_75, different owners, share row 3 / column 2. The isolated PHP UI fixture loads that JSON, creates distinct instances, and calls the real BattleSnapshotRenderer. The test checks state immutability. Browser artwork is a test SVG served to the real miniature image URLs; real frame and marker assets are served from the project. Unique image-availability probes are removed on shutdown.

Actually run:
- PHP 8.1 lint of BattleScreen.php: PASS.
- PHP 8.1 tools/test_mini_card_stage4.php: PASS (existing effects, hidden information, replay, rollback, render immutability).
- Node tools/test_cell_stack.cjs with headless Edge and Playwright: PASS. Two mixed-owner and same-owner occupants; both cyclic directions; 3 and 6 occupants; different illustration URLs, frame resources, HP, statuses, and displayed-ID click links; arrows and keyboard issue no card action; reordered state refresh retains ID; removal falls back; single-card switch absent; 30 cells remain 110x100; two markers and 3/6 fit into 16px; overflow scrolls without displacing switch; fire alone/with markers/over a card; fire allows card clicks; zoom after switching; no browser JavaScript errors. PHP fixture produced valid JSON without warnings/errors.
- git diff --check: PASS.

Limitations / failed infrastructure check:
- Existing local loader command `php tools/seed.php ui_cell_stack` was actually attempted. It failed in Db.php:28 / seed.php:39 because local MySQL refused the connection. No database-backed game was created; loader persistence and full authenticated live-game navigation remain unverified.
- Snapshot refresh in the browser test replaces actual renderer HTML and dispatches berserk:battle-updated. The full HTTP polling transport was not exercised by this new test.
- Original card illustrations are absent locally; the browser verifies different illustration URLs and uses test image responses. Real production artwork was not visually verified.
- No combat action, server targeting rule, engine, placement validation, LuckTest loader, symbiote or spirit mechanic was changed.

Re-run: `node tools/test_cell_stack.cjs`. The PHP and Edge/Playwright paths in the test match this Windows environment; CELL_STACK_OUTPUT can override the screenshot directory.

Follow-up: empty footers are omitted; single unmarked cards retain 102x92 size; bonfire alone has no footer; footer background is rgba(221, 221, 221, 0.55). Marker pointer events enable the existing native title hint. Browser checks for original size, absent empty strips, background and bomb hover hit-testing passed. Updated screenshots include cell-stack-clean-tested.png.

Marker hint follow-up: native title replaced with the existing buff tooltip (data-mini-hint), positioned below the marker; pointer cursor added. Browser checks passed for matching tooltip, below-marker placement, no native title, pointer cursor, hover, pinned click without game action, focus/Enter/Escape, and cleanup on field update.
