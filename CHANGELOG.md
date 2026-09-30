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

## refactor/apply-marker-poison — 2026-XX-XX

**Проблема:**
`applyMarker` и `applyPoison` дублировались в `Engine` и `DamageResolver`
с идентичными телами. Правка в одном месте не долетала до другого.

**Изменения:**
- `DamageResolver::applyMarker` и `applyPoison` — стали public.
- `Engine::applyMarker` и `applyPoison` — делегируют в `DamageResolver`
  через существующий фабричный метод `damageResolver($state)`.

**Как проверить:**
Регрессии быть не должно — оба пути и раньше были идентичны.
Проверить работу: любая карта, кладущая маркер (Хозяйка прайда — sand_claws,
Борг — stun, Мира — ничего, Василиск — rooted, Гиррит — hunt) и любая карта
с отравлением (Ундина, Сеятель, Хеди, Арацент, Ноками).

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