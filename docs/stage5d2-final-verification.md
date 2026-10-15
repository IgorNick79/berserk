# Stage 5D.2 — Final Verification

Дата: 2026-10-15. Рекомендация: **NOT READY TO CLOSE**.

Проверена база `090a7c3241ef5106454064f7d61259d252ab3e95` после Stage 1B. До начала аудита по отдельному предыдущему поручению Stage 1B fast-forward слит в локальную `main` с `2d48026`. Сам аудит выполнен в `codex/stage5d2-final-verification`; merge/push этой ветки не выполнялись. `origin/main` остался `21baed7d524307d254a32cf8775e76b9ca29abf3`. `feature/bulbagur` не менялась: `9924c5ac2cfa64b618a6208bc7d70ef487ce3d34`.

## 1. Фактическая архитектура

Порядок в `www/index.php`:

1. Определение AJAX-маршрута, конфигурация, PHP session. Обработка `debug` и `debug_roll`, затем `Dice::init()` — **до проверки участника** (строки 391–422).
2. Подключение БД; роль из `first/second`; определение userId; проверка game; загрузка State; membership check; определение playerKey (468–522). Это прототип авторизации: `first` означает userId=1/host, `second` — userId=2/player. Проверка участия существует, полноценной аутентификации здесь нет.
3. `battle_sync` (524–564): status/no_change/snapshot, без Engine и сохранения State.
4. `battle_command_status` (567–601): чтение журнала в пространстве game/player/command_id, без Engine.
5. `battle_command` (605–988): POST и expected_version; далее **две разные ветки**.
6. Только после AJAX — legacy command (992–1110): во время battle наличие непустого `cmd`, включая массив, приводит к 302 на очищенный URL; Engine не вызывается. Вне battle сохраняется существующий маршрут команд подготовки.

### Команда с command_id

Валидация id/type/payload → `registerProcessing` (652, отдельная autocommit-запись) → повтор существующего id обрабатывается без Engine → проверка expected_version/status → Engine::apply (761) → при отказе `rejected` и перечитывание State → при успехе `saveCommittedCommand` (786) → перечитывание и ответ.

`GameRepository::saveCommittedCommand` (84–131): одна connection, BEGIN → блокировка processing-записи → CAS UPDATE games (json, status, persistence version) → UPDATE journal committed/before/after → COMMIT. Обе таблицы InnoDB. При известной ошибке выполняется ROLLBACK. Начальная processing-запись создана раньше и откат не удаляет её.

Уникальность — `(game_id, player_key, command_id)`; повтор обязан иметь прежние command_type, payload hash и expected_version (`CommandJournalRepository.php:39–95`). Изменение UI-параметров не меняет игровой payload. Повтор committed возвращает актуальный snapshot, а не воспроизводит сохранённый HTTP-ответ. Повтор processing не запускает Engine. Это идемпотентность в пределах данного ключа, а не «любые одинаковые действия всегда исполняются один раз».

### Команда без command_id

Отдельная совместимая ветка `index.php:899–981`: expected_version/status/payload → Engine (946) → обычный CAS save (963) → snapshot. Command Journal здесь отсутствует. CAS отклоняет повтор со старой версией, но не заменяет устойчивый идентификатор намерения. Универсальная гарантия «каждая успешная боевая команда проходит журнал» фактически не выполнена.

### Отдельный файловый GameLog

`Engine.php:19–23` записывает результат выполнения до транзакции сохранения. Это `storage/games/.../events.jsonl`, не Command Journal. При последующем SQL-откате в GameLog остаётся `success=true` для несохранённого действия; это воспроизведено и приложено в HTTP results. Файловый лог нельзя использовать как доказательство DB commit. Изменять его архитектуру в этом аудите не стали.

## 2. Изоляция и методика

Отдельная копия исходников: `C:/Users/mi/Documents/Berserk/stage5d2-verification`. Рабочие config/db.php, партии и storage исходного репозитория для мутирующих тестов не использовались.

MariaDB 10.6.9, `127.0.0.1:33874`, БД `berserk_stage5d2_test`, отдельный datadir внутри тестовой копии. Все четыре таблицы (`games`, `battle_command_journal`, `cards`, `elements`) проверены как InnoDB; каталог cards импортирован из tracked tmp/cards.sql и до запуска тестов переведён из MyISAM в InnoDB. AUTO_INCREMENT games начат со 100000, чтобы не пересечь фиксированные номера файловых логов Engine-тестов.

Реальный PHP 8.1.9 HTTP server обслуживал копию на `127.0.0.1:8797`. Тест использует HTTP stream с `follow_location=0`, читает реальные status/Location и PHP-разбор query/form parameters. Это дополняет существующие CLI endpoint-тесты: их headers_list без Xdebug не доказывает Location.

Новый `tools/test_stage5d2_http_audit.php` отказывается работать из checkout с `.git`, требует явные URL/datadir, проверяет имя БД, фактический datadir, InnoDB и одноразовым случайным probe подтверждает, что HTTP server обслуживает именно тестовую копию, до создания партий. Он создаёт fixtures и временные SQL triggers только в этой БД; triggers и временное переименование cards восстанавливаются через finally. Результат с FAIL имеет exit=1; это аудит acceptance, а не фиктивно зелёный регрессионный тест.

Первый прогон: 46 сценариев, 40 PASS / 6 FAIL. После двух локальных исправлений и расширения сценариев: **50 сценариев, 47 PASS / 3 FAIL, exit=1**. Полные Expected/Actual по каждому запросу: [baseline](stage5d2-http-baseline.json), [итог](stage5d2-http-results.json).

## 3. Дефекты и решения

### D1 — успешная команда без Command Journal; блокирует закрытие заявленной гарантии

Источник: `www/index.php:628` выбирает journal только при наличии POST command_id; ветка `946–963` выполняет Engine и обычный save.

Воспроизведение: свежая battle fixture, POST `ajax=battle_command`, `cmd=gain_coin&card_id=1&expected_version=2`, **без command_id**, в том числе с query `debug_roll=6*`. Actual: HTTP 200, ok=true, persistence version 2→3, карта получает фишку и закрывается; journal пуст. Это существующая совместимость, не регрессия Stage 1B. Отсутствующий id отличается от пустого/массива id: последние отклоняются 400.

Минимальное предложение: после согласования совместимости требовать валидный command_id для мутирующего battle_command и отклонять отсутствие до Engine. Генерировать случайный id на сервере недостаточно для защиты от повторной отправки. В этом аудите не изменено, поскольку удаление старой ветки меняет согласованный AJAX API; существующий endpoint-тест прямо проверяет работу без id. Альтернатива — официально сузить гарантию этапа до keyed-клиента и документировать исключение, но это решение Project Lead.

### D2 — известный rollback оставляет команду в processing; блокирует корректное завершение lifecycle при сбое

Источники: processing INSERT в `CommandJournalRepository.php:55`; транзакционный rollback в `GameRepository.php:114–130`; `index.php:787–888` отдельно обрабатывает stale/unknown, остальные ошибки попадают в общий `catch` 982, возвращающий только server_error.

Воспроизведение дважды: BEFORE UPDATE trigger с SIGNAL SQLSTATE '45000' (а) на games нужной fixture; (б) на journal при NEW.status='committed'. Отправить новую keyed gain_coin. Actual: HTTP 500 JSON; State/json/status/version полностью прежние; journal processing с null before/after. Удалить trigger и повторить тот же запрос: HTTP 200 command_status=processing, terminal=false; Engine повторно не запускается. В коде нет lease/timeout или восстановления такого processing, поэтому повтор сам не завершит lifecycle. Это **не потеря атомарности State/committed**, а зависание результата после известного отката.

Минимальное предложение: отличить подтверждённый rollback от неизвестного исхода commit/rollback и только для первого завершать ранее созданную запись существующим `markFailed` (`CommandJournalRepository.php:220`) с command metadata в ответе. Unknown outcomes нельзя помечать failed наугад. Нужны согласование поведения восстановления и отдельные failure-injection проверки; обработчик/репозиторий в этом аудите не менялись.

### D3 — массив debug_roll повреждал JSON; исправлено локально

`www/index.php:411–412`: массив приводился к строке до AJAX try/catch. `battle_sync&debug_roll[]=6` возвращал HTTP 200 с `Warning: Array to string conversion` перед JSON при display_errors=On. При выключенном display_errors предупреждение скрыто, но некорректное значение всё равно попадало в session.

Исправление: обрабатывать только строковый HTTP-параметр через `is_string`. Массив игнорируется; штатные строковые очереди, `6*`, пустая строка и off сохраняют прежнюю семантику. Проверены sync и keyed command/replay с массивом — корректный JSON, без лишних мутаций.

### D4 — cmd[] вне battle вызывал необработанный TypeError; исправлено локально

`www/index.php:992`, перед созданием `Command` на 1088. В состояниях mode/game_over `cmd[]=resign` доходил до конструктора со значением array. На данном PHP runtime Fatal error оказался в теле с HTTP 200, поэтому одного status check недостаточно.

Исправление: строковый commandType только из строки, иначе ''. Существующий `hasLegacyCommand` оставлен: массив cmd во время battle по-прежнему даёт безопасный 302. Вне battle массив игнорируется и штатно рендерится экран; State/version/journal не изменяются. Валидные строки и команды подготовки не изменены.

## 4. Debug и границы гарантий

Строковый debug_roll задаётся в session независимо от `debug=1`; `debug=0` выключает отображение debug, но не запрещает forced rolls (`index.php:402–422`, `Dice.php:17–32`). Ограничений по роли/debug flag в этом пути нет. Поэтому нельзя подтвердить несуществующую серверную политику доступа к debug. Это зафиксированная семантика, она не ужесточалась без согласования.

Комбинация legacy cmd+debug_roll даёт 302 без Engine, State/version/Command Journal/GameLog не меняются, но session debug_roll может меняться до redirect и даже до membership check. Keyed AJAX с этой комбинацией выполняет одну команду и создаёт одну committed-запись, replay не выполняет её вновь. Ветка без id сохраняет D1 и с debug_roll, и без него. PHP session/Dice queue не входят в DB transaction.

## 5. Регрессионные тесты

Все перечисленные запущены повторно после исправления в изолированной копии, exit=0, **без SKIP**:

| Тест | Expected | Actual | Result |
|---|---|---|---|
| test_battle_sync_endpoint.php | snapshot/no_change/status, validation, no writes | Passed | PASS |
| test_battle_command_endpoint.php | legacy/AJAX validation, versions, keyed replay/status | Passed | PASS |
| test_command_journal_repository.php | unique key, payload/version match, terminal immutability, commit/rollback | Passed | PASS |
| test_repository_optimistic_locking.php | stale CAS cannot overwrite newer state | Passed | PASS |
| test_resign_pending.php | surrender despite pending; actor/state guards | 48 surrender cases + repeats/outsiders/guards passed | PASS |
| test_cell_marker_cancel.php | marker cancellation unchanged | Passed | PASS |
| test_whip_cancel.php | whip cancellation unchanged | Passed | PASS |
| test_battle_stage1b_ui.php | InfoPanel/debug/protocol | 40 view combinations + snapshots passed | PASS |
| test_battle_stage1a_ajax_layout.php | viewport/AJAX markup contract | Passed | PASS |
| test_battle_snapshot_renderer.php | snapshot fragments unchanged | Passed | PASS |
| test_battle_cancel_action_ui.php | current cancel availability | Passed | PASS |
| test_dice.php | deterministic queue semantics unchanged | Passed | PASS |
| php -l www/index.php | no syntax errors | No syntax errors | PASS |
| php -l tools/test_stage5d2_http_audit.php | no syntax errors | No syntax errors | PASS |
| git diff --check | no whitespace errors | No errors | PASS |

Это успешная регрессия существующих контрактов, но не доказательство выполнения более сильных acceptance criteria: именно эти тесты допускают keyless path и не внедряют оба воспроизведённых SQL write faults.

## 6. Реальный HTTP: Expected / Actual

Полные response metadata, journal before/after и сохранённые доказательства SQL rollback доступны в итоговом JSON. В таблице ниже actual сокращён; PASS обозначает конкретное ожидание строки, не готовность этапа в целом.

| Scenario | Expected | Actual | Result |
|---|---|---|---|
| legacy cmd=end_turn | 302 clean Location; unchanged State/version/journal | {"status":302,"location":"?first&game=100078&mode=move&sel=1","json_valid":false,"body_prefix":""} | PASS |
| legacy cmd[]=end_turn | 302 clean Location; unchanged State/version/journal | {"status":302,"location":"?first&game=100078&mode=move&sel=1","json_valid":false,"body_prefix":""} | PASS |
| legacy cmd=end_turn&cmd[]=resign | 302 clean Location; unchanged State/version/journal | {"status":302,"location":"?first&game=100078&mode=move&sel=1","json_valid":false,"body_prefix":""} | PASS |
| legacy cmd[]=resign&cmd=end_turn | 302 clean Location; unchanged State/version/journal | {"status":302,"location":"?first&game=100078&mode=move&sel=1","json_valid":false,"body_prefix":""} | PASS |
| legacy cmd[deep][x]=resign | 302 clean Location; unchanged State/version/journal | {"status":302,"location":"?first&game=100078&mode=move&sel=1","json_valid":false,"body_prefix":""} | PASS |
| legacy cmd=resign&debug_roll=6* | 302 clean Location; unchanged State/version/journal | {"status":302,"location":"?first&game=100078&mode=move&sel=1","json_valid":false,"body_prefix":""} | PASS |
| sync first ignores cmd | 200 snapshot, no writes | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| sync second ignores cmd | 200 snapshot, no writes | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| sync no_change | 200 no_change, no writes | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| cmd array | 400 JSON; unchanged State/version/journal | {"status":400,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| payload array | 400 JSON; unchanged State/version/journal | {"status":400,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| version array | 400 JSON; unchanged State/version/journal | {"status":400,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| id array | 400 JSON; unchanged State/version/journal | {"status":400,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| empty id | 400 JSON; unchanged State/version/journal | {"status":400,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| empty cmd | 400 JSON; unchanged State/version/journal | {"status":400,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| command GET method | 405 JSON; no writes | {"status":405,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| missing role | 400 JSON; no writes | {"status":400,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| nonparticipant | 403 JSON; no writes | {"status":403,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| keyed success/replay | one committed record; version +1; identical persisted state on replay | first: HTTP 200; replay: HTTP 200; journal: committed | PASS |
| key reused with different payload | 409; unchanged persisted state | {"status":409,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| stale version | 409 conflict; no game update | {"status":409,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| keyed success/replay&debug_roll=6* | one committed record; version +1; identical persisted state on replay | first: HTTP 200; replay: HTTP 200; journal: committed | PASS |
| key reused with different payload&debug_roll=6* | 409; unchanged persisted state | {"status":409,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| stale version&debug_roll=6* | 409 conflict; no game update | {"status":409,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| keyed success/replay&debug_roll[]=6 | one committed record; version +1; identical persisted state on replay | first: HTTP 200; replay: HTTP 200; journal: committed | PASS |
| key reused with different payload&debug_roll[]=6 | 409; unchanged persisted state | {"status":409,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| stale version&debug_roll[]=6 | 409 conflict; no game update | {"status":409,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| rejected/replay | 200 ok=false; rejected once; unchanged game | first: HTTP 200; replay: HTTP 200; journal: rejected | PASS |
| mutation without command_id | every successful game mutation has a committed journal record | response: HTTP 200; journal: empty; version 2→3 | FAIL |
| atomic rollback games | 500; State/version rolled back; no false committed journal | response: HTTP 500; journal: processing; game unchanged: True | PASS |
| known failure terminal outcome games | known rolled-back command obtains a terminal recoverable outcome | replay: HTTP 200; journal: processing | FAIL |
| atomic rollback battle_command_journal | 500; State/version rolled back; no false committed journal | response: HTTP 500; journal: processing; game unchanged: True | PASS |
| known failure terminal outcome battle_command_journal | known rolled-back command obtains a terminal recoverable outcome | replay: HTTP 200; journal: processing | FAIL |
| response failure after commit | 500 unknown; status/replay recover committed result without reexecution | response: HTTP 500; replay: HTTP 200 | PASS |
| pending host resign first | game_over; opponent wins; one commit; replay no-op | response: HTTP 200; journal: committed; winner: player | PASS |
| game_over sync host first | 200 game_over status, no writes | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| command after game_over host first | rejected journal, unchanged game | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| pending host resign second | game_over; opponent wins; one commit; replay no-op | response: HTTP 200; journal: committed; winner: host | PASS |
| game_over sync host second | 200 game_over status, no writes | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| command after game_over host second | rejected journal, unchanged game | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| pending player resign first | game_over; opponent wins; one commit; replay no-op | response: HTTP 200; journal: committed; winner: player | PASS |
| game_over sync player first | 200 game_over status, no writes | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| command after game_over player first | rejected journal, unchanged game | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| pending player resign second | game_over; opponent wins; one commit; replay no-op | response: HTTP 200; journal: committed; winner: host | PASS |
| game_over sync player second | 200 game_over status, no writes | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| command after game_over player second | rejected journal, unchanged game | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| debug disabled legacy command | 302; game/journal unchanged (debug_roll remains an independent session option) | {"status":302,"location":"?first&game=100092","json_valid":false,"body_prefix":""} | PASS |
| debug_roll array | valid JSON; no warnings or game writes | {"status":200,"location":null,"json_valid":true,"body_prefix":null} | PASS |
| legacy array outside battle game_over | no uncaught error; unchanged State/version/journal | {"status":200,"location":null,"json_valid":false,"body_prefix":"<!DOCTYPE html>\r\n<html lang=\"ru\" class=\"\">\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <title>Berserk — партия #100094</title>\r\n    <meta name=\"viewport\" content=\"width=device-width,initial-scale=1.0\">\r\n    <link rel=\"stylesheet\" href=\"/assets/css/global.css?v=1792074317\">\r\n</head>\r\n<body class=\"\">\r\n<div class=\"toolbar\">\r\n    <span>Партия #100094</span>\r\n    <span>Роль: <b>host</b><"} | PASS |
| legacy array outside battle mode | no uncaught error; unchanged State/version/journal | {"status":200,"location":null,"json_valid":false,"body_prefix":"<!DOCTYPE html>\r\n<html lang=\"ru\" class=\"\">\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <title>Berserk — партия #100095</title>\r\n    <meta name=\"viewport\" content=\"width=device-width,initial-scale=1.0\">\r\n    <link rel=\"stylesheet\" href=\"/assets/css/global.css?v=1792074317\">\r\n</head>\r\n<body class=\"\">\r\n<div class=\"toolbar\">\r\n    <span>Партия #100095</span>\r\n    <span>Роль: <b>host</b><"} | PASS |

## 7. Ограничения и итог

- Атомарность подтверждена для State/persistence version/terminal committed в **keyed** InnoDB-транзакции и двух детерминированных ошибок записи. Предварительная processing-запись живёт отдельно; rejected/conflict тоже не являются commit игрового состояния.
- Физическое падение mysqld, потеря ACK COMMIT, разрыв сети, deadlock stress, fsync/power-loss durability не моделировались: **NOT RUN**, не PASS. Unknown-outcome ветки изучены по коду. Реально внедрён сбой формирования snapshot уже после успешного commit: status/replay восстановили committed без второй мутации.
- HTTP server однопоточный; параллельная HTTP нагрузка не запускалась. CAS/уникальность/терминальные записи покрыты repository-тестами и последовательными HTTP replay. Это не отчёт о нагрузочной или security сертификации.
- Проверена membership validation в имеющейся модели first/second. Полноценную аутентификацию и перестановку host_id/player_id эта модель не гарантирует; механизм не менялся.
- Геометрия UI и браузерные сценарии в этом HTTP-аудите повторно не измерялись (**NOT RUN**); UI/JS/CSS не менялись, серверная регрессия Stage 1B выполнена.
- Сохранение legacy команд подготовки вне battle не переархитектуривалось. Подтверждённый array-cmd crash устранён в общей точке; полный аудит всех команд подготовки вне scope.

**Stage 5D.2 NOT READY TO CLOSE**: D1 противоречит универсальному требованию журнала; D2 оставляет известную неуспешную команду без terminal outcome. Нужны решения Project Lead по совместимости command_id и восстановлению известного rollback. Локальные D3/D4 устранены, но они не устраняют D1/D2. Строковый debug_roll оставлен с существующей политикой доступа и требует явного понимания этой границы.

Изменённые файлы:

- `www/index.php`: две проверки строкового типа, 2 additions / 2 deletions.
- `tools/test_stage5d2_http_audit.php`: guarded воспроизводимый реальный HTTP-аудит, fault injection и машинный отчёт.
- `docs/stage5d2-final-verification.md`: этот отчёт.
- `docs/stage5d2-http-baseline.json`: доказательства до локальных исправлений.
- `docs/stage5d2-http-results.json`: итоговые 50 Expected/Actual.

Production diff:

```diff
-if (isset($_GET['debug_roll'])) {
+if (is_string($_GET['debug_roll'] ?? null)) {
@@
-$commandType = $_GET['cmd'] ?? '';
+$commandType = is_string($_GET['cmd'] ?? null) ? $_GET['cmd'] : '';
```

Полный diff: `git diff 090a7c3..HEAD` после audit commit. Core, механика, pending, Command Journal implementation, AJAX schema и Stage 1B UI не изменены. После отчёта работа остановлена для code review; merge/push аудита не выполнялись.
