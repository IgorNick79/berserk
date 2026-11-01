# Roll: аудит, реализация и визуальная приёмка

## Результат аудита

Roll в проекте — две реальные стадии: `turn` (готовые результаты и подтверждения игроков), `side` (победитель выбирает сторону, соперник ожидает). Кубики генерируются вместе при входе в `turn`; равные результаты автоматически перебрасываются внутри PrepareProcessor до показа экрана. Отдельного состояния UI с выбором повторного броска нет. Генерация, сравнение, подтверждения и переходы PrepareProcessor сохранены без изменений.

GameChat и MatchPause раньше допускали View и бой. По отдельному согласованию добавлены `turn`/`side`. Контракты GameChat::canRead/canSend и MatchPause::supportsStage централизуют доступность внутри соответствующих компонентов. `deck`, `place`, `deal`, `draft`, `settings` не подключены. Пауза не зависит от настройки таймера. Существующая проверка Engine блокирует confirm_turn/choose_side; Engine не изменён. Журналируемый AJAX допускает только команды паузы на этих стадиях; игровые команды остались на прежнем маршруте подготовки с CSRF, версией и защитой записи. Счётчики паузы сохраняются при turn → side; во время паузы переход невозможен.

## Прямо использованные компоненты

- Иллюстрация `/assets/images/battle/waiting-war-table.png` и `.room-illustration` из lobby-room.css; без наложений на изображение.
- PreGameLayout с существующими InfoPanel-слотами и четырьмя темами аккаунта; battle-ui-skins.css и локальные боевые шрифты.
- DiceValue::render: настоящие боевые SVG-гранями без собственной системы кубиков или анимации.
- Исходный footer View извлечён в PreGameLayout::actionsFooter и используется обоими экранами. Стандартные семантические кнопки не скопированы в Roll CSS.
- Статистика из ViewScreen::readOnlyDeck: колода/сайдборд/минимум, кристаллы и стихии. На Roll кнопки статистики превращены в неинтерактивные span; карточки не открываются.
- game_chat.tpl, battle-chat.js/css, panel-secondary-view.js, BerserkMatchPause из battle-match-pause.js. Журнал сообщений общий для всей партии.

Минимальные адаптации: существующий клиент view-deck.js поддерживает Roll по отдельной конфигурации, переиспользуя обновление разметки, хранение незавершённой команды, проверку статуса и общую панель чата/паузы. View сохраняет свой маршрут и поведение. game-transport.js продолжает отправлять игровые формы Roll прежним способом; повторный polling для Roll отключён, поскольку обновление уже выполняет общий клиент. Изменения turn → side отображаются без обязательной перезагрузки ожидающего игрока, выход к deal/place использует обычную навигацию. CSS Roll содержит только геометрию, а не аналоги кнопок, чата или паузы.

## Проверки

Реальные локальные DB/HTTP-прогоны в отдельных временных БД и под тестовыми аккаунтами; рабочая БД не использовалась. Браузер — установленный Яндекс Браузер, headless, отдельные профили.

- Roll: 28 принятых проверок HTTP/БД + успешный браузерный набор (29 записей в http-checks.json); 19 браузерных проверок. Запрос/подтверждение/готовность/возобновление, отказ, повтор команды и восстановление статуса, блокировка legacy-действий, гонки с confirm_turn/choose_side, turn → side с сохранением used; оба игрока, четыре темы, история чата между стадиями и чат во время паузы, отсутствие горизонтального скролла 360/390 px, нативный переход side → deal.
- View: 64 браузерных, 19 HTTP/DB-проверок, включая DB-регрессии драфта и сравнительные проверки боевой панели, чата и паузы.
- Общий набор меню/мастера/комнаты: 227 успешных записей HTTP/DB, включая браузерные наборы меню, мастера, комнаты и чата комнаты/боя.
- Пройдены test_roll_presentation.php (оба возможных победителя, обе перспективы, ожидание, неизменность GameState при рендере, доступность стадий), test_prepare_dice.php, test_match_pause.php (119 assertions), test_game_chat.php (21), test_preparation_engine_boundary.php, test_view_sideboard.php, test_combat_panel_ui.php, test_combat_continuation.php, test_battle_snapshot_renderer.php, test_turn_timer.php и test_pending_wait_timer.php.
- PHP lint всех изменённых PHP, синтаксис JavaScript и git diff --check пройдены. В браузерных наборах Roll/View ошибок JavaScript нет.

### Отдельный сбой и незавершённые проверки

`tools/test_turn_timer_client.js` НЕ прошёл: `Same-second authoritative pause is accepted`, ожидается 01:38, получено 01:28. Сам тест и три загружаемых им файла battle-turn-timer.js, battle-match-pause.js, battle-sync.js побайтно совпадают с HEAD (сверены git blob-хеши); тест изолирован в VM и не использует новые файлы Roll. Это существующий сбой клиентского таймера; он не исправлялся в данной задаче. Нельзя объявлять всю доступную регрессию зелёной.

Ранее незавершённая полная HTTP-регрессия боевого таймера остаётся обязательной: TURN_TIMER_TEST_URL не настроен. Production, Google Chrome и Firefox отдельно не проверялись. Мобильная функциональность проверена без отдельной визуальной полировки. Игровые команды Roll сохраняют прежний ответ stale_preparation при конфликте версии; тест обычного подтверждения ожидает синхронизацию версии второго игрока, отдельные конкурентные тесты проверяют отклонение конфликтующих действий.

## Скриншоты

Все снимки — реальные страницы приложения и реальные тестовые игровые состояния. Каталог `docs/evidence/roll/`:

- turn-host.png, turn-player.png — обе перспективы броска; turn-host-waiting.png — уже подтвердивший игрок.
- side-host.png, side-player.png — выбирающий игрок и ожидающий соперник.
- turn-grimoire.png, turn-stone.png, turn-leather.png, turn-parchment.png; side-grimoire.png, side-stone.png, side-leather.png, side-parchment.png.
- chat-host.png, chat-player.png — общий чат при активной паузе; side-chat.png — та же история на side.
- pause-picker.png, pause-request-player.png, pause-active-host.png, pause-active-player.png.
- side-390.png, side-360.png.

## Файлы

Серверная доступность: src/Core/GameChat.php, src/Core/MatchPause.php, www/index.php.
Представление: src/View/Screen/TurnScreen.php, src/View/Screen/SideScreen.php, src/View/Screen/ViewScreen.php (только извлечение общего footer), src/View/Ui/PreGameLayout.php, новый src/View/Ui/RollResults.php.
Шаблоны: templates/screen/turn.tpl, side.tpl, view.tpl, battle.tpl, game_over.tpl; templates/includes/pregame/layout.tpl. Изменения боевых шаблонов — версии общих ресурсов.
Общие стили и клиенты: www/assets/css/battle-ui-skins.css, view-deck.css, global.css (только доступность цвета countdown для Roll), новый roll.css; www/assets/js/game-transport.js, pregame-ui.js, view-deck.js.
Проверки: tools/test_roll_http.py, tools/test_roll_browser.cjs, tools/test_roll_presentation.php; данный отчёт.

Commit, push, deploy и миграции рабочей БД не выполнялись. Реализация остановлена для визуальной приёмки.

## Правка после визуального ревью

На turn и side возвращён заголовок View «Твоя дека: …». «Бросок кубиков» перемещён в общую плашку заголовка карты. Строка «Колода / Сайдборд / Минимум» удалена только на Roll. При преобразовании статистики в неинтерактивные span сохраняются классы и aria-label: кристаллы и стихии снова используют исходную компоновку, иконки и числа выровнены через flex с align-items: center и gap: 4px. Ссылки, команды и фильтрация не добавлены.

Повторно пройдены 21 браузерная проверка Roll, 29 записей HTTP/DB-набора, PHP lint, JavaScript syntax, test_roll_presentation.php, test_view_sideboard.php и git diff --check. Скриншоты обеих стадий, перспектив, четырёх тем, чата и паузы обновлены. Ранее зафиксированные ограничения таймера остаются без изменений. Commit и push этой правки не выполнялись.
