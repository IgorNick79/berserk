# Battle History — Stage 4A.1: проверка уже созданной production-схемы

**Пять battle_history_* таблиц уже созданы на production**, согласно сообщению Project Lead. SQL-триггеры создать не удалось из-за отсутствия права TRIGGER; Lead согласовал работу без них. Это допустимое состояние Stage 4A.1. Создавать триггеры, запрашивать это право и повторно запускать полную CREATE-миграцию не требуется.

Codex не подключается к production и не выполняет там SQL. Фактический production DDL пока не предоставлен: факт создания таблиц известен от Lead, соответствие каждого индекса/типа ещё нужно проверить. Recorder и регистрация реальных матчей остаются выключенными до отдельного согласования Stage 4B.

## 1. Только read-only postchecks в phpMyAdmin

В phpMyAdmin выбрать проверенную production-базу. Вкладка SQL: выполнить [20261020_battle_history_postchecks.sql](../tools/migrations/20261020_battle_history_postchecks.sql). Файл содержит исключительно SELECT и SHOW; нет CREATE, DROP, ALTER, UPDATE или DELETE. Выполнить его можно целиком либо запросами по очереди, сохранив все результаты. Не выбирать вместо него файл CREATE-миграции.

Postchecks включают выбранную БД/server version/sql_mode/account, SHOW CREATE TABLE для games/journal и пяти новых таблиц, SHOW INDEX, SHOW TABLE STATUS, типы/кодировки/generated columns, PK/UNIQUE/FK/CHECK metadata и counts. CHECK expressions сравниваются по SHOW CREATE TABLE. Запрос metadata triggers не требует их создания; при отсутствии TRIGGER privilege сервер может скрывать их metadata, поэтому пустой список не является доказательством отсутствия триггеров. Работоспособность приложения от них не зависит; отсутствие привилегии проверено изолированно на отдельном аккаунте.

Для проверки собственных прав Lead при необходимости выполняет `SHOW GRANTS FOR CURRENT_USER;` отдельно. Перед передачей вывода убрать credential/authentication clauses, если конкретная версия сервера их показывает. Право TRIGGER не нужно. Все production-проверки остаются read-only.

## 2. Ожидаемая схема

Точное ожидаемое DDL пяти таблиц — в [20261020_battle_history.sql](../tools/migrations/20261020_battle_history.sql), **только как эталон для сравнения существующей схемы**. Stage 4A.1 удаляет из него два CREATE TRIGGER, но не меняет ни одной CREATE TABLE, колонки или ограничения. Если исходная Stage 4A выполнила все пять CREATE TABLE, а отказ был только на триггерах, изменение схемы не требуется.

Проверить:

- Пять таблиц присутствуют, Engine=InnoDB, table collation=utf8mb4_unicode_ci; identifiers/hash используют предусмотренный ascii_bin.
- game_id новых таблиц — signed BIGINT; journal_id — unsigned BIGINT. Реальные games.ind и journal поля должны помещаться в поддерживаемые положительные 64-bit PHP int. signed/unsigned INT game IDs подходят; unsigned BIGINT за пределами PHP_INT_MAX требует отдельного решения.
- matches: PK(game_id); версии/статусы/флаги, last_event_seq/last_step_seq и CHECK из эталона.
- batches: PK(game_id,journal_id); UNIQUE journal_id; UNIQUE(game_id,player_key,command_id); INDEX(game_id,command_id).
- actions: PK(game_id,action_id); INDEX(game_id,root_id); lifecycle и JSON CHECK.
- events: PK(game_id,event_seq); UNIQUE(game_id,journal_id,command_order); action/event_seq index; JSON/version CHECK.
- steps: PK(game_id,step_seq); UNIQUE(game_id,boundary_key); UNIQUE(game_id,journal_id,capture_order); generated nullable initial_guard/final_guard и соответствующие UNIQUE; JSON/version/flags CHECK; state_hash CHAR(64).
- Внутренние FK присутствуют как в эталоне, без CASCADE; внешние FK к games/journal не добавлены. Автоматические имена FK/CHECK и supporting indexes могут отличаться, сравнивать смысл/порядок колонок, не только имена.
- games и battle_command_journal не изменены. Их InnoDB/CAS/journal identity остаются существующим контрактом приложения.

Сохранить VERSION()/sql_mode: фактически аттестована MariaDB 10.8.4, InnoDB. Возможности другого production MySQL/MariaDB проверяются по его DDL и clone tests; совместимость не заявляется только по наличию таблиц. В частности CHECK должны реально исполняться, а generated guards и utf8mb4/FK должны соответствовать эталону.

Ожидаемые counts — ноль, поскольку регистрация ещё не включалась. Если есть строки, **не удалять и не перезаписывать их**: выяснить источник. Прежний state_hash=SHA256(state_json) не покрывает проекции и не принимается новым reader как достоверный кадр. Нет автоматического пересчёта старых checksum, миграции captures или их восстановления из games.json.

## 3. Гарантии без триггеров

HistoryRepository пишет snapshots исключительно INSERT. Retry читает committed batch; повторная semantic boundary отклоняется unique constraint и откатывает всю транзакцию. Нет UPDATE/DELETE/REPLACE/upsert для steps, отдельных COMMIT или механизма исправления сохранённых кадров. games + batch + actions + events + captures + journal committed по-прежнему подтверждаются одной caller transaction, с прежним порядком journal→games→metadata locks.

state_hash теперь проверяется до выдачи любого кадра в participant и полном server reader. Он покрывает окончательные JSON-строки полного state и public/host/player projections в версионированном envelope; подробности в design. Mismatch вызывает отказ чтения, не обновление данных/hash и не возврат повреждённого кадра.

С SQL-правами UPDATE/DELETE внешняя операция физически может изменить/удалить историю. SHA-256 выявляет изменение содержимого без согласованной замены checksum, но не защищает от одновременной подмены обоих значений. Это контроль целостности, не подпись, не защита от администратора и не прежняя DB-level immutable гарантия. Приложение не зависит от ограничения production grants; сокращение прав на steps/events/batches можно отдельно согласовать в следующем этапе, оно не требуется для этой доработки.

## 4. При расхождениях — отдельное согласование, без автоматического repair

На текущем этапе фактических расхождений, кроме отсутствующих ненужных триггеров, не доказано. Для них список DDL-операций **пуст**. Ни CREATE, ни DROP, ни ALTER не предлагать к выполнению как обязательный postcheck.

Если результаты покажут другие отличия, Lead передаёт SHOW CREATE/INDEX/columns и counts. Подготовить отдельный точный адресный patch только для доказанного отличия с clone test, оценкой блокировок, backup и отдельным согласованием. Примеры возможных решений для review (не команды для запуска сейчас): отсутствующий индекс — ADD INDEX с точными колонками; отсутствующий UNIQUE — сначала read-only GROUP BY/HAVING на дубликаты и только затем отдельно согласованный ADD UNIQUE; отсутствующий CHECK/FK — проверка существующих значений/orphans до отдельного ADD CONSTRAINT. Несовместимые типы/charset/generated expressions не править автоматически: ALTER может перестроить таблицу, изменить данные или блокировать запись.

Не удалять данные ради UNIQUE/FK, не делать DROP TABLE, не заменять таблицы и не менять games/journal. Если обнаружены неожиданные строки либо drift, оставить инфраструктуру выключенной и расследовать. При изменениях схемы в будущем нужна проверенная восстанавливаемая backup и отдельное окно/план; для текущих read-only postchecks backup-операция или DDL не требуются.

## 5. Только новая пустая установка

Полная `20261020_battle_history.sql` предназначена исключительно для новой базы, где ни одной battle_history_* таблицы ещё нет, после обычного preflight/backup и ручного разрешения Lead. Она содержит пять CREATE TABLE, не требует TRIGGER, не выполняется из runtime. Не переносить эту процедуру на текущую production-базу с уже созданными таблицами. Повтор по-прежнему намеренно останавливается на существующей первой таблице; IF NOT EXISTS не скрывает drift. DDL implicit COMMIT не позволяет считать набор CREATE атомарной миграцией.

## 6. Подтверждение Project Lead

Передать фактический production HEAD, server version/выбранную базу, read-only postcheck output без секретов, результаты сравнения типов/индексов/constraints и counts. Если таблицы соответствуют эталону, явно подтвердить: «пять таблиц совместимы, триггеры не требуются, SQL-изменения не нужны». Включение записи истории и Stage 4B остаются отдельным решением. Merge/deploy/production SQL Codex не выполняет.
