# Stage 5E — SQL deployment order

**Сейчас не выполнять. Production SQL — только Lead после отдельного согласования.** Общий release ZIP содержит только read-only SQL, не содержит CREATE installer. Все CREATE ниже — условные ссылки на reviewed Git sources d59fc669, отдельный набор выбирается после фактического inventory. Существующие games, journal, history, configs и данные не пересоздавать. Нет SQL в runtime, TRIGGER не требуется.

| Шаг | Действие Lead после inventory | Ожидание и STOP |
|---|---|---|
|0|После согласованного read-only доступа выполнить 20261021_release_preflight.sql; записать aggregate baseline по существующим games/journal/history, без JSON. |Точная схема/сервер; strict mode, InnoDB, enforced CHECK. Иная версия/неподдержанный metadata query — STOP и адаптация audit отдельно, не угаданный ALTER.|
|1|Сверить games.ind/host_id/player_id/version: signed INT NOT NULL, ind AUTO_INCREMENT PRIMARY; status/json/date/active по текущим GameRepository/MatchCreator. |games уже существует. Отсутствующий version/несовместимые типы — STOP, отдельная миграция для реальной схемы; ни CREATE games, ни runtime ALTER.|
|2|Если **обе** accounts/auth_login_attempts отсутствуют: после backup+maintenance вручную выбрать только 20261021_auth.sql; затем auth_postchecks.sql. Если обе существуют — только compare/postchecks. |accounts.id signed INT PK, login/login_key/password_hash ascii_bin, UNIQUE login_key, CHECK; limiter bucket char64 PK, attempts unsigned, datetime6. invalid_accounts=0. Частичная/несовместимая установка STOP; не rerun CREATE.|
|3|lobby_preflight.sql. Если lobby_rooms отсутствует: выбрать только 20261021_lobby.sql, затем lobby_postchecks.sql. Иначе только compare/postchecks. |4FK на signed accounts.id/games.ind, UNIQUE nullable game_id, PK/indexes, unsigned version>0, точный state CHECK, InnoDB; invalid_rooms=0. Чужие types/данные — STOP.|
|4|battle_command_journal уже может существовать: сверить DDL/indexes с 20261021_command_journal.sql и repository validator. Только при доказанном отсутствии выбрать этот CREATE отдельно. |UNIQUE(game_id,player_key,command_id), status index, signed INT game_id/versions, bigint PK. Никакого повторного CREATE/очистки журнала.|
|5|Проверить существующие game_chat_messages/game_chat_reads по 20261019_game_chat.sql. Только если обе отсутствуют на новой установке — согласовать этот CREATE отдельно. |PK/UNIQUE request index и game/sender/time indexes, InnoDB. Частичное состояние STOP. Existing chats сохраняются.|
|6|Пять battle_history_*: compare с 20261020_battle_history.sql и read-only postchecks. Если уже есть — **не исполнять CREATE**. Если все отсутствуют и история нужна утверждённым flags — отдельное согласование missing-only SQL. |Partial history или несовместимость STOP. При disabled flags не включать Recorder ради миграции. Старый журнал493 не менять.|
|7|Повторить release/transport preflight и auth/lobby/history postchecks применительно к существующим таблицам. |Ноль нарушений state/ownership; original game/journal/history aggregates объяснимо сохранены. Нет ожидаемых TRIGGER/DDL runtime grants.|

DDL MySQL/MariaDB не атомарен. Ошибка любого CREATE — не повод запускать весь файл повторно или удалять созданное. Maintenance оставить закрытым, записать частичное состояние, отдельное решение Lead. Никаких DROP/TRUNCATE/DELETE/ID reassignment/backfill legacy ownership. В repo нет согласованной миграции, меняющей games.version на production: при её необходимости STOP.

Runtime privileges (сверить каждый соединённый user):

- Auth: SELECT,INSERT accounts; SELECT,INSERT,UPDATE auth_login_attempts. Никаких games/TRIGGER/DDL.
- Lobby: SELECT accounts; SELECT,INSERT,UPDATE lobby_rooms/games в той же application schema, InnoDB transaction для room+GameState.
- Game: SELECT необходимых cards/decks/elements/catalog sources (card_synergy при наличии); SELECT,INSERT,UPDATE games, battle_command_journal, game_chat_messages/game_chat_reads и history таблиц при согласованных flags. Сверить существующие repository операции. Нет CREATE/ALTER/DROP/TRIGGER/GRANT/FILE; не выдавать ALL из-за503.

Существующие prototype games с host/player1/2 не привязывать к accounts1/2. GameAccess требует started lobby_rooms mapping + совпадение SQL/JSON IDs. Без mapping новые аккаунты не могут открыть старые игры даже при числовом совпадении. Legacy данные сохраняются; отдельного archive UI этот релиз не добавляет. Legacy journal binding допускается только для не-account-owned игры, не даёт gameplay/replay principal. Не использовать account admin IDs для обхода membership.

Фактический production inventory пока не получен: нельзя заранее утверждать, что accounts отсутствуют, journal уже совместим или выбрать единый исполняемый SQL-файл. Условный порядок согласован с reviewed schema, но окончательный missing-only набор требует результатов Lead.
