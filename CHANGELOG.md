# CHANGELOG

Журнал изменений. Одна запись = одна ветка = одна логическая правка.

**Правила ведения:**
- Запись создаётся в первом коммите ветки (только заголовок, статус `[WIP]`).
- Заполняется в последнем коммите перед мержем в `main`.
- Если ветка брошена — запись остаётся со статусом `[WIP]` и пометкой «не закончено».
- Откат — через `git revert <sha>` мерж-коммита, отдельной строкой в записи.

---

## fix/intercept-and-voznitsa — 2026-09-30

**Проблема:**
1. Возница (s1_143): UI подсвечивал атаку через ряд, `declare` отклонял с «Цель не соседняя».
2. Аргвальд (s1_60): `ranged_intercept: ["discharge"]` не работал в серверной валидации
   (`validateTarget` вызывала `getRangedInterceptors` без `$type`), но работал в UI.
3. Мерцающий змей (s1_167): ключ `ranged_intercept_all` в данных не читается кодом —
   перехват не работал вообще.

**Изменения:**
- `src/Core/StrikeResolver.php` — в `declare()` читается `row_extreme` вместо `row_strike`.
- `src/Core/ActionResolver.php` — в `validateTarget()` передаём `$type` в `getRangedInterceptors`.
- SQL, карта `s1_167` — `ranged_intercept_all: true` → `ranged_intercept: ["shot","throw"]`.

**Как проверить:**
1. Возница на клетке (2,1) → атака по (2,5), тот же ряд. Должно пройти.
2. Аргвальд + союзник рядом, враг пытается discharge по третьей цели. Должно отклониться.
3. Мерцающий змей + союзник рядом, враг пытается shot/throw по третьей цели. Должно отклониться.

**Откат:** `git revert <sha>` — миграций нет, SQL-правку откатывать вручную.

---

## fix/seyatel-any-death — [WIP]

**Проблема:**
1. Сейатель хвори (s1_124): кандидаты в `triggerOnAnyDeath` — только враги сеятеля.
   По правилам должны быть все карты рядом с умершим, включая своих.
2. Кнопки «Отмена» нет в UI окна выбора (серверная часть уже готова).

**Изменения:**
- `DamageResolver::triggerOnAnyDeath` — снять фильтр `$t->owner === $seeder->owner`.
- UI (battle screen) — добавить кнопку «Отмена».
- SQL, карта `s1_124` — удалить неиспользуемый ключ `target: "all_near"`.

**Как проверить:**
1. Сейатель на поле, враг рядом, кто-то умирает от яда. В окне выбора — и свои, и чужие рядом.
2. Нажать «Отмена» — фаза продолжается без отравления.

**Откат:** `git revert <sha>`.

---

## Шаблон новой записи

```markdown
## <branch> — YYYY-MM-DD

**Проблема:**
<что не работало, как воспроизвести>

**Изменения:**
- <файл> — <что>
- SQL, карта <ukid> — <что>

**Как проверить:**
1. <шаг>
2. <шаг>

**Откат:** <git revert | ручная миграция | n/a>

**Заметки:** (опционально — если есть недоработка или TODO)

## fix/seyatel-any-death — 2026-XX-XX

**Проблема:**
1. Сейатель хвори (s1_124): кандидаты в `triggerOnAnyDeath` — только враги сеятеля.
2. Окно `pending_any_death` — кнопки вместо radio, без координат и подписи владельца.
3. После выбора/отмены не вызывается resume фазы — ход зависает.

**Изменения:**
- `DamageResolver::triggerOnAnyDeath` — снят фильтр `$t->owner === $seeder->owner`.
- `DamageResolver::chooseAnyDeathTarget` — resume после завершения очереди (обе ветки).
- `src/Core/Choice/AnyDeathChoice.php` — buttons → radio, подписи с координатами,
  свои помечены «— моё существо».
- SQL, s1_124 — удалён неиспользуемый `on_any_death.target: "all_near"`.

**Как проверить:**
1. Сейатель рядом с умершим от яда — в окне есть и свои, и чужие.
2. Свои помечены «— моё существо».
3. «Отмена» → фаза продолжается.
4. Выбор → яд наложен, фаза продолжается.

**Откат:** `git revert <sha>`.

## fix/turn-phase-shadow-card — 2026-XX-XX

**Проблема:**

В `TurnProcessor::continueStartTurn()` внутренний цикл использовал то же имя
переменной `$card`, что и внешний. После выхода из вложенного цикла переменная
указывала на последний элемент массива, а не на текущий во внешнем цикле.
Как следствие — `ranged_hits_this_turn` сбрасывался только у одной карты
вместо всех карт активного игрока. Ломало Миру (s1_86, `close_on_ranged_hits: 2`).

**Изменения:**
- `TurnProcessor::continueStartTurn()` — два независимых цикла вместо вложенных,
  разные имена переменных.

**Как проверить:**
1. Мира стреляет в цель — в debug-панели у цели `ranged_hits_this_turn = 1`.
2. Ход переходит врагу, затем обратно активному.
3. В начале нового хода `ranged_hits_this_turn` у всех карт активного игрока = 0.

**Откат:** `git revert <sha>`.

=======
В `TurnProcessor::continueStartTurn()` внутренний цикл сброса `damage_taken_this_turn`
использовал то же имя переменной `$card`, что и внешний. После выхода из вложенного
цикла `$card` указывал на последний элемент массива, а не на текущий во внешнем.
Строка `$card->flags['ranged_hits_this_turn'] = 0;` сбрасывала флаг не той карте.
Ломало Миру (s1_86, `close_on_ranged_hits: 2`).

**Изменения:**
- `TurnProcessor::continueStartTurn()` — во внутреннем цикле переменная переименована
  в `$c`, сбрасывается флаг именно у неё. Внешний цикл получил корректный `$card`.

**Как проверить:**
1. Мира стреляет в цель — у цели `ranged_hits_this_turn = 1` (видно в debug).
2. Ход переходит врагу, затем обратно.
3. В начале нового хода у всех карт активного игрока `ranged_hits_this_turn = 0`.

**Откат:** `git revert <sha>`.

## refactor/wound-transfer-unified — 2026-XX-XX

**Проблема:**
Перераспределение ран жило на двух путях: старый `pending_transfer` (Осклизг)
и новый `pending_wound_transfer` / `WoundTransferProcessor` (Волхв, Отшельница).
Осклизг через старый путь не умел ни фильтр по элементу, ни «на себя» без цели.
Рэккен в prop был нерабочим (`impact` без `transfer_wounds`).

**Изменения:**
- `WoundTransferProcessor` — в `collectDonors` добавлены фильтры `donor_element`,
  `donor_near`, исключение источника; `chooseAmount` при `target_filter: source`
  сразу переходит к `target_amount` (пропускает выбор цели).
- `ActionResolver::handle` — расширена ветка `wound_transfer` (проброс `kind`,
  `donor_element`, `donor_near`, `on_finish`); удалена ветка `impact.transfer_wounds`.
- Удалены методы `startTransfer`, `chooseTransferDonor`, `chooseTransferAmount`
  и блок `pending_transfer` в `cancelPending`.
- Удалён `src/Core/Choice/TransferChoice.php` и его регистрация в `ChoiceRegistry`.
- `Engine::doApply` — удалены команды `choose_transfer_donor`, `choose_transfer_amount`.
- SQL s1_109 (Осклизг) — prop на `wound_transfer`.
- SQL s1_110 (Рэккен) — prop: Рэккендум на `wound_transfer`, throw остался.

**Как проверить:**
1. Осклизг рядом с раненым болотным союзником. Клик по «Эликсиры Ракштольна».
2. Окно: выбор донора → количество HP → подтвердить. Пропускает шаг цели.
3. Осклизг получает раны, донор лечится. Осклизг закрыт, монета списана.
4. Отмена на любом шаге — монета возвращается, Осклизг открывается.
5. Рэккендум (Рэккен) — аналогично, max 2 HP, без монет.
6. Волхв и Отшельница — работают как раньше.

**Откат:** `git revert <sha>` + откат SQL.

text
- `WoundTransferProcessor::chooseAmount` — при `target_filter: source` сразу
  записывает transfer и вызывает `finish()`, минуя шаг `target_amount`.
  Убирает лишний экран подтверждения для Осклизга и Рэккена.
- SQL s1_110 (Рэккен) — убран `donor_near`, т.к. текст карты
  не требует «рядом» (в отличие от Осклизга).
- `WoundTransferProcessor::start` — источник больше не закрывается сразу
  при клике. Закрытие перенесено в `finish()` (для `on_finish: main_phase`
  тоже).
- `ActionResolver::cancelPending` — источник открывается всегда, монеты
  возвращаются только если были потрачены.
- `WoundTransferChoice::commandTypes` — добавлен `cancel_pending`;
  в `apply` он маршрутизируется в `ActionResolver::cancelPending`.
  Без этого отмена не работала — реестр отклонял команду с «Ожидается выбор».

markdown
## fix/rekken-self-wounds — 2026-XX-XX

**Проблема:**
Рэккен (s1_110), метание «ритуального ножа» — `value: "self_wounds"`.
В `resolveDamage` приведение к `(int)` давало 0, метание никогда не наносило урон.

**Изменения:**
- `ActionResolver::resolveDamage` — поддержана `value: "self_wounds"`.
  X = hpMax − hp метателя, cap `max_value` (у Рэккена 4).

**Как проверить:**
1. Рэккен ранен (hp < hpMax) — метание в цель даёт X урона, X = число ран (max 4).
2. Рэккен полный (hp = hpMax) — метание даёт 0 урона, действие завершается.

**Откат:** `git revert <sha>`.

## refactor/opposite-helper — 2026-XX-XX

**Проблема:**
Три разных реализации «карта напротив»:
- `ActionResolver::getOppositeFieldCard` (owner ± 1)
- `TurnPhaseProcessor::findOpposite` (owner ± 1)
- `TurnProcessor::findOpposite` (`7 - row`) — неверно для задних рядов

**Изменения:**
- `CardStats` — новые методы `oppositeCell()` и `getOppositeFieldCard()`.
- `ActionResolver` — удалён приватный `getOppositeFieldCard`, вызов идёт через `CardStats`.
- `TurnPhaseProcessor::findOpposite` — делегирует в `CardStats::getOppositeFieldCard`
  + проверка владельца.
- `TurnProcessor` — удалены мёртвые `applyTurnStartEffect` и `findOpposite`.

**Как проверить:**
1. Кобольд (s1_92) бьёт врага напротив — предложение излечиться.
2. Гном-поджигатель (s1_35) в начале хода ранит врага напротив, своих не трогает.
3. То же для Гнома на краю поля (row 1 host / row 6 player).

**Откат:** `git revert <sha>`.

## feat/card-s1_24 — 2026-XX-XX

**Карта:** Посвященный Дзара (s1_24, Тоа-Дан, цена 5, элита).
9 HP, move 2, удар 1-1-2.

**Механики:**
1. Разряд на 2-3-4, дальность 1.
2. При перемещении — +1 к дальности разряда до конца хода.

**Изменения:**
- `CardStats::getEffectiveRange` — учитывает модификатор `action_range`
  с фильтром по `types`. Новый публичный метод `getActionRangeBonus`.
- `CardStats::statLabel` — метка «Дальность» для `action_range`.
- `MovementEffectResolver::applyOnMoveEffects` — модификаторы из `on_move`
  сохраняют все поля (в т.ч. `types`), а не только `stat/value/expire`.
- SQL s1_24 — prop: discharge + on_move modifier.

**Как проверить:**
Сценарий `debug/scenarious/card_s1_24_dzara.json`:
1. Дзара (3,2) — цель (3,5) недосягаема (dist 3, range 1).
2. Move Дзара (3,2) → (3,3) — в debug появляется modifier `action_range`.
3. Цель (3,5) доступна (dist 2, range 2). Разряд 2-3-4.

**Откат:** `git revert <sha>` + откат SQL.

## refactor/dead-code — 2026-XX-XX

**Проблема:**
Мёртвые методы и дубли, оставшиеся с прошлых итераций. Не влияют на поведение,
но зашумляют код и путают при чтении.

**Изменения:**
- `StrikeResolver` — удалены `hasCombatInstants`, `hasCombatInstantsType`
  (0 вызовов). `getCombatInstants` оставлен (public, используется в `InfoPanel`).
- `TurnPhaseProcessor` — удалён `executeInstant` (0 вызовов, дублировал
  логику `executeSub`).
- `TurnProcessor` — удалены `applyTurnStartEffect` и `findOpposite`
  (0 вызовов, дублировали `TurnPhaseProcessor::executeTurnStartEffect`).
- `BattleHelper::getAttackTargets` — удалён дубликат строки
  `$result[$target->instanceId] = true;` в ветке `strike`.
- `DamageResolver::applyDamage` и `Engine::applyDamage` — удалён параметр
  `$skipHunt` (всегда передавался `false`).

**Как проверить:**
Регрессии быть не должно — удалённые методы не вызывались.
Прогнать боевой сценарий с hunt-механикой (Гиррит стреляет — цель получает
маркер `hunt`, летун добивает — цель получает +2 урона).

**Откат:** `git revert <sha>`.

## feat/card-s1_87 — 2026-XX-XX

**Карта:** Фагор (s1_87, Страж леса, цена 7).
12 HP, move 1, удар 3-3-5.

**Механика:**
`<counter><tap>`: Магический удар на X, где X — сумма слабых ударов
ваших существ слева и справа от Фагора (в одном ряду).

**Изменения:**
- `ActionResolver::resolveDamage` — поддержано `value: "adjacent_ally_weak_sum"`.
  Считает `strikeWeak` союзников вплотную слева/справа.
- SQL s1_87 — prop: action magic с coins:1, value: adjacent_ally_weak_sum, save_coins:true.

**Как проверить:**
Сценарий `debug/scenarious/fagor.json`.
1. Ход 1: «Накопить монету» → Фагор closed, coins=1.
2. Ход 2: «Магический удар» → клик по врагу (4,3).
3. Урон = 1 (Кшар слева) + 1 (Кочевник справа) = 2.
4. Монета списана, Фагор closed.

**Откат:** `git revert <sha>` + откат SQL.

## feat/card-s1_80-bjorn — 2026-XX-XX

**Карта:** Бьерн (s1_80, Страж леса, цена 5).
10 HP, move 1, удар 2-2-3.

**Механика:**
Когда Бьерн становится защитником — излечить на 2 существо,
которому он выступает защитником.

**Изменения:**
- `StrikeResolver::chooseDefender` — расширен триггер `on_become_defender`:
  поддержан тип `heal_target` (лечит атакуемую карту).
  Модификаторы (`stat`) работают как раньше (Клаэр).
- `InfoPanel` — отображение `defender_heal` в результатах удара.
- SQL s1_80 — prop: `on_become_defender: [{"type": "heal_target", "value": 2}]`.

**Как проверить:**
Сценарий `debug/scenarious/card_s1_80_bjorn.json`.
1. Раненый союзник, атакован врагом.
2. Игрок выбирает Бьерна защитником.
3. Союзник излечивается на 2, удар идёт в Бьерна.

**Откат:** `git revert <sha>` + откат SQL.

## refactor/cancel-pending-table — 2026-XX-XX

**Проблема:**
`ActionResolver::cancelPending` — 15 почти одинаковых блоков `if (!empty(...))`.
Каждый новый pending требовал копипасты ~8 строк. Внутри был дубль
`pending_dice_choice`.

**Изменения:**
- `cancelPending` переписан на три группы:
  - простые (unset при owner === playerKey) — таблица `[key, ownerField]`;
  - card-owned (unset при карте игрока) — таблица `[key, cardIdField]`;
  - спец (возврат монет, reopen) — через `cancelWithRefund` или свой блок.
- Дубль `pending_dice_choice` устранён.
- Логика ответов и resume `turn_phase` — без изменений.

**Как проверить:**
Отмена на 4 разных pending: whip (Смотритель стойла),
wound_transfer (Волхв), coin_spend (Пустотник), self_wound (Центурион).

**Откат:** `git revert <sha>`.

## refactor/multi-target-pick — 2026-XX-XX

**Проблема:**
`chooseMultiHeal` и `chooseMultiDischarge` дублировали ~30 строк
валидации: чтение pending, проверка владельца, парсинг `target_ids`,
дедуп, лимит `max_targets`, наличие в `candidates`.

**Изменения:**
- `ActionResolver::parseMultiPick($playerKey, $cmd, $pendingKey)` —
  общий валидатор. Возвращает `[attacker, targetIds, config]` или `Result`.
- `chooseMultiHeal` и `chooseMultiDischarge` используют его.
  Бизнес-логика (лечение vs урон) остаётся раздельной.
- Сигнатуры public-методов не менялись, вызывающие
  (`Engine::doApply`, `MultiHealChoice`, `MultiDischargeChoice`) не затронуты.

**Как проверить:**
Аколит Дзара (s1_10) — тройной разряд по 3 целям.
Фея леса (s1_72) — излечение 3 лесных союзников.
Негативные: 0 целей, 4 цели (больше max_targets), невалидный target_id.

**Откат:** `git revert <sha>`.

## feat/card-s1_22 — 2026-XX-XX

**Карта:** Орк-бомбардир (s1_22, Орк, элита).
10 HP, move 1, удар 2-2-3.

**Механика:**
`<tap>`: метание бомбы на 1 по врагу в пределах 3. На следующий
ход владельца — бомба взрывается, 2 урона всем на клетке.

**Изменения:**
- `ActionResolver::resolveBombShot` — новый метод, обрабатывает
  `type: bomb_shot`. Наносит 1 урона, ставит `cell_markers[X_Y]`
  типа `bomb` с `source` = владелец, `damage` = 2.
- `ActionResolver::handle` — новая ветка `bomb_shot`.
- `CardStats::isOffensiveAction` — `bomb_shot` в списке.
- `TurnPhaseProcessor::buildActiveQueue` — новая bulk-задача `bombs`,
  если есть бомбы владельца.
- `TurnPhaseProcessor::executeBombs` — сканирует `cell_markers`,
  наносит `impact` урон карте на клетке, удаляет маркер.
  Возвращает `standalone_text` «взорвалась на клетке (X;Y)».
- `TurnPhaseProcessor::hasPendingFromTask` — `bombs` + `pending_any_death`.
- `InfoPanel::renderStartAck` — поддержка `standalone_text` в items.
- `InfoPanel::renderStrike` — отображение результата `bomb_shot`.
- `BattleScreen::buildPanel` — метка `bomb_shot` → «Бомба».
- SQL s1_22 — prop: action `bomb_shot`.

**Как проверить:**
Сценарий `debug/scenarious/card_s1_22_bombardier.json`.
1. Ход 1: бомба в Кочевника → −1 HP, маркер на клетке.
2. Ход 2 владельца: взрыв → −2 HP, маркер снят.

**Откат:** `git revert <sha>` + откат SQL.

- `ZoneManager::markerBlocksMovement` — статический хелпер: бомба не блокирует
  клетку, остальные маркеры (костёр) — блокируют.
- Правки в `MovementResolver::validateDestination`, `BattleHelper::getMoveCells`
  и `getJumpCells`, `ForcedMovementResolver::destination`, `ActionResolver::startDive` —
  проверка через новый хелпер.
- `BattleScreen::buildField` — маркер клетки показывается поверх карты
  (иконка бомбы 💥 / костра 🔥). Новый метод `buildCellMarkerOverlay`.
- `global.css` — стиль `.cell-marker-overlay`.
- `executeBombs` — убран `break`, взрыв бьёт все карты на клетке.

## feat/cell-marker-stack — 2026-XX-XX

**Проблема:**
Костёр рисовался как отдельная ветка рендера клетки, бомба — как оверлей.
Оба маркера хранились как один объект в `cell_markers[X_Y]` — второй
затирал первый.

**Изменения:**
- `cell_markers[X_Y]` — теперь массив маркеров. Старый формат
  (один объект с `type`) читается через `markersAt()` для совместимости.
- `ZoneManager` — новые методы: `markersAt`, `hasMarker`, `hasBlockingMarker`,
  `addMarker`, `removeMarkersByType`. `setCellMarker` обновляет/добавляет в список.
- Все проверки блокировки движения — через `hasBlockingMarker` (только костёр).
- `resolveBombShot` — через `ZoneManager::addMarker`.
- `executeBombs` — удаляет только бомбы своего источника, другие маркеры остаются.
- `TurnProcessor::afterEndPhase` — тик маркеров работает со списком.
- `BattleScreen::buildCellMarkersOverlay` — контейнер `.cell-markers`
  с иконками `.cell-marker-<type>` в ряд.
- CSS: `.cell-markers` — flex-контейнер в углу клетки; иконки 22×22.

**Как проверить:**
1. Бомба + костёр на одной клетке — две иконки в углу.
2. Клетка блокирована (костёр).
3. Ход владельца бомбы — взрыв, иконка бомбы исчезает, костёр остаётся.

**Откат:** `git revert <sha>`.

## feat/berserk-strike-consecutive — 2026-XX-XX

**Карта:** Берсерк (s1_195).
**Механика:** Две атаки за ход, только подряд. Движение после первой
атаки снимает вторую.

**Изменения:**
- `MovementResolver::move` и `jump` — если карта с `strike_consecutive`
  двигается после того, как уже атаковала (`attacks_used_this_turn > 0`),
  ставится флаг `strike_chain_broken`.
- `StrikeResolver::declare` и `BattleHelper::getAttackTargets` — при
  `strike_chain_broken` лимит атак снижается до 1.
- `TurnProcessor::continueStartTurn` — флаг `strike_chain_broken`
  сбрасывается в начале хода владельца.
- SQL s1_195 — в prop добавлен `strike_consecutive: true`.

**Как проверить:**
A. Стоит или двинулся до атак — 2 атаки.
B. Ударил, двинулся — вторая атака недоступна.
C. Ударил, ударил — обе прошли, движение после ок.

**Откат:** `git revert <sha>` + откат SQL.

## fix/berserk-strike-consecutive-ui — 2026-XX-XX

**Проблема:**
При `strike_targets_unique` (Берсерк) после первой атаки цель всё ещё
подсвечивалась как доступная, но `declare` отклонял удар по ней.
UI расходился с логикой.

**Изменения:**
- `BattleHelper::getAttackTargets`, ветка `strike` — уже атакованная
  цель не включается в список (при `strike_targets_unique` и
  `attacks_used_this_turn > 0`).

**Как проверить:**
Сценарий `debug/scenarious/card_s1_195_berserk.json`.
1. Берсерк бьёт по A — A больше не подсвечивается.
2. B подсвечивается. Удар по B — проходит, карта закрыта.

**Откат:** `git revert <sha>`.

## fix/instant-exclude-busy-cards — 2026-XX-XX

**Проблема:**
Атакующая карта (Ост и любые другие с combat-инстантами) могла сыграть
свой инстант в combat-окне на своё же объявленное действие. По правилам —
она уже зарезервировала действие (strike/shot/discharge), инстант
недоступен. Аналогично — карта в активном pending (даже отменяемом).

**Изменения:**
- `InstantProcessor::getInstants` — карта-атакующий (`strike['attacker_id']`)
  не включается в список доступных инстантов.
- Новый приватный метод `isCardInPending` — карта в любом `pending_*`
  (как `attacker_id`, `card_id`, `source_id`, `healer_id`) тоже не показывается.

**Как проверить:**
1. Ост (s1_5) объявляет discharge → в combat-окне своего инстанта не видит.
2. Другая карта того же игрока с combat-инстантом — видит (не атакующий).
3. Карта, активировавшая pending (Волхв, Осклизг) — не видит своих инстантов,
   пока pending не завершён или не отменён.

**Откат:** `git revert <sha>`.

## feat/card-s1_96 — 2026-XX-XX

**Карта:** Тергала (s1_96, Эльф).
**Механика:** `<tap>`: выбрать ряд. В начале своего следующего хода —
ранить X чужих на 1-2-2, X = свои без ран в выбранном ряду.
Если чужих < X — не срабатывает.

**Изменения:**
- `ActionResolver::startRowSpell` и `chooseRow` — выбор ряда через
  `pending_row_pick`. UI-номер конвертируется в физический row
  (host: 1→1, player: 1→6). Хранится в `card->flags['row_spell_pending']`.
- `Choice/RowPickChoice` — 6 кнопок + отмена.
- `TurnPhaseProcessor::buildActiveQueue` — задача `row_spell` для карт с
  флагом. `executeRowSpell` — считает X, проверяет чужих, при достаточном
  числе открывает `pending_row_spell_pick`.
- `Choice/RowSpellPickChoice` — чекбоксы выбора ровно X целей.
- `ActionResolver::chooseRowSpellTargets` — один бросок кубика, урон
  1/2/2 всем выбранным.
- `Engine::doApply` — команды `choose_row`, `choose_row_spell_targets`.
- `InfoPanel`, `BattleScreen` — метка и отображение `row_spell`.
- SQL s1_96 — prop с actions.

**Как проверить:**
1. Тергала `<tap>` → кнопки «Ряд 1…Ряд 6» + Отмена.
2. Выбор ряда → карта закрыта, флаг.
3. Следующий свой ход — ack «Цветущие руны: ряд N, X = …».
4. Если чужих хватает — pending с чекбоксами, выбрать ровно X.
5. Урон 1/2/2 по броску.
6. Если чужих < X — только текст «не срабатывает».

**Откат:** `git revert <sha>` + откат SQL.

## feat/card-s1_138-fire-imp — 2026-XX-XX

**Карта:** Огненный Имп (s1_138, Демон, цена 3).
5 HP, move 1, удар 1-2-2.

**Механика:**
`<tap>`: метание «лавы» на 1, дальность 4.
При телепортации союзного существа с соседней клетки на половину
противника — Имп получает +1 к следующему метанию (максимум +1 за ход).

**Изменения:**
- `MovementEffectResolver::applyTeleportAdjacentBonus` — новый метод.
  Срабатывает при jump с `range >= 10`, переходе с `own` на `enemy`
  половину, старте с клетки, соседней с Импом. Ставит
  `next_action_bonus` +1 на `throw` (`consume: true`) и флаг
  `teleport_adjacent_bonus_used` (один раз за ход).
- `TurnProcessor::continueStartTurn` — сброс флага
  `teleport_adjacent_bonus_used` в начале своего хода.
- SQL s1_138 — prop: action `throw`, `on_teleport_adjacent_bonus`.

**Как проверить:**
Сценарий `debug/scenarious/card_s1_138_fire_imp.json`.
1. Рогатый демон рядом с Импом → телепорт на чужую половину →
   у Импа появляется `next_action_bonus=1`.
2. Удар лавой → 2 урона (1 базовый + 1 бонус).
3. Рогатый демон далеко от Импа → телепорт не даёт бонуса.
4. Два телепорта рядом за ход → всё равно +1 (флаг блокирует).

**Откат:** `git revert <sha>` + откат SQL.

## feat/card-s1_145-envy-demon — 2026-XX-XX

**Карта:** Демон зависти (s1_145, Демон, цена 5).

**Механика:**
- Телепортация (`jump range 99`).
- +1 к простому удару, пока рядом 2+ существа противника с `strikeWeak >= 3`.
- `<tap>: Магический удар на 3`, доступен только при 2+ существах
  противника с магией (discharge / magic / cast) рядом.

**Изменения:**
- `CardStats::checkCondition` — принимает и строку, и массив.
  Тип `enemies_near` с параметрами `count`, `weak_min`, `has_magic`.
- `CardStats::countEnemiesNearMatching`, `matchesEnemyFilter`, `enemyHasMagic`.
- `BattleHelper::getAttackTargets`, `ActionResolver::handle`,
  `BattleScreen::buildPanel` — универсальная проверка `action.condition`
  (и строка, и массив).
- SQL s1_145 — prop: actions + ability с параметризованным condition.

**Как проверить:**
Сценарий `debug/scenarious/card_s1_145_envy_demon.json`.
1. 2 врага рядом с weak 3+ → +1 к простому удару.
2. 2 врага рядом с магией → кнопка «Магический удар» активна.
3. Параметры (count, weak_min, has_magic) задаются в prop, код не меняется.

**Откат:** `git revert <sha>` + откат SQL.