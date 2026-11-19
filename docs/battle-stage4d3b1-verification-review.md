# Stage 4D.3B.1 — итоговая регрессионная и HTTP-проверка

Основа: `f7454d2`. Проверка завершена 19.11.2026. Этот отчёт закрывает ограничения первоначального `battle-stage4d3b1-health-events.md` в части перечисленных ниже тестов. Новый health runtime во время проверки **не изменялся**: SHA-256 восьми файлов совпали с исходной проверяемой версией, сохранённой в source-only стенде. Изменены только тесты и документация. Stage4D.3B.2 не начинался.

## 1. Идентичность пяти ошибок

Четыре комбинации на набор: исходный `main` и health-патч, каждое два раза. В варианте «original» использованы исходные версии тестов и stage31_fixtures из Git, а не обновлённые тесты. Для сравнения извлечены именно сообщение ошибки и exit code; абсолютный путь/stack trace не выдаётся за идентичный байтовый stdout.

| Набор | Точное сообщение original | Exit на main / patch | Причина и адаптация теста |
|---|---|---|---|
| test_stage31_fixes | `FAIL R2 combat cancel at source "selected stage"` | 1 / 1 | Hermit автоматически выбирает единственного донора и открывает amount. Для проверки source создана настоящая неоднозначность: два раненых допустимых донора. Остальные три стадии не менялись; проверяются отмена, HP, расход/возврат и повтор |
| test_nested_combat_ownership | `Auto target completion resumes combat owner` | 255 / 255 | Выбор цели auto-shot теперь создаёт собственный shot.results с ACK, а не немедленно освобождает родительский бой. Тест подтверждает реальный дочерний результат и затем родительский, сохраняя проверки ownership, таймера и завершения |
| test_pending_wait_timer | `New handler requires ownership coverage: Berserk\Core\Choice\SymbioteSeatingChoice` | 255 / 255 | Явный перечень зарегистрированных pending устарел. Добавлены pending_symbiote_seating и pending_flying_preparation; общий контракт owner/таймера применяется и к ним. Проверка полноты перечня не удалялась |
| test_gloomy_noblewoman | `regeneration_does_not_consume_first turn_ack: Подтверждение устарело` | 255 / 255 | turn_ack требует текущий ack_id из TurnPhaseAck. После исправления transport-helper раскрылась следующая устаревшая команда: Dar жизни больше не принимает amount/enemy/ally одним шагом. Helper выполняет три настоящие команды, окончательные HP/монеты и все ожидания JSON-case сохранены |
| test_ochi_informer_multi_heal | `Existing cancellation URL` | 255 / 255 | Цели перенесены из form.items в cardSelection.candidates; cancel URL включает `&mode=`. Проверки целей теперь реально итерируют кандидатов и проверяют escaping/координаты; URL проверяется на имеющуюся команду отмены, реальные cancel/retry/restart/лечение сохранены |

Все пять исходных сообщений совпали во всех четырёх запусках каждого набора. Пять сбоев не вызваны health-патчем. В Ochi original также выводились warnings о старом form.items; адаптация устраняет не только финальный assert, но и этот пропуск проверок кандидатов.

## 2. Что было скрыто первым сбоем

После адаптации протоколов запущены **все 16 тематических suites на main и патче, дважды** — 64 успешных запуска. Вместе с 20 original-запусками матрица содержит 84 записи. Не выполнено «просто пропустить assertion»: сценарии доведены до конца с действующими командами и прежними проверками результата.

- Stage3.1: все проверки, включая source/amount/target/target_amount, выполняются.
- Nested ownership: все 10 детерминированных путей выполняются, включая дочерний auto-shot и родительский результат.
- Pending wait: проверяется каждый зарегистрированный handler, включая новые generic pending. Карты второго сета не загружались.
- Gloomy noblewoman: **67 cases, 1274 checks PASS**, включая пути после старого первого failure и актуальные последовательные выборы Life Gift.
- Ochi/multi-heal: обработчики отмены, повтор, возобновление лечения, оба направления координат и notice проходят.

Остальные suites: Hydra, Ragnar, combat instant phases, Volkhv WoundTransfer, turn instant stack, turn timer, LuckTest Recorder atomicity, LiveJournal, journal gap causes, restoration, Kersam/Satyr/WildHealer — PASS. Это адресная регрессия health/pending/журнала, не заявление о запуске каждого теста всего репозитория.

**22 парных health-сценария повторены — PASS**: GameState DTO и результаты действий с Recorder и без него совпали. Отдельный дефект боевого расчёта, авторизации или UI этим прогоном не выявлен; побочные исправления runtime не делались.

## 3. Почему устарел HTTP harness

Первый отказ 400 не был ошибкой лобби: `www/lobby.php` принимает create с обязательным 32-символьным `creation_key`, выданным LobbyWizard. Старый тест отправлял только action/csrf/setup_mode.

Тестовый Client теперь получает настоящий `/lobby.php?new=1`, извлекает hidden creation_key и отправляет форму. Не добавлены обходы в рабочее лобби. Тестовый bootstrap применяет уже существующие миграции lobby_wizard и account_ui_preferences (включая сообщения) и выдаёт предусмотренные DML-права synthetic auth principal. В фикстуру cards добавлен существующий release_id, который читает DeckView. Runtime DDL/TRIGGER права не выдавались; все миграции запускались исключительно на проверенном лабораторном datadir.

Другие устаревшие ожидания browser harness:

- `framenavigated` считает изменения URL/fragment; теперь reload считает реальные document navigation requests, плюс проверяется сохранённость DOM-узла журнала.
- `.textContent` не содержит чисел SVG-кубиков (они в alt), а координаты представлены римскими бейджами. Тест восстанавливает доступные значения из alt/aria-label и строго сравнивает их с API. Рабочий JS/CSS не менялся.

## 4. Полный доступный HTTP/SQL-прогон

**96 проверок PASS.** Настоящие Auth HTTP сессии четырёх synthetic accounts, обычное создание комнаты через актуальный wizard, два участника, посторонний, membership и side spoof, обязательная metadata, preparation/placement, move, strike, dice, pending/ACK, end_turn, timeout, resign и completed reading. Проверены quick-game, non-quick LuckTest, grid/Solomon, no-progress polling, повтор, stale CAS, rollback состояния/истории, отсутствие late backfill и отключения журнала конфигом.

Здоровье дополнено test-only `health_http_probe.php`: реальный GameRepository/CommandJournal callback исполняет CardHealing, DamageResolver и WoundTransferProcessor в управляемой позиции с определениями первого сета. Карты и их отображаемые имена вставляются в лабораторный справочник из versioned fixture; серверные события не подставляются. Это тест транзакционного разрешения процессоров и последующего настоящего HTTP/UI чтения, **не** ручное прохождение каждого выбора навыка через браузер.

Сохранены ровно по одному `health.maximum`, `health.replaced`, `health.transfer_out`, `health.prevented`, `health.armor`. Раны/смерть идут через общие hooks; дополнительных копий этих фактов health-probe не создаёт. Последовательность сохранённых типов:

`card.healed → health.replaced → health.prevented → health.armor → combat.wounds → combat.death → health.maximum → card.healed → health.transfer_out → combat.wounds`

Между ними допускаются существующие технические scopes/gap; они не выдаются за дополнительные health-факты. Counts пяти типов проверены на конкретном journal_id.

Примеры из реального API лабораторного боя:

- `Тролль восстанавливает 2 HP.`
- `Серк: вместо лечения карта открывается.`
- `Тролль: предотвращено ран — 2.`
- `Тролль: броня поглотила 1 ед. урона.`
- `Жжраг: Трупоедство — максимум HP 10 → 13.`
- `Жжраг восстанавливает 12 HP. Трупоедство.`
- `Тролль передаёт раны → Кочевник: восстановлено 1 HP.`

Источник лечения скрыт, имя Лешего не появляется в выдаче противнику. Никакие raw source payload поля не добавлены в API. Две истории отличаются (20/19 видимых строк); сохранённый cursor обеих 52. Полные проекции и точные строки приложены.

## 5. Браузеры, дубли и повторное чтение

Два настоящих Edge/Playwright контекста с независимой авторизацией — PASS. Каждый получает свои строки из настоящего battle-journal.php. Включая все пять health-типов, доступный DOM совпадает со всем nongap API prefix. После reload и offline/online совпадение сохраняется, дубликатов seq нет. Журнал завершённого боя доступен обоим участникам.

Отдельная live-проверка: две реальные команды end_turn, каждая панель 1→5 строк, duplicates0, DOM panel preserved, document reloads0. API не мокался.

Свежая отдельная проверка отказа INSERT **card.healed** — PASS: CHECK добавляется администратором только лабораторной схемы, игровой DML principal остаётся без ALTER. Ошибка откатывает game JSON/version и history, та же processing-команда затем сохраняет ровно одно лечение; повтор регистрации возвращает committed. Повторное чтение обоими участниками неизменно.

## 6. Изменённые файлы этой проверки

Только тесты: `tools/stage31_fixtures.php`, `test_stage31_fixes.php`, `test_nested_combat_ownership.php`, `test_pending_wait_timer.php`, `test_gloomy_noblewoman.php`, `test_ochi_informer_multi_heal.php`, `test_live_journal_restoration_http.py`, `test_live_journal_restoration_browser.cjs`, `test_live_journal_restoration_live_browser.cjs`; новый `tools/health_http_probe.php`. Отчёт и evidence. Все восемь runtime файлов health-патча неизменны относительно предыдущего этапа проверки.

## 7. Воспроизведение и ограничения

Использованы PHP8.1, MariaDB10.8 на собственном `health-stage4d3-lab/tmp/live-journal-restore-lab/data`, `--no-defaults`, loopback33671, PHP HTTP33672–33675. Identity порта/datadir проверена до SQL. Реальные production и локальная рабочая berserk не изменялись. Из БД ранее читались только definitions первого сета. Для повторного запуска tools/test_live_journal_restoration_http.py требуется source-only копия без .git и без private configs (harness это проверяет), существующие PHP/MariaDB/Node/Edge и Playwright. Новые тесты запускать из копии, не из рабочего repo.

Ограничения: нет проверки на production, нет повторной ручной приёмки всех комбинаций карт, нет полного браузерного прохождения каждой способности и полного тестового массива всего проекта. Транзакционный health-probe задаёт позицию и вызывает настоящие процессоры в callback; полноценный HTTP путь обычного боя проверяется отдельно в том же прогоне. Старые записи не изменялись. Пять известных failures закрыты актуализацией тестов, а не исправлениями механики.

Артефакты: `docs/evidence/stage4d3b1-review/`: матрица84, HTTP96, 22 пары, две server histories, browser live/reload, журналы тестов/скриншоты, SHA runtime и syntax checks. Секреты, БД, sessions и приватные конфигурации не входят в evidence. Commit/merge/push/deploy не выполнялись, перехода к Stage4D.3B.2 нет.
