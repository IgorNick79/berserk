# Stage 5B — ручная миграция и изолированные проверки

Production не изменялся. SQL применяет только Project Lead после отдельного согласования и backup. Нет runtime DDL, TRIGGER, DROP или изменения старых games в миграции.

1. Подтвердить базу Stage 5A (commit 822dacc), действующую accounts schema, PHP >=8.1 с mysqli/mysqlnd и реальную MariaDB/MySQL версию. Агент проверил MariaDB 10.8.4, PHP 8.1.9; production конфигурация не исследовалась.
2. Приватным SQL client config выполнить `tools/migrations/20261021_lobby_preflight.sql`. Accounts.id и games.ind должны быть signed INT, PK, InnoDB. Проверить games host_id/player_id signed INT, status/json/version/date/active и поддержку начального version=1. Existing games.version должен уже существовать: автоматическое ALTER из GameRepository здесь не вызывается. Проверить enforced CHECK constraints и strict SQL mode.
3. Если lobby_rooms уже существует, остановиться для сверки структуры и данных, не накладывать миграцию повторно. При несовпадении типов/engine также остановиться, не ALTER games наугад.
4. Применить один additive CREATE из `tools/migrations/20261021_lobby.sql`: accounts/games FKs, UNIQUE nullable game_id, индексы и CHECK state invariants. Без каскадного удаления. Выполнить read-only `20261021_lobby_postchecks.sql`: InnoDB, все ключи/constraints, invalid_rooms=0. Сверить неизменность прежних игровых строк.
5. Оставить отдельного auth_runtime Stage 5A без доступа к игре. Создать lobby_runtime: SELECT accounts; SELECT/INSERT/UPDATE lobby_rooms; SELECT/INSERT/UPDATE games. Без DELETE, TRIGGER, DDL и доступа к battle/history/chat таблицам. В config/auth.local.php вручную добавить `lobby_db` с этим соединением; пример — config/auth.example.php. Без lobby_db используется db, поэтому прежний ограниченный auth user безопасно откажет при отсутствии прав, а не выдаст их автоматически.
6. Проверить приватность local config/session storage, HTTPS, cookie и false allow_loopback_http в production. Никаких секретов в Git/log/argv. Новые auth/lobby PHP routes не требуют игрового config/db.php.
7. Развернуть только согласованные файлы после отдельного разрешения. Не активировать обычный игровой переход до Stage 5C. Не включать prototype URLs как способ продолжения созданного матча. Rollback UI — отключение новых endpoints с сохранением комнат/матчей/ownership, без автоматического удаления таблиц.

Runtime при чтении проверяет наличие таблиц, InnoDB, обязательные columns и PK/UNIQUE; missing/incompatible schema даёт общий 503. Полное соответствие типов, FK и CHECK доказывается операторскими pre/postchecks. Новые endpoints не запускают старые constructors с runtime DDL.

## Воспроизведение тестов

`tools/test_lobby_http.py` сам создаёт **новый** datadir только в `<checkout>/tmp/lobby-lab/data` через mariadb-install-db. Перед запуском требует отсутствие auth.local.php и свободные loopback порты 33561–33567. После запуска mysqld сверяет точный @@datadir и @@port до CREATE schema. Создаёт уникальные test schema/users, не удаляет существующие таблицы. Все runtime права без TRIGGER/DDL. Четыре независимых PHP HTTP workers разделяют реальную InnoDB; session lock снимается до room mutations.

```powershell
python tools/test_lobby_http.py --php C:/OSPanel/modules/php/PHP_8.1/php.exe --db-bin C:/OSPanel/modules/database/MariaDB-10.8-Win10/bin --node C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe --playwright C:/Users/mi/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright --browser "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"
```

Browser runner запускается автоматически, использует headless Edge и три независимых storage contexts, проверяет настоящие HTTP и polling. Временный router разрешает только auth/lobby/static assets и запрещает autoload Engine/GameRepository/automatic classes. Только accept может загрузить существующие GameState/PlayerState/GameSettings/BoosterSettings/PlaymatCatalog. Это изоляция теста, router не входит в deploy.

Fault injection выполняет только лабораторный setup user: временно отнимает UPDATE games (провал после INSERT при сохранении JSON), затем UPDATE lobby_rooms (провал при связывании). Оба сбоя проверяются на rollback без orphan games и без изменения room.version. Ещё один setup UPDATE games.status имитирует завершённость для проверки таблицы. Все изменения ограничены точной test schema.

Existing GameRepository regression запускается в disposable source-only копии с приватным config, привязанным к тому же лабораторному DB, а не на production. Auth security runner Stage 5A воспроизводится отдельно по `stage5a-auth-sql.md`; после удаления старого datadir он требует повторной явной инициализации новой auth-лаборатории.

Runner завершает процессы и удаляет private auth.local.php даже при ошибке. Перед передачей итогового пакета tmp datadirs, sessions, TLS keys, invitation ledgers и regression configs удалить после проверки абсолютных путей внутри checkout. Сохранять только санитизированные JSON результатов/postchecks/скриншоты, без cookies/credential/token traces.
