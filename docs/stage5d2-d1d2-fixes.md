# Stage 5D.2 — D1/D2 implementation

Дата: 2026-10-15. База: `e35514355bce85672ac25ecb135e9d9694324875`.
Ветка: `codex/stage5d2-d1-d2-fixes`.

Рекомендация: **READY TO CLOSE для согласованного объёма D1/D2**, после независимого code review. Новых блокирующих дефектов не обнаружено. Это не заявление об устранении всех возможных зависших processing после process crash или неизвестного исхода транзакции.

## Изменения D1

`www/index.php`: POST command_id обязателен после существующих проверок метода и expected_version. Используется прежняя requestCommandId: отсутствующий/GET-only id → HTTP 400 missing_command_id; пустой/массивный/некорректный → HTTP 400 invalid_command_id. Запрос отклоняется до Engine, State save и journal INSERT. При одновременно отсутствующей версии сохраняется прежний приоритет missing_expected_version.

Условная journal-aware обёртка снята; альтернативная keyless-ветка Engine + обычный repo->save удалена вместе с неиспользуемым ajaxCommandIdPresent. В AJAX command остались один Engine::apply и один saveCommittedCommand. Большая часть строк production diff — снятие одного уровня отступа у сохранённого journal-aware маршрута; содержательные изменения удобнее смотреть через git diff -w.

Штатный браузер уже устанавливает id. Его JS не менялся. Успешные snapshots, journal metadata, namespace game/player/id и правила идентичности повторов сохранены. Старый keyless API намеренно отключён; сервер не генерирует id за клиента. Legacy GET redirect и команды подготовки не менялись.

`tools/test_battle_command_endpoint.php`: мигрированы старые keyless-сценарии с уникальными id; сохранена проверка их исходной логики. Добавлены отдельные отрицательные missing/GET-only/empty/array/invalid id с проверкой State JSON, persistence version, journal count и отсутствия новых GameLog entries. Для malformed cmd/payload используется валидный id, чтобы проверка не маскировалась ранним отказом.

## Изменения D2

`www/index.php`: непосредственно вокруг saveCommittedCommand добавлен catch(DbQueryException). Существующие StaleStateException и TransactionCommitUnknownException/TransactionRollbackUnknownException обрабатываются отдельно.

Контракт проверен по текущим GameRepository/Db: preconditions и BEGIN не выбрасывают DbQueryException; SQL query failures транзакционной части проходят через rollback. Если rollback подтверждён, исходный SQL exception возвращается вызывающему коду; если подтверждение rollback потеряно — наружу выходит отдельный TransactionRollbackUnknownException. COMMIT failures имеют отдельный TransactionCommitUnknownException. После успешного COMMIT внутри saveCommittedCommand нет последующих SQL queries. Поэтому узкий catch относится к подтверждённо несохранённой попытке; inTransaction()==false для классификации не используется.

Для journalId новой записи, зарегистрированной именно этим запросом, вызывается markFailed(journalId,currentVersion,'persistence_failed'). Существующий conditional UPDATE меняет только processing. before_version=after_version=версия State до попытки.

При подтверждённом failed ответ: HTTP 500, ok=false, type=command_status, исходный command_id, command_status=failed, terminal=true, error_code=persistence_failed. Изменённый в памяти State не сериализуется; sync_version в этом ответе null, клиент выполняет forced snapshot. При ошибке markFailed выполняется чтение записи по её id: сохранённый terminal outcome возвращается как факт, processing/недоступность чтения → unknown. Committed не перезаписывается failed.

Повтор уже failed id сохраняет существующее поведение terminal status endpoint/duplicate route (HTTP 200 с ok=false, failed и terminal=true); Engine не выполняется. Первый ответ нового подтверждённого persistence failure — HTTP 500. Повторное осознанное действие игрока создаёт новый id.

Core/Db/GameRepository/CommandJournalRepository/Engine/GameLog production-файлы не менялись. Схема БД, статусы, UI и protocol успешных команд не менялись.

## Изолированная среда

Отдельная копия src/templates/tools/www: `C:/Users/mi/Documents/Berserk/stage5d2-d1d2-verification`.
Отдельный MariaDB 10.6.9 datadir внутри этой копии, loopback 127.0.0.1:33875, БД berserk_stage5d2_test_fixes. Перед мутирующими тестами проверены DATABASE(), @@port, @@datadir и InnoDB для games, journal, cards, elements. Cards импортированы из tracked каталога; таблица переведена в InnoDB до тестов. Game IDs начинаются со 100000; реальные партии и storage не использовались.

PHP 8.1.9 HTTP server: 127.0.0.1:8798, document root www из копии; session/tmp paths внутри копии. Opcache выключен. HTTP audit дополнительно подтверждает document root одноразовым probe и запрещает запуск из checkout с .git.

SQL write failures внедрены временными MariaDB triggers. Потери подтверждений COMMIT/ROLLBACK/markFailed моделировались точечными исключениями **только в Db.php тестовой копии**, после реальных SQL-операций. Это не mock-БД и не физический разрыв сети с mysqld. Изменения копии восстанавливаются через finally; в конце сверены SHA256 Db/GameRepository/Journal/Engine-related source и production index/JS. В production test hooks не добавлялись.

Отдельно реально отправлен HTTP POST через TCP socket с закрытием без чтения ответа: последующие status/replay восстановили один committed outcome без повторного Engine.

## Результаты проверок

Все 12 наборов выполнены на указанной изолированной БД, exit=0, без SKIP:

| Набор | Результат |
|---|---|
| test_battle_command_endpoint.php (обновлён) | PASS |
| test_command_journal_repository.php | PASS |
| test_repository_optimistic_locking.php | PASS |
| test_battle_sync_endpoint.php | PASS |
| test_resign_pending.php | PASS: 48 surrender cases + repeats/guards |
| test_cell_marker_cancel.php | PASS |
| test_whip_cancel.php | PASS |
| test_battle_stage1b_ui.php | PASS: 40 view combinations + snapshots |
| test_battle_stage1a_ajax_layout.php | PASS |
| test_battle_snapshot_renderer.php | PASS |
| test_battle_cancel_action_ui.php | PASS |
| test_dice.php | PASS |

Расширенный реальный HTTP audit: **60/60 PASS, exit=0**. Каждый Expected/Actual сохранён в [JSON](stage5d2-d1d2-http-results.json). Покрыты missing/invalid/GET-only id, legacy redirect, оба viewer, stale, game_over, pending/surrender, committed/rejected/conflict/failed repeats, несовпадение payload/expected_version и отсутствие повторного Engine.

PHP -l: index.php и оба изменённых test-файла — PASS. node --check battle-sync.js — PASS (JS не менялся). git diff --check — PASS.

### Failure injection: Expected / Actual

| Сценарий | Expected | Actual | Result |
|---|---|---|---|
| Ошибка UPDATE games | State/version прежние; 500 failed; before=after; persistence_failed | Именно этот ответ; один Engine call | PASS |
| Ошибка UPDATE journal committed после State UPDATE | Полный rollback State; 500 failed | State/version прежние; failed, before=after | PASS |
| Повтор failed + command_status | Исход failed сохраняется, Engine не вызывается вновь | Запись и State неизменны, Engine calls=1 | PASS |
| UPDATE markFailed отклонён | Не выдумывать failed | 500 unknown; journal processing; повтор не запускает Engine | PASS |
| UPDATE failed выполнен, подтверждение потеряно | Перечитать и восстановить failed | 500 terminal failed | PASS |
| UPDATE failed выполнен, повторное чтение недоступно | Сейчас unknown; позже обнаружить failed | unknown → status/replay failed, без Engine | PASS |
| COMMIT выполнен, подтверждение потеряно | Вернуть реально сохранённый committed | Committed прочитан, State +1, повтор не выполняется | PASS |
| COMMIT не подтверждён, тестовая транзакция фактически отменена | Не делать failed по одному исключению | 500 unknown, journal processing, State прежний | PASS |
| ROLLBACK выполнен, подтверждение потеряно | Unknown не превращается в failed | 500 unknown, journal processing, State прежний | PASS |
| Snapshot rendering падает после COMMIT | Commit сохраняется, восстановление через журнал | 500 unknown → committed status/replay; State +1 один раз | PASS |
| Ответ HTTP целиком отброшен клиентом | Status/replay без новой мутации | Committed, State +1, Engine calls=1 | PASS |

### Штатный браузер

В in-app browser через реальные ссылки gain_coin, с неизменённым battle-sync.js:

1. Обычная команда: generated UUID, committed, версия 2→3, карта закрылась, монеты 0→1.
2. При SQL trigger failure: failed/persistence_failed, версия 2→2, сообщение ошибки, forced snapshot показал открытую карту и 0 монет.
3. После снятия trigger ещё один обычный click: новый UUID, committed, версия 2→3, карта закрылась, монеты 1. Прежняя failed-запись осталась failed.

[SQL evidence до/после новой попытки и наблюдения UI](stage5d2-d1d2-browser-results.json). Это подтверждает разблокировку клиента после terminal failed; JS recovery не переписывался.

## Оставшиеся границы

- Если markFailed невозможно сохранить, processing остаётся; ответ unknown, автоматического повторного Engine нет. Это предусмотренный безопасный исход, а не фиктивный terminal failure.
- Падение процесса после processing INSERT, ошибка BEGIN и не-SQL исключение Engine не терминализируются новым узким catch. Автоматический timeout/reclaim не вводился.
- Клиент по-прежнему может долго ждать unknown/processing; pendingCommand живёт только в памяти страницы. Расширение восстановления не входит в согласованный D1/D2.
- Физический power loss, потеря пакетов DB-соединения, fsync durability и параллельный HTTP stress не запускались. ACK outcomes проверены контролируемыми исключениями вокруг реальных SQL; CAS/уникальность дополнительно покрыты repository-тестами.
- GameLog остаётся диагностическим файлом до commit и может содержать success для откатившегося действия. Его нельзя считать committed Replay-историей. Эти исправления сохраняют различие committed/failed/unknown и не препятствуют будущему Replay.
- Старые keyless-клиенты намеренно перестают исполнять команды. Существующие клиент и успешный wire protocol совместимы.

## Файлы и diff

Изменены: www/index.php; tools/test_battle_command_endpoint.php; tools/test_stage5d2_http_audit.php.
Добавлены: этот отчёт; stage5d2-d1d2-http-results.json; stage5d2-d1d2-browser-results.json.

Полный итоговый diff: `git diff e355143..HEAD`. Отдельная копия patch для просмотра: `C:/Users/mi/Documents/Berserk/stage5d2-d1d2-final.diff`. Упрощённый просмотр содержательных production-изменений: `git diff -w e355143..HEAD -- www/index.php`.

main остаётся 090a7c3241ef5106454064f7d61259d252ab3e95; feature/bulbagur — 9924c5ac2cfa64b618a6208bc7d70ef487ce3d34. Merge/push не выполнялись. После локального commit работа остановлена для независимого code review.
