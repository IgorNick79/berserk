# Stage 4D.2 — Release Review

Stage 4D.1 зафиксирован отдельным коммитом **`b266c3e14d4fb5cc737153a42f93ed02153554bc`** в `codex/battle-replay-stage4d1`. Stage4D: `f05163e93d346ab114474cc3ccc6b4e23c664ff7`. Release подготовлен для ручной проверки/ограниченной установки; production readiness не утверждается без preflight оператора. Merge/push/deploy/production SQL не выполнялись, production приглашения не создавались.

## Review и границы

Просмотрены полный tracked diff и все untracked source files Stage4D.1: HistoryRuntime, JournalInvitations, index, journal-access, CLI, тест, config sample, отчёты и evidence. Runtime изменения Stage4D.1 ограничены доступом: новые механики/Recorder/таблицы/миграции/диагностическая инструментализация не добавлялись. К коммиту добавлены ровно12 согласованных файлов; db.php, активных local config, ledger, invitations, sessions, временных файлов нет. Архив evidence проверен по именам и на известные plaintext test binding tokens из лабораторных сессий/identities; совпадений нет. Это целевая проверка известных секретов, не заявление о полном универсальном secret audit бинарных файлов.

Приглашения не задают trusted principal и не открывают replay; exact game/side/expiry/revoke проверяются сервером. Проверены CSRF, rotation, session-only cookie, 256bit entropy, hash-only ledger, flock, atomic replacement, fail-closed corrupt storage и CLI-only выдача. Приватность filesystem/TLS/session backend остаётся обязательным условием оператора, а не автоматически доказанной настройкой сервера.

## SQL mismatch

Исходная первая пара before/after не сохранена: **первопричина остаётся неизвестной**. Сохранились пять повторных пар (journal/hidden/instant/status/vampire), они повторно сравнены в Stage4D.2 — равны. Исходный inspect делает несколько SELECT без общего consistent snapshot и без ORDER BY для большинства таблиц; поэтому сравнение может быть чувствительно к конкурентным игровым/automatic запросам и порядку строк. Это возможные объяснения, не установленная причина. Не переименовываем наблюдение в PASS и не объявляем его исправленным дефектом.

Отдельное подтверждение Stage4D.1: GET чтение прошло с SELECT-only DB account и trap на загрузку gameplay; review LiveJournal/HistoryRepository показывает только SELECT, без Engine/automatic progress. Пропуск первоначальных снимков — ограничение evidence. Если mismatch повторится при приёмке: остановить игровой polling/команды, сохранить обе стороны сравнения по таблицам с устойчивым PK order и consistent snapshot, время и параллельные запросы, повторить с SELECT-only reader на изолированном стенде. Не включать SQL general logging с секретами и не чинить историю production SQL. До этого наблюдение допускает лишь ограниченную контролируемую проверку Lead, не массовую активацию.

## Повторные проверки Stage4D.2

- Git status/full diff review, git diff --check.
- SHA256 восьми проверенных PHP совпадают с Stage4D.1 evidence; PHP lint8 PASS, JS syntax2 PASS.
- Контракты приглашений в новой source-only копии без БД PASS: single-use, scope, revoke, disabled scope, hashes, OFF, no replay principal.
- Реальный HTTP OFF smoke без config/db.php/БД, loopback33502: journal-access404, battle-journal404, replay401. CLI при OFF: exit1, invitation file не создаётся. В первом smoke ожидание404 для replay уточнено на401 по реальному порядку auth checks; runtime не менялся.
- Archive integrity/отсутствие private file paths/известных plaintext bindings PASS; пять сохранённых SQL before/after пар равны.
- Новые DB/UI прогоны не требовались: runtime после полноценных Stage4D.1 HTTP/security/two-Edge/21 regression проверок не изменялся. Их исходные результаты и ограничения сохранены, не выдаются за повторный запуск Stage4D.2.

## Пакет

`docs/releases/battle-stage4d2-manual-package.zip`: 24 runtime/reference файла Stage4D+4D.1 относительно обязательного Stage4C baseline, OFF config example, checklist, read-only postchecks SQL, FILES.txt и SHA256SUMS. В архиве нет config/battle_history.local.php, config/db.php, тестовых endpoints/секретов/БД/сессий, автоматического installer и CREATE миграции. Проверка zip integrity и SHA передана вместе с manifest. Активную приватную конфигурацию оператор создаёт вручную по `docs/examples/battle_history.local.php.example`. Список загрузки: `docs/releases/battle-stage4d2-files.txt`.

Ключевое уточнение порядка: `issue` и consume требуют records AND journals. Поэтому выбранный allowlisted матч включается до выдачи приглашений, но до любых игровых действий/расстановки; этап «включить Recorder после подключения» без изменения кода невыполним. Проверка отключения: revoke требует invitation mode ещё ON; сначала остановить recording/journal, затем revoke, затем access OFF. Все параметры сверены с кодом, фиктивных TTL/game_over config keys нет.

После game_over чтение требует journal allowlist/включение и прежнюю сессию/неистёкший grant; recording может быть OFF. Полный preview остаётся OFF. Отключение не удаляет данные и не позволяет возобновлять запись после пропущенных команд. HTTPS=on, SSH/CLI, FPM ini/session paths, права и схема проверяются только Project Lead по checklist. На этом этапе агент к production не обращался.
