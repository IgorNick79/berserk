# Account-scoped Debug Access and LuckTest JSON Scenarios

Дата: 22 октября 2026. Изменения подготовлены в `codex/lucktest-stage3-review` относительно `50696da`. Следующие стадии не начинались.

## Обновление интерфейса и URL-бросков — 25 октября 2026

Этот раздел заменяет прежнее описание текстового поля бросков в Settings и запрета GET debug_roll ниже.

В Debug pre-game теперь только чекбокс «Разрешить debug_roll в URL», по умолчанию выключенный. Хост выбирает разрешение для этой партии; оно сохраняется в приватном GameState и проходит из Lobby при создании матча. Allowlist аккаунтов остаётся обязательным: чекбокс не выдаёт Debug обычным аккаунтам. Выключение до старта очищает старые очереди. После начала игры разрешение не меняется.

При включённом разрешении участник с Debug может открыть `index.php?game=123&debug_roll=6,1,4`, `debug_roll=6*` или `debug_roll=off`. Устанавливается только его очередь в этой игре, затем 303 переводит на чистый URL; обновление страницы не устанавливает её повторно. Отдельные кости одного боя по владельцу не разделяются: сохраняется прежний actor-based Dice. При выключенном разрешении/отзыве доступа/другом режиме/завершённой игре — 403 до автоматического продвижения. Неверный синтаксис — 400.

URL-настройка идёт через действующий command journal и locked GameRepository, повторно проверяет разрешение под блокировкой и не меняет карты, pending, фазу, активного игрока или таймер. Это техническая команда без событий публичной истории и без новых gaps. Ошибка записи откатывает курсор. В pre-game и меню удалено текстовое поле debug_roll; старый HTTP POST-ввод бросков через index отклоняется. Внутренние CLI-фикстуры могут по-прежнему подавать raw очередь напрямую для регрессий.

Справка сценария содержит описание cards/owner/ukid/row/col и полный валидный пример. Одна SVG-кнопка копирует весь JSON; не отправляет форму и не заменяет редактируемое поле. При выключении Quick Game поле сценария скрывается/не отправляется, введённый текст сохраняется в DOM.

Проверки: test_lucktest_url_roll.php, test_lucktest_debug_ui.cjs на disposable копии; обновлённый test_lucktest_debug_http.py — 60 реальных HTTP/Auth/MySQL проверок, включая default-off, URL после включения, off, защищённый pending, неверные параметры, откат при SQL-ошибке и повтор. HTTP-фикстура поддерживает LUCKTEST_SQL_PORT/LUCKTEST_SQL_DATADIR и проверяет точный datadir до записи. Проверено на отдельном MySQL 5.7; рабочие credentials и БД не использовались. Миграция не требуется.

## Результат и настройка

LuckTest остаётся доступным всем аккаунтам. Debug и дополнительные поля доступны только аккаунтам из приватной auth-конфигурации и только в LuckTest. JSON задаёт разрешённый host в Quick Game. Другой участник не получает разрешение из-за состава пары.

По умолчанию Debug закрыт: пример `config/auth.example.php` содержит `debug_account_ids => []`. Реальные ID в исходники/assets не добавлялись, рабочая конфигурация не менялась.

Инструкция Project Lead:

1. Выбрать два уже зарегистрированных тестовых аккаунта и проверить их ID в существующей accounts, например read-only запросом `SELECT id, login FROM accounts WHERE login_key IN ('test_login_one', 'test_login_two');`. Имена здесь — placeholders.
2. В существующем приватном `config/auth.local.php` добавить `debug_account_ids` с двумя различными положительными целыми ID. Использовать PHP integers без кавычек. Не коммитить этот файл.
3. Проверить обновление конфигурации PHP/opcache на сервере. При необходимости сбросить opcode cache или перезапустить соответствующий PHP worker.
4. Проверить обычный аккаунт и тестовый аккаунт на LuckTest: обычному Debug/textarea не выдаются, у тестового host появляются Debug и поля Settings/Lobby.
5. Для полного отключения поставить пустой массив. Отсутствие ключа, строки вместо integers, повтор ID, неверное количество элементов закрывают Debug всем. Для замены одного аккаунта оставить ровно два действительных ID и обновить конфигурацию.

SQL-схема, миграции, admin-роль, дополнительные DB grants не нужны. Существование текущего HTTP-аккаунта проверяет прежний AuthStore/AuthContext. Ни account_id, ни role, ни debug_allowed из запроса не используются как разрешение.

## Серверная модель разрешения и UI

Новый DebugAccess централизует единственный allowlist из auth.local.php. AuthContext и аутентифицированный Lobby bind-ят свежий Account в request-local контекст. Представления дополнительно проверяют режим LuckTest и соответствие account_id участнику GameState. Core получает прежнего авторитетного actor через существующий HTTP/GameAccess или внутренний серверный вызов; Core не принимает account identity из JSON.

У обычного аккаунта отсутствуют пункт Debug и диагностический overlay. BattleSnapshotRenderer по-прежнему использует BattleScreen; тот же gate защищает info_panel_html в AJAX. Данные prop/скрытых карт/pending не попадают к обычному аккаунту через diagnostic view. Разрешённому участнику LuckTest доступно прежнее buildDebug-представление, включая подробности скрытых карт; это намеренная привилегия только тестового режима.

Боевой пункт Debug теперь локальная кнопка overlay, без старого debug=1 URL. Старые GET debug/debug_roll/first/second по-прежнему отклоняются. Fallback debug.tpl заменён общим сообщением недоступного состояния без служебных сведений.

battle-menu.js допускает отсутствие Debug, сохраняет открытие меню/звук, локально открывает overlay и скрывает устаревшую кнопку при исчезновении разрешённого фрагмента. Отдельный JS переключает видимость и disabled textarea вместе с quick_game; сервер независимо отклоняет непустой сценарий ручного режима.

## Очередь Dice и транзакционная граница

Dice.php не изменялся. Новый LuckTestDice адаптирует его существующий session-интерфейс к закрытому разделу GameState `lucktest_debug`.

Очереди индексируются по account_id; каждая запись содержит также game_id, account_id, исходную строку Dice и его сохранённый cursor state. Аккаунт выбирается по серверному actor Engine-команды. Проверяются оба ID и актуальный allowlist. Очередь оппонента не наследуется. Существующие серверные автоматические команды используют своего штатного actor; наличие тестового аккаунта среди участников не даёт вызывающему пользователю debug-доступа.

Настройка возможна только из Settings до формирования карт, через confirm_settings или новый preparation command set_lucktest_debug. Вход защищён прежними AuthContext, GameAccess, CSRF, prepare_version и CAS. JSON дополнительно требует host. HTTP отклоняет такие поля до автоматического продвижения, если аккаунт/режим/стадия не подходят. Engine повторно проверяет условия при выполнении, включая locked execution.

Поддержаны конечная последовательность, `6*`, `off`. Бесконечный бросок не обходит штатный enterTurn: запуск с активным infinite cursor получает прежнюю контролируемую ошибку. Проверена разрешённая native infinite queue второго аккаунта в бою. Новую очередь после старта устанавливать нельзя.

Внутри Engine candidate:

1. Создаются копии GameState и Recorder для настройки/запуска и для команд с сохранённой очередью.
2. Адаптер временно подаёт только подходящую очередь в существующие session keys и вызывает Dice::init().
3. Штатная команда использует обычный Dice::roll().
4. Только при успешном Result остаток копируется в candidate GameState.
5. Session bridge очищается в finally; в аутентифицированном HTTP-контексте старые session overrides не восстанавливаются.
6. Только успешный candidate публикуется в исходный объект. Долговечное сохранение выполняет прежний CAS/journal/RoomTransaction.

Result::error/Throwable не публикуют курсор. CAS loser и отказ SQL не меняют долговечный остаток. Повтор после rollback начинает с прежнего сохранённого cursor. При неизвестном результате COMMIT фактический исход нужно читать из существующего journal/игры; нельзя автоматически считать его rollback и повторно выполнять команду.

CLI regression fixtures без HTTP-auth контекста по-прежнему могут управлять нативным Dice напрямую, как раньше. Это не HTTP-путь доступа: в аккаунтном bootstrap legacy session debug удаляется, а адаптер дополнительно его изолирует.

## JSON: проверка, генерация, расстановка

Необязательное поле `lucktest_scenario` содержит объект с единственным ключом cards. Максимум 4096 байт и глубина JSON 8. Хотя бы одна запись суммарно, до K на владельца; второй владелец может отсутствовать. owner/ukid обязательны, координаты — либо оба integer, либо отсутствуют. Дополнительные поля, в том числе stats/instanceId/старые seed-поля, отклоняются. Одинаковые ukid обозначают разные экземпляры и разрешены.

LuckTestScenario проверяет формат, поля и предварительную выполнимость через уровни ZoneManager. Полные K/quick_game берутся после штатного settingsFromCommand, включая существующий nested settings payload; вторую систему разбора настроек не вводили. Неизвестный ukid отклоняется по штатному каталогу DraftDeckBuilder; клиентские характеристики не принимаются.

LuckTestGenerator::generate получил необязательный список обязательных catalog snapshots. Обязательные карты образуют неизменяемый prefix, случайно генерируется только K−M. Случайные улучшения стоимости затрагивают только свободный suffix. Score остаётся для всего отряда относительно K×average_cost, с прежней приблизительной целью. Балансировка сохраняет равенство наличия special cards; невозможные категории исключаются, невозможная комбинация возвращает понятную ошибку. При пустом списке прежний алгоритм сохраняется. generateReserve не менялся: ровно 30−K случайных карт каждого игрока.

После создания экземпляров обязательные записи связываются с различными squad instanceId, включая повторы ukid. Клетки с координатами резервируются. Доступные обязательные карты ставятся первыми; при необходимости случайные карты заполняют предыдущие уровни, не занимая резервированные места. Используются только cellLevels/isCellAllowed/isFieldOccupied и placeCard. Прогресс ограничен K шагами на владельца; при невозможности candidate отклоняется.

Перед штатными confirmPlace проверяются все обязательные instance/owner/ukid и заданные координаты. Затем работают прежние validateLuckTestCards и Engine::confirmPreparePlace → TurnProcessor::startBattle. Координаты относятся к моменту до startBattle: летающая карта может штатно перейти в flying.

Непустой сценарий при quick_game=false отклоняется. Пустой сценарий и off не создают специального room metadata и не ограничивают обычный LuckTest. Обязательные pending и стартовые эффекты не разрешаются автоматически.

## Хранение и приватность

До игры нормализованные данные находятся в закрытом `lobby_rooms.setup_json.debug`, отдельно от общих settings. При создании сравниваются разрешённый account_id и настоящий создатель. При принятии RoomTransaction/RoomSetup повторяют проверку; MatchCreator связывает данные с фактическим host. Это работает и в ветке с BattleHistory и borrowed transaction.

После создания данные находятся в GameState.lucktestDebug и сериализуются как lucktest_debug. Это серверное состояние, не новый client DTO. Settings не считаются подтверждением разрешения. Перед запуском сохранённого сценария снова проверяется host permission. Для нового cursor используются настоящий game_id и account_id, а не данные POST.

Lobby projections не возвращают debug/setup_json. BattleHistory сохраняет прежние explicit projections без сценария/очереди; семантика initial не менялась. GameLog удаляет исходные lucktest_scenario/debug_roll из params, оставляя SHA-256 hashes. Он не становится доказательством COMMIT: файловая запись, как прежде, может предшествовать отказу SQL.

## Изменённые файлы

- Auth: `src/Auth/AuthContext.php`, новый `src/Auth/DebugAccess.php`, `config/auth.example.php`.
- Core: `src/Core/Engine.php`, `GameState.php`, `GameLog.php`; новые `LuckTestDebug.php`, `LuckTestDice.php`.
- Prepare: `src/Core/Prepare/PrepareProcessor.php`, `LuckTestGenerator.php`; новый `LuckTestScenario.php`.
- Lobby: `src/Lobby/LobbyStore.php`, `RoomSetup.php`, `MatchCreator.php`.
- Views: `src/View/Screen/BattleScreen.php`, `SettingsScreen.php`, `src/View/Ui/GameMenu.php`.
- Templates: `templates/screen/battle.tpl`, `debug.tpl`.
- HTTP/assets: `www/index.php`, `www/lobby.php`, `www/assets/js/battle-menu.js`; новый `lucktest-debug.js`.
- Tests: новые `tools/test_lucktest_debug.php`, `test_lucktest_debug_sql.php`, `test_lucktest_debug_http.py`, `test_lucktest_debug_http_fixture.php`, `test_lucktest_debug_menu.cjs`; точечная стабилизация часов в helper `test_lucktest_manual.php`.
- Этот отчёт.

Не изменялись Dice, ZoneManager, TurnProcessor, Db, GameRepository, CommandJournalRepository, Recorder/ReplayStateBuilder, AuthSession/GameAccess/AuthStore, GameSettings и DraftDeckBuilder. Миграции, зависимости и schema приложения не менялись. Необходимые API-расширения необязательные: fixed argument генератора, trusted debugAccount у RoomSetup::parse и контекст состояния у GameMenu::preparation.

## Проверки

Все проверки — в отдельной копии `C:/Users/mi/Documents/Berserk/lucktest-debug` без рабочих конфигов. Рабочая БД не использовалась. PHP 8.1.9, MariaDB 10.8.4 на отдельном 127.0.0.1:33987 с проверкой datadir `.../lucktest-stage3/sql-data`, отдельные случайные synthetic schemas. HTTP — PHP development server 127.0.0.1:33988, session files в изолированной папке; тестовые private configs удаляются после прогона.

| Проверка | Результат |
| --- | --- |
| Новые unit/domain Debug/scenarios | 218 PASS |
| Реальные HTTP Auth/Lobby/index | 51 PASS |
| Новые Debug SQL CAS/journal/Lobby | 30 PASS |
| Native JS menu с/без Debug, звук, overlay, отзыв | 8 PASS |
| LuckTest Stage 1 / manual Stage 2 / quick Stage 3 | 186 / 1014 / 765 PASS |
| Recorder same-source retry | 83 PASS |
| Existing isolated LuckTest SQL / Recorder SQL retry | 64 / 16 PASS |
| BattleHistory storage regression | 23 сценария PASS |
| BattleHistory Recorder regression | 20 сценариев PASS, включая HTTP fixture и два процесса |
| Settings, Sealed, prepare dice, View sideboard, Solomon, Draft temporary rules/timer/selection evaluator | PASS; Solomon 1767 проверок последнего запуска |
| PHP lint изменённых/новых PHP, JS syntax | PASS |

Старые DB-подпроверки Draft явно SKIP без config/db.php; они не выполнялись на рабочей БД.

Проверены: K=3/10, обе стороны/победители, одиночная обязательная карта, одинаковые ukid, полностью заданный отряд, свободные координаты, несколько уровней и случайная поддержка уровня, невозможные клетки/балансировка, приблизительный бюджет с дорогой обязательной картой, flying и обязательный pending, настоящий боевой Dice из saved cursor, infinite/finite/off, разные аккаунты/игры, revoked permission, forged account/role/flag, CSRF и legacy URLs, account switch, обычное создание/принятие LuckTest, закрытые HTML/AJAX/history projections.

Повторная попытка на том же GameState после start effect TypeError за initial проверяет одновременно чистоту Recorder и очереди, затем ровно один initial + battle_ready. SQL faults на UPDATE games и INSERT history проверяют rollback JSON/cursor/scenario/journal, успешный retry и stale CAS. HTTP SQL fault возвращает общий 503 без PHP/SQL деталей, сохраняя долговечный cursor. Повторный HTTP launch не меняет уже начатый Battle.

Обнаружена старая нестабильность manual-теста: проверка полной неизменности rejected Battle-команды зависела от перехода wall-clock секунды, хотя штатный Engine обслуживает timer. Заморожены часы только в тестовом helper; правила/таймеры приложения не менялись. Дополнительно закрыт вывод внутренних ошибок сохранения в LuckTest preparation: HTTP возвращает общий uncertain-save ответ; server log сохраняет диагностическое событие, автоматического retry нет.

Для изолированной BattleHistory-регрессии изменялись только временные координаты подключения в копиях существующих tools; эти изменения не переносятся в репозиторий. Артефакты summary и regression-harness.diff сохранены рядом с full.diff.

## Ограничения и риски

- До установки двух реальных ID Debug остаётся выключенным. Изменение config требует обычной актуализации PHP/opcache; права проверяются на новых запросах, уже начатый запрос не отзывается задним числом.
- Курсор принадлежит серверному actor команды, не отдельной атакующей/защищающей кости. Бросок, вычисляемый в команде другого участника, не расходует чужой cursor. Сам алгоритм Dice не получает новую информацию о владельце отдельной кости.
- В Lobby дополнительные параметры задаёт создатель до принятия. Участник может настроить собственную очередь на доступной стадии Settings; после перехода в Turn/Side/Place/Battle настройки закрыты.
- `6*` не может обойти initial dice guard. Поле принимает синтаксис, но запуск с активной бесконечной очередью исполняющего host отклоняется штатным enterTurn.
- При отзыве permission сохранённый cursor остаётся нерасходованным и не применяется. Повторное включение того же account ID разрешит оставшийся cursor; конфигурация не редактируется автоматически.
- Queue/CAS atomicity относится к долговечному состоянию. При неизвестном результате COMMIT возможен фактический commit: требуется штатное чтение journal/игры, а не повтор без проверки.
- UI проверен реальными HTTP HTML/AJAX и JS controller tests; полный browser E2E не запускался. SQL — MariaDB 10.8.4; MySQL 5.7 и production TLS не тестировались.
- Сохраняются прежние experimental/partial ограничения BattleHistory и gaps для неаттестованных механик. Разрешённый диагностический overlay намеренно показывает скрытые боевые сведения только тестовому аккаунту LuckTest.

Полный diff этой реализации: `C:/Users/mi/Documents/Berserk/lucktest-debug/full.diff`. После реализации остановка для code review, без дальнейших стадий.
