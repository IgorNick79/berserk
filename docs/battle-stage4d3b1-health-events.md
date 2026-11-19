# Stage 4D.3B.1 — Health & Wound Events

Последующие проверки завершены: см. [итоговый review](battle-stage4d3b1-verification-review.md). Ниже сохранён первоначальный результат реализации; ограничения старого harness закрыты в новом отчёте.

Основа: `f7454d2`, ветка `codex/battle-journal-stage4d3`. Только первый сет. Фактические определения выбранных карт прочитаны SELECT из локальной `berserk` в READ ONLY transaction и сохранены как тестовый fixture без конфигураций/паролей/игр пользователей. Реализация не меняет вычисления HP, броню, выбор целей, стоимость, очереди и pending/continuation.

## Изменённые файлы

- `src/Core/TurnPhaseProcessor.php`: hook после выполненной регенерации и существующего ActionResolution.
- `src/Core/CardHealing.php`: один writer восстановления в finish для active и passive healing; события замены открытием, ранами или предотвращения. Нулевое фактическое восстановление не пишет card.healed. «Открывается» только при настоящем переходе closed→open.
- `src/Core/ActionResolver.php`: удалён повторный hook активного лечения в callback; существующая строка применения способности сохранена.
- `src/Core/Engine.php`: удалён устаревший hook ветвей лечения после switch (текущие ветви возвращают через CardHealing). Игровой ActionResolution не менялся.
- `src/Core/DamageResolver.php`: наблюдение двух ранних блокировок; броня — фактическая разница до/после; обе ветви Трупоедства записывают максимум и восстановление. Смерть/зоны/очереди не менялись. Для новых combat.wounds сохраняется безопасный результат цели без source_id/имени скрытого атакующего; исходный action type остаётся.
- `src/Core/WoundTransferProcessor.php`: факт восстановления донора с фактическим HP delta и списком получателей; раны получателям остаются единственным существующим combat.wounds. Это не card-healing и не вызывает замены CardHealing.
- `src/Core/BattleHistory/Recorder.php`: target-only healthEvent/healed; видимый результат не исчезает из-за скрытого источника. Для transfer_out отдельные сохранённые projections включают только имена известных получателей, без recipient_ids. Полная server payload остаётся диагностической; пользовательский API её не читает.
- `src/Core/BattleHistory/JournalText.php`: текст новых фактов и причин keyword-восстановления; совместимость старых записей брони. JS/CSS/UI controls/API format не менялись.
- Тесты: `tools/test_health_journal.php`, `health-s1-fixture.json`, `health_transaction_probe.php`, `test_health_transaction_http.py`. Этот отчёт и `docs/evidence/stage4d3b1/`.

## События и покрытие

| Сценарий | До | После |
|---|---|---|
| Регенерация | ActionResolution без гарантированной истории | card.healed с фактическим amount и причиной regeneration |
| Активное/пассивное лечение | Разные writers | Один hook CardHealing::finish; card.healed только при HP delta>0 |
| Максимум HP / full HP | Нельзя заключать успех по объявленному value | Пишется фактическая восстановленная величина; no-op без card.healed |
| Замена лечением открытием | Нет полного текста | health.replaced(result=opened), только реальный переход |
| Замена лечения ранами | Общие раны | Те же единственные раны плюс health.replaced(result=wounds), без вымышленного лечения |
| Дворянка блокирует лечение | Диагностический ledger | health.replaced(result=prevented), без раскрытия скрытой карты-причины |
| Трупоедство | Смерть и ActionResolution | health.maximum при изменении максимума, затем card.healed при реальном восстановлении |
| Перенос ран | Полученные раны, пропущен результат донора | health.transfer_out с реальным восстановлением донора и видимыми получателями; один combat.wounds на получателя |
| Щит/блок ответа | Ранний return | health.prevented только при реально сработавшей проверке |
| Броня | Полное поглощение текстом, частичное без отдельного числа | health.armor с поглощённой величиной перед combat.wounds |
| Скрытый источник | Мог скрыть всё событие | Известная цель получает безопасную строку без source_id, имени и способности источника |

Новые типы: `health.maximum`, `health.replaced`, `health.transfer_out`, `health.prevented`, `health.armor`. Повторно используются `card.healed`, `combat.wounds`, `combat.death`. Для полного поглощения новым событием добавлен `armor_reported`: прежняя нулевая combat.wounds строка не дублирует броню, старые записи без флага читаются по прежним правилам.

Состояние самого Recorder является transaction-local; SQL pipeline не менялся. Не копируется ActionResolution, не запускается второй расчёт эффекта. ReplayStateBuilder/Preview не менялись; история старых боёв не переписывается.

## Примеры текстов

Примеры основаны на управляемых позициях с реальными определениями первого сета, не на production-боях:

- `Тролль восстанавливает 3 HP. Регенерация.` — когда реальный остаток до максимума составляет три.
- `Жжраг: Трупоедство — максимум HP 10 → 13.` и затем соответствующее фактическое восстановление (конкретный исход зависит от начальной позиции).
- `Серк: вместо лечения карта открывается.` — без card.healed.
- `Вожак сатиров: лечение заменено нанесением ран.` — после единственного события ран жертве.
- `Тролль передаёт раны → Кочевник: восстановлено 2 HP.`; получатель отдельно получает единственную строку фактических ран.

Если получатель переноса скрыт от смотрящего, имя не попадает в сохранённую проекцию; восстановление видимого донора остаётся. Для обычного лечения со скрытым источником текст нейтральный: известная цель восстанавливает N HP. Технические коды/имена скрытых способностей не раскрываются.

## Проверки

Source-only копия `health-stage4d3-lab`, без .git и приватных конфигураций. Рабочая berserk использовалась только для чтения определений. Боевые тесты работают в памяти и пишут диагностический storage только внутри лаборатории.

**22 парных сценария PASS:** сравниваются целиком GameState::toArray() и возвращённые результаты с Recorder и без него. Проверены лечение 1/3/99 при недостающих трёх HP, full/no-op, регенерация, частичная/полная броня, блок ответа/щит, Трупоедство немедленно/из очереди, Серк, переносы Волхва/Осклизга/Рэккена/Отшельницы, Вожак сатиров и Дворянка с настоящим pending, stale повтор выбора, скрытые healer/attacker/recipient. Проверяются amount, типы, отсутствие дублей и порядок armor→wounds, maximum→heal, wounds→replacement.

Для transfer-проверок создаётся контролируемый валидный pending и вызывается настоящий finish; это проверка границы разрешения, а не полный HTTP-клик каждого этапа способности. Отдельный старый `test_volkhv_wound_transfer` проверен регрессионно.

**Отдельный SQL probe PASS:** собственный MariaDB datadir, --no-defaults, loopback33671 и synthetic lobby_* schema; runtime DML principal без DDL. Администратор лаборатории ставит CHECK, запрещающий INSERT card.healed. Ошибка откатывает game JSON/version и history. После снятия fault та же processing-команда сохраняет ровно одно восстановление. Повтор регистрации возвращает committed; оба пользователя читают одинаковый committed prefix при повторном чтении. Probe использует настоящий GameRepository/CommandJournal/CardHealing и синтетическую callback-позицию; это не полный браузерный сценарий навыка.

Из 16 существующих PHP regression suites **11 PASS**: Hydra, Ragnar, combat instant phases, Volkhv/WoundTransfer, turn instant stack, turn timer, LuckTest Recorder atomicity, LiveJournal contracts, journal gap causes, restoration, Kersam/Satyr/WildHealer.

Пять старых suites падают **также на неизменённом f7454d2** в том же окружении:

- test_stage31_fixes: R2 combat cancel at source, selected stage.
- test_nested_combat_ownership: Auto target completion resumes combat owner.
- test_pending_wait_timer: отсутствует ownership coverage для SymbioteSeatingChoice.
- test_gloomy_noblewoman: старый turn_ack без действующего подтверждения.
- test_ochi_informer_multi_heal: ожидания старого UI/cancellation URL.

Их exit codes и baseline stdout сохранены. Сбои не исправлялись в рамках health logging. Исходные ошибки первой подготовки лаборатории (недостающие config/debug fixtures) устранены до финального baseline-сравнения.

Полный прежний restoration HTTP harness не прошёл подготовку: текущий lobby rejects его старую форму создания комнаты (400). Поэтому не заявляется повторная полная браузерная приёмка. SQL probe выделен отдельно и успешно выполнен. UI не менялся.

PHP lint изменённых PHP и новых tests, Python AST, git diff --check — PASS. Тестовый DB harness завершает свои процессы и удаляет приватные конфиги; datadir остаётся только в лаборатории и в Git не включается.

## Ограничения

Объясняются два явно указанных ранних return DamageResolver; не все защитные эффекты других processors автоматически получили полное описание. Это требует отдельной инвентаризации блока специальных атак. Глобальные ActionResolution и игровые очереди не менялись.

Нет полной UI-приёмки всех четырёх переносов через HTTP на актуальном lobby. Перенос может восстановить донору объявленную разрешённую величину даже если последующая фактическая потеря HP получателя меньше из-за его защиты — это существующая механика, патч её не меняет. Журнал показывает раздельно реальные дельты, не утверждает их равенство.

Пять воспроизводимых baseline failures остаются отдельными вопросами. Следующий блок не начат. Commit/merge/push/deploy не выполнялись.
