# LuckTest Stage 3 — Quick Game

Дата: 22 октября 2026. Реализация подготовлена для code review; последующие стадии не начинались.
Diff Stage 3 считается относительно принятого рабочего состояния Stage 1–2, включая исправленный отчёт проверки геометрии. Предыдущие изменения сохранены.

## Изменённые файлы

- `src/Core/Engine.php` — единая атомарная операция и последовательность автоматической подготовки.
- `src/Core/Prepare/PrepareProcessor.php` — запрос признака быстрого старта через существующий парсер настроек для маршрутизации истории.
- `src/Core/GameRepository.php` — граница истории Settings → Battle для LuckTest и использование внешней транзакции Lobby.
- `src/Core/Db.php` — ограниченный временем callback адаптер уже открытой mysqli-транзакции.
- `src/Lobby/RoomSetup.php` — LuckTest запускается через Engine.
- `src/Lobby/MatchCreator.php` — запись быстрого старта с включённой историей в существующей RoomTransaction.
- `www/index.php` — быстрый старт направляется в существующий locked journal executor, когда запись истории разрешена сервером.
- `tools/test_lucktest.php`, `tools/test_lucktest_manual.php` — явный ручной флаг и проверка совместимости сохранённых Stage 2.
- `tools/test_lucktest_quick.php`, `tools/test_lucktest_quick_sql.php` — новые изолированные проверки.
- Этот отчёт.

Генератор, стоимость, балансировка, ZoneManager, TurnProcessor, Recorder, интерфейс, SQL-схема и зависимости не изменялись.

## Фактический путь выполнения

Обычный запуск: POST confirm_settings → существующие проверки prepare_version/CSRF → Engine::apply → confirmPrepareSettings.
Engine создаёт рабочую копию через GameState::fromArray(toArray()). Старый объект не получает изменения до завершения всего applyObserved, включая post-processing CoinAbilities, pending и таймеров.

Внутри одной операции:

1. PrepareProcessor::confirmSettings → startLuckTest → существующая генерация K карт отряда и 30−K резервных карт для каждого игрока; enterTurn/rollDice сохраняет штатные броски, переброс обоих кубиков при равенстве и защиту от бесконечного debug.
2. Штатные confirmTurn обоих игроков.
3. chooseSide вызывается от победителя броска со случайной стороной 1/2. Фактически начинает бой владелец стороны 1; победитель кубиков остаётся в firstPlayer.
4. Порядок K карт перемешивается. Для каждой карты перебираются только ZoneManager::cellLevels владельца; допустимые незанятые клетки определяются isCellAllowed/isFieldOccupied. Случайный выбор одной клетки → штатный placeCard → ZoneManager::toField. Резерв не участвует. Нет собственной геометрии или ограничений найма.
5. Оба подтверждения идут через тот же Engine::confirmPreparePlace, что и ручная команда. PrepareProcessor::confirmPlace повторно проверяет оба полных состава через validateLuckTestCards: количество, резерв, flags, владельцы, instanceId, координаты, отсутствие коллизий и соответствие deckCards.
6. Нативный TurnProcessor::startBattle выполняет открытие/скрытие, перевод летающих, таймер, initial history, стартовые эффекты и обязательные pending. Нового запуска Battle или автоматического ответа на pending нет.
7. После успешного внешнего apply опубликованы поля рабочего GameState; persistenceJson/persistenceVersion остаются у исходного объекта. Сохранение делает прежний repository/CAS, а не внутренние операции.

Внутренние действия вызывают доменные методы напрямую, без вложенных Engine::apply, save или HTTP-команд. События собраны в один Result, с `lucktest_quick_preparation_completed`; они не являются отдельными snapshots.
При quick_game=false генерация заканчивается Turn. Сохранённые Stage 2 Turn/Side/Place с любым значением quick_game продолжаются вручную: автоматизация запускается только по confirm_settings из Settings.

## Атомарность, ошибки и повторы

Любая внутренняя ошибка быстрого пути возвращает контролируемую общую ошибку; исходные cards, players, settings, IDs, status и battle не получают частичный результат. Throwable обрабатывается вокруг всей операции, в том числе ошибки после создания временного initial внутри startBattle. Запись такого batch при отказе не выполняется.
Техническая диагностика идёт через существующий PHP error_log: событие `lucktest_quick_preparation_failed`, game_id, класс, code, message, file, line. Ошибки самой генерации сохраняют `lucktest_generation_failed`. Игрок не получает exception message, stack или пути.

При отсутствии истории repository сохраняет один конечный JSON обычным CAS. Два кандидата одной версии могут вычислиться, но сохраняется только один. При отказе UPDATE долговечное состояние остаётся исходным; рабочий кандидат запроса не считается сохранённым.
При включённой истории команда зарегистрирована существующим CommandJournalRepository и исполняется после блокирующего перечитывания игры. Исходная версия проверяется до выполнения. JSON, metadata/actions/events/captures и committed-статус журнала фиксируются одной транзакцией; ошибка любой записи откатывает всё. Повторный command_id возвращает уже имеющуюся запись. PRG и prepare_version сохранены. Повторный confirm_settings из Battle не генерирует карты/стороны/расстановку и не повторяет эффекты. Обычное обслуживание battle-таймера остаётся штатным.

## Начальная история и Lobby

Обнаружены две существующие границы, которые требовали интеграции: history executor допускал первичную запись только из Place, а Lobby создавал игру через собственное mysqli-соединение внутри RoomTransaction.

Минимальное расширение executor допускает Settings только для LuckTest + journal command_type=confirm_settings и проверяет после успеха quick_game=true и конечный Battle/game_over. Единственный initial создаёт существующий startBattle перед стартовыми эффектами. Его момент не переносился: последующие штатные Battle-кадры сохраняют прежнюю семантику. Для простого аттестованного каталога проверены один initial, отсутствие подготовительных кадров и recording_gap; сложные механики сохраняют прежние ограничения experimental Recorder.
Серверный snapshot содержит весь состав, а public/host/player projections скрывают резерв. Нет публикации raw cards/prop через новый endpoint.

Lobby без включённой записи вызывает тот же Engine через RoomSetup. При включённой записи MatchCreator сохраняет только невидимый снаружи Settings seed в уже открытой RoomTransaction и использует существующие repository/journal/history на том же mysqli. Db::withTransaction не открывает/не завершает транзакцию; begin/commit/rollback запрещены, адаптер теряет возможность query после выхода из callback. Подтверждает room/game связь и выполняет единственный commit прежняя RoomTransaction. Ошибка истории или внешней части операции откатывает также INSERT games, journal и room link.
Запись истории по-прежнему определяется серверным allowlist; Quick Game не включает её для всех новых игр.

## Проверки

PHP 8.1, lint всех 11 изменённых/новых PHP-файлов — PASS.

`php tools/test_lucktest_quick.php` запускает три набора:

- Stage 1: 186 проверок — PASS.
- Stage 2 manual: 1014 проверок — PASS.
- Stage 3 quick: 765 проверок — PASS.

Покрыты K=3/10, стороны 1/2, оба победителя, ничья, разные случайные легальные доски, точные 30 карт на игрока, неизменность резерва, сериализация, полный состав/геометрия через прежний валидатор, переход летающих, стартовые эффекты обеих сторон ровно один раз, незатронутый резерв, обязательный pending и таймер, один initial, скрытые projections, отсутствие внутренних кадров, повторы, CAS. Проверены ошибка выбора стороны, исключение после частичной расстановки, отсутствие доступных клеток на повреждённой доске, бесконечный debug и ошибка startBattle. Повреждённые составы и пророчество покрываются также прежним manual-набором.

Изолированная SQL-проверка: `php tools/test_lucktest_quick_sql.php --isolated` — 64 проверки PASS.
Использован новый MariaDB 10.8.4 на 127.0.0.1:33987 с отдельным datadir `C:/Users/mi/Documents/Berserk/lucktest-stage3/sql-data`; без службы Windows и без подключения к рабочей БД. Скрипт требует LUCKTEST_SQL_PASSWORD, проверяет порт/datadir, создаёт новый случайный schema с префиксом berserk_lucktest_stage3_, использует синтетические карты/аккаунты и существующие миграции только там. Пример последнего schema: berserk_lucktest_stage3_c7a111282b.
Проверены успешный durable Battle, journal committed, ровно один initial, duplicate command, конфликт устаревшей версии, отказ UPDATE games, отказ append истории после UPDATE и откат, успешный retry, неправильный каталог, исключение расстановки, исключение после временного initial, manual final-Place history boundary, срок и полномочия DB-адаптера, реальные MatchCreator/RoomTransaction, ручной Lobby, успешный быстрый Lobby и два вида отката. Отдельное соединение не видит игру/историю до commit внешней RoomTransaction.
Временный history config SQL-теста удалён; рабочие конфиги не читались и не менялись.

Регрессии: test_settings_flow, test_sealed_flow, test_prepare_dice, test_view_sideboard, test_solomon_draft (1773 проверок последнего запуска), test_draft_temporary_rules, test_draft_timer_history, test_draft_selection_evaluator — PASS.
DB-подпроверки старых Draft-наборов явно SKIP: config/db.php отсутствует в изолированной копии. Это не проверки на рабочей БД.

## Ограничения и риски

- Проверены CLI/domain и реальные SQL-транзакции. Полный браузерный/HTTP-сценарий развёрнутого приложения и нагрузочный тест не запускались. HTTP-маршрутизация, CSRF, prepare_version и PRG сверены по коду.
- CAS проверен двумя кандидатами одной версии и устаревшим locked исполнением; это не нагрузочное исследование гонок.
- SQL проверен на MariaDB 10.8.4; MySQL 5.7 отдельно не запускался. Схема и миграции приложения не менялись.
- BattleHistory остаётся прежним experimental/partial механизмом с server allowlist и recording_gap для неаттестованных механик; полнота replay для всех карт не заявляется.
- Файловый GameLog, как прежде, может записаться до отказа SQL commit. Это диагностика запроса, не свидетельство сохранённого боя; durable journal/history откатываются вместе с JSON.
- Ошибка боевой способности после фактического commit в последующих командах не относится к подготовке; механики способностей не переписывались.

Полный Stage 3 diff приложен отдельно в `C:/Users/mi/Documents/Berserk/lucktest-stage3/full.diff`. Рабочая БД, схема приложения, ветки и коммиты не изменялись. Реализация остановлена для code review.
