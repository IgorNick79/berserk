# Stage 5E.1 — MySQL 5.7 compatibility audit

Production environment подтверждено Lead: MySQL5.7.30-log. Агент production SQL не выполнял. Read-only source audit и отдельные локальные synthetic tests проведены на MySQL5.7.39, не выдаются за точный5.7.30. Основной источник версионных правил — [официальный MySQL5.7 Reference Manual](https://downloads.mysql.com/docs/refman-5.7-en.pdf); старые HTML5.7 URLs сейчас могут перенаправляться на новую версию.

## Результат по схемам

В изолированной5.7 исходные reviewed CREATE accounts/auth_login_attempts, lobby_rooms, battle_command_journal, chat и пяти battle_history таблиц успешно выполнены **без изменения DDL**. Именованные CHECK в accounts/lobby принимаются, но игнорируются, как и unnamed CHECK в history. Тест отрицательного значения под CHECK прошёл INSERT — это отсутствие защиты, не совместимость enforcement.

- accounts/limiter: ascii_bin, VARCHAR32/255, datetime6, INT signed account PK, UNIQUE login_key и PK bucket поддерживаются. Duplicate normalized login_key отклонён1062. ASCII syntax/lowercase и password policy проверяются приложением, read-only postcheck ищет нарушенные строки; никаких UPDATE accounts/rehash миграций.
- lobby_rooms: signed INT references к accounts.id/games.ind, nullable FK кандидата/accepted/game, индексы участников, UNIQUE game_id поддерживаются. Несуществующий account отклонён1452. NULL в UNIQUE game_id допускает несколько open rooms. Cross-column state/creator/candidate invariants через CHECK **не обеспечены MySQL5.7**: приложения остаются единственным штатным writer, существующие данные проверяются postchecks, доступ runtime users ограничен. Не заявлять полную эквивалентность enforced CHECK на MariaDB/MySQL8.
- Command Journal: PK BIGINT unsigned, game_id signed INT, UNIQUE(game_id,player_key,command_id), status index совместимы. Максимальный составной UTF8MB4 ключ здесь около324 bytes, ниже даже767 byte boundary; auth/lobby keys ещё короче. Изменение ROW_FORMAT/index prefixes не требуется по исходному DDL, но реальные production types/row formats сверяются metadata.
- History: signed BIGINT game refs между history tables, ascii action/command keys, JSON_VALID expression syntax, DATETIME6 и STORED generated initial_guard/final_guard поддерживаются. Unique generated initial guard действительно отвергает второй initial step1062; это работающий UNIQUE, в отличие от ignored CHECK. Recorder/history runtime не менялся. Старые таблицы не пересоздавать.
- FK требуют одинаковые signed/types, существующие PK/индексы и InnoDB; FK/UNIQUE/NOT NULL исполняются. Внешних FK к legacy games из history не добавлялось. Существующий games не создаётся и не ALTERится релизом.

## Минимальные изменения SQL

1. В 20261021_release_preflight.sql убран только SELECT из отсутствующей в5.7 information_schema.CHECK_CONSTRAINTS. Все SELECT/SHOW columns/indexes/FK/engines остаются; запрос теперь действительно проходит5.7. Эту таблицу нельзя заменить выдуманной проверкой CHECK enforcement.
2. Добавлен **read-only** 20261021_mysql57_postchecks.sql: SQL mode-related settings, counts invalid accounts/room-state/ownership, FK metadata и только hash cost/prefix aggregates (без hash values). Запускать, только когда перечисленные таблицы существуют. При ненулевых invalid counts STOP и отдельное решение, не автоматический repair.
3. Установочные CREATE не переписаны и не продублированы: named CHECK уже синтаксически допустим. Нет необходимости удалять их ради парсера, а на поддерживающей СУБД они остаются дополнительной защитой. Общий ZIP не включает CREATE installer; Lead выбирает missing-only источники после inventory. Для эквивалентной DB-enforced cross-column защиты нужен отдельный архитектурный выбор (иной server или дополнительная схема), не TRIGGER без прав и не скрытая миграция.

## Транзакции и P1 runtime blocker

MySQL5.7/InnoDB поддерживает START TRANSACTION, SELECT FOR UPDATE, COMMIT/ROLLBACK. Лабораторный SQL тест подтверждает: общий rollback убирает новый games+room, commit сохраняет ровно связанную пару. Все migrations отдельно от runtime: DDL вызывает implicit commit, не считается атомарным и не выполняется внутри accept. Ошибка установки оставляет partial schema — STOP, без повторного общего CREATE/DROP.

**P1:** MatchCreator::create требует SELECT @@in_transaction, а MySQL5.7 не имеет этой system variable. Лабораторный сервер вернул1193. Реальный текущий LobbyStore прошёл create/claim, но accept вернул1193 и оставил awaiting_confirmation, новый game не создан. Это fail-closed rollback, но пользовательский сценарий не работает.

SQL-миграция не может корректно создать @@in_transaction или исправить контракт PHP-кода. В рамках read-only audit Lobby/MatchCreator не менялись. Нельзя ловить1193 и объявлять транзакцию активной, начинать вложенную транзакцию (может потерять room lock) или требовать PROCESS/TRIGGER ради обхода. Нужен отдельный согласованный portable transaction-guard fix и повтор конкуренции/rollback на MySQL5.7. До этого **NO-GO**.

## Порядок после устранения P1 и отдельного разрешения Lead

Read-only inventory → backup/restore rehearsal → maintenance → auth.sql только если обе auth tables отсутствуют → auth_postchecks → lobby preflight/lobby.sql только если отсутствует → lobby_postchecks → existing journal/chat/history compare (CREATE только proven missing groups по отдельному решению) → обновлённый release preflight + mysql57_postchecks → least-privilege grants и runtime smoke. Если schema уже частично установлена/несовместима, не rerun CREATE. games.version отсутствует или game/account ID types не совпадают — отдельная миграция review, не guessed ALTER.

Auth grants SELECT,INSERT accounts и SELECT,INSERT,UPDATE limiter сохраняются; bcrypt fix не требует UPDATE accounts. Lobby SELECT accounts + SELECT,INSERT,UPDATE room/games; game — прежние права. Ни DDL, ни TRIGGER/PROCESS/FILE/ALL runtime не нужны. Все пользовательские games/journal/history/config/storage и legacy1/2 ownership сохраняются. CHECK отсутствие не компенсируется backfill старых игроков в accounts.

Evidence: mysql57-before.json (ignored CHECK/отсутствующие metadata/system variable), mysql57-results.json и mysql57-preflight.txt. 22 assertions включают подтверждение **ожидаемого сбоя accept**, поэтому не являются22 зелёными release-scenarios. Восстановление/создание/check injections выполнялись только на новом loopback datadir.

Дополнительно настоящий AuthStore/AuthService на MySQL5.7 с runtime SELECT/INSERT accounts +SELECT/INSERT/UPDATE limiter успешно зарегистрировал и аутентифицировал аккаунт с cost12. Это CLI application/DB тест, не HTTP/FPM production тест.
