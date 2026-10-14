# Игровые координаты UI

Ветка: `codex/battle-ui-coordinates`, от локального `main`.
Изменения подготовлены к code review; задача не считается принятой до review.

## API

`BattlefieldPosition::display(?int $row, ?int $col, string $viewerKey)` — единственное преобразование; возвращает `{row, col, side}` либо `null` для отсутствующих/недопустимых координат и неизвестного наблюдателя.

Системные ряды 1–6 преобразуются в 3, 2, 1, 1, 2, 3 независимо от наблюдателя. Сторона `own`/`enemy` определяется половиной поля, а не владельцем карты. Для host столбец сохраняется, для player равен `6 - col`. `label()` возвращает текст `Свой [1:3]` или `Вражеский [1:3]`.

`Coords::render()` возвращает безопасный HTML с цветом стороны. `CardButton` и существующие потребители `label()` автоматически используют новую нумерацию. `TaskCard::render()` принимает необязательный пятый аргумент `viewerKey` для нового компонента; существующие вызовы без ориентации оставлены для отдельной миграции.

`CellMarkerPicker` — адаптер View для существующего `CellMarkerChoice`. Получает допустимые клетки из pending, проверяет активный chooser и владельца выбора, формирует кнопки и ссылки. Core, правила, команды и параметры не изменены. Некорректные ключи клеток отбрасываются; корректные системные координаты сохраняются в URL.

## Поле и Крондак

BattleScreen формирует одну шкалу столбцов и шесть круглых номеров рядов в составе `field_html`. Свои ряды зелёные, вражеские красные; цвета взяты из текущего оформления карт. Номера размещены в отдельном от клеток отступе, не перехватывают указатель. Размер клеток сохранён: CSS 100×100, внешний размер с рамкой 102×102.

Кнопки Крондака подписаны `🔥 Свой 1-й ряд · столбец 3`, имеют цвет стороны, переносящийся текст и высоту минимум 44px. Кнопка отмены сохранена. Допустимые пустые клетки дополнительно подсвечены и выбираются обычной ссылкой с той же командой `choose_cell_marker`. Занятые клетки не получают альтернативную ссылку, существующие оверлеи маркеров сохраняются. Для другого игрока доступны только ожидание и обычное поле.

На ширинах до 900px поле прокручивается горизонтально, не сжимая клетки. Существующий layout со своими вертикальными прокрутками сохранён.

## Проверки

- `tools/test_battle_coordinates_ui.php`: все 6×5 клеток для обоих игроков, сторона поля, крайние координаты, отсутствие/невалидные значения, полный набор кнопок, исходные координаты URL, HTML-экранирование, повторный рендер без дублирования, отсутствие выбора у противника и после завершения pending.
- Существующие тесты defender, ally modifier, auto target, death choice, redirect, Charon, combat instant, vampire HP. Ожидания координат обновлены в шести тестах, использующих общий label API.
- PHP syntax checks изменённых и новых PHP-файлов, `git diff --check`.
- Браузерная проверка статического HTML, полученного настоящим BattleScreen: 1280×900 и 390×844. Подписи корректны, клетки 102×102, кнопки ≥44px, на 390px кнопки не выходят за экран, поле имеет горизонтальную прокрутку.

В этой версии main обработчик AJAX боевого поля не обнаружен (есть fetch таймера драфта). Проверен повторный рендер field_html; реальный AJAX-цикл и живая партия с БД не проверялись. При замене field_html подписи приходят вместе с полем и не требуют отдельной клиентской инициализации. Визуальная проверка выполнена на пустом поле с 30 вариантами выбора; реальная партия с картами требует review/приёмки.

## Остальные места для отдельной миграции

Системные подписи остаются в choosers: AnyDeath, AnubisarChain, BloodStorm, AttackSeriesContinue, CombatPick, DvergGift, ForcedDirectionalMove, ForcedStrikeAdjacent, GatePick, GreedTeleport, HealInsteadWound, IncarnationWound, InstantPick, IntrigueStart, LifeGift, NokamiWound, ParticlePick, RangedAttackRedirect, RowSpellPick, ShaksiRoll, TeleportTarget, ValhallaPick, WildHealerMove; также проверить клеточные Incarnation/Revive.

В InfoPanel остаются координаты перемещений/телепортов, бомбы, призыва и dive (поиск `new_row`, `new_col`, `summon_cell`, `choose_dive_cell`). В BattleScreen debug сохранены системные координаты для диагностики. TurnPhaseChoice, TurnSubChoice и TurnInstantStackChoice пока вызывают TaskCard без viewerKey. CellMarkerChoice::spec() по-прежнему содержит системные подписи, однако боевой InfoPanel заменяет их через View-адаптер.

## Изменённые файлы

- src/View/Ui/BattlefieldPosition.php
- src/View/Ui/Coords.php (новый)
- src/View/Ui/CellMarkerPicker.php (новый)
- src/View/Ui/TaskCard.php
- src/View/Screen/BattleScreen.php
- src/View/Screen/Battle/InfoPanel.php
- www/assets/css/global.css
- tools/test_battle_coordinates_ui.php (новый)
- tools/test_battle_defender_ui.php
- tools/test_battle_ally_modifier_ui.php
- tools/test_battle_auto_target_ui.php
- tools/test_battle_death_choice_ui.php
- tools/test_battle_redirect_ui.php
- tools/test_charon.php
- docs/battle-coordinates-ui.md (этот отчёт)