# Stage 5D — Final Release Review

**NO-GO. Review и подготовка выполнены; допуск в production не выдан.** Воспроизведён блокирующий continuous Grid timeout. Новых критических обходов авторизации/CSRF или нарушений атомарности комнаты и матча при проверках не обнаружено. Полный критерий release readiness не выполнен из-за Grid.

## Git

Checkout: C:/Users/mi/Documents/Berserk/stage5a-auth.
Ветка: codex/berserk-stage5c-integration.
База: 1e0163748c32c452443763252661072f11c66576.
Stage5A: 822dacc89a32b7121fef4541f959f3d91ac2ee3f.
Stage5B: 46e9380941d8e1662cff3809fec07cdb998ac4b5.
Stage5C / проверенный runtime HEAD: **1b83c62a6197fe7dfaadebc48f645a72eef0a8a6**.

Stage5C зафиксирован отдельным коммитом:49 файлов. Следующий commit содержит только документы/evidence, SQL preflight и тестовые инструменты Stage5D; runtime после 1b83c62 не меняется. Финальный hash пакета доступен в git log (самоссылочный hash документа не записывается).

Объединённый diff base→Stage5C проверен:100 файлов. Manifest `evidence/stage5d/release-files.txt`, SHA256 `release-sha256.json`, Git сводка `git-review.txt`. Это delta, а не полный дистрибутив: исходные зависимости/assets тоже необходимы. В staged5C нет private configs, sessions, TLS keys, БД, tmp или storage. storage/games сохранён. Production SQL/config, push/merge/deploy не выполнялись.

## Итоговый полный diff review

| Область | Вывод |
|---|---|
| Account/AuthService/AuthStore | ASCII normalized UNIQUE login; bcrypt 10–72 bytes/NUL; prepared SQL; expensive dummy verification; HMAC login/IP limiter со stable-order locks. Schema только проверяется чтением. |
| AuthSession/AuthContext | BERSERKAUTH5A, строгая cookie session, HttpOnly/Lax/Secure, rotation и полная замена principal/CSRF; PHPSESSID/role/user_id не импортируются. Существование аккаунта проверяется БД, config fail-closed. |
| LobbyStore/MatchCreator | Creator/candidate/accepted разделены; POST+csrf+version+request под FOR UPDATE. Stale/double/self/noncreator accept запрещены. Один InnoDB transaction games+JSON+room link, no orphan. Существующий GameState constructor, sides null; подготовка до accept не начинается. |
| auth/lobby PHP, CSS/JS | Server validation, CSP/no-store, clickable row без Join; candidate/reject/accept; polling/backoff/epoch, read recovery без повторной mutation при потерянном ответе. started ведёт в существующий index. |
| index.php и GameAccess | Membership до chat/repos/Dice/automatic progress; SQL room.started, creator/accepted, games IDs и JSON IDs должны совпасть. Candidate/outsider/legacy binding не становятся actor. Нет create/reset/first/second/debug authority; GET cmd405, POST csrf, сторонний403. Просмотрены все6 PHP entrypoints в www. |
| Battle command/sync/status | Существующие nonce/journal/CAS/recovery/automatic order сохранены; csrf добавлен и исключён из payload hash. Raw POST вне journal battle endpoint405. Command status actor-scoped. Авторизованный battle_sync GET сохраняет прежнюю automatic semantics. |
| Preparation/GameTransport | POST+csrf+persistence version, stale409, PRG303; place journal сохранён. HTML/fragment decoration и JS меняют только HTTP transport. Non-draft prepare_sync читает metadata без Engine/progress. |
| Draft sync | POST+csrf; обычный GET больше не запускает draft timeout. Процессоры/правила не менялись. Обнаружен существующий Grid тупик, описанный отдельно. |
| Chat | Early branch после membership; реальный account sender/shared csrf, side flags убраны из JS/template. GameChat неизменён. |
| Live Journal/Replay/invitations | AuthContext+GameAccess до существующих читателей, flags/allowlists сохранены. Legacy binding только для legacy journal; account-owned запрещён. Numeric admin bypass Replay закрыт outer membership. Найденный invitation redirect на first/second исправлен на battle-journal. |
| Repository/SQL | Только runtime CREATE/ALTER удалены, missing version fail-closed. Journal DDL перенесён без изменений в manual SQL. CAS/commit/rollback сохранены. Нет runtime CREATE/ALTER/DROP TABLE или CREATE TRIGGER по поиску src/www. |
| Config/gitignore/docs/tools | Приватные configs игнорируются, разрешены конкретные additive SQL. Исторические 5A/5B отчёты описывают те этапы, не текущий HEAD. Кодировка комментариев .gitignore ранее испорчена, patterns работают. Tests/evidence не являются production runtime. |

По src/Core base→HEAD менялись только GameRepository и CommandJournalRepository ради DDL removal. Engine, GameState, Prepare/Draft/Sealed, Recorder/BattleHistory, Strike/Action/Instant, GameChat, pending и timer classes неизменны. Журнал493 не исправлялся.

## Реально выполнено повторно

PHP8.1.9 CLI/mysqli/mysqlnd, MariaDB10.8.4/InnoDB, отдельные datadirs с проверкой port/datadir до setup SQL, несколько HTTP workers; runtime users без DDL/TRIGGER.

- **68/68 integration HTTP**, **7/7 browser** (два независимых Edge/Playwright contexts): register→login→lobby→candidate→accept→existing prep/place/battle/terminal. System/Grid/Solomon/Sealed, outsider/side spoof, reload/relogin, command nonce/status/CAS, pending/timer/chat/live journal/completed replay/integrity. Browser покрывает System и общий UI; остальные режимы real HTTP. Некоторые браузерные действия выполняются same-session fetch, не всё click-only.
- **48/48 Auth/security**, включая rotation/fixation/logout, uniqueness/length/NUL/injection/XSS/CSRF/limiter/concurrency и локальный реальный TLS ingress/Secure cookie. Первые setup попытки не запустили полный suite из-за отсутствующего sessions directory; после создания каталогов полный PASS, Auth runtime не менялся.
- **44/44 Lobby HTTP** через tools/test_lobby_release.py: исходный 5B runner с исключённым obsolete placeholder browser block. Current browser отдельно выше. Candidate race200/403, double accept200/409, accept/reject200/409; stale/rejected URLs, lost reply recovery и две fault-injection проверки rollback без orphan. evidence/lobby-concurrency.json.
- **11/11 regression suites**: repository optimistic locking и command journal realDB; turn_timer, pending_wait_timer, informational_wait, turn_timeout, match_pause, settings_flow (включая realDB), sealed_flow, solomon_draft, live_battle_journal. Disposable source copy, outputs сохранены.
- PHP lint **18/18**, отдельно fixture PHP syntax, JS6/6 и Python AST3/3; git whitespace и engine diff. Release read-only SQL выполнен на lab MariaDB,289 строк metadata.
- Grid **168 сценариев**, по два resolveDraftTimeouts на каждый:150 exhausted (135 view /15 guard errors),3 seed12 (все одинаково failing),15 boundary/terminal (успех). В100 seeded exhausted8 ошибок, в50 native RNG7 в итоговом прогоне. Это воспроизведённый дефект, а не зелёный Grid suite. Точные state/hashes/errors в evidence.

Команды: python tools/test_integration_http.py; python tools/test_lobby_release.py; python tools/test_auth_http.py --openssl <local openssl>; php -n tools/test_grid_timeout_fixture.php. Auth lab требует новый data и sessions; приватные configs runners не перезаписывают. Результаты в evidence/stage5d, полные regression outputs приложены.

## Риски, пропуски и необходимые изменения

**P1 / release blocker — Grid timeout.** Frozen seed12: host30/player29, осталась card_15, у player уже3 копии. Player взять нельзя по copy cap; host нельзя по opponent deck minimum. pass/recycle повторяются до guard500. Partial in-memory progression при ошибке не сохраняется; повтор с новым random иногда проходит. Локализованная причина и воспроизводимый seed не доказывают тождественность всех шагов исходного HTTP failure, RNG trace которого не сохранён. Подробности — stage5d-grid-timeout.md. Без отдельного разрешения processors/правила не исправлялись.

**Hosting не проверен реально:** FPM/webserver/proxy/PKI, production SQL modes/grants/schema, session store/workers, permissions/CDN. Auth принимает HTTPS=on/1, legacy journal-access только on; требуется доверенный FPM HTTPS=on, без доверия произвольному X-Forwarded-Proto и без production allow_loopback_http. Исторический HTTPS rejection на хостинге не диагностирован из-за отсутствия доступа. Готов конкретный hosting gate и smoke в stage5d-production-deployment.md.

Long timers/HP/metadata faults ускорялись SQL только в lab, это не длительная естественная партия. Synthetic catalog без production artwork/synergy. Не запускались исторические scripts с hardcoded опасным datadir; Recorder/history проверялись текущими HTTP и LiveJournal model suite. Replay experimental допускает incomplete completeness metadata по существующему контракту. Бой493 не проверялся и не восстанавливался. Legacy invitation redirect проверен lint/diff; end-to-end consume legacy invitation заново в5D не выполнялся, account-owned binding denial проверен HTTP. Load/soak, crash/power-loss COMMIT и multi-host session storage не проверены.

Операционные ограничения: active lobby без pagination/room quotas/archive; limiter buckets не чистятся runtime. За proxy общий REMOTE_ADDR способен объединить пользователей под IP limit20/15min: проверить адресацию с провайдером, не доверять пользовательским headers. Требуется контроль429 и объёма хранения при будущем ограниченном тесте.

До deployment: отдельное разрешение на исправление Grid и regression/HTTP повтор; реальный hosting HTTPS/session gate; проверка схем/grants, backup restore; smoke под maintenance и отдельное решение Lead. План SQL — stage5d-sql-review.md; read-only preflight — tools/migrations/20261021_release_preflight.sql. Ограниченный релиз без Grid потребовал бы согласованного серверного ограничения; в текущем коде его нет. Текущий полный набор режимов **не готов даже к ограниченной production выкладке**.

Stage5C закоммичен, review package подготовлен. Остановка для Project Lead. Production не изменён; storage/games не удалён, runtime tests над пользовательскими данными не выполнялись.
