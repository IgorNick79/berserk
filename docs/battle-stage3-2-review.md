# Berserk — Stage 3.2: финальный review и закрепление решений

Дата: 20 октября 2026. Ветка `codex/battle-replay-stage3`.

**Результат review: блокирующих дефектов в проверенном diff R1–R3 не обнаружено.** Исправления и относящиеся к ним тесты закреплены отдельным коммитом:

`9e5e57e6fd6db044323cd413a1704fe29ced0c5e`

Сообщение: `fix(battle): preserve combat instant results and resolve pending cancellations`.

Baseline до исправлений: `6be7d6875b7ac99c98b88b29061b0eba4dc6ee03`. Финальный коммит кода содержит ровно **7 src-файлов и 3 тестовых файла**. Конфигураций, datadir, storage, временных hooks, миграций или архивов в этом коммите нет. Архитектура/отчёты/evidence сохраняются отдельно от коммита исправлений.

## Проверенный diff

Просмотрены все изменения исходников и связанные вызовы/сериализация:

| Файл | Вывод review |
|---|---|
| src/Core/Engine.php | Регистрирует успешно применённые level transformations текущего combat. Это локальная механика пересчёта, не новая система исполнения/логирования. Legacy attacker override учитывается при добавлении следующего effect |
| src/Core/StrikeResolver.php | Пересчитывает базовую таблицу и чисто сворачивает сохранённые преобразования. Общий freeze снят; recalc не повторяет расходы, callbacks, summaries или урон. Legacy marker не блокирует defender recomputation |
| src/Core/ActionResolver.php | Изолированная делегация отмены WoundTransfer; запрет cancel обязательного forced strike сохранён |
| src/Core/WoundTransferProcessor.php | Проверяет владельца и соответствие paused queue item; refund/reopen однократны при сохранённом command identity; снимается соответствующее ожидание и продолжается штатный processor |
| src/Core/InstantProcessor.php | Optional closeSource=false только для cancel, успешное completePausedCombatItem по умолчанию сохраняет прежнее закрытие источника. Соседние items не отбрасываются; продолжаются в существующем порядке |
| src/Core/Choice/ValhallaPickChoice.php | UI cancel соответствует server commandTypes и специальному обработчику |
| src/Core/ValhallaProcessor.php | Cancel прекращает остаток pending, заканчивает его subtask и штатно продолжает фазу. Не откатывает выполненные эффекты и не возвращает использованное удвоение Рагнара |

`GameState.fromArray/toArray` уже сохраняют battle целиком; новые base_result/strike_level_effects проходят тем же JSON-путём. Отдельная сериализация или схема БД не добавлялась. Прямой review и тест с реальным reload подтвердили различие base medium / effective weak и точное сохранение списка `[attack, reduce_one]`.

Проверены неизменность GameLog, CommandJournalRepository, GameRepository, схем/миграций, controller, шаблонов и правил Гидры относительно baseline. Порядок фаз и LIFO в InstantProcessor не менялся. Поиск диагностических маркеров Stage 3 (`stage3_probe`, initial-before-start-effects, after-local-combat-before-followup) в рабочих src не обнаружил совпадений. SHA256 всех **163 src PHP-файлов** совпадают с финальной протестированной копией.

## Замечания review и выполненная доработка

Замечания не потребовали дополнительного изменения runtime:

1. В прежних проверках R1 сохранение metadata проверялось главным образом через итоговый HP и количество effect entries. Добавлены точное сравнение base_result/strike_level_effects после roundtrip и DB reload, а также проверка чистого повторного recalc: нет новых summaries, расходов или ран.
2. Для R2 усилена проверка сохранности соседнего queue item и предыдущей summary. Добавлен отрицательный сценарий: если pending не соответствует queue[0], cancel отклоняется, очередь/ожидание/coins сохраняются.
3. Архитектурная формулировка H1 в Stage 3/3.1 устарела после окончательного решения Lead. В матрицу внесён самостоятельный End-step; старые обсуждения сохранены с явным указанием, что они исторические. Авторитетная норма вынесена в `docs/battle-replay-action-lifecycle-spec.md`.

Файлы тестов: `tools/stage31_fixtures.php`, `tools/test_stage31_fixes.php`, `tools/test_stage31_persistence.php`. В Stage 3.2 усилены два последних файла. Синтетические fixtures не содержат реальные матчи и не разрешают запуск теста в checkout с .git.

## Повторная изолированная проверка

Повторно использовано ранее доказанное отдельное окружение: `C:/Users/mi/Documents/Berserk/stage3-1-verification/app`, собственный datadir и storage. Запущен только lab mysqld с --no-defaults, bind=127.0.0.1, port=33388. Перед тестами повторно проверены SELECT @@datadir/@@port/DATABASE(), права stage31 только на berserk_stage31.* и InnoDB для games. Рабочий config не использовался. PHP 8.1.9, MariaDB 10.8.4, отдельный ini/sessions/error log; PHPRC контролирует конфиг дочерних endpoint процессов.

| Проверки | Фактический результат Stage 3.2 |
|---|---|
| Адресные R1/R2/R3/H1 и serialization | **33 PASS**, включая два новых review cases |
| Настоящие InnoDB/Command Journal сценарии | **6 PASS** |
| Существующие regression suites | **18 PASS**, exit=0, без SKIP |
| PHP lint | **10/10**: семь изменённых src и три тестовых файла |
| git diff --check / staged diff --check | Успешно |
| Всего учётных единиц выполнения | **57**, failed=0, skipped=0, blocked=0 |

Suites считаются единицами отдельно от их внутренних assertions; число assertions повторно не суммируется как число сценариев. Повторный запуск не заявляется как новое воспроизведение исходного baseline: результаты до исправлений сохранены в Stage 3.1 evidence.

18 повторённых наборов: test_hydra, test_ragnar, test_combat_instant_phases, test_degasca, test_bon_and_berrow, test_swamp_children, test_movement_effects, test_forced_directional_movement, test_volkhv_wound_transfer, test_resign_pending, test_nested_combat_ownership, test_command_journal_repository, test_battle_command_endpoint, test_turn_timer, test_informational_wait, test_turn_timeout, test_pending_wait_timer, test_turn_instant_stack.

Значимые подтверждения:

- R1: reduce_one заканчивается medium/3 ранами, defender set — strong/4 ранами; phase/LIFO, redirect, dice pause/resume проходят; три recalc не накапливают reduction и не повторяют effects.
- R2: отмена на четырёх этапах выбора, refund, следующее wounds pending, предыдущие summaries и автоматический ACK проходят. Retry того же committed command_id не отменяет следующий item и не даёт второй refund.
- R3: обычная/удвоенная отмена, отмена второго повтора после первого HP19, разные цели, исчезнувшие цели, wrong owner, phase continuation и terminal проходят. Потраченный repeat остаётся потраченным.
- Hydra End→непосредственно end_turn: closed **false → true → true**, раны первой цели сохраняются, следующего удара нет; старый JSON первого боя неизменён. Natural end закрывает карту внутри последнего боя.

Evidence Stage 3.2: `docs/evidence/stage32/summary.json` и `stage32-evidence.zip` с outputs, точными диагностическими состояниями, lint и source hash manifest. Архив содержит результаты, не конфиги/БД/датадир. Старые отчёты и архивы Stage 3/3.1 сохранены без удаления.

## Закреплённое решение Гидры

Новая норма утверждена пользователем как решение Project Lead и внесена в спецификацию и матрицу:

- Каждый завершённый бой — отдельный Replay Step с фактическим состоянием.
- Добровольный успешный attack_series_end — **самостоятельное игровое действие и отдельный snapshot с closed=true**, не новое сражение.
- Предыдущий snapshot не изменяется. End остаётся отдельным шагом даже перед немедленным end_turn; после завершённого перехода хода идёт его итоговый кадр.
- Natural end внутри последнего боя включается в его кадр; дополнительного шага нет.
- Повторный/отклонённый запрос без нового подтверждённого действия не создаёт snapshot.

Ранее описанный конфликт H1 разрешён этим решением: дополнительное реальное действие End теперь прямо допускает собственное состояние. Никакого искусственного уровня атак, изменения механики или реализации captures на Stage 3.2 не добавлено.

## Ограничения

Нет исчерпывающей аттестации всех сочетаний карточных props и сложных nested Coin/Degasca/death chains. Старый движок мог уже потерять reductions/defender overrides — отсутствующие исторические данные не восстанавливаются автоматически. Гипотетические новые timings вне действующего порядка фаз требуют отдельного решения правил.

Проверено реальное InnoDB persistence и controller в CLI subprocess; физические сетевые разрывы, lost COMMIT acknowledgement, power loss и стресс всех deadlocks не моделировались. Новый command_id не приравнивается к retry прежней команды; существующая pending identity не перерабатывалась.

Production HEAD/окружение не подтверждались. Merge в main, push/deploy и Stage 4 не выполнялись. Action Lifecycle Recorder, event/snapshot tables, миграция GameLog и интерфейс реплея не разрабатывались.

Итог: исправления R1–R3 готовы к дальнейшей интеграции по отдельному решению Lead; архитектурная норма Hydra End закреплена, история проверки сохранена.
