# Stage 5C — единый principal и игровой транспорт

## Идентичность и membership

AuthContext использует **существующий AuthSession Stage 5A**: cookie BERSERKAUTH5A, strict mode, TLS, HttpOnly/SameSite, единственный integer berserk_authenticated_user_id. Пароль/rotation/logout/CSRF принадлежат Stage 5A; новая параллельная авторизация не добавлена. PHPSESSID не импортируется и не может установить игровой principal. AuthStore повторно подтверждает существование аккаунта.

GameAccess::resolve получает только server principal и integer game_id. Одним SELECT он связывает lobby_rooms.state=started с games и проверяет:

1. Principal совпадает с creator_account_id или accepted_account_id, это два разных положительных аккаунта.
2. SQL host_id/player_id соответствуют creator/accepted.
3. JSON game_id/host_id/player_id и players.host.user_id/players.player.user_id соответствуют той же связи.

Только затем actor становится host/player. Номер аккаунта не является PlayerState.side; выбор 1/2 и firstPlayer остался в Engine. GET/POST first/second, cached role, user_id, client actor не выбирают сторону. Prototype flags и public debug/dice overrides отвергаются. Legacy игры без started room запрещены, даже при совпадении account IDs 1/2.

Guard исполняется перед ранним chat exit, любым repository constructor, Engine/Dice/progress, командами/command-status и snapshot. При guest — 401, чужом матче/несовпадении состава — 403; отсутствующей конфигурации/некорректном JSON — общий отказ, без игрового действия. Новые игровые запросы не создают games; GET без game у вошедшего пользователя ведёт в lobby.

## URL и методы

- Игра: `index.php?game=N`. Строка лобби выполняет существующий POST open; started room направляет обоих участников на этот URL. Refresh/relogin загружает persisted GameState, не вызывает create и не сбрасывает матч.
- Подготовка: POST на тот же index?game, cmd + csrf + prepare_version (games CAS version). Сохраняются существующие Engine/PrepareProcessor и PRG. Неактуальная версия даёт 409. Для Solomon expected_version остаётся **логической** GameState.version, decision_id — существующим ID решения; их не заменяет prepare_version.
- Draft sync: POST cmd=draft_sync + csrf, тот же resolveDraftTimeouts/CAS. Страница Draft использует прежний draft_timer.js с безопасным методом. Обычный GET страницы больше не исполняет явную draft timeout команду.
- Остальная подготовка: GET ajax=prepare_sync возвращает только SQL status/version **до** repository/Engine/progress. Один metadata poll обновляет ожидающие mode/deck/view/turn/side/deal экраны при смене стадии/версии. Это уведомление, не альтернативный игровой API. Никаких карт/колод в ответе.
- Бой: существующий POST ajax=battle_command с csrf, command_id, expected_version; GET ajax=battle_sync и battle_command_status остаются существующими путями. Raw POST cmd в боевой HTML endpoint запрещён 405, иначе возник бы обход journal через подготовку. GET cmd всегда запрещён. Actor и scope command status определяются сервером, а не payload.
- Chat: прежние GET messages и POST send/read, общий membership guard, server account sender, общий CSRF. До завершения своей early branch чат не создаёт Engine, не меняет games/таймер/pending. GameChat storage/rate/idempotency/visibility сохранены.
- Playmat: прежний POST ajax=playmat, membership и общий CSRF до existing cosmetic CAS.

Все мутации проверяют POST/CSRF до progression. Служебные csrf/prepare_version исключены из hash gameplay payload. Refresh и повтор команд используют существующие PRG, journal command_id и CAS. Неизвестный AJAX endpoint отвергается. Battle sync/status способны исполнять **только прежний серверный due progress**, после проверки доступа: нельзя выбрать automatic actor или действие клиентским параметром.

GameTransport — декоратор существующего HTML на HTTP boundary: убирает role flags/hidden fields, переводит подготовительные формы на POST, добавляет CSRF/CAS, нормализует fragments. Capture listener отправляет command links подготовки POST раньше старого pregame-place GET listener. Боевые формы оставлены совместимыми с battle-sync, который сам добавляет CSRF в **прежний** journal endpoint. Layout, карты, field и процессоры правил не переписаны. Старый prepare_token удалён из command URL, чтобы CSRF не попадал в query/referrer.

## Таймеры и доверенное серверное исполнение

TurnTimer, pending, InformationalAutoAck, AutomaticTurnEnd, HttpBattleProgress и Engine не получили AuthSession dependency. Внешний HTTP сначала проверяет account, затем вызывает прежний service. Внутренний automatic actor определяется по сохранённому wait/clock, а не выдуманному account или GET side. CLI/server orchestration по-прежнему может вызывать services с доверенным GameState/host-player контекстом; новый scheduler не добавлялся.

Сохранены порядок pause → informational ACK → timeout, journal/CAS, reserved command prefixes и uncertain outcome handling. Metadata preparation polling, chat и history reads не запускают эту цепочку. Изменённые GET команды подготовки не выполняются; due battle progression на authorized sync сохраняет прежнюю серверную семантику.

## Журнал, replay и legacy invitations

Account-cookie journal: AuthContext + GameAccess до LiveJournal. Клиент не задаёт side; service выбирает прежнюю persisted историческую проекцию по trusted principal. Feature flags и allowlists остаются server-side. Recorder, schema событий, историческая redaction не менялись.

Replay: только подтверждённый участник account-owned игры, затем прежние terminal/feature/allowlist/initial-final/sequence/SHA checks. Configured numeric admin IDs не обходят новый outer membership guard. Новым accounts не выдаётся legacy replay. **Experimental partial preview продолжает поддерживать completeness=incomplete с меткой и gaps** — Stage 5C не превращает его в гарантию полной истории и не исправляет дефект №493.

Legacy invitation cookie остаётся отдельным PHPSESSID. journal-access consume разрешён только если у game нет lobby_rooms ownership; после consume переход только на battle-journal.php. Legacy journal branch принимает исключительно binding и отбрасывает любой trusted/user_id из этой session. Даже корректный binding на account-owned game запрещён. Публичный prototype gameplay через first/second больше не поддерживается; старые данные не удалены.

## Схема

Новых таблиц для 5C нет. GameRepository проверяет наличие games.version чтением; CommandJournalRepository валидирует existing journal и индексы чтением. Прежние constructor ALTER/CREATE удалены. При отсутствии схемы — отказ, runtime ничего не устанавливает.

`tools/migrations/20261021_transport_preflight.sql` — read-only проверка. `20261021_command_journal.sql` — точная существующая схема прежнего constructor, вынесенная в ручной файл **только для установки отсутствующей таблицы**; existing production table не пересоздавать и не накладывать поверх. Production SQL выполняет Lead. Никаких новых FK/событий/второго journal или формата GameState.
