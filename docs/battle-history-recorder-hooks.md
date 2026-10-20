# Stage 4B — семантические hooks и ограниченный контракт Recorder

Основа: Stage 3.2 Action Lifecycle specification и Stage 4A.1 trigger-free persistence. Stage 4B — **experimental/partial**, не полный replay. Реальный Engine остаётся единственным владельцем очередей, эффектов, выбора и фаз.

## Серверное включение

Обычный `new GameRepository($db)` оставляет Recorder выключенным: нет SELECT/INSERT в battle_history_*, построения DTO или новых требований к schema. В www/index.php, bootstrap и автоматических процессорах включения нет.

Явный opt-in серверного кода: `new GameRepository($db, new ExperimentalHistory([$gameId], $frozenCatalog))`. Пустой allowlist не включает ничего; wildcard/HTTP-параметра/env auto-enable нет. ID положительные и перечисляются явно. Catalog — доверенные versioned display entries по ukid, соответствующие полям ReplayState v1. Включение не выводится из одного наличия таблиц или metadata.

Первое recorded выполнение разрешено только из стадии **place**; нельзя включить запись в существующем battle. Metadata создаётся внутри caller transaction только после успешного apply, с completeness=incomplete. Начальный кадр появляется на реальном startBattle. Для opted-in матча использовать executeLockedCommand; saveCommittedCommand без locked apply и прямой save battle/game_over через этот gated repository отклоняются. После final дальнейшая запись запрещена.

Gate — объект серверной конфигурации repository, а не глобальное состояние. При будущей активации ВСЕ пути выбранного матча, включая подготовку, таймерные/автоматические команды и побочные сохранения, должны получать согласованный gate. Нынешний обычный web bootstrap намеренно не изменён; подготовительные HTTP-сохранения сегодня не journaled, поэтому их production routing нужно отдельно согласовать перед активацией. Без такого согласования не включать Stage 4B на реальных матчах. Предоставленный opt-in проверен с настоящими Engine/GameRepository/Command Journal, в том числе HTTP fixture, и не заменяет отдельное production activation решение.

## Поток одной команды

1. Существующий caller регистрирует processing Command Journal вне игровой транзакции, как раньше. Уже committed retry читает прежний outcome; apply не повторяется.
2. commitJournalCommand берёт journal lock, затем games lock и CAS/version validation. Только для opted-in ID читает metadata и open actions; Recorder подключается к **transient** GameState.historyRecorder, отсутствующему в games.json.
3. Engine.apply оборачивает прежнее тело applyCommand сигналами commandStarted/commandFinished. Disabled ветка вызывает прежнее тело без observer. Rejected результат не сохраняет batch, captures или новую metadata.
4. Локальные hooks добавляют факты и сериализуют DTO непосредственно в момент границы. Никакой реконструкции промежуточных полей из финального GameState.
5. После успешного apply: при необходимости provision metadata → прежний save/CAS games → HistoryRepository.append того же Db → markCommitted → единственный COMMIT. На любой обязательной ошибке весь SQL откатывается. Observer снимается в finally, включая reject/rollback/unknown outcome.

Нет отдельного journal, scheduler, pending или управления continuation. Open IDs восстанавливаются из battle_history_actions, а не по наличию strike/null. В поддерживаемом subset может быть один open combat/turn_end/battle_ready соответствующего типа; неожиданные/дублирующие scopes отклоняются как нарушение контракта. Root живёт через команды до явного settle/interruption. Child Actions в первой интеграции не нужны.

## Карта hooks

| Место | Реальный сигнал / обоснование | Capture |
|---|---|---|
| TurnProcessor.startBattle после нормализации field/flying/hidden row, создания battle и подготовки timer; **до applyStartEffects** | Подготовка поля действительно завершена. Закрывается root initial; отдельный root battle_ready начинается для стартовых последствий | initial один раз, независимо от будущих HP/armor/start изменений |
| TurnProcessor.afterStartPhase сразу после успешного TurnTimer.resume | Штатная точка завершения очередей и отложенной start_resolution уже прошла, а не просто сменился active | Закрывает open turn_end либо battle_ready; без open root ничего не создаёт |
| MovementResolver.move после MovementEffectResolver и bumpVersion | Реальное обычное перемещение успешно выполнено. Профиль certified должен исключить дополнительные обязательные эффекты | move, отдельный settled root |
| StrikeResolver.declare после создания обычного strike, до before window | Зафиксированы реальные attacker/target и тип атаки | Только combat.started, root остаётся open |
| StrikeResolver.resolve после реальных Dice::roll и присвоения base/result | Уже известны фактические dice/modifiers | combat.dice, без snapshot |
| StrikeResolver.apply сразу после присвоения final strike table | Фактическое решение движка normal/decreased принято | combat.result, без snapshot |
| DamageResolver.applyDamage после изменения HP/armor | Реальные wounds, HP/armor before/after; также armor-only удар с нулём ран | combat.wounds, без snapshot |
| DamageResolver.resolveDeath после перехода hp=0/dying=true, до death reactions | Реальная гибель отмечена движком | combat.death, без snapshot |
| StrikeResolver.continueAfterStrikeResultAck после completeCombat, flush dying и проверки after-strike execute; после openAttackSeriesChoice | Все поддержанные собственные последствия и ACK завершены; natural Hydra closure уже выполнен | combat settled и capture. Отдельная ветка с pending следующего боя тоже завершает текущий combat |
| ActionResolver.chooseAttackSeriesEnd после фактического закрытия и finalizeDying | Добровольный End, включая cancel_pending alias того же handler | Отдельный series_end, closed=true |
| TurnProcessor.endTurn непосредственно перед beginEndPhase | Переход хода принят после прежних проверок; active уже поменялся, но root НЕ завершается | Только turn_end.started; capture лишь в afterStartPhase |
| Engine wrapper после успешного apply и прежнего post-processing при реальном game_over | Фактическое terminal состояние, без принудительной доигровки pending | Open scopes interrupted; final match_terminal capture, **без completed статуса partial history** |

После hooks Recorder не выполняет Engine/Choice/TurnProcessor, не бросает кубики и не меняет игровой state. В disabled ветке hooks nullsafe, их аргументы не вычисляются, кроме двух простых локальных чтений HP/armor для damage metadata. GameLog.append и диагностические JSONL не читаются как источник и остаются прежними. GameLog по-прежнему пишет попытку исполнения до SQL commit; rollback истории не откатывает файловую диагностику — это существующее ограничение, не replay truth.

## Реально поддержанные Replay Steps

| Шаг | Условия |
|---|---|
| initial | Нормализованная расстановка до start effects; UNIQUE initial guard страхует повтор |
| battle_ready | Штатно закончено начало первого хода; отдельная граница после start effects, не технический ACK |
| move | Обычное соседнее движение в certified board без дополнительных эффектов/markers |
| combat | Обычный удар с defender selection/таблицей/ACK; HP/armor/death; один root через несколько journal commands |
| series_end | Подтверждённый добровольный End Гидры, включая alias; не сражение |
| turn_end | После полного штатного окончания end/start transition в certified profile |
| match_terminal | Фактический game_over после apply; незавершённые roots не доигрываются |

Certified predicate намеренно узкий: все card definitions есть в frozen catalog; нет modifiers/markers/cell markers; prop пуст либо содержит boolean attack_series/unanswer и простой start.get_hp. Любое другое свойство, неизвестный display effect или неподдержанный pending даёт recording_gap и прерывает observed open scopes без фиктивного capture. Техническая ожидательная структура сама по себе не доказывает завершение: только перечисленные hooks дают settle. Обычный ACK без закрытия root и настройки звука не создают step; отклонённые/no-op запросы тоже не создают его.

Не поддержаны Coin/Degasca/nested/forced combat, jump/teleport, переноса ран/Вальхаллы как отдельных действий, before/combat/after instant chains, сложные start/end task queues, произвольные healing/marker/modifier/visual свойства. R1–R3 и их gameplay работают как прежде, но в таких матчаx Recorder пишет gap вместо ложного завершения. Не сертифицируются все карты по внешнему сходству с простым ударом. Новые hooks этих механик нужны Stage 4C/4D, без массовой инструментализации здесь.

## Partial, terminal и integrity

Recorder всегда создаёт HistoryBatch(partial=true). HistoryRepository сохраняет completeness=incomplete. Даже с final capture metadata остаётся recording/has_final=1, **не completed**; дальнейшие append запрещены. Старый completedServerHistory не открывает такой match как полный реплей. Legacy synthetic Stage 4A batch с partial=false сохраняет прежний контракт; это не режим реального Recorder.

У каждого неподдержанного успешного command/семантического profile есть явный recording_gap event; он не изображает игровое действие или capture, а фиксирует предел наблюдения. Даже если позже появляются новые certified шаги, промежуток нельзя скрыть/интерпретировать как непрерывный replay. Если closure вообще не вызывает Engine, batch также содержит gap.

Сохранены event_seq, step_seq, command identity, stable boundary и capture_order constraints Stage 4A.1. Root IDs детерминированы game/journal/local ordinal; continuation берёт прежний ID из SQL actions. Внутри команды события одного root связаны cause_order, между командами — тем же root и глобальным game-local event_seq. Нет выдуманных причин между независимыми атаками серии. SHA-256 capture envelope и fail-closed чтение неизменны; UPDATE/DELETE/REPLACE кадров или новых SQL-triggers нет.

## ReplayState и историческая видимость

ReplayStateBuilder копирует stored position/HP/move/armor/strike/coins/closed/revealed/dying и frozen display catalog. Он не считает боевые результаты и не копирует pending/flags/timer/journal. Catalog передаётся server-side с revision/name/image/description/type/element/classes/price, без чтения mutable текущих свойств при replay.

Полная server projection содержит state всех instances. Participant/public projection опускает deck/sideboard, чужую hand; скрытая полевая карта остаётся facedown placeholder без definition, HP/strike/coins и секретного catalog entry. Владелец знает свою карту, public — только раскрытые. Event payload с card refs выдаётся в projection только если все связанные карты известны этому view в момент hook; иначе redacted placeholder. При чтении current revealed не используется. Поздний reveal не меняет старые bytes.

Для неподдержанных визуальных marker/modifier profiles не заявляется пригодность captures к публичному отображению; semantic gap и общий partial статус обязательны. User endpoints/renderer отсутствуют. Производственная политика assets, замороженный card catalog loader и endpoint authorization требуют отдельного согласования. Настоящие catalog свойства не выдумываются из Engine events.
