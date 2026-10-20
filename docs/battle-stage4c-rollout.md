# Ограниченная выкладка Stage 4C — только по решению Project Lead

Codex выкладку/production SQL не выполнял. Наличие пяти таблиц не включает запись. Не запускать CREATE/migration повторно и не добавлять SQL-триггеры.

## Файлы

Сначала должны быть доставлены runtime зависимости коммита Stage 4B `870d3cc38e791ceec29b7eb4db7c1be25ab5fe0c`: Core/ActionResolver.php, DamageResolver.php, Engine.php, GameRepository.php, GameState.php, Movement/MovementResolver.php, StrikeResolver.php, TurnProcessor.php, BattleHistory/HistoryBatch.php, HistoryRepository.php, ExperimentalHistory.php, Recorder.php, ReplayStateBuilder.php (все под src/).

Stage 4C обновляет src/Core/BattleHistory/HistoryRepository.php, src/View/Screen/BattleScreen.php, src/View/Screen/PlaceScreen.php, www/index.php; добавляет src/Core/BattleHistory/HistoryRuntime.php, ReplayPreview.php, src/View/Screen/ReplayScreen.php, src/View/Ui/BattleCardMarkup.php, www/replay.php и www/assets/css/replay.css. config/battle_history.example.php — выключенный пример для оператора. Используются уже существующие templates/includes/battle_cell.tpl, global.css, Coords и инфраструктура Stage 4A/4A.1. Источники обеих стадий должны быть согласованной версией.

Не выкладывать evidence/harness, lab-login.php, tools тестов, локальные конфиги стенда, БД, sessions, storage или диагностические traps. Не перезаписывать production config/db.php, storage и пользовательские файлы.

## До разрешения включения

1. Получить отдельное разрешение Lead на ограниченную выкладку. Загрузить согласованные файлы с отсутствующим local config или обоими enabled=false. Это ничего не активирует.
2. Оператор сверяет существующую схему read-only postchecks `tools/migrations/20261020_battle_history_postchecks.sql` с Stage 4A.1 SQL: пять battle_history_* таблиц, InnoDB/utf8mb4, нужные columns/indexes/FK, games.version и journal. При несовпадении остановиться; не выполнять runtime DDL или пересоздание таблиц. Recorder использует существующую journal/CAS транзакцию на одном Db connection.
3. Подключить реальную аутентификацию, устанавливающую integer session key `berserk_authenticated_user_id` только после проверки пользователя. Прототип role route для этого непригоден. Без bridge preview намеренно не открывается. Сохранить безопасные session cookie настройки HTTPS, HttpOnly и SameSite. Не доверять HTTP user ID и не выкладывать test login fixture.
4. Зафиксировать каталог только выбранных тестовых definitions: ukid => revision/name/image/description/type/element/classes/price согласно ReplayState v1. Не менять revision/definition во время тестового матча; отсутствие или неподдержанные механики явно дают incomplete/gap. Автоматический production catalog loader не входит в этап.
5. Создать новые тестовые матчи обычным игровым путём с записью OFF. Выбрать конкретные ID до стадии place, привязать реальных участников. Не включать на текущем battle/game_over без его записанного initial. Не использовать wildcard, все матчи, диапазон или HTTP параметр.
6. Скопировать пример в игнорируемый config/battle_history.local.php; проверить PHP lint. Указать явные integer record_game_ids, preview_game_ids и при необходимости admin_user_ids. Поставить recording_enabled=true только для выбранных ID. Preview можно включить независимо; завершение всё равно проверяется сервером.
7. Тестовые участники завершают расстановку через сгенерированные ссылки с CSRF/version; final confirm_place пишет initial/battle_ready атомарно. Повтор/stale request не создаёт новый кадр. Более ранние стадии подготовки остаются без snapshots. При processing/unknown исходе — сверить журнал; не выполнять Engine вручную и не удалять journal rows.
8. Проверить выбранный завершённый матч: metadata incomplete, has_final, checksum, UI gaps, первый/следующий/последний кадры; active/outsider/legacy principal должны быть отвергнуты. Preview всегда раскрывает полный сохранённый server state лишь разрешённым пользователям завершённого матча. Не делать публичных ссылок.

## Отключение

Поставить recording_enabled=false либо убрать конкретный ID из record_game_ids: следующие игровые запросы используют обычный GameRepository, игра продолжается. Переключать конфигурацию между запросами атомарной заменой файла; запрос, уже начавший транзакцию с включённым gate, заканчивает её атомарно. Историю не удалять, completeness не менять. Если матч закончен при OFF, final snapshot не появится и preview останется закрытым.

Не включать обратно запись матча, в котором были игровые команды при OFF: этот этап не заполняет пропуск переключения конфигурации. Для нового эксперимента использовать новый ID до place. Для полного отключения просмотра поставить preview_enabled=false/удалить ID; можно отключить запись и продолжать preview уже корректно завершённых сохранённых тестов.

## Ограничения эксплуатации

Поддержаны initial/battle_ready/move/combat/series_end/turn_end/match_terminal. Сложные инстанты, coin, Degasca, Вальхалла, markers/modifiers не обещаются. Прямой battle save по-прежнему блокируется для whitelisted Recorder матча; смена playmat с новым ID в таком матче не подключена к журналу. При необходимости этого вспомогательного действия отключить запись и завершить матч обычным путём; не обходить guard.

Preview не пишет в SQL и не воздействует на pending/таймеры/игровые автоматические действия. Реальные HTTP проверки, SELECT-only user, сравнение persisted rows и throw-on-load traps подтверждены в evidence. Минимальный preview читает все кадры для проверки checksum; публичный rollout и большие матчи требуют отдельного этапа производительности и доступа.
