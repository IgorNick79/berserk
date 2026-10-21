# Stage 5C — интеграция аккаунтов, лобби и игры

21 октября 2026. Локальная реализация подготовлена для review Project Lead. Production, merge/push/deploy не выполнялись. Финальный интеграционный прогон PASS; отдельное промежуточное наблюдение Grid и его пределы описаны ниже, без заявления об исправлении Engine.

## Git и подготовка

Настоящий checkout: `C:/Users/mi/Documents/Berserk/stage5a-auth`, origin — локальный `C:/Users/mi/Documents/Codex/berserk`. Сохранена единая история, новые репозитории/параллельные базы не создавались.

- Stage 5A: **822dacc89a32b7121fef4541f959f3d91ac2ee3f**.
- Перед 5C reviewed весь diff 5B, повторены **45 HTTP + 13 UI assertions Stage 5B**, все PASS. Stage 5B сохранён отдельным коммитом **46e9380941d8e1662cff3809fec07cdb998ac4b5** (`feat(lobby): add transactional candidate confirmation for Stage 5B`). В коммит включён явный список auth/lobby/source/SQL/tests/docs/evidence, без private configs, БД или sessions.
- Создана текущая ветка **codex/berserk-stage5c-integration** от этого коммита. Фактический **HEAD = 46e9380941d8e1662cff3809fec07cdb998ac4b5**. Изменения 5C оставлены в рабочем дереве для review, дополнительный commit 5C не создавался.

До изменения игрового транспорта проведён read-only аудит всех шести PHP entrypoints www и ранних веток index. Матрица и решение границ зафиксированы в [stage5c-endpoint-audit.md](stage5c-endpoint-audit.md). Неустранимых транспортом архитектурных противоречий не выявлено. Изменение правил/Engine не потребовалось.

## Результат интеграции

Оба подтверждённых участника после accept автоматически открывают **существующий** `index.php?game=N`. Новый матч при переходе не создаётся; загружается сохранённый mode/GameState Stage 5B. Подготовка, System/Grid/Solomon/Sealed, view/turn/side/deal/place и battle используют прежние процессы и storage. Добавлена ссылка возврата в лобби; активную игру можно вновь открыть из строки таблицы, refresh/relogin не сбрасывают состояние.

AuthContext начинает тот же AuthSession/BERSERKAUTH5A и читает только `berserk_authenticated_user_id`, проверяет существование аккаунта. GameAccess одним SELECT проверяет room.started, membership creator/accepted, SQL host/player и JSON/PlayerState IDs. Только затем выдаёт server actor host/player. Проверка стоит **до** раннего chat exit, repository/Engine/Dice/automatic. Нет переноса PHPSESSID, URL-role или cached role. Account IDs 3/4 и коллизии 1/2 проверены отдельно; side/firstPlayer сохранили игровую семантику.

GET create/reset/first/second/debug не являются публичным способом игры. GET cmd отвергается, подготовка отправляет POST с CSRF и persisted version через существующий index/Engine, с PRG. Декоратор GameTransport адаптирует HTML/fragment transport без переписывания Screen/Engine. Для остальных ожидающих подготовительных экранов добавлен metadata-only prepare_sync: status/version, без прогрессии и записей. Draft sync стал POST+CSRF с тем же timeout processor.

Боевой transport сохраняет **единственный** battle_command/journal путь, command_id, expected_version, CAS, recovery и server-side actor. Raw battle POST в подготовительный handler запрещён: это необходимое закрытие возможного обхода journal. Battle-sync продолжает перехватывать исходные боевые формы и добавляет общий CSRF. Chat отправляет реальный account sender и остаётся отдельным от таймера/Engine.

Journal использует тот же confirmed membership и прежнюю историческую side projection. Replay требует confirmed participant нового матча, terminal state и прежние allowlists/flags/initial-final/sequence/SHA проверки; numeric admin config не обходит outer guard. Legacy invitations остались отдельным PHPSESSID journal-only механизмом: ни новый account match, ни gameplay они не открывают. Данные старых матчей не удалялись.

Подробности principal, методов, версий, cookies и границ — [stage5c-auth-context.md](stage5c-auth-context.md).

## Изменения persistence и неизменность Engine

Убраны только прежние constructor schema mutations:

- GameRepository при отсутствии games.version отказывает, не делает ALTER.
- CommandJournalRepository проверяет existing InnoDB/schema/indexes, не выполняет CREATE IF NOT EXISTS.

Это precondition persistence adapters, не изменение commit/command/gameplay семантики. Та же существующая схема battle_command_journal вынесена в ручной `20261021_command_journal.sql` только для отсутствующей таблицы. Новых логических таблиц, второго journal, нового GameState/Engine/storage нет. Полный `rg` по src не нашёл CREATE/ALTER/DROP TABLE.

Engine, PrepareProcessor/Draft/Sealed, StrikeResolver, ActionResolver, InstantProcessor, GameState, TurnTimer, pending/automatic services, GameChat, Recorder, HistoryRuntime/LiveJournal/ReplayPreview/HistoryRepository **не менялись**. Git diff protected paths относительно 46e9380 — exit=0 ([engine-unchanged.txt](evidence/stage5c/engine-unchanged.txt)). Core diff ограничен двумя schema-precondition участками repositories; их execute/commit/CAS пути сохранены.

## Лаборатория и воспроизведение

PHP **8.1.9**, mysqli/mysqlnd, MariaDB **10.8.4**, InnoDB. Отдельный datadir `tmp/integration-lab/data`, только 127.0.0.1:33571, четыре PHP HTTP процесса 33572–33575. До любого setup SQL сверены точные @@port/@@datadir. Каждый прогон использует новую схему и пользователей; runtime не имеет DDL/TRIGGER, tables устанавливает только lab setup. Root production config не использовался; временные config/auth.local.php, db.php и battle_history.local.php содержали только lab connection и удаляются runner finally.

```powershell
python tools/test_integration_http.py
```

Runner принимает --php, --db-bin, --node, --playwright, --browser; defaults соответствуют установленным локальным инструментам. Он отказывается перезаписывать существующие private configs и подключаться к занятым lab ports; новый datadir инициализирует только под tmp/integration-lab. Синтетический каталог: 100 обычных существующих creature definitions, все четыре rarity (включая `ultrarare`), official deck, accounts через настоящие registration/login endpoints. Это транспортная лаборатория, не копия production каталога.

## Финальные результаты

**68/68 HTTP/DB assertions PASS**, [results.json](evidence/stage5c/results.json). В число включены два aggregate assertions для regression/browser runners, поэтому не складывать их как независимые сотни E2E тестов.

- Registration/login/lobby/create/candidate/accept → один game → System preparation → place → battle. Обоим доступны актуальные экраны, GET role/commands не выполняются, POST CSRF проверяется.
- Guest/outsider/legacy game и PHPSESSID denied; valid invitation binding не читает новый match. SQL room/JSON mismatch fails closed. IDs 3/4 дают creator→host, accepted→player; account 1 не наследует этот матч. Logout/switch account/relogin не сохраняют cached role, продолжается тот же game.
- Обычные move/strike/defender/instant/result acknowledgement проходят существующий journal pipeline. Повтор command_id возвращает committed, stale CAS — 409, чужой ход не разрешается membership. Чужой actor не читает journal status своей стороны по чужому command_id. Raw battle POST без journal endpoint — 405.
- Истинный informational wait token проверяется; stale token отвергнут. Устаревшие **тестовые часы** информационного ожидания вызывают существующий automatic ACK через authorized battle_sync. Outsider sync до этого не пишет. Аналогично проверен expired turn clock и AutomaticTurnEnd: actor меняется, модель таймера и счётчик раунда не переписаны.
- Grid и Solomon: реальный HTTP start, по одному допустимому ручному решению двух акторов, POST sync; исчерпание **тестовых** elapsed budgets проходит прежний auto-draft до view, затем view/turn/side/deal/place/battle и resign. Для Sealed пройден реальный pool/view и существующие sideboard действия для лишних копий, затем battle. Победа через уничтожение последнего существа проверена с отдельной **fixture HP=1/closed target**, а не объявлена полноценной сыгранной турнирной партией.
- Chat sender=account, сообщения доступны двум участникам и недоступны outsider; chat не вызывает игровой progress. Prepare metadata sync read-only.
- Recorder/history allowlist включались **только в lab** до place. Обе journal стороны доступны своим accounts, client side override отвергнут. Active replay denied, completed replay разрешён участнику, outsider/configured numeric admin не обходит membership. Повреждённая SHA и отсутствующий initial capture — 409. Experimental partial completeness metadata сохраняется.

**7/7 Browser E2E assertions PASS**, [browser-results.json](evidence/stage5c/browser-results.json). Два независимых headless Edge storage context: login → таблица/создание/кандидат/accept → автоматический переход обоих в тот же index → существующая подготовка → обе страницы battle → reload → реальный UI resign → terminal на обеих сторонах. Mode, recruit/place и resign использовали UI; часть подтверждений/выбора колоды browser runner отправляет через fetch **в контексте того же аккаунта на тот же production endpoint**, с настоящими CSRF/version. Это не in-memory и не новый игровой API; не называем тест исчерпывающим click-only UI тестом каждой карты. JS page errors отсутствуют. Скриншот [battle-two-accounts.png](evidence/stage5c/battle-two-accounts.png) просмотрен; artwork синтетических карт не поставляется.

**48/48 Auth security regression PASS**, [auth-security-results.json](evidence/stage5c/auth-security-results.json). Повторены registration/unique race/hash/bounds/login/logout/CSRF/fixation/rotation/rate limiting между сессиями и конкурентными workers/TLS cookie headers/missing schema. Последний старый assertion о replay-cookie адаптирован к 5C: account cookie теперь законно читается общим AuthContext, но legacy replay без ownership остаётся 403/404 (при OFF flag — 404), а не старый 401. Это намеренное изменение политики, не ослабление membership.

**11/11 existing regression suites PASS**, [regressions.json](evidence/stage5c/regressions.json), полные outputs рядом:

1. test_repository_optimistic_locking.php — реальная MariaDB, create/load/CAS/stale/sequential saves.
2. test_command_journal_repository.php — реальная MariaDB, register/idempotency/conflict/commit scope.
3. test_turn_timer.php — in-memory timer rules.
4. test_pending_wait_timer.php — in-memory pending ownership, все 57 handlers.
5. test_informational_wait.php — in-memory ACK policy/ownership.
6. test_turn_timeout.php — in-memory expiry eligibility/guards.
7. test_match_pause.php — in-memory pause/resign/timer semantics.
8. test_settings_flow.php — model checks и доступная DB draft initialization; **не skipped**.
9. test_sealed_flow.php — in-memory rules, отдельно от настоящего HTTP Sealed выше.
10. test_solomon_draft.php — in-memory adapter/rules, отдельно от HTTP Solomon выше.
11. test_live_battle_journal.php — Recorder/projection/event contracts in-memory, отдельно от реальных HTTP/history checks выше.

Suites выполнялись в disposable source-only copy без .git, с нужными docs/cards fixtures и явной lab DB configuration. Hardcoded старые destructive history laboratory runners не запускались на чужом datadir; вместо них history/Recorder прошли настоящее HTTP/DB recording/replay и выбранную существующую regression suite. Полные тесты всех production карт, настоящие TLS/FPM/reverse proxy и весь возможный случайный каталог не проверялись.

PHP lint **9/9 PASS**, JS syntax **6/6 PASS**, Python AST и diff/whitespace PASS. Результаты: [lint.json](evidence/stage5c/lint.json), [syntax-checks.txt](evidence/stage5c/syntax-checks.txt).

## Промежуточные наблюдения и пределы evidence

Промежуточные failures не скрываются за финальным PASS. Исправлены ошибки тестовой scaffolding: неверный `id` вместо wait_id, rarity `ultra_rare` вместо существующего `ultrarare`, отсутствие fixture поля power и docs/cards, выбор видимости script вместо state=attached, navigation race теста. Sealed excess copies убираются **штатными** sideboard командами, не исправлением Engine. Для automatic turn-end проверяется active/period, а не обязательное увеличение номера раунда на каждом переходе.

Один промежуточный randomized Grid timeout прогон не достиг view после искусственного исчерпания обоих total budgets; итоговый assertion был FAIL. Его сохранённая persisted fixture повторно выполнена **read-only** через тот же неизменённый PrepareProcessor: success=true, after_status=view, 23 events ([draft-timeout-diagnostic.json](evidence/stage5c/draft-timeout-diagnostic.json)). Синтетические GameState и каталог этого наблюдения сохранены без accounts/sessions/secrets в [grid-timeout-fixture.json](evidence/stage5c/grid-timeout-fixture.json); их можно загрузить в GameState::fromArray и повторить resolveDraftTimeouts на отдельной read-only fixture DB, без save/Engine changes. Первопричина расхождения **не установлена**; это не объявляется исправленным дефектом. Финальный полный прогон этого сценария PASS, но перед широкой активацией Lead должен учитывать возможную нестабильность randomized draft/timeout на конкретном каталоге. Для исправления правил/процессора потребуется отдельный review и разрешение; в 5C их не меняли. Это ограничение воспроизводимости, не доказательство универсальной стабильности всех random pools.

SQL time-travel/HP/hash/metadata fault injection принадлежит только lab setup, явно отделён от пользовательского lifecycle. Он проверяет transport authorization и existing server continuation, но не выдаётся за естественное ожидание 180 секунд или полноценный долгий бой без fixture intervention.

## Diff review, файлы, deployment

Полный список — [file-list.txt](evidence/stage5c/file-list.txt). Reviewed все изменения: общая session/membership bootstrap, ранний chat, методы/CSRF, scope status, обход journal raw POST, HTML/fragment decoration, propagation токена, draft sync, metadata polling, journal/replay/invitation boundaries и read-only schema preconditions. GameState и формат событий не изменены; нового engine/command API нет.

Новые runtime adapters: AuthContext, GameAccess, GameTransport, game-transport.js. Изменены index, journal/replay/access entrypoints; battle-sync/chat/draft/lobby JS; у chat template удалён unused prototype data-role. Repositories менялись только ради запрета runtime DDL. Добавлены audit/API/docs, ручная existing journal schema/read-only preflight, два воспроизводимых integration runners и безопасное evidence. В evidence нет session IDs, CSRF/binding tokens, private configs, БД, TLS keys или cookies. Temp datadirs и private configs удалены после проверок абсолютных путей.

Deployment checklist и безопасный rollback — [stage5c-deployment-checklist.md](stage5c-deployment-checklist.md). Нельзя откатить публичный index к prototype first/second, открыв account-owned games; сначала maintenance/закрытие endpoints, сохранение guard/ownership и данных. Production SQL выполняет только Lead; flags/allowlists/приватная конфигурация проверяются оператором.

Дефект отсутствующих событий журнала боя №493 остаётся отдельным backlog; авторизованный доступ и успешные synthetic Recorder tests не означают восстановления его production событий. Работа после отчёта остановлена для Project Lead. Merge/push/deploy/production SQL, изменение игровых механик и дополнительных таблиц не выполнялись.

Очистка: автоматический approval review отклонил рекурсивное удаление storage/games из-за риска потери важных игровых данных. Каталог сохранён, обход запрета не выполнялся; он не включён в review manifest. Удаляются только явно созданные временные лаборатории tmp.
