# Berserk — Stage 4A.1: история боя без SQL-триггеров

Дата: 20 октября 2026. Ветка: `codex/battle-replay-stage4a`. Фактический HEAD: `2755c79a6f20c98725b8d9709188884f2c902ce2`. Изменения Stage 4A/4A.1 находятся в рабочем дереве, отдельный коммит не запрашивался и не создавался.

## Результат

Система хранения адаптирована к работе **без SQL-триггеров и без права TRIGGER**. Согласно сообщению Project Lead, пять production-таблиц уже созданы, а отказ был на двух CREATE TRIGGER. Повторная полная миграция для них **не нужна**. SQL на production Codex не выполнял; точное соответствие фактических типов/индексов пока требует read-only postchecks от Lead.

Регистрация реальных матчей остаётся выключенной до отдельного Stage 4B. GameRepository, Command Journal, Db, Engine, GameLog, pending/continuation, способности карт и Гидра не менялись. Нет Recorder, endpoints, UI, merge или deploy.

## Изменения

| Файл | Что изменено |
|---|---|
| tools/migrations/20261020_battle_history.sql | Удалены два CREATE TRIGGER; пять CREATE TABLE, колонки, индексы/FK/CHECK/generated guards сохранены без изменений. Только для новых пустых установок |
| tools/migrations/20261020_battle_history_postchecks.sql | Новый отдельный SELECT/SHOW файл для пяти уже созданных таблиц; не выполняет DDL/DML |
| src/Core/BattleHistory/HistoryRepository.php | Checksum окончательных bytes полного state и трёх проекций; обязательная проверка перед steps и completedServerHistory; отказ без rehash/repair |
| tools/test_battle_history.php | Работа под аккаунтом без TRIGGER; 23 checks, включая повреждения state/projections/hash и неизменность кадров при retry/boundary conflict; SQL fault injection через временный CHECK только в lab |
| docs/battle-history-persistence-design.md | Новый уровень гарантий, точный hash envelope, fail-closed чтение и совместимость старых checksum |
| docs/battle-history-production-sql.md | Текущая инструкция для уже созданной production-схемы в phpMyAdmin; полную миграцию не повторять |
| docs/battle-stage4a-report.md | В начале добавлена актуализация Stage 4A.1; прежний отчёт и доказательства сохранены как исторические |
| docs/battle-stage4a1-no-triggers.md | Этот отчёт |
| .gitignore | Только адресное исключение для read-only postchecks SQL, сохранены прежние правила |
| docs/evidence/stage4a1/summary.json и stage4a1-evidence.zip | Новые доказательства; evidence Stage 3/3.1/3.2/4A не заменялся |

SHA-256 новой CREATE-миграции: **`fa8d3d94335271aa129ef20ebaba83e5086c827f8c655d435c1c202d469dfb20`**. Это контрольный hash эталона для fresh install, не предложение повторно применять его на production. Точное совпадение всех пяти CREATE TABLE с прежним Stage 4A проверено отдельно.

## Проверка persistence-контракта

Snapshots создаются исключительно INSERT. В HistoryRepository нет UPDATE/DELETE/REPLACE для battle_history_steps, ON DUPLICATE KEY UPDATE, механизма исправления кадров или отдельного COMMIT. Повтор committed identity читает существующий batch; при попытке повторной записи уникальность journal/command/boundary/capture приводит к ошибке и caller rollback. Отдельный тест сравнивает все bytes и checksum исходного кадра после retry и semantic boundary conflict.

Порядок locks остаётся journal→games→history metadata. Полная транзакция включает games, batch, lifecycle actions, events, несколько snapshots, metadata sequences и journal committed. Ошибка второго event INSERT или второго snapshot INSERT откатывает также первую вставку и новое games state; зарегистрированный до транзакции processing journal остаётся processing. Retry после подтверждённого rollback даёт прежние начальные sequence без gaps. Дополнительных COMMIT нет.

## SHA-256 и чтение

Прежний Stage 4A считал hash только state_json и при чтении его не проверял. Stage 4A.1 использует существующий CHAR(64) state_hash для SHA-256 версионированного envelope: format=`capture-sha256-v1`, schema_version и точные строки state_json/public_json/host_json/player_json, в этом порядке. JSON envelope кодируется теми же JSON_UNESCAPED_UNICODE/JSON_UNESCAPED_SLASHES/JSON_THROW_ON_ERROR флагами. Hash рассчитывается после окончательной сериализации каждого capture; несколько captures одной команды имеют независимые данные и checksum.

Reader берёт исходные сохранённые bytes, проверяет hash_equals и только затем возвращает выбранную проекцию; полные данные/hash не попадают в participant результат. completedServerHistory после обычной проверки завершения также проверяет каждый кадр. Любой mismatch вызывает UnexpectedValueException с номером шага; повреждённый кадр или частично проверенная история не возвращаются. Нет повторной сериализации DTO при проверке, обновления state_hash, fallback к другой проекции или маскирования несоответствия.

Проверены повреждения state_json, public_json, host_json, player_json и state_hash отдельно, включая валидный JSON с неверным содержимым. Все три participant view и полный reader отказывают в выдаче; исходный hash остаётся прежним. Повторное чтение валидной записи не меняет ни данные, ни checksum.

Прежний state-only checksum не подтверждает сохранённые проекции и **не принимается** новым reader автоматически. Нет автоматического rehash/migration старых кадров. Production-регистрация ещё выключена; если read-only counts покажут неожиданные captures, оставить их без изменений и отдельно определить происхождение/формат. Старые synthetic доказательства сохранены отдельно и не считаются новым production-реплеем.

## Уровень гарантий

Теперь неизменяемость — контракт приложения + unique constraints + проверка целостности, **не DB-level запрет UPDATE/DELETE**. SQL-пользователь с этими правами может изменить или удалить данные внешней командой. SHA-256 обнаруживает изменение bytes без согласованной замены checksum, но не предотвращает одновременную замену обоих; это не подпись и не криптографическая защита от администратора БД. Отсутствующую из-за внешнего DELETE запись один checksum не восстановит. Identity/order/action metadata вне envelope не защищены checksum от привилегированной подмены. В production API операций исправления нет.

## Изолированные проверки

MariaDB **10.8.4**, InnoDB, PHP **8.1.9**. Сохранённый отдельный datadir `C:/Users/mi/Documents/Berserk/stage4a-verification/data`, порт **33389**, bind 127.0.0.1, --no-defaults. Перед работой проверены @@datadir/@@port/VERSION. Рабочая/production БД не использовалась.

Новая synthetic schema: **berserk_stage4a_run7** (финальный прогон; run6 сохранён отдельно), новый аккаунт **stage4a1**. Явно выданы только SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER на эту схему; глобально только USAGE. Права TRIGGER нет. Схема создана с нуля, миграция успешно выполнена этим аккаунтом; root read-only проверка подтверждает **0 triggers** в тестовой схеме. В тестах триггеры тоже не создавались. Для ошибки на второй вставке временный CHECK scoped к synthetic game добавлялся и снимался исключительно в этой lab-схеме; это не часть migration/production API.

Отдельный каталог новых доказательств: `C:/Users/mi/Documents/Berserk/stage4a1-verification`. Копия приложения остаётся без .git, test guard требует BERSERK_STAGE4A_ISOLATED=1, фиксированные host/port/datadir и synthetic schema allowlist. Старые suites работают в отдельной berserk_stage4a_regression со своим synthetic config; production config/storage не копировались. Тестовый сервер после проверки остановлен; данные/результаты сохранены.

| Проверка | Результат |
|---|---|
| Stage 4A checks, адаптированные и дополненные | **23 PASS**, 0 FAIL/SKIP |
| R1–R3 адресные сценарии | **33 PASS** |
| Существующие regression suites | **18 suites PASS**, exit=0, без SKIP |
| PHP lint | **4/4** |
| Новый production postchecks SQL на isolated schema | exit=0, SELECT/SHOW-only |
| Пять CREATE TABLE относительно Stage 4A | Все 5 совпадают, не изменены |
| Прежние исходники приложения | 163 файла совпадают с HEAD; GameLog/Db/journal/Engine не менялись |
| Git / whitespace | diff --check и отдельная проверка новых файлов успешны |

Всего **74 проверочные единицы**: 23 SQL/DTO/integrity/concurrency checks + 33 cases + 18 suites; lint/postchecks/integrity дополнительно. Существующие suites: hydra, ragnar, combat_instant_phases, degasca, bon_and_berrow, swamp_children, movement_effects, forced_directional_movement, volkhv_wound_transfer, resign_pending, nested_combat_ownership, command_journal_repository, battle_command_endpoint, turn_timer, informational_wait, turn_timeout, pending_wait_timer, turn_instant_stack.

Среди проверок: создание всех таблиц без TRIGGER; разные snapshots одной persistence version; SHA-256 tamper detection; read-only поведение reader; committed retry и semantic boundary conflict; rollback второй event/snapshot вставки; allocation/ordering; два PHP-процесса с конкурирующими командами, включая stale loser без history allocation. Гидра: combat(closed=false) → series_end(closed=true) → немедленный turn_end(closed=true), старые кадры неизменны; natural finish — один combat(closed=true), без End-step. Новая система не исполняет реальные атаки.

## Что делает Project Lead

1. В выбранной production-базе phpMyAdmin выполнить только `20261020_battle_history_postchecks.sql`, сохранить результат без секретов. Полный CREATE-файл не запускать.
2. Сравнить пять таблиц с эталоном по инструкции: типы, InnoDB, charset/collation, unique/FK/CHECK/generated guards и counts. Отсутствие триггеров допустимо и не требует исправлений.
3. Если всё совпало — подтвердить, что schema repair не требуется. Если есть иные расхождения — передать SHOW CREATE/INDEX/counts для отдельного адресного патча и согласования; ничего не удалять/пересоздавать и не переписывать snapshots.
4. Регистрацию истории оставить выключенной. Stage 4B/Recorder включать только после отдельного разрешения.

Иных фактических production-расхождений пока не установлено; список обязательных repair SQL пуст. Проверки физического network/crash/power-failure при COMMIT не добавлялись: прежние ограничения Stage 4A сохраняются. Merge/deploy/production SQL не выполнялись.
