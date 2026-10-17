# Combat and creature inspection views

- Active `battle.strike` sets the shared phase label to `Фаза сражения` and adds a `Сражение` heading with the same background as the creature heading.
- Server markup starts with combat content visible and the retained selected-card fragment hidden.
- `battle-ui.js` stores the inspected card ID outside replaceable HTML. A combat key resets inspection on combat transitions; normal snapshot replacement retains it. Selection waits for matching fresh card HTML, so an unrelated stale card is never displayed.
- Existing battle-sync selection URLs, snapshot composition, game commands, pending choices and timer code are unchanged. Command links remain command links; only plain card selection requests inspection.
- Combat inspection renders no creature commands/status-action content, only a `Закрыть` button using the existing cancel style. The global footer is hidden only during inspection, and restored on Close. Close makes no HTTP/game request and returns keyboard focus to the combat heading.
- `data-panel-card-id` identifies the card in the independent `panel_html` fragment.

Validation: PHP combat panel checks for both viewers and selected/unselected cards; existing Stage 1B 40-case, snapshot, card visuals, descriptions, timer snapshot/UI and timer countdown/AJAX/audio tests passed. Browser checks passed at 1600/800/375px for both viewers: combat entry/reset, async card selection, read-only inspection, polling, Close by pointer/keyboard, footer visibility and preserved combat confirmation links. Existing full UI polish browser suite also passed on all three widths.

Browser tests use actual PHP fixtures and existing applySnapshot code with mock fetch, without live game actions. Timer HTTP tests remain skipped because TURN_TIMER_TEST_URL is not configured.

Final polish: while switching from one inspected card to another, retain the current card until the fresh selection arrives; do not flash the combat panel. A newly started inspection still waits for the requested card instead of showing an unrelated retained selection. Combat context has no outer padding; its body retains 12px padding so the combat heading spans exactly the same width and uses the same horizontal inset as the creature heading. Browser regression checks verify both directions of card switching and matching heading edges for both viewers at all three widths.
