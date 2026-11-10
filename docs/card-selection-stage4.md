# CardSelectionController Stage 4

## Инвентаризация

База — `d9a2f2f`. В `ChoiceRegistry::all()` 70 регистраций. Учёт ведётся по основному маршруту обработчика. У одного класса могут быть дополнительные ветки, например подтверждение результата у GraveRitual или «да/нет» у CoinAbility. Число регистраций не равно числу способностей.

- **A, типизированные подтверждаемые формы (16)** — PairedRanged, GraveRitual, Alvalind, CoinAbility, IncarnationWound, AnyDeath, HolvertOpen, AnubisarChain, MultiHeal, MultiDischarge, DvergGift, Grezy, RowSpellPick, Strangulation, ParticlePick, LifeGift.
- **B, стандартный выбор с адаптером (12)** — ForcedStrike, AfterStrikeExecute, InstantPick, CombatPick, ForcedStrikeAdjacent, Incarnation, ValhallaPick, Whip, KoboldHeal, TalionIncarnation, AttackSeriesContinue, Revive.
- **C, специальные решения и фазы (26)** — DeckReorder, TempleDice, AbyssMovement, Tremor, DealLinkedRecruit, DealScoutRecruit, DealVariableRecruit, FirstStrikeJump, CoinSpend, DiceChoice, CellMarker, CellEffectDirection, IncarnationAck, SelfWound, CardChoice, SeggerShield, BloodTap, RowPick, OpponentRowMarker, GatePick, GreedTeleport, TurnAck, TurnInstantResult, TurnInstantStack, TurnSub, TurnPhase.
- **D, смешанные или контекстно-зависимые (16)** — CardHeal, MatriarchOrder, SwampQueenSummon, DegascaSequence, RangedAttackRedirect, WoundTransfer, IntrigueStart, ShaksiRoll, HealInsteadWound, WildHealerMove, ForcedDirectionalMove, DestroySelfAndTarget, BloodStorm, NokamiWound, TeleportTarget, RangedNeighborTeleport.

Имена в списках обозначают классы с суффиксом `Choice` в `src/Core/Choice`. У CardChoice это имя класса целиком.

## Матрица покрытия

Строки имеют разные единицы учёта и пересекаются. Например, кладбищенская форма входит и в одиночный выбор. Таблица не является подсчётом динамических веток.

| Когорта | Найдено | Общий механизм до → после | Осталось | Причина |
|---|---:|---:|---:|---|
| Одиночные подтверждаемые ветки категории A | 9 | 2 → 9 | 0 в когорте | PairedRanged, Grezy, Alvalind, CoinAbility (разряд), AnyDeath (яд), DvergGift, HolvertOpen, Strangulation, GraveRitual |
| Зарегистрированные checkbox-обработчики | 7 | 1 → 6 | 1 | IntrigueStart меняет серверный параметр `in_side` |
| Одновременные именованные группы карт | 1 | 1 → 1 | 0 | LifeGift. Grezy — последовательные одиночные шаги |
| Карточные ссылки и адаптеры категории B | 12 | 0 → 3 | 9 | ForcedStrike, ValhallaPick и Whip сохранены как немедленные ссылки. Остальным требуется сверка момента отправки и переходов |
| Ручные формы, указанные в задании | 3 | 0 → 0 | 3 | Defender смешивает варианты и пропуск. StartOrder и DiveChoice используют специальные команды |
| Выбор на кладбище в GraveRitual, Revive/StitchRevival и TalionIncarnation | 3 | 0 → 1 | 2 | Нужно сохранить клетку, изгнание, подписи жетонов и переходы |
| Зарегистрированные специальные обработчики категории C | 26 | 0 → 0 | 26 | Другая игровая семантика |

## Точки входа и контракты

- `CreatureChoice::confirmed()` получает явно объявленные строки экземпляров, контекст и пределы. `Panel::render()` строит штатную форму. Адаптер сохраняет исходные значения, порядок отправки и предвыбор; отдельный `instanceId` нужен, если wire-значение отличается. Подписи сортируются по имени.
- Одиночные ветки берут цели из текущего Pending и сохраняют `target_id`, скрытые токены операции, подтверждение и пропуск. У AnyDeath пропуск остаётся `target_id=0`. `GraveRitualChoice::spec()` не выставляет полевую привязку. Одноимённые открытые экземпляры на кладбище различаются по видимому номеру.
- `ActionResolver::parseMultiPick()` требует от 1 до `max_targets` для лечения и разряда. Частица тоже требует от 1 до `max_targets`. `chooseRowSpellTargets()` требует ровно `x`, `chooseIncarnationWound()` — ровно `count`. `StrikeResolver::chooseAnubisarChain()` требует ровно `max_targets` различных экземпляров и заново вычисляет допустимость. Формы отправляют `target_ids[]` в исходном порядке. При отмене разряда и частицы сохраняется `cancel_pending` и сбрасывается UI-режим.
- `CreatureChoice::immediateButtons()` принимает явные `instanceId` и URL. `ForcedStrikeChoice::spec()` сохраняет немедленную команду `choose_forced_strike&target_id=…`, порядок и стиль ссылок. ValhallaPick и Whip используют тот же адаптер и сохраняют собственные ссылки отмены. Данные закрытых целей маскируются.
- `BattleScreen::pendingDefenderTargets()`, `pendingRedirectTargets()`, `pendingAutoTargets()`, `pendingDeathTargets()` и `pendingAllyModifierTargets()` используют отдельные полевые ссылки. `InfoPanel::renderDefenderRadioForm()` принимает варианты `defender:ID` / `redirect:ID` и пропуск. Сохранены также `renderStartOrder()`, `renderDiveChoice()`, формы закрытия/перемещения вместо урона и обычное наведение из `BattleHelper`.
- Категория D включает серверные фильтры, смешанные payload, очереди, пропуски и выбор клеток. Существующие эвристики `CreatureChoice::decorate()` для старых потребителей сохранены без расширения. Например, Revive уже может преобразовываться старым декоратором; изменение времени отправки требует отдельной проверки.
- При отсутствии собственного идентификатора Pending адаптер использует версию состояния как консервативную границу решения. Это сохраняет выбор между снимками той же версии. Если состояние изменило версию, такая форма может сбросить выбор. Для последующих миграций предпочтительна уже существующая идентичность операции или шага.

## Подключение новой способности

Вернуть `PanelSpec` с `CardSelectionSpec` или вызвать `CreatureChoice::confirmed()` после вычисления серверных кандидатов. Указать контекст конкретного решения, пределы, исходное wire-поле и существующие действия. Для независимых групп использовать `CardSelectionGroup`. Для немедленных ссылок передать явные метаданные в `immediateButtons()`. Контрактный тест должен сверять кандидатов, пределы и фактическую команду. Глобальный запрет radio и checkbox не нужен.
