# Field / Demon of Greed follow-up — 2026-10-25

Implemented requested items:
1. Cell marker glyphs use #111 on the light marker background (runes, gates, snow); bomb/fire emoji and lantern image keep their existing assets.
2. Bonfire remains 42px and pointer-transparent.
3. Mandatory gate placement reuses CellMarkerPicker (Krondak): side, Roman row, column, existing choose_gate commands. Gate teleport keeps its small button list with existing Coords badges. Teleport acknowledgement stores a structured position event and renders Roman row / viewer-relative column for each viewer.
4–5. Battlefield, fly slots, grave/exile/terrain slots use rgba(0,0,0,.55). This is background alpha only, so cards/text retain full opacity.
6. Live flying card wrappers no longer carry own/enemy background classes; frame ownership and attack-target highlighting remain.
7. Tergala row labels use own/enemy halves and I/II/III, while commands retain their existing UI-row to physical-row conversion. Row spell target labels also use BattlefieldPosition.
8. Greed teleport filters bonfire gates during end-turn generation, excludes them from the choice view, and rejects stale/forged selection before moving. Occupied stale destinations are rejected too. Existing all-unavailable self-damage path remains.
9. Gate placement has no Cancel action; ActionResolver also rejects direct cancel_pending without mutating the pending choice. Other cancellation paths are unchanged.
10. Empty-cell marker island is 28px with 16px marker row and padding. With bonfire it sits just below the 42px flame within the cell. Occupied-cell footer remains 16px.

Actual checks:
- PHP 8.1 tools/test_greed_field_fixes.php: PASS. Both owners: three mandatory gates, compact picker adapter, direct cancellation rejection/no mutation, blocked destination filtering/rejection/no mutation, valid teleport and both viewer acknowledgements, actual end-turn filtering and all-blocked 3-damage behavior, all six Tergala row labels and actual chooseRow mapping.
- Node/Playwright/headless Edge tools/test_greed_field_fixes.cjs: PASS. Actual PHP BattleScreen and actual battle template; Krondak picker interaction for both viewers; original system row/col in command; no Cancel; backgrounds alpha .55 for field/flying/grave/terrain; flying owner backgrounds absent and miniature frame retained; no JS errors.
- Node tools/test_cell_stack.cjs: PASS. Existing 2/3/6 creature switching, hints, frame/HP/status/ID routes and update behavior; 1/2/3/12 marker overflow; dark glyph computed style; 28px islands; bonfire containment and click-through; unmarked cards retain 102x92; cells stay 110x100.
- PHP tools/test_cell_marker_cancel.php: PASS (other cell-marker cancellation still works).
- PHP tools/test_battle_result_coordinates_ui.php: PASS.
- PHP tools/test_bomb_result_after_death.php: PASS.

No Queen of the Swamp-specific implementation or data was changed. No database-backed game was started; local fixture tests use actual engine/renderer code, not production card catalog data. Full live server/network polling was not exercised.

Changed source files: src/Core/ActionResolver.php, src/Core/TurnPhaseProcessor.php, src/Core/Choice/{GatePickChoice,GreedTeleportChoice,RowPickChoice,RowSpellPickChoice,TurnAckChoice}.php, src/View/Screen/Battle/InfoPanel.php, src/View/Screen/BattleScreen.php, src/View/Ui/CellMarkerPicker.php, www/assets/css/battle-mini-card.css, templates/screen/battle.tpl (CSS cache version). Tests: tools/test_greed_field_fixes.php/.cjs and tools/test_cell_stack.cjs. Evidence: docs/evidence/cell-stack/*.png.
