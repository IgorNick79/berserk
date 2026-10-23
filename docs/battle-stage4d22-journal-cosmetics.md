# Stage 4D.2.2 — косметика Live Battle Journal

## Изменения

- `JournalText.php`: координаты через существующий `View/Ui/BattlefieldPosition::label`, сторона передаётся из reader. Например, исходные [5,1] → [6,2] дают host «Вражеский [2:1] → Вражеский [3:2]», player «Свой [2:5] → Свой [3:4]». Формат поля и ориентация не дублируются отдельным журналовым алгоритмом.
- `HistoryRepository.php`: единственное изменение — передача `$side` текстовому форматтеру при чтении. SQL, column selection, visibility, cursor и запись не менялись.
- `View/Ui/RussianNumber.php`: общий помощник выбора трёх русских форм с исключениями 11–14. В проекте обнаружен только частный tokenSuffix для «жетон», общего помощника не было. Журнал использует винительный падеж: 1/21 рану, 2–4/22–24 раны, 5–20/25 ран.
- `battle-journal.js`: текстовый API остаётся совместимым; точный существующий префикс строки броска превращается в доступный inline-кубик и «Атакующий …». Использован игровой CSS `.dice` с текстовым символом грани U+2684 + text presentation selector; отдельного файла иконки кубика среди ресурсов нет. Emoji не используется. Доступное название — «Бросок кубиков», role=img; HTML событий по-прежнему не исполняется.
- `battle-journal.css`: компактный размер существующего кубика, перенос длинных имён и отступы панели на мобильном экране.

Пункт title проверен отдельно: в строках журнала, его кнопке с собственной battle-icon-tooltip и панели уже нет дублирующих title. Полезные hints, aria-label кнопки и закрытия сохранены; новый кубик также не создаёт native title. Посторонняя кнопка паузы не менялась.

## Проверки

Source-only изолированная копия без production configs/БД. PHP 8.1.9; headless Edge/Playwright.

- Новый `tools/test_journal_cosmetics.php`: общий coordinate formatter для двух сторон, reader действительно передаёт viewer, исходный payload неизменен, API без дополнительных полей, прежняя редактированная проекция скрыта; 24 количества ран, включая 11–14, 111–114 и 121–125. PASS.
- Новый `tools/test_journal_cosmetics_ui.cjs`: 6 сценариев — host/player × 1280×900, 390×844, 320×568. Кубик, доступное имя, отсутствие повторных title, перенос длинного имени, сохранение plain-text XSS строки, отсутствие горизонтального/вертикального выхода панели и дубликатов при polling. PASS. Скриншоты мобильного и desktop отображения сохранены и мобильный просмотрен.
- Регрессии `test_live_battle_journal`, `test_live_journal_restoration`, `test_journal_gap_causes`, `test_battle_vampire_hp_ui` — PASS. Observer по-прежнему не меняет Result/DTO; pending и coverage контракты сохранены.
- Прежний `test_battle_journal_gap_ui.cjs`: обе стороны, 14 gaps → одно предупреждение, cursor19, reconnect, XSS и duplicates0 — PASS.
- `test_live_journal_restoration_browser.cjs`: ожидаемый DOM текст приведён к новому визуальному контракту кубика; сам полный HTTP/SQL harness этого этапа не повторялся (не было SQL/storage изменений).
- PHP lint изменённых форматтеров и reader — PASS. JS реально исполнен в браузерных проверках. `git diff --check` — PASS.

Recorder, Engine, расчёты, структурированные события, сохранённые проекции, правила доступа и schema не менялись. Старые строки форматируются при чтении; backfill или переписывания истории нет. API сохраняет только прежние seq/kind/text. Изолированные browser tests используют подставной read-only endpoint для проверки UI, не заявляют повторную приёмку реальных аккаунтов/production.

## Файлы

Runtime: src/Core/BattleHistory/JournalText.php; src/Core/BattleHistory/HistoryRepository.php; src/View/Ui/RussianNumber.php; www/assets/js/battle-journal.js; www/assets/css/battle-journal.css.
Тесты: tools/test_journal_cosmetics.php; tools/test_journal_cosmetics_ui.cjs; tools/test_live_journal_restoration_browser.cjs.
Этот отчёт и docs/evidence/stage4d22/.

Предыдущее отдельное локальное исправление HP вампира (BattleScreen.php и test_battle_vampire_hp_ui.php) сохранено, не является изменением журнала этого этапа. Commit/push/deploy не выполнялись.
