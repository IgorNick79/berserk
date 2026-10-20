# Stage 4D.1 — ограниченная ручная выкладка

Выкладка не выполнялась. Основа сервера должна соответствовать Stage 4C (139979d); ниже перечислен полный runtime delta Stage 4D + 4D.1. На другом baseline сначала сверить зависимости. Никакая загрузка файлов не включает Recorder автоматически.

## Файлы

- `config/battle_history.example.php`
- `src/Core/ActionResolver.php`
- `src/Core/BattleHistory/ExperimentalHistory.php`
- `src/Core/BattleHistory/HistoryRepository.php`
- `src/Core/BattleHistory/HistoryRuntime.php`
- `src/Core/BattleHistory/JournalInvitations.php`
- `src/Core/BattleHistory/JournalText.php`
- `src/Core/BattleHistory/LiveJournal.php`
- `src/Core/BattleHistory/Recorder.php`
- `src/Core/BattleHistory/ReplayStateBuilder.php`
- `src/Core/DamageResolver.php`
- `src/Core/Engine.php`
- `src/Core/GameRepository.php`
- `src/Core/InstantProcessor.php`
- `src/Core/TurnProcessor.php`
- `src/Core/ValhallaProcessor.php`
- `src/View/Screen/Battle/InfoPanel.php`
- `www/assets/css/battle-journal.css`
- `www/assets/js/battle-journal.js`
- `www/assets/js/battle-sync.js`
- `www/battle-journal.php`
- `www/index.php`
- `www/journal-access.php`

Дополнительно загрузить CLI `tools/journal_access.php` вне document root. Тесты, docs/evidence, лабораторные скрипты, конфиги БД, sessions, storage и приглашения не загружать. `config/battle_history.example.php` — образец, не активный конфиг.

## Предварительные ручные проверки

1. Сохранить резервную копию текущих исходников и local config. Document root — только `www/`. Проверить, что config, src, tools, приватные каталоги и резервные копии недоступны через любой vhost/alias.
2. PHP 8.1+ (проверено 8.1.9), mysqli, mbstring, JSON, random_bytes, рабочие PHP sessions. Выполнить PHP lint загружаемых PHP. Локальный дисковый filesystem с корректными flock и atomic rename; NFS и несколько независимых узлов без общей блокировки не поддерживаются.
3. Оператор самостоятельно запускает **только read-only** `tools/migrations/20261020_battle_history_postchecks.sql` в нужной БД. Сверить SHOW CREATE/индексы/constraints с `20261020_battle_history.sql`, не исполняя CREATE. Проверить пять существующих history tables, games и battle_command_journal, InnoDB, JSON columns, game/version и journal references, уникальные sequence/batch ключи. При несовпадении остановить включение. DDL/DML/триггеры этого этапа не требуются. Проверено на MariaDB 10.8.4; совместимость конкретного production не подтверждена удалённым запуском.
4. TLS обязателен. PHP должен непосредственно видеть HTTPS=on; за доверенным reverse proxy это устанавливает конфигурация сервера. Приложение намеренно не доверяет X-Forwarded-Proto. Отключить запись тел запросов/APM capture и POST-параметров для journal-access.php; обычный access log содержит только маршрут, никогда приглашение. Не использовать query string для секрета.
5. Создать приватный каталог **вне всего checkout и всех web roots**. Linux: владелец — PHP/оператор, каталог 0700, файлы 0600; Windows: эквивалентные ACL только этим учётным записям. PHP должен уметь читать/писать/переименовывать файлы. Session storage тоже приватный. Не использовать публичный storage приложения, backup sync, общий temp или Git. Проверить HTTP-недоступность каталога. Не делать доступным файл выдачи другому участнику.

## Один тестовый матч

1. Оставить все switches OFF. Создать новый тестовый матч стандартным прототипом; получить его ID из адреса. Не делать ни расстановку, ни confirm_place. Участники прототипа фиксированы: host user 1, player user 2. У сервера нет доказанной внешней авторизации; first/second — выбор роли.
2. Подготовить приватный `config/battle_history.local.php` по образцу. Вписать только integer ID этого матча в record_game_ids и journal_game_ids. Указать проверенный frozen catalog по ukid с revision (см. Stage 4D API/матрицу); неизвестные определения дадут gaps/скрытие, вымышленных записей не будет. preview_enabled=false, preview_game_ids=[], admin_user_ids=[], journal_test_bindings=[].
3. Вписать абсолютный journal_access_directory, journal_access_local_http=false. После ручной проверки атомарно включить recording_enabled=true, journal_enabled=true, journal_access_enabled=true. Проверьте OPcache invalidation local config по процедуре хостинга. Не включать посреди боя: engine guard отклоняет инициализацию вне place.
4. Через доверенный shell выполнить (секрета в аргументах нет):

```sh
php tools/journal_access.php issue GAME_ID host 7200 /private/berserk-journal/host-invitation.json
php tools/journal_access.php issue GAME_ID player 7200 /private/berserk-journal/player-invitation.json
```

Файлы должны быть новыми и находиться непосредственно в journal_access_directory. TTL от 1 до 86400 секунд относится и к приглашению, и к последующему доступу. CLI сохраняет `{id,secret}` приватно и не печатает секрет. Оператор читает каждый файл вне записываемого терминала/CI и передаёт secret соответствующему игроку по отдельному доверенному каналу. Не отправлять через URL, Git, журнал чата боя или публичную страницу. Сохранить несекретный grant id для отзыва, удалить файл с secret после передачи.
5. В двух отдельных браузерных профилях/контекстах открыть **https://HOST/journal-access.php**. Каждый вводит своё приглашение в password поле. Приложение проверяет CSRF, меняет PHP session ID, расходует приглашение под файловой блокировкой и перенаправляет на фиксированную сторону/матч. Повторное использование запрещено. Сторона и game из формы не принимаются. На странице секрет не печатается и не встраивается в исходный HTML.
6. Сыграть расстановку и бой, открыть «Логи», проверить обновление, скрытую карту/приватную способность с двух сторон, gap, прокрутку и reconnect. После game_over тот же URL матча и journal API доступны до expiry/revoke. Полный replay остаётся закрытым (401 без отдельного trusted principal).
7. Обычный матч вне allowlists не получает Recorder или журнал. Его существующая прототипная авторизация не изменена; приглашение не создаёт общую пользовательскую идентичность. Выбранные IDs сохраняют защиту игрового route даже при OFF switches, пока находятся в списках. Удаление ID из всех списков возвращает исходный прототипный режим — не использовать это как отзыв доступа.

## Отключение и ошибки

- Отозвать каждое приглашение/доступ: `php tools/journal_access.php revoke GRANT_ID`. Работает до и после погашения; следующий запрос журнала/защищённого игрового route получает отказ. Удалить остаточные файлы выдачи. Не удалять ledger выборочно вручную при активных запросах.
- Отключить journal_access_enabled и journal_enabled атомарной заменой local config; очистить journal_test_bindings. Остановить эксперимент/отозвать участников перед recording_enabled=false. Оставить IDs в allowlists, чтобы не открыть прототипный игровой route.
- При остановке записи не продолжать Recorder того же матча как полную историю. Для нового эксперимента создать новый матч. Существующие gaps/incomplete не исправлять SQL и не пересчитывать; окончание боя не делает частичную историю полной.
- Session cookie непостоянный: после закрытия браузера обычно понадобится **новое** приглашение, старое не оживает. Некоторые браузеры восстанавливают session cookies: это тот же доступ до expiry/revoke. Потеря сессии/ошибка её сохранения после погашения требует отзыва grant и выдачи нового. Приглашение не возвращается в оборот.
- 403: проверить grant expiry/revoke, выбранную сторону, cookie, TLS и разрешённый game; 404: выключенный режим/allowlist; 503: права приватного каталога, session storage, блокировки, JSON ledger или БД. Не включать debug output и не публиковать секреты. При повреждении ledger доступ закрыт; восстановление только оператором из защищённой копии либо отзыв и новая выдача. Удаление ledger отзывает все текущие capabilities.
- Уже загруженный в браузер журнал нельзя отозвать из памяти пользователя. Новые запросы проверяют отзыв. Работающая страница не получает новые записи после отключения. Долговременная история остаётся в БД.

Ограничения: это не регистрация и не общий security audit прототипа; chat/прочие обычные маршруты сохраняют существующую политику. Не выдавать этот режим для настоящих массовых матчей. Проверка реальной production TLS/session/ACL/SQL схемы выполняется оператором перед включением; лабораторные пароли, lab-dice.php и HTTP exception не переносить.
