# Berserk — Stage 4B: Transactional Battle History Recorder

Дата: 20 октября 2026. Ветка: **codex/battle-replay-stage4b**. База и фактический HEAD: **57a382aed9b5687ca64360a1dcbf6a46361b821d**. Stage 4B подготовлен в рабочем дереве, отдельный финальный коммит не запрашивался. Merge/push/deploy и production SQL не выполнялись.

## Итог

Реализован ограниченный Recorder с реальными Engine, GameRepository и Command Journal. Immutable captures создаются локальными semantic hooks и записываются после CAS games, до journal.markCommitted, в той же InnoDB-транзакции/соединении. Проверены реальный HTTP-запрос с двумя разными кадрами одной SQL-version и Root Action через несколько HTTP-запросов. Recorder не исполняет эффекты и не управляет pending/очередями.

Feature gate по умолчанию выключен. Обычный web bootstrap не включает его. Явный opt-in — server-owned ID allowlist + frozen catalog при создании repository; первое recorded выполнение только из place. **Вся история Stage 4B experimental/incomplete**, включая terminal, и не открывается как полный replay. Неизвестные механики создают recording_gap, а не ложный завершённый кадр.

## Фиксация Stage 4A/4A.1

Перед реализацией проверен HEAD 2755c79a6f20c98725b8d9709188884f2c902ce2 и весь согласованный набор Stage 4A/4A.1. Коммит **57a382aed9b5687ca64360a1dcbf6a46361b821d**, `feat(battle): add trigger-free transactional history persistence`, содержит 15 согласованных файлов: три classes, tests, две SQL-инструкции, документацию/evidence и адресные gitignore exceptions. В нём нет production config, DB/datadir, storage или временного HTTP fixture. CREATE-миграция без CREATE TRIGGER. Блокирующих дефектов инфраструктуры при review не найдено. Новая ветка создана от этого коммита; cherry-pick/merge не выполнялись.

Для Recorder потребовалось совместимое расширение HistoryBatch(partial=true): terminal experimental batch больше не ставит completed, остаётся incomplete/has_final. Старые synthetic Stage 4A tests с partial=false сохраняют прежний контракт. Пять таблиц, SQL-миграция, triggers policy и SHA-256 envelope Stage 4A.1 не менялись.

## Файлы и точки инструментации

| Исходник | Изменение / причина |
|---|---|
| GameRepository.php | Optional server gate; observer только внутри existing locked command; append после CAS и до markCommitted; detach в finally; отказ прямого battle save через gated repository |
| GameState.php | Только transient nullable historyRecorder, отсутствует в games.json |
| Engine.php | Observer wrapper вокруг прежнего apply; actual terminal после прежнего post-processing; disabled ветка выполняет прежнее тело |
| TurnProcessor.php | Initial после нормализации до start effects; начало принятого end_turn; завершение на штатном afterStartPhase/resume |
| Movement/MovementResolver.php | Успешный обычный move после его effects; только certified profile |
| StrikeResolver.php | Живые declaration/dice/final result; завершение после обязательных последствий/ACK, включая natural Hydra closure |
| DamageResolver.php | Фактические HP/armor changes и death flag в месте исполнения, без пересчёта боевого результата |
| ActionResolver.php | Подтверждённое добровольное завершение серии после закрытия Гидры |
| BattleHistory/HistoryBatch.php | Optional partial flag, default false для совместимости |
| BattleHistory/HistoryRepository.php | Terminal partial не completed; append после has_final запрещён |
| BattleHistory/ExperimentalHistory.php | Новый explicit server allowlist и frozen card catalog; нет HTTP/env auto-enable |
| BattleHistory/ReplayStateBuilder.php | Новый ReplayState v1 copier и исторические проекции; conservative certified predicate |
| BattleHistory/Recorder.php | Новый transaction-local event/capture/lifecycle collector; SQL open identities, gap, terminal; не scheduler |

Дополнительно созданы tools/test_battle_recorder.php, [документация hooks](battle-history-recorder-hooks.md), этот отчёт и docs/evidence/stage4b/{summary.json,stage4b-evidence.zip}. Архитектурные детали/список hook locations и реально поддержанных шагов приведены в hooks document.

Полный diff review: изменения ограничены observer/persistence и явными signal calls. Порядок resolve, instant windows, wounds, result ACK, Coin/Degasca processing, death reactions и turn queues не переставлялся. Нет изменений GameLog/CommandJournalRepository/Db, SQL/schema, www/bootstrap/endpoints/templates/UI, карт или правил. Два дополнительных локальных чтения HP/armor в DamageResolver не меняют state. Нет новых runtime DDL, UPDATE/DELETE/REPLACE/upsert snapshots или отдельных COMMIT. В Stage 4B не добавлены diagnostic probes в Engine.

## Поддержка и пределы

Поддержанные Replay Steps: **initial, battle_ready, move, combat, series_end, turn_end, match_terminal**. Initial и battle_ready — реальные последовательные стадии: подготовленное поле до start effects и окончание начального разрешения. Они могут завершиться одной командой с разными HP и одной SQL-version. Доход до HTTP response, strike=null или отсутствие pending сами по себе не являются сигналами settle.

Certified board: frozen catalog для всех instances, отсутствие modifiers/markers/cell markers, prop пуст либо boolean attack_series/unanswer и простой start.get_hp. Обычный удар может включать выбор защитника/таблицы, live dice/result/wounds/death и ACK. Один combat root переживает HTTP-команды. Не создаются child actions на каждый processor/instant. После ACK и локального завершения последствий сохраняется combat-step. Гидра: каждый combat отдельно; добровольный End отдельный series_end closed=true; immediate end_turn отдельный turn_end; natural closure входит в последний combat. Старые frames не переписываются.

Coin/Degasca/nested/forced chains, Вальхалла/WoundTransfer как самостоятельные шаги, complex instants, jump/teleport, произвольные marker/modifier/start/end effects остаются неподдержанными для Recorder. Их gameplay не отключается и не меняется, но Recorder даёт gap/interrupted scope без сертифицированного кадра. Даже при отсутствии gaps experimental history всё равно incomplete; никакого completed/full replay обещания нет.

Полнота и projection suitability — разные вопросы: Recorder не выдаёт неизвестный визуальный профиль за пригодный публичному просмотру. Frozen catalog передаётся сервером; production loader/asset retention и auth будущего reader требуют отдельного решения. Для supported snapshots сохранены HP/move/armor/strike/coins/position/closed/revealed/dying; технические поля исключены. Public/opponent view скрывает deck/sideboard/чужую hand и характеристики/catalog скрытой карты. Позднее revealed не меняет прошлые JSON. Event проекции с неизвестными view card refs редактируются в redacted placeholder.

Ограничение gate: это серверный dependency repository, не глобальное включение по наличию metadata. До реальной активации нужно согласованно передавать gate всем путям opted-in матча, в том числе подготовке и automatic commands. Нынешний production preparation path не journaled; его routing намеренно не включался и требует отдельного review перед activation. В тестах используются те же реальные Engine/Repository/journal через locked command, а HTTP fixture существует только в изолированном каталоге и не является пользовательским endpoint.

## Проверки и доказательства

Isolated MariaDB **10.8.4** / InnoDB, PHP **8.1.9**. Retained datadir `C:/Users/mi/Documents/Berserk/stage4a-verification/data`, bind 127.0.0.1, порт **33389**, --no-defaults; @@datadir/port/database проверены перед setup и в тестах. Это отдельный disposable server, без production/service. Новые app/results/config/session/error/storage находятся в `C:/Users/mi/Documents/Berserk/stage4b-verification`.

Финальные Engine integration tests: **berserk_stage4b_run6**, account stage4b без TRIGGER, явные права только на synthetic schemas. Stage 4A.1 tests: berserk_stage4a_run8 / stage4a1 без TRIGGER. Старые regressions: berserk_stage4b_regression; эта база **не содержит battle_history таблиц**, что подтверждает работу default disabled repository без зависимости от них. Полный timer_sync HTTP suite дополнительно выполнен в отдельной timer-app/berserk_turn_timer_t2_test (порт HTTP 33491) с тем же datadir и всеми исходными guards; без SKIP. Данные/предыдущие прогоны сохранены, не DROP. Сервер после проверки остановлен.

| Проверки | Результат |
|---|---|
| Реальный Engine + GameRepository + Command Journal | **20 PASS**, 0 FAIL/SKIP |
| Trigger-free Stage 4A.1 SQL/integrity/concurrency suite | **23 PASS** |
| Адресные R1–R3 cases | **33 PASS** |
| Существующие regression suites | **21 suites PASS**, exit=0, без SKIP (18 прежних + настройки игрока, пауза, полный timer HTTP sync) |
| PHP lint изменённых/новых PHP | **14/14** |
| Git diff --check / отдельная проверка новых файлов | Успешно |
| Миграция/production config/DB/logging вне задачи | Не изменены |

Всего **97 проверочных единиц** (20 + 23 + 33 + 21; suites считаются одной единицей, их внутренние assertions не суммируются), плюс lint и integrity. Неисправленных блокирующих ошибок не осталось.

Новые integration cases доказывают:

1. Initial hp=20 и последующий battle_ready hp=25 после реального start.get_hp; отдельные roots и одинаковая конечная persistenceVersion. DB reload подтверждает оба snapshot.
2. Реальный move с координатами/расходом движения; прежний initial JSON неизменен.
3. End-turn кадр **после** восстановления move следующего игрока; повторный отклонённый End не даёт шага.
4. Live declaration→dice→result→wounds→settled, один combat Root ID в нескольких journal commands, без capture на первом ACK.
5. Реальная гибель с combat.death и graveyard после ACK. Отдельно lethal strike с game_over и terminal snapshot фактического state без принудительного удаления оставшегося strike.
6. Оба сценария Гидры, immutable combat/End/end_turn frames.
7. Committed retry не вызывает Engine, возвращает прежний batch и не дублирует initial.
8. Rejected команды и успешная настройка звука без изменения поля не создают snapshots.
9. Ошибки обязательного event INSERT и snapshot INSERT откатывают games JSON/version, lifecycle, events/captures/counters/batch и committed transition. Processing registration, сделанная до transaction, сохраняется.
10. Ошибка **второго** initial capture также откатывает уже вставленный первый capture и initial metadata; retry прежней команды успешно создаёт ровно два кадра.
11. Disabled Recorder не пишет history, pending/timer/GameLog сохраняются; старый endpoint suite работает с default repository и без history tables.
12. Unsupported on_move profile реально меняет поле, но даёт gap/incomplete и не создаёт ложного certified step.
13. Скрытая карта 91: opponent frame без HP/definition/name/catalog; owner frame содержит известное ему. После end_turn/reveal ранняя projection неизменна.
14. Реальный resign и lethal strike дают final, но partial metadata не completed; full-history reader остаётся закрытым.
15. Mid-battle activation и direct-save bypass через gated repository отклоняются.
16. **Настоящий локальный HTTP POST** через ephemeral PHP server создаёт два разных initial captures в одном запросе; duplicate HTTP command возвращает прежний committed outcome. Последующие HTTP strike/choices/ACK сохраняют тот же open→settled root.
17. Два PHP-процесса конкурируют на реальном executeLockedCommand: один committed, другой stale, один новый frame, непрерывный event_seq и отсутствие orphan batch.

Финальные примеры snapshots и structured events находятся в docs/evidence/stage4b/stage4b-evidence.zip: final/summary.json содержит initial до/после start effects, три Hydra frames, terminal и HTTP outcome; транзакционные proof rows — final/transaction-proof.json. Hashes всех delivered/tested sources — final/source-integrity.json. Старые Stage 3/3.1/3.2/4A/4A.1 evidence сохранены.

## Review найденных вопросов

Первый draft fault-теста добавлял CHECK, запрещавший уже существующие initial rows; исправлен только harness на запрет новых sequences нужного synthetic game. Затем добавлена адресная проверка второй initial вставки. Был также один false failure endpoint suite из-за повторного положительного game ID в общем изолированном GameLog namespace разных synthetic DB. Назначен непересекающийся ID range: regressions от 1000001, финальная integration schema от 4000001; suite повторно прошёл без изменения исходников endpoint/GameLog. Старые failed outputs сохранены как lab evidence, финальные результаты отдельно.

При code review metadata provisioning отложено до успешного apply: rejected команда не оставляет новую history metadata. Настройки звука распознаются как orthogonal command, не обрывают combat root и не создают шага. Metadata без initial не позволяет обойти запрет mid-battle activation; финальный enabled прогон повторён после добавления этой проверки. Загрузка неожиданных/дублирующих open scopes отклоняется, а не молча объединяет identities.

## Оставшиеся риски и решение Lead

SQL atomicity доказана для actual repository path; файловая диагностика GameLog остаётся вне SQL transaction и может отражать попытку, которая откатилась. Она не источник replay. Physical network/COMMIT ACK loss, server crash/power failure и весь operational recovery не симулировались заново; используется прежний unknown outcome protocol с fresh journal/batch lookup. SHA-256 не подпись и не защита от внешней согласованной подмены contents+hash либо DELETE.

Отдельного migration SQL не требуется, пять production-таблиц не менялись. Production HEAD/DDL по-прежнему не проверены Codex, production activation не выполнялась. Не использовать partial history как готовую пользовательскую хронику. Перед активацией Lead должен отдельно согласовать bootstrap/preparation/automatic routing, frozen catalog provider, profile expansion и auth/projections. Stage 4C/4D должны добавить недостающие semantic hooks, а не реконструкцию эффектов из итогового state.

Stage 4B остановлен на предоставлении этих результатов Project Lead. Stage 4C, merge, push и deploy не выполнялись.
