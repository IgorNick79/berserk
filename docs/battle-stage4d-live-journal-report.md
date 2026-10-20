# Stage 4D — Live Battle Journal

Дата: 2026-10-20. Ветка: `codex/battle-replay-stage4d`. Базовый/фактический HEAD: `139979d92e6921a187dcaebf783f8c5f6257f389` (отдельный коммит Stage4C уже существовал). Stage4D подготовлен в рабочем дереве этой новой локальной ветки. Merge, push, deploy и production SQL не выполнялись.

## Результат

Внутри боя работает текстовая панель за кнопкой «Логи»: toggle/закрытие, хронологические записи, прокрутка к предыдущим строкам, обновление без reload, сохранение scrollTop при чтении старых строк и продолжение после reconnect. После game_over есть отдельная кнопка доступа к сохранённому журналу. Панель не заменяется при обновлении battle fragments и не смешана с чатом.

Источником остаются структурированные battle_history_events того же Recorder и той же journal/CAS-транзакции. Тексты не сохраняются параллельным GameLog: JournalText форматирует только уже сохранённую проекцию нужной стороны. Existing GameLog остаётся прежней диагностикой; Result.events не парсится. Новых таблиц, триггеров и миграций нет.

API `/battle-journal.php` — отдельный SELECT-only route. Он не подключает index.php, не создаёт игровой repository, не запускает Engine/automatic progress, не пишет role/session/game/journal/timer/pending. Выдаёт только seq/kind/text, cursor, has_more и ограниченные recording flags. Не выдаёт raw payload, UKID, root/journal/command metadata. Фиксирует watermark и читает bounded pages одной исторической host/player projection. Cursor проходит скрытые и технические строки, пустой видимый ответ поддержан.

## Аудит и подключение

Матрица фактических источников подготовлена до реализации: [battle-stage4d-event-matrix.md](battle-stage4d-event-matrix.md). Уже существующие movement/combat/dice/result/wounds/death hooks переиспользованы, без дублирования. Dice сохраняется в реальном resolve, final — после эффектов в apply, раны — после actual mutation. Recalc не пишет повторные броски/результаты.

Добавлены безопасные наблюдения active heal (clamped HP delta), vampire heal, instant heal/status, applied combat/turn instant summary, отдельных Valhalla heal/modifier, начала/конца хода. Номер завершаемого хода копируется до существующего увеличения счётчика, а игровой порядок остаётся прежним. Значения HP/closed/эффекты и условия их применения не изменены.

В journal-enabled тестах допуск к событию отделён от допуска к snapshot. Uncertified combat сохраняет один event root между командами, но не создаёт combat snapshot. Gaps и event-only marker сохраняются в имеющихся lifecycle/action fields; события не превращаются в новые Replay Steps. При неизвестном nested combat observer явно прерывает прежний неполный scope и пишет gap, вместо исключения, способного помешать игре. Полная вложенная причинная модель не заявляется.

## Видимость и доступ

Исторические имена и refs копируются в projection при emit. Все относящиеся к событию cards должны быть известны стороне и иметь подтверждённую frozen definition, иначе projection null. Последующее раскрытие не обогащает старую запись. Имена способностей/instant statuses консервативно owner-only; другая сторона видит отдельно разрешённые фактические исходы. Gap показывается без его скрытой причины. Старые события Stage4B/C без нового display envelope не реконструируются из текущего GameState.

Полной авторизации в прототипе по-прежнему нет. `first/second` и legacy user_id не дают доступа. В production всё OFF. Ограниченный режим принимает trusted principal участника либо отдельную заранее созданную сервером PHP-session binding, ограниченную game/side/expiry. Сторону API определяет сам; пользовательские side/role/player_id и любые лишние query keys отвергаются. Нет публичного endpoint выдачи binding. Тестовый lab-login существует только в disposable стенде/evidence, не в repository www.

Это разрешённый заданием тестовый способ доступа, а не новая общая авторизация. Он не записывает trusted principal и не ослабляет Stage4C Replay Preview: тестовая binding не открывает full replay. Для production-доступа требуется отдельное решение Lead и безопасный server-side provisioning; иначе использовать только стенд. Точный план: [battle-stage4d-rollout.md](battle-stage4d-rollout.md).

## Проверки

PHP8.1.9; MariaDB10.8.4/InnoDB, loopback33389, подтверждённый datadir `C:/Users/mi/Documents/Berserk/stage4a-verification/data`; финальная свежая схема `berserk_stage4d_run3`, synthetic game IDs от30000001. Source-only copy без .git, production config/storage. У тестового DB user нет TRIGGER; отдельный SELECT-only user успешно обслужил journal API. Lab data и прежние отчёты сохранены.

| Набор | Итог |
|---|---|
| Повтор UI Stage4C перед созданием ветки | PASS, Edge/Playwright, review diff clean |
| Реальные HTTP Stage4D | 17 PASS (10 основных +3 дополнительных + status + access expiry + turn labels + final read isolation) |
| Два PHP workers / реальные InnoDB locks | PASS: один commit, один stale, одно move, непрерывный event_seq |
| Observer/access contracts | 4 PASS: expire/revoke/zero identity; unknown refs; nested event-only reload; один dice-change без событий от recalc |
| UI Edge headless, две независимые сессии | PASS: live update, toggle, panel identity, scrollTop=0, reconnect, нет duplicate/reload/page errors |
| Regression Recorder Stage4B | 20 PASS на новой berserk_stage4b_run11 |
| Regression хранения Stage4A.1 | 23 PASS на новой berserk_stage4a_run11 |
| Регрессионные suites | 21 PASS/no skip, включая33 R1–R3 cases |
| PHP lint / git diff --check / JS syntax | PASS; exact delivered/tested source hashes в evidence |

Проверки включали реальные HTTP place confirmations, move, strike, defender choice, combat instant play/pass, ACK, активное лечение, turn-stack status, конец хода, lethal death/terminal и resign. В instant case результат strong→medium и3 фактические раны; ровно один combat.dice/result/wounds, один applied ability, один combat root через несколько команд. В active/vampire heal записаны фактические2HP вместо номинального5/4. Status и способность после turn instant действительно присутствуют; их отсутствие не считалось PASS.

### Покрытие обязательных требований

| Требования задания | Доказательство |
|---|---|
| 1–4: сессии, разные projections, hidden, later reveal | HTTP normal/hidden, two-players.json, неизменные байты старого player_json=null |
| 5–9: move, strike, dice, instant-final, wounds | HTTP normal/instant, реальные stored events/root/HP |
| 10–13: heal, death, end turn, terminal | HTTP heal/lethal/normal + vampire + turn-label boundary |
| 14: incomplete/gaps | HTTP instant + snapshot policy; нет uncertified combat Replay Step |
| 15–17: retry, rollback, concurrency | committed retry; CHECK fault INSERT; два InnoDB workers |
| 18–19: live UI/scroll | два Edge contexts, реальные HTTP команды и existing sync, panel не заменён |
| 20–21: readonly state/timer/pending | byte comparison всех persisted rows; SELECT-only; throw-on-load gameplay traps; отдельное чтение при реальном pending turn instant |
| 22–24: чужой матч, spoof side, legacy | HTTP403/400, expiry403, full replay401 для binding-only session |
| 25: Recorder OFF | normal unlisted game принимает старые команды и не пишет историю |

CHECK fault применялся только к synthetic event insert в лаборатории, затем снят. На rollback games/json/version и пять history tables не меняются; отдельный failed command journal row не превращается в событие. Concurrency проверялась отдельными PHP workers через реальный GameRepository/Engine, поскольку single-process PHP web server сериализует HTTP. Read isolation дополнительно проверена throw-on-load traps Engine/GameRepository/AutomaticBattleProgress/HttpBattleProgress только в disposable copy, с восстановлением исходных байтов. В рабочих исходниках диагностики нет.

В первом UI прогоне тестовый дочерний HTTP client попал в localhost proxy; отключён proxy только в fixture client. Найденные при review дефекты исправлены: панель перекрывала кнопку (позиция привязана к кнопке); end-turn использовал уже увеличенный номер (локально копируется прежний); неизвестный nested scope мог вызвать observer exception (event-only gap); zero principal не считается user ID. Финальные HTTP/UI выполнены на свежей схеме после исправлений. Старые fixture data/outputs не удалялись. Стендовый chat не провизионировался: его503 ответы не использовались как journal evidence; сам chat не изменялся.

## Поддержка и ограничения

Поддержаны: начало/конец хода, обычное movement/strike, исходные броски и применённые изменения dice, итог атаки после инстантов, раны/поглощение бронёй, death, active heal, vampire heal, instant heal/open/close/marker, применённые combat/turn instants, отдельные Valhalla heal/modifier, добровольный series_end, terminal. Термин «поддержаны» относится к этим hooks, не ко всем возможным веткам карточных механик.

Неполное/неподдержанное покрытие: multi-heal, kobold/wild healer, regeneration/start/death triggers вне перечисленных мест, все частные Coin/Degasca/WoundTransfer/статусы/модификаторы и nested continuations. Там возможны gaps и отсутствие отдельных строк; события не вычисляются из конечного состояния. Full event completeness не устанавливается даже при terminal snapshot. Название способности может быть скрыто от соперника даже если итог HP виден — это сознательная fail-closed граница до подтверждения всех правил.

Global cursor показывает продвижение общего источника без содержимого скрытых строк; endpoint остаётся приватным и не предназначен для публичной аналитики. Секретами доступа являются session cookie и server binding; их выдача/revoke — задача доверенного оператора, не GET role. На первом открытии загружаются все видимые страницы в DOM; для больших production историй нужна отдельная оценка памяти/пагинации. Переход game_over по-прежнему использует штатную навигацию самой игры, после которой журнал доступен отдельной кнопкой; в обычном live бою журнал не перезагружает страницу.

Чтение не влияет на Engine/pending/timer; фоновый игровой sync сохраняет свои прежние automatic функции. Прямые внежурнальные saves и playmat guard Stage4B остаются прежними. При остановке записи не возобновлять её на том же матче с пропущенными командами. Новую общую авторизацию, публичные реплеи, полную поддержку всех карт, анимацию и последующие стадии не реализовывали.

## Изменённые файлы

Runtime: `config/battle_history.example.php`; `src/Core/ActionResolver.php`, `DamageResolver.php`, `Engine.php`, `GameRepository.php`, `InstantProcessor.php`, `TurnProcessor.php`, `ValhallaProcessor.php`; `src/Core/BattleHistory/ExperimentalHistory.php`, `HistoryRepository.php`, `HistoryRuntime.php`, `Recorder.php`, `ReplayStateBuilder.php`; новые `JournalText.php`, `LiveJournal.php`; `src/View/Screen/Battle/InfoPanel.php`; `www/index.php`, `www/assets/js/battle-sync.js`; новые `www/battle-journal.php`, `www/assets/js/battle-journal.js`, `www/assets/css/battle-journal.css`.

Tests/docs: новый guarded `tools/test_live_battle_journal.php`; этот отчёт, event matrix, API doc, rollout doc; `docs/evidence/stage4d/README.md`, `summary.json`, `stage4d-evidence.zip` с HTTP/integration/UI fixture source, реальными событиями обеих сторон, SQL proof, скриншотами и regression outputs. Credentials/local config/БД/sessions/datadir/storage в артефакты не включены. Старые Stage3–4C отчёты/evidence сохранены.

Документация API: [battle-live-journal-api.md](battle-live-journal-api.md). Доказательства/воспроизведение: [evidence/stage4d/README.md](evidence/stage4d/README.md). Лабораторные серверы после проверок остановлены, данные сохранены.
