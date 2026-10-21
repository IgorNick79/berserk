# Stage 5A — аккаунты и авторизация

Дата: 21 октября 2026. Реализация подготовлена для review Project Lead. Production не изменялся.

## База и Git

Настоящий checkout найден по ссылкам существующих worktrees: `C:/Users/mi/Documents/Codex/berserk`. Его текущая ветка `codex/battle-journal-gap-ui`, чистый status, HEAD `1e0163748c32c452443763252661072f11c66576` (`fix(battle): consolidate journal gap warnings in UI`). История содержит Stage 4D.1 `b266c3e14d4fb5cc737153a42f93ed02153554bc`, release review `1736330`, Stage 4D `f05163e`; ancestry Stage 4D.1 проверена git merge-base --is-ancestor, exit=0. Это подтверждённая локальная база, без заявления о её совпадении с production.

Для работы создан настоящий локальный clone --no-hardlinks этого репозитория в `C:/Users/mi/Documents/Berserk/stage5a-auth`, затем ветка **codex/berserk-stage5a-auth** от указанного HEAD. HEAD не менялся: коммиты не создавались. Исходный checkout не переключался и не редактировался. Новый репозиторий не инициализировался из лабораторной копии. Merge, fetch/push и deploy не выполнялись. Origin клона — локальный исходный репозиторий.

Изучены утверждённый аудит `C:/Users/mi/Documents/Berserk/docs/auth-lobby-architecture-audit.md`, Autoloader, Db, sessions/bootstrap игры и games. Аудит ранее опирался на source-копии; теперь найдена реальная Git-база. Формат account ID — signed INT, совместимый с локальными migrations/fixtures games. Production SHOW CREATE остаётся обязательным preflight, серверная схема не читалась.

## Результат

- Account, AuthStore, AuthService и AuthSession: регистрация, UNIQUE login_key, password_hash/password_verify, проверка положительного trusted ID, CSRF и rotation/logout.
- Login строго 3–32 ASCII символа, регистронезависимая уникальность с сохранением отображения. Пароль 10–72 байта, без скрытого bcrypt truncation/NUL; верхние ограничения HTTP inputs.
- Независимый DB-backed limiter: 5 попыток/login и 20/IP за 15 минут, HMAC buckets, атомарное резервирование под InnoDB locks между процессами и сессиями, одинаковое сообщение unknown/wrong password.
- Отдельный `auth.php` с login/register/account/logout, текущим global.css и небольшой auth.css. После входа только заглушка: «Вы вошли как … Игровое лобби появится на следующем этапе».
- Ручные additive migration/preflight/postchecks. Accounts и auth_login_attempts; lobby_rooms отсутствует. Runtime не содержит CREATE/ALTER, не вызывает игровые repositories; отсутствующая/несовместимая схема даёт общий 503.

API/identity описаны в [stage5a-auth-api.md](stage5a-auth-api.md), установка и воспроизведение — в [stage5a-auth-sql.md](stage5a-auth-sql.md).

## Изоляция legacy и Stage 5C

Ключ principal единственный: `berserk_authenticated_user_id`. Используется **отдельная BERSERKAUTH5A session-cookie**, старый PHPSESSID не изменяется и не получает этот ключ. Поэтому новые accounts 1/2 не попадают в старые history membership/admin проверки. HTTP-проверка с реальным неизменённым replay.php и залогиненным account 1 вернула 401 authentication_required. Эта проверка не требует gameplay DB/config.

Игра не интегрирована с accounts; публичные prototype URL всё ещё являются известным ограничением. Stage 5A не заявляет, что старые игровые endpoints стали безопасными. При Stage 5C сначала нужен account-owned game gate для всех game/chat/history/replay paths, затем контролируемое подключение AuthSession; нельзя просто поделиться cookie/principal с legacy. Не заменять side или host/player на account ID. Stage 5B должен использовать тот же AuthSession/Store и добавить собственные rooms/creator/candidate/accepted, без запуска игры до accept.

## Проверки и evidence

Лаборатория: PHP **8.1.9**, mysqli/mysqlnd; MariaDB **10.8.4**, отдельный новый datadir `stage5a-auth/tmp/auth-lab/data`, TCP 127.0.0.1:33551, уникальная схема на каждый прогон. Ни config/db.php, ни production connection не использовались. Runtime fixture user имеет только SELECT/INSERT accounts и SELECT/INSERT/UPDATE attempts, без TRIGGER, DDL и прав на games. SQL применялся только в этой лаборатории. Четыре независимых PHP HTTP worker процесса для гонок.

**48/48 HTTP/security assertions PASS**, полный воспроизводимый runner `tools/test_auth_http.py`, evidence [results.json](evidence/stage5a/results.json):

- Register/login/logout, case-insensitive duplicate, invalid login, short/long/NUL и 72-byte boundary passwords, hash at rest; пароль не отражается в HTTP/session/access logs.
- CSRF, GET logout 405, account ID spoof, strict fixation rejection, regeneration/delete old session и удаление заранее внедрённых legacy role/user_id/test binding.
- Одновременная регистрация: один 303, один 422, одна строка. 12 concurrent login из независимых сессий: ровно 5 прошли до проверки пароля и 7 получили 429; правильный пароль также не обходит исчерпанный лимит. 21 разных login с одного IP: 20 разрешённых проверок + 429. Истечение окна восстанавливает вход.
- GET форм без SQL mutations, gameplay-autoload trap, отдельные cookie, исходная legacy game fixture неизменна. Runtime DB grants дополнительно физически запрещают изменение games.
- Missing schema/удалённый UNIQUE: общий 503, таблицы не создаются на лету. Исходная схема восстанавливалась только тестовым setup account.
- Production HTTP отклоняется, поддельный X-Forwarded-Proto не помогает. Настоящий локальный TLS ingress с самоподписанным сертификатом: Secure/HttpOnly cookie и успешный вход. HTTPS server variable задавался изолированным тестовым адаптером, а не пользовательским заголовком; production proxy/FPM/PKI не проверены.

**6/6 релевантных regression suites PASS** на disposable source-only копии без config/db.php и .git; [regressions.json](evidence/stage5a/regressions.json) и отдельные текстовые результаты:

1. test_turn_timer.php.
2. test_pending_wait_timer.php — все 57 зарегистрированных handlers.
3. test_settings_flow.php — PASS; **DB-backed draft initialization skipped**, поскольку намеренно нет игрового config/db.php. Это не полный DB regression.
4. test_sealed_flow.php.
5. test_solomon_draft.php — 1764 in-memory checks.
6. test_journal_invitations.php — одноразовость, scope/revoke/hash/OFF/no replay principal; private ledger вне disposable application.

PHP lint: 6/6 новых PHP файлов PASS. git diff --check PASS. [gameplay-unchanged.txt](evidence/stage5a/gameplay-unchanged.txt) подтверждает diff exit=0 по protected gameplay paths относительно base HEAD. [postchecks.txt](evidence/stage5a/postchecks.txt) сохраняет фактический результат схемы. Полные игровые DB/боевые HTTP suites не запускались: игровой runtime не менялся; их не выдаём за выполненные.

На промежуточных прогонах исправлена перезапись Cache-Control стандартным PHP session cache limiter. Исправлены тестовые ошибки: область Python import, регистр атрибута secure, OpenSSL config, расположение private ledger. Итоговое evidence содержит последний успешный прогон. При публикации evidence не копировались datadir, session files, credentials, TLS private key или invitation ledger.

## Итоговый diff review

Reviewed весь новый auth source, SQL, test runner, configuration example и документация; изменения ограничены auth boundary и additive artifacts. .gitignore добавляет только исключения для трёх одобренных SQL-файлов. Полный список — [file-list.txt](evidence/stage5a/file-list.txt).

Проверены существенные границы: SQL подготовленные statements; UNIQUE конфликт решает БД; limiter блокирует строки в стабильном порядке и коммитит до hash verification; нет nested game transaction или new Engine calls; request principal не берётся из GET/POST; password не попадает в session/SQL error response; logout работает без доступной auth DB; новая cookie не передаёт principal старой игре. Игровые index.php, GameRepository/GameState, CommandJournalRepository, Engine, PrepareProcessor, таймер, Chat, Recorder, LiveJournal и ReplayPreview не менялись. UI журнала и его неполная история не исправлялись.

## Ограничения и передача Lead

Production migration и TLS/session backend требуют ручного preflight Lead. Проверена конкретная MariaDB, не заявлена универсальная совместимость всех MySQL версий. Existing game runtime DDL оставлен неизменным, auth его не загружает. Для proxy нужен доверенный HTTPS server variable; REMOTE_ADDR limiter может объединять пользователей за proxy. Простая политика login limit допускает временный denial-of-service по известному login. Очистку устаревших HMAC buckets выполняет отдельное обслуживание; runtime не получает DELETE/DDL.

Нет лобби, создания игр, candidate/accept/reject, общей игровой авторизации или исправления Live Journal. Stage 5B/5C остаются отдельными этапами. После этого отчёта работа остановлена для review Project Lead; merge/push/deploy/production SQL не выполняются.
