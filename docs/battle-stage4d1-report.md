# Stage 4D.1 — итоговый отчёт

Ветка `codex/battle-replay-stage4d1`. Stage 4D проверен diff review и повторными observer/access тестами (4 PASS), затем закреплён отдельным коммитом `f05163e93d346ab114474cc3ccc6b4e23c664ff7`. Новые HTTP-прогоны дополнительно проверили Stage 4D на реальных командах. Push, merge, deploy и production SQL не выполнялись.

## Авторизация и решение

Аудит www/index.php: first/second выбирают host/player, user_id назначается как 1/2, стандартная партия создаётся с участниками 1/2; legacy session не доказывает личность. Проверенного внешнего login/родительского auth bridge в исходниках не найдено. Integer berserk_authenticated_user_id — только предусмотренный trusted principal, игровые маршруты его не устанавливают.

Добавлен JournalInvitations: серверный приватный файловый реестр вне checkout; random 256-bit одноразовые секреты, SHA-256 hashes без plaintext, сериализованное погашение flock + atomic replacement. После успешного CSRF и session regeneration новый случайный binding сохраняется только в PHP session, hash — в реестре. Game/side/expiry задаёт CLI, запрос не выбирает права. Тот же expiry ограничивает чтение после game_over. Отзыв проверяется на каждом запросе. Реестр повреждён/недоступен — отказ. Доступ не задаёт trusted principal и не открывает replay.

В активном invitation mode статические test bindings Stage 4D игнорируются. Защита выбранных игровых IDs больше не зависит только от recording_enabled: отключение записи не должно открывать first/second обход. Это единственное изменение index.php; правила боя, pending, continuation, Recorder, БД и старое логирование не менялись. UI Stage 4D сохранён.

## Изменения

- src/Core/BattleHistory/JournalInvitations.php — приватный реестр.
- src/Core/BattleHistory/HistoryRuntime.php — конфигурация и проверка capability, защита выбранных IDs.
- www/journal-access.php — TLS + CSRF POST redeem, безопасная смена сессии, закрытый по умолчанию маршрут.
- www/index.php — проверка protects вместо только records перед обработкой игры.
- tools/journal_access.php — issue в приватный новый файл и revoke по grant id; CLI only.
- tools/test_journal_invitations.php — изолированные контракты без БД.
- config/battle_history.example.php — новые опции OFF.
- Этот отчёт, инструкция выкладки и evidence.

## Проверки

Изолированная source-only копия без .git/production config/storage; отдельная MariaDB 10.8.4, 127.0.0.1:33389, проверенный datadir stage4a-verification/data, новая схема berserk_stage4d1_run1. HTTP PHP 8.1.9 только 127.0.0.1:33494. Fixture user без TRIGGER, SELECT-only reader для доказательства отсутствия SQL write. Ни одного обращения к production БД.

- 10 основных HTTP сценариев PASS: реальные move/combat/dice/wounds/turn/terminal, обе исторические проекции, инстант меняет окончательный результат, лечение, смерть, доступ, read-only, идемпотентность и rollback, ordinary OFF.
- 17 проверок доступа PASS: оба подключения, повторное приглашение, чужая сторона/матч, expiry/revoke, новая сессия, replay 401, CSRF, запрет query, отсутствие plaintext в ledger/log, ordinary journal OFF.
- 5 дополнительных security проверок PASS: один победитель погашения, session ID rotation, session-only HttpOnly/SameSite cookie, HTTPS enforcement, corrupt ledger fail closed. Дополнительно два отдельных PHP-процесса одновременно погашали приглашение: ровно один успех; браузер полностью закрывался и запускался заново, новый cookie отсутствует, повторное приглашение отклонено.
- Дополнительные HTTP: vampire heal, ограничения API/disable, event-only replay policy (3 PASS); turn instant/pending/timer (1), номер завершённого хода (1), финальные проекции пяти матчей (1).
- Edge/Playwright, два независимых контекста: доступ через настоящую форму, 45 строк, live без reload, панель сохраняется, scrollTop=0 сохраняется, дубликатов 0, reconnect продолжает cursor, ошибок JS 0. Оба скриншота сохранены. Lab-only dice endpoint задавал детерминированные броски и не входит в поставку.
- 21 набор регрессий PASS, включая R1–R3, Гидру, Ragnar, инстанты, перенос ран, Command Journal, pending/continuation/timers/pause. Четыре первые ошибки из-за отсутствующих docs/cards JSON исправлены добавлением неизменённых фикстур; повторены только эти наборы, первичные результаты сохранены.
- В первой дополнительной full-SQL сверке появился mismatch после чтения hidden; повтор с сохранением before/after снимков прошёл по всем пяти матчам. Основной read-only HTTP сценарий также прошёл с SELECT-only учётной записью и запретом загрузки gameplay классов. Сам endpoint по review выполняет только SELECT; первоначальный mismatch не используется как PASS evidence.

PHP lint восьми проверенных файлов и git diff --check — PASS; SHA исходников совпадают с тестовой копией.

Подробности и воспроизводимые harness scripts: docs/evidence/stage4d1. Старые отчёты Stage 3–4D и архивы сохранены.

## Границы

Recorder/UI/сражения не расширялись. Правило Гидры Stage 3.2 сохранено. Incomplete/gaps остаются неполными после terminal; история не обогащается текущим состоянием. Конкретный production TLS, ACL, PHP session storage и существующая SQL схема требуют ручной проверки по rollout. Хранение ledger рассчитано на один сервер/общий локальный filesystem с flock; не на несогласованные независимые узлы. Отзыв не удаляет уже увиденное из браузера. Browser restart может восстановить session cookie в зависимости от браузера, но не продлевает срок и не отменяет отзыв. Новые приглашения создаются только оператором на сервере, production секреты не выпускались.
