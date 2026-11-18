# Stage 3A: cell presentation refinement

Branch feature/set2-terrain. UI-only: no mechanics, seating, flight, database or drag-handler changes.

- The existing total-count badge uses muted text/border/background and normal weight. It still shows N and is absent for 0/1 card.
- Existing previous/next buttons now have compact visible frames on desktop AND tablet. Native button semantics and capture click switching retain cyclic instance selection without issuing commands.
- Count and arrows reuse data-mini-hint / mini-hint-text and the existing shared tooltip. Arrow hints appear below their buttons so they do not intercept clicks.
- Removed ONLY the cell-switch swipe recognizer, click suppression and tablet pan-y override. Card drag code is unchanged. The existing drag filter already excludes buttons and hint targets.
- Existing ID-based presentation persistence remains unchanged. PHP render tests still verify the selected instance's right-panel identity; browser fixtures verify exact ID links and fragment reorder/movement/removal.

Files: src/View/Screen/BattleScreen.php; www/assets/js/battle-mini-card.js; www/assets/css/battle-mini-card.css; templates/screen/battle.tpl (cache versions); tools/test_symbiote_cells.cjs; tools/test_symbiotes.php; tools/test_symbiote_types.php (count markup assertions); this report.

Validation: headless Edge 1600/800 fixtures verify arrows at both widths, count, hints, no arrow game commands, exact card ID, AJAX replacement/reorder/removal/moved-cell identity, no touch-swipe switching, and vertical touch compatibility. Actual unmodified battle-drag.js is loaded: arrow press/motion does not start drag, card drag still starts. PHP suites: 48 seating and 24 type/catalog tests plus 186 LuckTest baseline checks. PHP/JS syntax and diff whitespace checks passed. Screenshots from synthetic artwork fixtures inspected; no live production match or full HTTP right-panel update is claimed.

Follow-up polish: hand cursor for count/arrows, stacked-card SVG in the existing badge, count follows active-card buffs and precedes cell markers, square 20px arrows with .16s / 1.12 hover scaling (reduced-motion respected). Edge tests additionally verify icon, cursor, ordering and square dimensions; drag and touch checks still pass.

Final icon alignment: count uses the supplied layered-card SVG at the existing 10x11 size. Arrow glyphs are replaced by the existing berserk-chevron-gold.svg rotated left/right; flex centering removes font-baseline dependence. Browser tests verify both chevron centers within 0.6px of button centers, plus existing 1600/800 touch/drag/tooltip checks.
