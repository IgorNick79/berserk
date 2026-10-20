# Бой 493 — причины recording_gap и минимальный UI-патч

Основа: codex/battle-replay-stage4d2, HEAD 173633012e0da240d3fc3b9bd34d341eabb2865d. Production агентом не читался и не изменялся. Данные боя — предоставленные Lead результаты SELECT (19 строк); целиком payload, сессии и секреты не запрашивались.

## Фактические причины первых 13 предупреждений

В исходном описании было13 предупреждений. Полученный позднее список содержит19; первые13 заканчиваются event_seq36. Это согласуется с расширением истории, но точный момент первого снимка UI нам неизвестен. Event_seq — номер события, а не порядковый номер предупреждения: recording_gap.settled и другие события занимают промежутки.

| event_seq | Команда | Причина по сохранённым данным и коду |
|---|---|---|
| 1,3,5,7 | place_card host | uncovered_command:place_card: успешная расстановка без history hook |
| 9,11,13,15 | place_card player | то же для второго игрока |
| 19 | confirm_place player | uncertified_mechanic_or_display: старт боя/первого хода не прошёл simple() |
| 24 | move host | uncertified_mechanic_or_display: состояние вне сертифицированного snapshot subset; live move всё равно может записаться |
| 29 | uchr host | uncertified_mechanic_or_display: УЧР требует prop.actions; simple() такой prop не допускает. УЧР не открывает root через Recorder.combatStarted |
| 34 | confirm_strike host | uncovered_command:confirm_strike: техническое первое ACK после УЧР, без поддерживаемого открытого root |
| 36 | confirm_strike player | uncertified_mechanic_or_display: завершающее ACK проходит через combatFinished→eligible→simple=false |

Итого первых13: **8 gaps расстановки +4 отказа snapshot-сертификации +1 uncovered ACK**. Далее seq39 strike,41 choose_defender,44 combat_instant_pass,48 confirm_strike,51/57 end_turn — ещё6 uncertified gaps. Во всём предоставленном списке: 8 place_card,10 uncertified,1 uncovered ACK =19.

Причина группы uncertified в БД намеренно общая. Кроме достаточного условия prop.actions для выполненного uchr, по этим строкам нельзя установить конкретный card/prop/pending/catalog, отвергнутый simple(). Не объявляем catalog боя493 пустым: конфигурация и исторические projections не предоставлены. Гипотеза пустого/неполного catalog воспроизводится локально, но не доказана для production.

## Где возникает избыточность

Recorder.commandStarted сбрасывает gap=false/covered для каждой команды. unsupported() ограничивает повтор только **внутри одной команды**, создавая started+settled; на следующей команде может появиться новый gap. commandFinished исключает confirm_place, player settings и лишь несколько continuation-команд при наличии open root. place_card/unplace_card, большинство preparation-команд и различные служебные ветки такого покрытия не имеют. Обычные GET sync, чтение журнала и произвольные HTTP-запросы сами по себе не дают gap: нужна наблюдаемая успешная команда/путь batch. Rejected команды не append-ятся; тот же idempotency command ID обслуживается Command Journal, а не считается новым действием.

PrepareProcessor.placeCard/unplaceCard изменяют расстановку, но не вызывают history hook. Поэтому каждый такой ход до первого боя помечается uncovered. Это реальные непокрытые preparation действия в текущей архитектуре, а не поломка Engine; не следует трактовать восемь предупреждений как восемь потерь в уже начавшемся сражении. confirm_place первого игрока сам по себе exempt; финальное подтверждение вызывает TurnProcessor.startBattle→initial/startTurnFinished, где запускается snapshot eligibility.

ReplayStateBuilder.simple проверяет **все** карты состояния, включая резерв/другие зоны: catalog каждой карты, отсутствие markers/modifiers, очень узкий prop whitelist (bool attack_series/unanswer и специальный start/get_hp). Любой другой prop, даже обычный actions или значение false, отвергается. Cell markers и truthy pending/turn stacks/coin continuations тоже отвергаются. Поэтому штатные карты и pending служебных этапов могут давать uncertified при каждом новом запросе, даже когда текст события записан корректно.

LiveJournal только авторизует сторону и вызывает HistoryRepository.journalPage. Reader выдаёт каждое сохранённое recording_gap.started как отдельную generic строку и одновременно ставит recording.has_gaps. JournalText не создаёт gaps; форматирует сохранённую проекцию с _journal, не читает актуальное состояние. До патча UI добавлял каждую gap-строку в ol, хотя уже показывал общую плашку: это непосредственная причина спама предупреждений.

## Почему одна запись о начале хода

Turn.started не ссылается на карту; потому доступен при пустом catalog. Карточные события требуют frozen definition всех references и допустимой исторической видимости; неизвестная definition даёт null projection, неизвестный _journal/name — отсутствие текста. Позднее их reader не восстанавливает из нынешней карты. При полном catalog допустимые move/combat события сохраняются и при несертифицированном snapshot; это подтверждено тестом. Само отсутствие Replay Step **не доказывает отсутствие текстового события**.

Воспроизведён отдельный синтетический пример: 12 настоящих place_card, оба confirm_place, пустой catalog →13 gaps и одна видимая строка turn.started. Он доказывает механизм, но не подменяет факты боя493: у него восемь place_card в предоставленном списке, затем уже были move/uchr/ACK. Чтобы установить причину отсутствия конкретного текста именно493, нужны проверки сохранённых selected projection на null/_journal/names и approved catalog оператором, без публикации чужих проекций или секретов. Историю нельзя достраивать задним числом.

## Минимальный применённый патч

Единственный runtime файл — www/assets/js/battle-journal.js. Gap entries участвуют в seen/cursor/pagination, но не добавляются как li. Один существующий data-journal-notice показывает:

> История боя записана не полностью. Некоторые события могут отсутствовать

hasGaps запоминается в клиентской сессии панели и поддерживается recording.has_gaps. Содержательные event rows остаются в исходном порядке и отображаются через textContent. Нет изменения API/SQL/записи, сокрытия игровых событий, изменения side projections или ослабления snapshot eligibility. Каждый настоящий gap с seq/reason остаётся в БД/API для диагностики. При перезагрузке панель заново получает флаг из API.

Источник gaps **не исправлялся**. По анализу доказано, что generic eligibility warning и отдельный uncovered ACK избыточны как пользовательские сообщения, но безопасное изменение Recorder требует раздельной политики coverage. Минимальные дальнейшие предложения: явно определить границу начала текстового battle journal относительно preparation; отдельно учитывать text coverage и snapshot certification, не создавать semantic missing-event gap только из-за отказа capture; привязать ACK к конкретному root УЧР, не добавлять blanket exemption всех ACK/pending. Сохранить gaps для действительно неподдержанных событий и исторические null projections. Эти изменения не входят в текущий патч и не должны маскировать реальное отсутствие покрытия УЧР.

## Проверки

Изолированная source-only копия без config/db.php/production storage/.git; Engine пишет только в лабораторный GameLog. Ни SQL, ни MariaDB, ни production HTTP не запускались.

- Настоящие Engine.apply place_card/confirm_place, начало боя, move, обычный strike с dice/results/ACK и end_turn — PASS.
- Повтор отклонённого move: нет event/gap — PASS. Same-command-id database retry отдельно не прогонялся без БД; его путь не менялся и проверялся Stage4D/4D.1.
- Неизвестный Recorder prop при известном catalog: capture отсутствует, move text остаётся — PASS.
- Настоящие uchr + обе confirm_strike: последовательность reason как seq29/34/36 — PASS.
- Исторический owner move виден, opponent hidden проекция null — PASS.
- Pending fixture before/after passive observation равны — PASS; Engine/Recorder/Builder/reader/PHP/pending исходники совпадают с HEAD.
- Два изолированных headless Edge контекста, API fixtures (не настоящий сервер):13 начальных gaps + один turn; затем ещё gap и реальные по типу текста события. Плашка одна, gap-li0, содержательных строк host5/player4, секрет owner-only не виден opponent, cursor19, пагинация/пустой повтор/reconnect/toggle, дубликатов0, textContent XSS case — PASS.
- PHP lint диагностического теста и node --check патча/браузерного теста — PASS; git diff --check — PASS.

Evidence: docs/evidence/journal-gap (данные Lead, Engine trace и UI assertions); воспроизводимые тесты tools/test_journal_gap_causes.php и tools/test_battle_journal_gap_ui.cjs. PHP: BERSERK_JOURNAL_GAP_ISOLATED=1 и BERSERK_STAGE31_ISOLATED=1 только в source-only копии. UI: PLAYWRIGHT_MODULE/при необходимости BROWSER_EXECUTABLE и JOURNAL_JS с путями стенда; test не обращается к реальному хосту journal.test — все fetch перехватываются.

Для последующей согласованной ручной установки runtime patch достаточно battle-journal.js, с обновлением cache/version по политике хостинга; старый Stage4D.2 release ZIP содержит предыдущий JS и в этом задании не пересобирался. В ходе этой задачи ничего не выкладывалось/не отправлялось в remote. Бой493, SQL и Replay Preview не изменялись.
