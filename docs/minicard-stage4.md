# Мини-карты Stage 4 — реализация и проверка

Статус: реализовано в ветке `codex/minicard-stage4`, проверено локально на настоящем PHP-рендере и штатном AJAX applySnapshot с тестовым GameState. Приёмка в живом тестовом матче ещё не выполнена. В main и на сервер изменения не отправлены.

## Реализация

- Боевые клетки 110×100, мини-карты 102×92, gap 4. Отдельные CSS-переменные ширины/высоты. InfoPanel и её адаптивность не менялись. Коврик в 1600×900: 926×732, левая область 1092×884, панель 440×884.
- Рамки и ресурсы перенесены из Stage 3. Иллюстрация `/assets/cards/sN/<ukid>.webp`, если файл существует; contain без обрезки/деформации. При отсутствии файла остаётся фон, подставных картинок нет.
- Монеты, текущая броня, яд и счётчик эффектов берутся из CardInstance. Яд из markers.poison, в общей подсказке не повторяется.
- LiveBattleCard отдельный от BattleCardMarkup, который остался неизменным. ReplayScreen и replay.css не менялись.
- Существующие ссылки игровых действий не заменены. Слушатель в capture-фазе блокирует игровые клики только для индикаторов/подсказок.
- Hover Zoom: визуальная копия в body, pointer-events:none, задержка 200ms, scale1.15, transition180ms ease-out. Отсутствует на touch, скрытых картах и при reduced motion. Оригинальная hit-area не растёт; маркеры клетки не копируются. Ограничение позиции viewport. При уходе анимация возврата без задержки.
- Подсказки над zoom, в body, без обрезки полем, hover/focus/click/Enter/Space/Escape, повторный/внешний клик. На AJAX очищаются старые DOM-ссылки, порталы и таймеры; повторное подключение не добавляет обработчиков. ResizeObserver пересчитывает строки индикаторов, в том числе при показе скрытой страницы летающих.

## Проекция активных эффектов

ActiveCardEffects использует явные modifiers, markers кроме poison, getInheritedLineBonusValues, getActivePropBadges для условных ОВА/ОВЗ/direct и проверки строя CardStats. Источники ауры строя проверяются по текущим условиям getLineStrikeAuraBonus, связность через areInSameConnectedLine. Без вычитания базовых характеристик.

Неизменённые базовые direct/ОВА/ОВЗ не включаются. Однотипные числовые записи с одинаковыми условиями и сроком суммируются; разные сроки/условия остаются раздельными. Счётчик равен числу строк, а не количеству наложений. Нулевая сумма сохраняется. Неизвестные stat/marker не отбрасываются: выводятся с техническим названием и доступными параметрами. Тексты экранируются. Идентификаторы источников и владельцев не выводятся; для массива sources показывается количество.

Ограничения достоверности:

1. resolveGrantProp непосредственно записывает свойство в prop. Происхождение не сохранено, поэтому оно не объявляется активным полученным эффектом. Пример: полученное этим путём свойство direct неотличимо от базового.
2. Изменения, хранящиеся исключительно в flags (например, некоторые ограничения/режимы после перемещения), не отображаются: служебные flags не выдаются за эффекты.
3. Целевые и уровневые бонусы в prop.ability (element, closed, target_type, types, level, value_from=target_hp_half, per_ally) не превращаются в безусловный активный эффект без выбранной цели/уровня удара. Базовые неизменённые свойства не перечисляются.
4. Для неизвестных будущих типов есть техническая строка вместо придуманного названия; семантику нового типа нужно отдельно добавить в слой отображения.
5. Несколько существ в одной клетке: fieldMap и игровая модель не менялись. Сохраняется прежний выбор одной карты; фиктивных переключателей нет.

## Проверки

Пройдено:

- test_mini_card_stage4.php: базовые/активные свойства, два перемещения через настоящий MovementResolver: сумма ОВА+2 при базовой ОВА1 и изменение ОВЗ, отрицательная ОВЗ, direct-модификатор, оглушение/сеть, отдельный яд, неизвестные stat, экранирование, скрытые данные, аура строя, неизменность GameState, реальный live-рендер, replay и env-откат.
- test_mini_card_stage4_browser.cjs: настоящий шаблон battle.tpl и PHP-фрагменты, геометрия, все четыре индикатора, крупные значения без перекрытия названия, скрытая/закрытая/умирающая карта, подсветка атаки, клики выбора/атаки/хода/прыжка (маршруты, без команд живому API), изменение ОВА после applySnapshot, hover/click/key/touch, повторные AJAX-обновления, крайняя карта и слот летающих, отсутствие перехвата соседних кликов увеличенной копией, reduced motion, очищение устаревшей подсказки, legacy replay.
- test_battle_snapshot_renderer.php; test_battle_card_visual_ui.php (legacy rollback); test_fly_pagination.php; test_battle_auto_target_ui.php; test_movement_effects.php; test_ajax_multi_target.php; test_friendly_fire.php; test_battle_helper_movement.php; test_forced_strike_targets.php; test_degasca.php (включая оглушение); test_combat_continuation.php; test_cell_marker_cancel.php.
- InfoPanel: test_card_preview_ui.php, test_card_description_ui.php, test_card_panel_metadata.php.
- JS syntax, git diff --check.

Отдельно: test_battle_stage1b_ui.php падает на старом ожидании текстовой кнопки «Сдаться». Падение повторено с BERSERK_LEGACY_MINICARDS=1; новую карточку оно не использует. Тест и кнопка не переделывались в этой задаче.

Ещё не подтверждены: прохождение действий атаки/хода/прыжка от браузера через сервер с сохранением и загрузкой конкретного живого матча; полный набор специальных pending-сценариев вручную; реальные изображения всех карт на сервере. Поэтому окончательная приёмка интеграции не заявляется.

## Скриншоты

`docs/evidence/minicard-stage4/battle-1600x900.png` и `effects-1600x900.png`: Edge1600×900, deviceScaleFactor1, масштаб100%. Это настоящий battle.tpl/global.css и новый PHP-рендер с тестовым GameState, не продовый матч. Иллюстрации в тесте отдаются из локальных предоставленных пользователем файлов. Эти изображения не добавлялись в каталог боевых карт. Для переносимого теста без локальных иллюстраций предусмотрен SVG только на уровне mock-маршрута браузера.

Запуск: PHP8.1 tools/test_mini_card_stage4.php; Node tools/test_mini_card_stage4_browser.cjs. Переменные PHP_BINARY, PLAYWRIGHT_MODULE, BROWSER_EXECUTABLE переопределяют локальные пути. MINI_CARD_TEST_ART_DIR указывает папку с art-1.webp/art-2.webp/art-3.webp (в этой проверке mini-card-prototype/assets). Временные image-availability probes всегда удаляются через finally.

## Изменённые файлы

- src/View/Screen/BattleScreen.php — переключение live/legacy-рендера, viewer и признак дизайна.
- src/View/Ui/LiveBattleCard.php — новый рендер.
- src/View/Ui/ActiveCardEffects.php — подготовка эффектов.
- templates/screen/battle.tpl — подключение отдельных CSS/JS, признак дизайна.
- www/assets/css/battle-mini-card.css — геометрия и внешний вид; global.css не менялся.
- www/assets/js/battle-mini-card.js — zoom, подсказки и жизненный цикл AJAX.
- www/assets/images/battle/mini/: frame_friendly_wide.svg, frame_enemy_wide.svg, nameplate_friendly.svg, nameplate_enemy.svg, hp_medallion_compact.svg, coin_compact.svg, armor_compact.svg, poison_compact.svg, effects_compact.svg.
- tools/test_mini_card_stage4.php; tools/test_mini_card_stage4_browser.cjs — новая проверка.
- tools/test_battle_card_visual_ui.php — старые ожидания сохранены для legacy.
- tools/test_fly_pagination.php — поиск CSS-токена battle-card вместо точного равенства class.
- docs/minicard-stage4.md и docs/evidence/minicard-stage4/*.png — отчёт и evidence.

## Откат

Установить BERSERK_LEGACY_MINICARDS=1 в окружении всех PHP-воркеров, перезагрузить их и полностью обновить страницу матча. Вернётся прежний buildBattleCard/BattleCardMarkup и геометрия102×102. Env должен быть одинаковым на всех воркерах. Удаление переменной включает новый дизайн. Игровое состояние не меняется.

Для полного отката — revert коммита Stage4. Реплеи используют legacy независимо от переключателя.
