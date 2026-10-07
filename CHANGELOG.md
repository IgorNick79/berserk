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

## feat/card-s1_95-soulcatcher — 2026-XX-XX

**Карта:** Ловец душ (s1_95, Эльф, цена 6).
8 HP, move 1, удар 1-2-3.

**Механика:**
- `on_any_death` (любая смерть): +1 монета, cap 5.
- `<tap>`: частица души — до 2 существ без ран, −1 каждому (impact).
- `X<counter><tap>`: предсмертный дар — потратить X монет, ранить
  врага на X (cast, защита zom) и излечить союзника на X.

**Изменения:**
- `ActionResolver::startParticlePick` / `chooseParticlePick` — новый тип
  действия `particle`. Pending `pending_particle_pick` с фильтром
  `hp == hpMax`, до 2 целей.
- `ActionResolver::startLifeGift` / `chooseLifeGift` — новый тип `life_gift`.
  Pending `pending_life_gift` с X (1..coins), списком врагов и союзников.
  Урон — `cast` (проверка `hasDefense`), heal с капом.
- `Choice/ParticlePickChoice` — checkbox + переключатель
  «Мои существа / Существа противника».
- `Choice/LifeGiftChoice` — MultiRadio (amount / enemy_id / ally_id).
- `ChoiceRegistry` — регистрация.
- `Engine::doApply` — команды `choose_particle_pick`, `choose_life_gift`.
- `ActionResolver::cancelPending` — оба pending в `$simple`.
- `CardStats::isOffensiveAction` — добавлены `particle`, `life_gift`.
- `BattleHelper::getAttackTargets` — цели для этих действий не подсвечиваются.
- `BattleScreen::buildPanel` — immediate actions и метки.
- `InfoPanel` — отображение результатов + `$noDiceKinds` + `$headerText`.
- SQL s1_95 — prop.

**Как проверить:**
Сценарий `debug/scenarious/card_s1_95_soulcatcher.json`.
1. Частица души: 2 цели без ран, default враги, переключение на своих.
2. Предсмертный дар: X монет, урон cast (защита zom), heal союзнику.
3. `on_any_death`: +1 монета до cap 5.

**Откат:** `git revert <sha>` + откат SQL.