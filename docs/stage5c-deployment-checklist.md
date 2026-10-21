# Stage 5C — deployment checklist и rollback

Этот документ — инструкция для Project Lead, не выполненные production действия.

## Перед разрешением выкладки

1. Проверить реальные SHA Stage 5A 822dacc89a32b7121fef4541f959f3d91ac2ee3f и Stage 5B 46e9380, финальный diff/тесты 5C; сохранить backup кода и БД. Никаких merge/push/deploy агент не выполнял.
2. Перевести публичную игру в controlled maintenance перед заменой transport entrypoints. Не выкладывать guard, JS, auth/lobby, journal/replay по частям: старый index или chat может обходить новые права.
3. Проверить PHP >=8.1 mysqli/mysqlnd, HTTPS server variable на доверенном proxy/FPM, display_errors=Off, docroot=www, закрытые config/src/tools/storage, private session/ledger ACL. allow_loopback_http=false в production. PHPSESSID и BERSERKAUTH5A не объединять.
4. Отдельными read-only credentials выполнить `20261021_transport_preflight.sql` и postchecks Stage 5A/5B/history. Проверить InnoDB, games.version, уникальность journal game/player_key/command_id, signed INT IDs, rooms FK и согласованность SQL/JSON участников. Auth и game configs должны смотреть на утверждённую общую account/game schema, а не разные базы с совпадающими номерами.
5. Если existing battle_command_journal отсутствует — остановить включение и согласовать ручное additive применение `20261021_command_journal.sql`. Если существует — только сверить структуру, не запускать CREATE повторно. Если games.version отсутствует или schema несовместима — отдельный review Lead, без runtime ALTER и без догадок о типах. Runtime users не имеют CREATE/ALTER/DROP/TRIGGER.
6. Сверить least privileges: auth account lookup/limiter, rooms transaction, games/journal/chat DML; history DML только при записи. Не выдавать сверх того schema privileges. Секреты/local configs не переносить из lab; сохранить реальные частные конфиги отдельно.
7. Все old HTML/JS caches инвалидировать совместно: изменены battle-sync/chat/draft/lobby и добавлен game-transport.js. Не оставлять страницу старого клиента, пытающуюся отправлять GET/без CSRF. Проверить OPcache и фактический набор файлов по manifest. В private configs сохранить прежние history feature flags/allowlists; login сам по себе не включает Recorder всем играм.

## Ограниченный smoke до снятия maintenance

- Два новых аккаунта: login → lobby → create → candidate → accept → один game_id → mode на обеих страницах. Без first/second; outsider/guest/legacy PHPSESSID denied.
- Проверить выбранные подготовительные сценарии, POST CSRF/CAS, reload/relogin и открытие активной игры из таблицы. Завершённая игра исключается из активного списка.
- Проверить journalled move/strike/ACK/resign, retry/status, chat sender/visibility. Unauthorized sync не должен запускать due commands. Не изменять игровой порядок или таймер для smoke на production.
- На отдельном allowlisted тестовом матче проверить обе исторические проекции и terminal-only experimental replay с существующими completeness/SHA условиями. Отсутствие событий №493 не считать исправленным.
- Никаких реальных account IDs не назначать старым games 1/2 автоматически. Legacy invitations — только journal test scope без room ownership.

Снять maintenance только после отдельного решения Lead. Поведение production TLS, FPM, session backend, grants и реальных каталогов не доказано локальной лабораторией.

## Rollback

**Нельзя просто вернуть старый public index.php с first/second**: уже созданные account-owned games тогда станут доступны через prototype bypass.

При проблеме сначала закрыть публичные game/chat/history/replay mutation/read routes на webserver maintenance и остановить клиентский polling. Сохранить БД, rooms ownership, games JSON, journal/history и evidence, не выполнять DROP/DELETE/переназначение IDs.

Допустимо вернуть auth/lobby в безопасный placeholder режим Stage 5B, сохранив guard либо блокировку игровых endpoints. Legacy runtime восстанавливать только за закрытым тестовым периметром, не на публичном пути к новым матчам. Исследовать uncertain commands по persisted journal/status, не повторять слепо и не чинить JSON ручным production SQL. После исправления — повторный isolated regression и новый review Lead.

Выкладка/rollback не требуют изменения Engine или формата событий. Schema additive сохраняется при откате, sessions/feature flags проверяются оператором отдельно.
