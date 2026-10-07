## feat/card-s1_57-kriomant — 2026-XX-XX

**Карта:** Криомант (s1_57, Линунг, цена 5).
8 HP, move 1, удар 1-1-2.

**Механика:**
- Начинает бой с counter (`<second>`).
- `<counter><tap>`: «Ледяной дождь» — кубик 1-3/4-5/6 → N = 3/2/1.
  В следующий ход противника двигаться смогут только N существ.
- `<tap>`: разряд на 1.

**Изменения:**
- `ActionResolver::resolveFreezeMoves` — новый метод. Бросает кубик,
  пишет `battle['moves_limit']` с `owner` = противник, `limit`, `moved_ids`.
  Перезапись при повторном применении.
- `MovementResolver::move` и `jump` — проверка лимита перед движением,
  отметка `moved_ids` после.
- `ForcedMovementResolver::move` — то же (движение, инициированное другой картой).
- `BattleHelper::getMoveCells`, `getJumpCells` — если лимит исчерпан и
  существо не в `moved_ids`, клетки не подсвечиваются.
- `TurnProcessor::afterEndPhase` — сброс `moves_limit`, когда ход
  ограниченного игрока завершён.
- `InfoPanel` — отображение результата `kind: freeze_moves` + заголовок.
- `BattleScreen::buildPanel` — `freeze_moves` в immediate actions.

**Как проверить:**
Сценарий `debug/scenarious/card_s1_57_kriomant.json`.
1. `?debug_roll=2` → лимит 3. В ход player 3 существа двигаются, 4-е — блок.
2. `?debug_roll=6` → лимит 1. Только одно существо.
3. Лимит снимается после завершения хода игрока.
4. Jump и телепорт тоже ограничены.

**Не сделано:** UI-бейдж на панели хода (пункт 4 — позже).

**Откат:** `git revert <sha>` + откат SQL.