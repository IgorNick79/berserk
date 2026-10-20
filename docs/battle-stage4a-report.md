# Berserk — Stage 4A: итоговый отчёт Project Lead

> **Актуализация Stage 4A.1:** Project Lead сообщил, что пять production-таблиц уже созданы, а SQL-триггеры недоступны. Триггеры больше не требуются, повторный запуск полной миграции не нужен. Ниже сохранён исторический отчёт исходного Stage 4A, включая прежние checksum/trigger tests и hash SQL; эти требования и hash миграции заменены [Stage 4A.1](battle-stage4a1-no-triggers.md). Текущие design/production-инструкция описывают INSERT-only контракт, unique constraints и проверку SHA-256 серверного состояния и проекций при чтении. Новая гарантия не включает физическую защиту от внешнего UPDATE/DELETE. Recorder остаётся выключенным до Stage 4B.

Дата: 20 октября 2026. Ветка: `codex/battle-replay-stage4a`. Локальная инфраструктура подготовлена и проверена; runtime-регистрация истории не включена.

Фактический HEAD: **`2755c79a6f20c98725b8d9709188884f2c902ce2`**. Новая ветка создана от него при чистом рабочем дереве. Оба требуемых коммита `9e5e57e6fd6db044323cd413a1704fe29ced0c5e` и `2755c79a6f20c98725b8d9709188884f2c902ce2` являются предками HEAD. Cherry-pick не выполнялся. Stage 4A подготовлен как новые файлы рабочего дерева; отдельный коммит на этом этапе не запрашивался и не создан. Production HEAD не установлен.

## Артефакты

| Созданный / изменённый файл | Назначение |
|---|---|
| .gitignore | Одно исключение для проверенной SQL-миграции, иначе общее *.sql скрывает её |
| src/Core/BattleHistory/ReplayState.php | Exact whitelist ReplayState v1; synthetic display contract без Engine/GameState |
| src/Core/BattleHistory/HistoryBatch.php | Сериализованные независимые copies событий/captures и SHA-256 batch |
| src/Core/BattleHistory/HistoryRepository.php | Запись внутри caller transaction, lifecycle, allocation, committed retry lookup, безопасные исторические projections |
| tools/migrations/20261020_battle_history.sql | Ручной DDL: пять InnoDB utf8mb4 таблиц, два immutable snapshot triggers |
| tools/test_battle_history.php | 20 isolated SQL/DTO/concurrency checks и синтетический coordinator |
| docs/battle-history-persistence-design.md | Полный контракт, поля/индексы/связи, формат и future integration point |
| docs/battle-history-production-sql.md | Read-only preflight, backup, точный SOURCE SQL, postchecks и safe recovery |
| docs/battle-stage4a-report.md | Этот отчёт |
| docs/evidence/stage4a/summary.json | Итоги и hashes проверенных файлов |
| docs/evidence/stage4a/stage4a-evidence.zip | Финальные SQL/test/schema/source-integrity доказательства |

Единственное изменение существующего файла — исключение для SQL-миграции в .gitignore. Исходники прежнего приложения не изменены. Сохранены отчёты и доказательства Stage 3/3.1/3.2. Новые component classes нигде в www/Engine/старых repositories не вызываются. Нет изменений в GameLog, storage logger, диагностических JSONL, Command Journal, games, Db, обработчиках карт, StrikeResolver/InstantProcessor, pending/continuation, timers или Гидре. SQL к production не выполнялся.

SHA-256 исполняемого SQL: **`5952b78c4c34e5eefc2de2b1fa29c0e4c185d8916d524d6b194adb8474d91530`**.

## Результат архитектуры и review

Минимальная модель: `battle_history_matches`, `battle_history_batches`, `battle_history_actions`, `battle_history_events`, `battle_history_steps`. Snapshot хранится прямо в step, отдельная таблица на каждую projection не нужна. Batch нужен для надёжного сопоставления durable результата команды и диапазонов sequences, в том числе команды без capture. Actions поддерживают open/settled/cancelled/interrupted, root/parent и start/end событий через несколько запросов без копии pending.

Порядок game-local event_seq и step_seq выделяется из заблокированных metadata в общей транзакции. Существующий порядок блокировок journal→games сохранён; metadata добавляется после games. Несколько captures одной команды имеют разные capture_order/boundary/step_seq и одну afterVersion, без отдельных COMMIT. Unique command/journal/boundary ограничения защищают retry. Snapshots защищены от UPDATE/DELETE на уровне БД; SHA-256 полного DTO позволяет проверить байты.

Изучены GameRepository.commitJournalCommand, CommandJournalRepository, Db, www/index.php и automatic command paths. Для будущего подключения определена одна точка append после CAS save и перед markCommitted на том же Db; текущий GameRepository не менялся. Новый компонент не открывает/не подтверждает транзакции и не выполняет DDL. Caller обязан полностью откатывать любую ошибку обязательной истории. Это проверенный контракт synthetic coordinator, **не утверждение о внедрённой end-to-end гарантии для реальных матчей**.

Во время review исправлена проверка parent lifecycle: root и child могут оба завершиться внутри одной команды, если child входит в временной event-интервал parent. Добавлен самостоятельный regression case. Усилены fault-injections: ошибка второй обязательной вставки откатывает уже вставленные первый event или snapshot, а не только пустой batch. Ограничения unique event order, capture order, boundary и initial проверены отдельно от конфликтов primary key.

Полная payload и сохранённые public/host/player projections разделены. Полностью скрытый event не попадает в пользовательский reader; поздний reveal не переписывает раннюю видимость. Полный server reader закрыт до committed final + persisted game_over. Будущая аутентификация/авторизация endpoints и смысловая фильтрация полей остаются следующим этапам. ReplayState не включает служебные поля; catalog/versioned assets определяют стратегию совместимости, неизвестная schema_version отвергается.

## Изоляция

Отдельный datadir: `C:/Users/mi/Documents/Berserk/stage4a-verification/data`; новый процесс MariaDB **10.8.4**, `--no-defaults`, bind `127.0.0.1`, порт **33389**, без Windows service. Перед записью проверены фактические @@datadir,@@port,DATABASE(),VERSION(). Тестовый пользователь stage4a имеет права только на явно созданные synthetic schemas. Production config/storage не копировались.

PHP **8.1.9**, отдельный php.ini с mysqli/mbstring, sessions/error_log внутри lab. Копировались src/tools/docs/cards/templates/www без .git и production config; тестовый config создан заново и смотрит только на disposable DB. Test guard требует BERSERK_STAGE4A_ISOLATED=1, отсутствие .git, фиксированный host/port/datadir и имя `berserk_stage4a` либо `berserk_stage4a_runN`.

Разработческие прогоны сохранены в отдельных схемах, без DROP старых данных. Финальная схема: **berserk_stage4a_run4**, результаты `results-final`. Независимые старые suites работали в **berserk_stage4a_regression**; их игры не смешиваются с SQL доказательствами. Дополнительно migration через CLI проверена в **berserk_stage4a_cli**. Lab, результаты и исходные доказательства сохранены. Тестовый сервер после проверки остановлен.

## Проверки

| Группа | Результат |
|---|---|
| Новые SQL/DTO checks | **20 PASS**, 0 FAIL/SKIP |
| R1–R3 synthetic Stage 3.1 scenarios | **33 PASS**, 0 FAIL |
| Существующие regression suites | **18 suites PASS**, exit=0, без SKIP |
| PHP lint | **4/4** новых PHP-файла |
| SQL через MariaDB CLI | Первое применение exit=0; повтор exit=1 / ERROR 1050 на первой таблице, ожидаемо |
| Git/whitespace/source integrity | diff --check и отдельная проверка новых файлов; прежние src совпадают с проверенной копией и HEAD |

Всего **71 проверочная единица**: 20 отдельных новых tests + 33 адресных cases + 18 suites (внутренние assertions suites не суммируются). CLI/lint/integrity дополнительно.

Новые проверки охватывают:

1. Создание пяти таблиц InnoDB/utf8mb4 и triggers; безопасный отказ повторной миграции.
2. ReplayState whitelist, version mismatch, hidden-card DTO, независимую копию batch.
3. games + batch + actions + events + **два разных snapshots с одной persistence version** + journal committed в одном COMMIT.
4. Полный rollback на второй event INSERT и втором snapshot INSERT; metadata/sequences/game возвращаются, ранее зарегистрированный journal остаётся processing. Retry после подтверждённого rollback не оставляет gaps.
5. Stable boundary и command identity, отсутствие дублирования при retry, conflict другой payload/type.
6. Reconciliation после намеренно отброшенного успешного ответа: новое соединение читает committed journal и тот же batch. Engine повторно не исполняется.
7. Два concurrent PHP writers последовательно дают event_seq/step_seq [1,2]; второй действительно ждёт game lock. Конкурентный stale expected_version откатывается без history allocation.
8. Несколько HTTP-команд одного action; root/child в одной команде; cause/start/end checks; cancellation context.
9. Проекции событий и snapshots, полностью скрытые записи, поздний reveal, запрет полного чтения до подтверждённого final; форматы и ограничения уникальности.
10. Disabled/recording/incomplete/completed distinctions и отсутствие ложной полноты при пропущенном initial.

18 существующих suites: test_hydra, test_ragnar, test_combat_instant_phases, test_degasca, test_bon_and_berrow, test_swamp_children, test_movement_effects, test_forced_directional_movement, test_volkhv_wound_transfer, test_resign_pending, test_nested_combat_ownership, test_command_journal_repository, test_battle_command_endpoint, test_turn_timer, test_informational_wait, test_turn_timeout, test_pending_wait_timer, test_turn_instant_stack. Дополнительно test_stage31_fixes (33 cases). Запускались только копии внутри isolated app; GameLog создавался только там.

## Правило Гидры

Подтверждена представимость и неизменяемость **combat(closed=false) → voluntary series_end(closed=true) → immediate turn_end(closed=true)**. Три отдельных steps; первый JSON/hash не меняется. Natural ending представлен одним combat(closed=true), без дополнительного End-step. Это synthetic storage-проверка согласованного правила Stage 3.2, не регистрация реальных атак. Механика Гидры не изменена; Recorder не реализован.

## Ограничения и порядок Project Lead

Production DDL/HEAD и иные версии MariaDB/MySQL не проверены. Миграция не атомарна как набор DDL; при частичном применении — STOP, оставить новые пустые объекты и согласовать forward repair. Нет автоматического schema runner или фонового включения регистрации.

Физический разрыв соединения в точке COMMIT, потеря COMMIT ACK от сервера, crash/power-failure и deadlock recovery end-to-end не симулировались. Проверен durable retry после отброшенного ответа и контракт unknown outcome; это не доказательство всех сетевых аварий. При processing без batch нельзя автоматически считать rollback завершённым. Старый operational recovery остаётся авторитетным.

Storage предполагает доверенный semantic producer: schema validation не распознаёт секрет в произвольной строке, истинную границу действия, no-op с новыми IDs или правильность всей causal chain. SQL immutable triggers защищают snapshots, не DBA с DDL-правами. Исторические catalog texts заморожены, но внешнее сохранение versioned assets требует следующего этапа. Нет retention/purge политики или импортера старых GameLog.

Project Lead:

1. Рассмотреть design/DDL и принять модель; проверить фактический production HEAD/DDL read-only командами из инструкции.
2. Проверить совместимость конкретного сервера на clone и восстановимость backup. При несоответствиях не выполнять SQL.
3. Сверить SHA-256 файла, вручную применить его с остановкой на первой ошибке, сохранить output и postchecks.
4. Подтвердить пять таблиц/индексы/FK/CHECK/generated guards/triggers, пустые counts и неизменность games/journal DDL.
5. Передать подтверждение; регистрацию для реальных матчей оставить выключенной.
6. Отдельно согласовать Stage 4B/Recorder, secure projections и wiring перед любой интеграцией.

Merge, deploy, production SQL и Stage 4B не выполнялись.
