# Stage 1B: InfoPanel 440px и устаревшая отмена действия

## База и файлы

Ветка codex/battle-layout-stage-1b, исходный SHA 162098e8d10cbed3c6d4b7fe8b7324b3de425825. Рабочее дерево было чистым. Пользователь в последней строке задачи явно разрешил push этой ветки, без merge.

Изменены:

- src/View/Screen/BattleScreen.php — одна проверка доступности кнопки отмены.
- www/assets/css/global.css — общая CSS-переменная ширины InfoPanel и отступа debug.
- tools/test_battle_cancel_action_ui.php — жизненный цикл реального действия для обоих участников, отказ, отмена выбора, смена карты, заблокированная карта и актуальные AJAX-фрагменты.
- tools/test_battle_stage1b_browser.js — измерение 440px и точного совпадения Debug Overlay с левой областью.
- docs/stage1b-panel440-cancel.md — этот отчёт.

Core, GameState, игровые команды, URL, AJAX-протокол, battle-sync.js/battle-ui.js и структура InfoPanel не изменены. Другие кнопки и механика cancel_pending не менялись. feature/bulbagur не затронута.

## Причина и исправление

Кнопка «Отменить действие» формируется сервером в buildPanel, отдельно от основного блока кнопок режимов. В canCancelTargetMode проверялись явный режим, активный игрок, принадлежность карты, strike и pending, но не CardStats::isDisabled.

После успешного действия карта закрывалась. Пока показывался результат, strike временно скрывал отмену; после обоих подтверждений strike исчезал, а выбранная карта и старый mode=action:shot оставались. Новый HTML панели снова содержал отмену, хотя сервер уже запрещал действие закрытой картой. Следовательно, это была ошибка серверного условия, а не кеш/устаревшая замена AJAX.

В проверку добавлен существующий CardStats::isDisabled($card), используемый Core и остальным View: закрытая карта или карта в сети не получают отмену выбора цели. После отказа сервера открытая карта сохраняет выбранный режим и возможность отменить его. Режим без доступных целей по-прежнему можно отменить. Существующий чистый URL кнопки сохранён: это сброс UI-выбора, а не команда отмены обязательного pending.

## Геометрия

На battle-shell определена --battle-info-width: min(440px, 45%). Grid и отступ Debug Overlay используют одну переменную. Предел 45% для узкого viewport сохранён. Размеры клеточного поля не изменены.

Браузер при 1600×700 измерил:

| Элемент | x | y | width | height |
| --- | ---: | ---: | ---: | ---: |
| InfoPanel | 1152 | 8 | 440 | 684 |
| Левая область / Debug Overlay | 48 | 8 | 1092 | 684 |
| Клеточное поле | 70 | 8 | 588 | 670 |

У html и body scrollWidth=clientWidth=1600, scrollHeight=clientHeight=700. Header/Content/Footer имеют высоты 70/526/86. На длинном содержимом Content: scrollHeight=10016, clientHeight=526, scrollTop=9490; у страницы scrollTop=0, Header/Footer сохранили координаты.

## Проверки

PHP syntax checks для BattleScreen.php и test_battle_cancel_action_ui.php, JS syntax check изменённого браузерного теста и git diff --check прошли.

Новая PHP-регрессия на старом View выявила отмену для заблокированной карты. После исправления прошли все проверки, в том числе реальные Engine::apply(action) и confirm_strike обоих игроков. После результата проверяются и panel_html, и info_panel_html, а также BattleSnapshotRenderer с сохранённым явным action:shot. Ошибка target_id=999 не закрывает источник и не убирает отмену. Проверены чистый URL отмены, переключение карты, пассивная сторона и существующие pending-сценарии.

Всего прошли 20 PHP-сценариев:

- battle_cancel_action_ui, battle_stage1b_ui, battle_stage1a_ajax_layout, battle_snapshot_renderer;
- battle_coordinates_ui, battle_result_coordinates_ui, battle_defender_ui, battle_auto_target_ui;
- battle_death_choice_ui, battle_redirect_ui, battle_ally_modifier_ui, battle_card_visual_ui;
- cell_marker_cancel, resign_pending, whip_cancel, forced_directional_movement;
- turn_instant_stack, combat_instant_ui, battle_sync_endpoint, battle_command_endpoint.

Использована отдельная копия приложения и новый локальный MariaDB datadir на 127.0.0.1:33873, БД berserk_stage1b_panel440. Реальные партии и конфигурация не изменялись. При совместном прогоне существующий endpoint-тест сначала встретил журнал фиксированного Engine ID. Для новых DB-фикстур выделен диапазон AUTO_INCREMENT от 10000; повторный endpoint-прогон прошёл. Производственный код и сами endpoint-тесты ради этого не менялись.

Браузер обращался к настоящему index.php и AJAX endpoints с журналом команд. Для host и player выполнены: показ отмены до действия; реальный отказ на неверную цель (отмена остаётся); успешный выстрел (источник закрывается, цель теряет 1 HP); оба подтверждения. После завершения результата исходная страница через AJAX показывала закрытую карту без отмены, хотя URL всё ещё содержал sel=1&mode=action:shot. Отдельно проверен штатный переход по кнопке отмены: выбранная карта и режим сбрасываются.

БД подтвердила для каждой из двух партий ровно action/rejected×1, action/committed×1 и confirm_strike/committed×2, source.closed=true, target.hp=6, strike=null. Дубликатов исполнения не было.

Существующий браузерный набор Stage 1B с новыми измерениями прошёл по 25 проверок для обоих viewer: stale-response, вложенные фрагменты, focus/blur, Escape, повторная инициализация, отсутствие лишних observer-синхронизаций, debug availability и отсутствие скролла. Временные серверы, БД и файлы тестирования удалены после проверки.

## Ограничения и передача

Проверено во встроенном Chromium при 1600×700. Другие браузеры и мобильный редизайн в эту локальную правку не входили; существующее ограничение ширины 45% сохранено. Изображения карт и остальные команды не перерабатывались. Восстановление игры без JavaScript не выполнялось согласно ранее согласованному требованию JavaScript.

До отчёта diff составляет 53 добавленных и 5 удалённых строк в четырёх файлах; production-часть — одна проверка View и три строки CSS. Итоговый коммит и полный diff-stat приведены в сообщении передачи. Выполнен только push codex/battle-layout-stage-1b; merge не выполнялся. Решение остановлено для code review.
