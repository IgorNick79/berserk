# Turn Timer T1 — проверка lifecycle

## Последовательность выполнения

1. `Engine::confirmPreparePlace` (`src/Core/Engine.php`, метод около строки 175)
   вызывает `TurnProcessor::startBattle` только при событии `stage_changed:battle`.
   `startBattle` создаёт первый период до pre-battle intrigue/Degasca и pre-turn choice;
   период остаётся `paused/start_phase`.
2. `continuePreBattleEffects` и `chooseCardOption` продолжают тот же ход через
   `continueStartTurn`. Они не создают нового периода. `continueStartTurn(false)`
   вызывает `TurnPhaseProcessor::beginStartPhase` (TurnProcessor.php:394).
   Ветка `fromIncarnation=true` не запускает стартовую фазу повторно.
3. `TurnPhaseProcessor::advance` (TurnPhaseProcessor.php:222) проходит passive/active
   очереди. Подочередь, task pending и `pending_ack` останавливают продвижение.
   Решения продолжают фазу через `resume`, `ackPending`, `runSub` или `closeSub`.
   Стартовые инстанты продолжают её только после `ackTurnInstantResult` через
   `continueAfterTurnStack(type=turn_phase)` (InstantProcessor.php:1830).
4. `TurnPhaseProcessor::finish` (TurnPhaseProcessor.php:279) удаляет `turn_phase`.
   Для start-фазы вызывает `afterStartPhase` (:295), для end-фазы —
   `afterEndPhase(active_key, passive_key)` (:291).
5. `endTurn` (TurnProcessor.php:88) проверяет команду, замораживает старый таймер,
   выбирает `getOpponentKey(playerKey)`, меняет `battle.active` (:114), увеличивает
   `battle.turn` при передаче host и запускает `beginEndPhase` (:145).
   Пока end-фаза не закончена, таймер принадлежит прежнему владельцу.
6. После end-фазы `afterEndPhase` (:154) подготавливает следующий период **до**
   pre-turn choice. Затем путь возвращается к пунктам 2–4.

## Достижимость двух последовательных периодов одного владельца

В актуальных путях исполнения недостижима. `getOpponentKey` (GameState.php:154)
для host возвращает player и наоборот. Единственное присваивание
`battle['active']` после инициализации — в `endTurn`. `beginEndPhase` записывает
того же противника в `passive_key`, который `finish` передаёт в `afterEndPhase`.
Обработчиков дополнительного хода или пропуска игрока нет. Начало боя вызывается
только при переходе из подготовки. Load/fromArray не вызывают начало хода.

Таким образом, при текущих правилах совпадение владельца в `TurnTimer::prepare`
означает повтор подготовки того же периода; следующий самостоятельный ход всегда
принадлежит другому игроку. `TurnTimer.php` и идентификация периода не изменялись.
Это доказательство относится к текущему графу вызовов, а не к произвольным прямым
вызовам публичных методов с выдуманными аргументами.

Повтор AJAX-команды с тем же command_id обрабатывается до Engine существующей
веткой журнала в www/index.php:647–756. Повтор отправки end_turn через Engine
прежним владельцем отклоняется либо текущим ChoiceHandler, либо проверкой active.
Журнал и endpoints не менялись; DB-проверки повторов в этой среде недоступны.

## Подтверждённая проблема запуска

Стартовые «Цветущие руны» — воспроизводимый путь:

`beginStartPhase -> executeRowSpell -> pending_row_spell_pick ->
chooseRowSpellTargets -> strike(results) -> TurnPhaseProcessor::resume ->
finish -> afterStartPhase`.

В `ActionResolver::chooseRowSpellTargets` (ActionResolver.php:4735–4799) результат
сражения создаётся до `resume`. Очередь уже может быть пуста, поэтому `finish`
вызывается **до двух confirm_strike**, пока `strike` ещё существует.
Прежний `afterStartPhase` возвращался из-за strike и терял единственную попытку
запуска. `StrikeResolver::confirmStrike` (:1379) после второго подтверждения
очищает strike, но стартовую фазу не возобновляет: она уже удалена.
Таймер оставался paused навсегда. Новый тест сначала воспроизвёл этот отказ,
затем прошёл с исправлением.

Следовательно, `afterStartPhase` означает конец очередей фазы, но не гарантирует
завершение всех вложенных результатов и ожиданий.

## Точные изменения

- `TurnProcessor::afterStartPhase`: не запускает таймер при существующем
  `turn_phase`. После фактического finish сохраняет в таймере
  `pause_reason=start_resolution`, если период ещё ждёт старта. Возобновляет
  его только после исчезновения strike, pending_prophecy и активного обработчика
  ChoiceRegistry. Уже running/expired периоды не перезапускаются; другой owner
  не получает новый период из этого callback.
- `Engine::apply`: после успешной команды и существующего CoinAbilities::advance
  повторяет только отложенную попытку запуска с `start_resolution`, используя
  сохранённого владельца таймера. Это не повтор исполнения start/end-фазы.
- `test_turn_timer.php`: добавлены чередование host/player при обоих первых игроках,
  повтор end_turn, повтор afterEndPhase во время pre-turn pending, ранний callback,
  pre-turn choice/prophecy/whip/result ACK, start instant stack/result OK,
  регрессия рунического результата и повторные ACK. Состояние сериализуется между
  ожиданиями. Все временные значения поступают из управляемого серверного clock.

Публичные сигнатуры не менялись. Единственное дополнение к сохраняемому состоянию —
значение причины паузы `start_resolution`; лимит и остальные поля прежние.
Новая система lifecycle не вводилась. Running-таймер не переводится автоматически
в paused из-за pending: изменения относятся только к первоначальному запуску.

## Проверки

PHP 8.1 CLI, отдельная копия исходников без live storage:

- `test_turn_timer.php` — PASS (включая дополнительный повтор end-phase после
  основного прогона).
- `test_turn_instant_stack.php`, `test_combat_instant_phases.php`,
  `test_row_spell_pending_dice.php`, `test_whip_cancel.php`,
  `test_dynamic_flying_lifecycle.php`, `test_damage_lifecycle.php`,
  `test_resign_pending.php` — PASS.
- Всего запущено 76 PHP-скриптов; 73 завершились с кодом 0. Из них 4 полностью
  пропустили DB-проверки, ещё 3 пропустили DB-подпроверки.
- `test_booster.php` и `test_cards.php` не запускаются без config/db.php.
- `test_battle_vampire_hp_ui.php` падает на проверке infinity max. Повторная проверка
  с Engine/TurnProcessor **до этой доработки** даёт ту же ошибку.
- HTTP/browser harnesses, требующие изолированного сервера/БД, не запускались.
- PHP lint изменённых PHP-файлов и `git diff --check` — PASS.

Полный diff этой доработки относительно состояния в начале запроса находится
в `turn-timer-t1-lifecycle-followup.diff`. Предыдущие незакоммиченные изменения T1
в этот diff повторно не включены.

## Оставшиеся риски

- Если появятся дополнительные ходы/пропуски, инвариант чередования перестанет
  действовать. Тогда понадобится явный идентификатор lifecycle-перехода в prepare;
  менять правила или добавлять такой механизм заранее здесь не требуется.
- Отложенный старт завершается в общем Engine::apply. Прямые вызовы низкоуровневых
  процессоров вне Engine не являются полным командным путём и требуют отдельного
  завершения обработки таймера. Текущие HTTP-команды проходят через Engine.
- Уже сохранённый прежней версией зависший paused/start_phase без turn_phase
  автоматически не мигрируется: отсутствует достоверный маркер достигнутого finish.
  Новый start_resolution сохраняется и восстанавливается без такой неоднозначности.
- Будущие стартовые ожидания должны сохранять фазу либо быть видны через текущий
  серверный контракт ожиданий. Общий контракт владельца pending по-прежнему
  отсутствует; автоматические паузы не подключались.

Правила боя, лимит 180 секунд, AJAX UI, timeout-переход и Command Journal не менялись.
Коммит не создавался.
