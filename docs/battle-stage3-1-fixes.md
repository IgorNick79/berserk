# Berserk — Stage 3.1: R1–R3 и завершение серии Гидры

> **Stage 3.2, окончательное решение Lead:** добровольный End Гидры — самостоятельное действие и отдельный snapshot/Replay Step с closed=true. Предыдущий кадр не меняется; естественный End входит в кадр последнего боя. Предварительные ограничения/варианты H1 ниже сохранены как история исследования и заменены [согласованной спецификацией](battle-replay-action-lifecycle-spec.md).

Дата: 20 октября 2026. Ветка: `codex/battle-replay-stage3`. Исходный HEAD: `6be7d6875b7ac99c98b88b29061b0eba4dc6ee03`.

## Результат

R1–R3 исправлены независимо, без общего рефакторинга resolution. На неизменённом baseline адресные тесты дали **20 FAIL / 11 PASS**; тот же финальный набор с исправлениями — **31 PASS / 0 FAIL**. Дополнительно прошли **6 сценариев с реальной InnoDB/Command Journal** и **18 существующих наборов регрессии**. Итого после исправлений: **55 учётных единиц**, без failed/skipped/blocked; suite считается одной единицей, его внутренние assertions не прибавляются повторно.

Для R3 Project Lead явно выбрал: **разрешить отмену оставшихся применений, сохранив выполненные эффекты и расход повтора Рагнара**. Реализован именно этот вариант.

Гидра уже на baseline корректно завершала серию, включая отдельный сценарий «добровольно завершить → без действий картами сразу end_turn». Её игровая механика не изменена. Подтверждён архитектурный конфликт представления позднего изменения closed при одновременном запрете дополнительного состояния и перезаписи первого кадра; варианты решения приведены ниже.

Ни Recorder, ни игровые event/snapshot tables, ни UI журналов/реплея не реализовывались. GameLog и его storage не изменялись и не отключались. Stage 4 не начинался. Изменения подготовлены в рабочем дереве, без merge/deploy.

## Исходники и изоляция

Рабочий репозиторий: `C:/Users/mi/Documents/Codex/berserk`. До исправлений tracked source diff к baseline Stage 3 был пуст; в рабочем дереве находились только ранее созданные отчёт Stage 3 и evidence. Эти артефакты сохранены.

Для Stage 3.1 создана новая копия **`C:/Users/mi/Documents/Berserk/stage3-1-verification/app`**, отдельно от Stage 3. Baseline-app содержит исходные src для запуска идентичных адресных тестов до исправлений. Из рабочего проекта копировались src/tools/templates/www/docs; config, storage, .git, реальные матчи и рабочие дампы не копировались.

| Параметр | Проверенное значение |
|---|---|
| PHP | 8.1.9, отдельный php.ini, mysqli/mbstring |
| MariaDB | 10.8.4, новый mysqld с `--no-defaults`, bind=127.0.0.1, порт **33388** |
| datadir | `C:/Users/mi/Documents/Berserk/stage3-1-verification/data/` |
| DB/user | **berserk_stage31 / stage31**, права пользователя только на эту БД |
| Хранилища | Собственные app/storage, baseline-app/storage, sessions, results |
| SQL | Новая синтетическая games InnoDB; штатный journal создаётся только в этой БД; cards/elements синтетические |
| Проверка подключения | SELECT @@datadir, @@port, DATABASE(); дополнительные runtime guards в DB-тесте |
| Исходники | SHA256 **163 src PHP-файлов**; только 7 целевых файлов отличаются от baseline; переданные в ветку bytes совпадают с протестированной копией |

В Stage 3.1 **не использовались временные probe-вставки в движок**. Диагностические копии состояния создаются только тестами. Существующие Engine/GameLog пишут обычные тестовые логи лишь в копии. Тесты отказываются запускаться в checkout с .git и требуют `BERSERK_STAGE31_ISOLATED=1`.

Evidence: `docs/evidence/stage31/summary.json` и `stage31-evidence.zip`. В архиве before.json/after.json/db-after.json, полные диагностические состояния, stdout 18 suites, integrity manifest и REPRODUCTION.md. Конфигурация production не читалась; production HEAD/окружение не проверялись. Сервис тестовой БД после проверки останавливается, datadir и доказательства сохраняются.

## R1. Перезапись уровня удара

### До исправления

Адресные сценарии воспроизводят ошибки текущего baseline, а не полагаются только на старый отчёт:

| Сценарий | Baseline | После |
|---|---|---|
| Attack reduce_one, полный main resolve | strong→medium в summary, затем strong/4 раны; тест FAIL | **medium/3 раны**, HP цели **17** |
| Defender set strong при dice 4/2 | Defender set теряется, ответный урон не соответствует strong; FAIL | Ответ **4**, HP атакующего **16**, атака medium/3, HP цели **17** |
| Attacker set strong | PASS, target HP16 | **PASS**, target HP16 |
| Две reduce_one одной фазы | Уменьшения теряются; FAIL | strong→medium→weak, target HP**18**, sequence **[1,0]** |
| Redirect до/после level change | Последующий пересчёт теряет reduction; FAIL | Новый target HP**17**, старый target HP**20** |
| Изменение dice перед reduction | Пересчёт теряет reduction; FAIL | Dice=4, medium→weak |
| Повторный recalc после JSON roundtrip | Возвращается исходный уровень; FAIL | Три recalc сохраняют medium, без накопительного уменьшения |
| Attacker set и новый базовый defender result | Глобальный guard запрещает нужный пересчёт; FAIL | Attack set сохраняется, defender/base пересчитывается |
| Dice pause/resume + reduction | Финальные раны не соответствуют применённому effect; FAIL | Dice=4, target HP**18**, ровно две summaries, повтор выбора отклонён |

Тесты: `tools/test_stage31_fixes.php`, группа R1; сохранённый путь через InnoDB — `tools/test_stage31_persistence.php`, `DB R1 dice pause resume metadata and final HP`. Existing combat phases suite дополнительно прошёл свой phase/LIFO/dice matrix.

### Причина и семантика

`Engine.applyCombatEffect — strike_level` менял result непосредственно; `StrikeResolver.recalcTable` восстанавливал таблицу из dice/modifiers. Старый `level_overridden` выставлялся лишь для attacker set и блокировал **весь** recalc. Для reductions/defender set защиты не было; для attacker set блокировалась также необходимая базовая часть таблицы.

Минимальное разделение:

- `base_result` — чистая strikeTable от текущих dice/modifiers/защитника;
- `strike_level_effects` — упорядоченные **уже успешно разрешённые** преобразования уровня: side, set(value) или reduce_one;
- `result` — базовая таблица с последовательным применением этих преобразований.

Это локальные данные текущего combat, необходимые расчёту механики; не Recorder и не журнал боя. Они сериализуются существующим games JSON вместе со strike.

При recalc заново вычисляется base, затем выполняется **чистое свёртывание** преобразований в фактическом порядке разрешения. Не выполняются второй раз инстанты, расходы, callbacks, summaries, раны или раскрытия. Повторный recalc не накапливает reductions, потому что всегда начинает с base. Winner/выбор режима по исходным кубикам не переопределён новой логикой.

Успешный set фиксирует уровень соответствующей стороны; reduction уменьшает её уровень на одну ступень. Сохраняется прежний запрет создавать удар из промаха. Phase ordering и LIFO не меняются: порядок списка получается из существующего разрешения InstantProcessor, а не из новой сортировки.

Глобальный early return по level_overridden удалён. Сам старый marker сохранён для совместимости существующего post-strike пути; он больше не блокирует recalc. Для уже сохранённых старым движком strike с attacker override и без списка преобразований есть локальная совместимость: сохранившийся attack result преобразуется в set, без замораживания defender table. Уже потерянные старым кодом reductions/defender effects автоматически восстановить из отсутствующих данных нельзя.

### Изменённые методы

- `src/Core/Engine.php — applyCombatEffect`: сохраняет успешные level changes и их порядок, поддерживает legacy override при последующем новом effect.
- `src/Core/StrikeResolver.php — resolve/recalcTable`: хранит base_result, пересчитывает base и сворачивает принятые изменения без общего lock-флага.

Redirect после level effect в адресном тесте — проверка нижнего допустимого applyCombatEffect/recalc пути. Она **не** вводит право менять порядок фаз и не утверждает существование конкретной production карты с таким timing. Обычный phase порядок redirect/dice до power/value остаётся прежним. Для гипотетических новых карточных комбинаций с изменением кубиков после power и превращением попадания в промах потребуются отдельные правила Lead; новые возможности/фазы здесь не добавлены.

## R2. Отмена переноса ран

### До исправления

На baseline четыре адресных сценария cancel в source/amount/target/target_amount и сценарий следующего item падают. При cancel исчезает pending_wound_transfer, но текущий instant_resolution.queue item не снимается; parent уже имел подтверждения, поэтому повторный confirm не завершает resolution. Ранее применённые раны/summary при этом существуют.

### Исправление

`ActionResolver.cancelPending` делегирует только этот тип ожидания `WoundTransferProcessor.cancel`. Запрет отмены обязательного forced_strike сохранён.

Новый cancel:

1. Проверяет owner. Для on_finish=combat_resolution дополнительно проверяет соответствие queue[0] источнику и типу redistribute_wounds, чтобы не снять чужой item.
2. Возвращает реально предусмотренную положительную coins_cost и синхронизирует coin bonus, открывает источник, удаляет in_stack — сохранена существующая семантика cancel/refund.
3. Удаляет только pending переноса; неперенесённый план не применяется, прежние раны/эффекты не откатываются.
4. Для combat вызывает существующий `completePausedCombatItem(false, reason, transferred=0, closeSource=false)`. Item получает skipped/cancelled summary, снимается, очередь штатно продолжается. Source не закрывается заново после отмены.
5. Вне combat продолжает штатную turn phase, если она есть.

`InstantProcessor.completePausedCombatItem` получил единственный необязательный параметр closeSource=true; действующий путь успешного применения остаётся прежним. Отмена передаёт false, чтобы сохранить reopen, а не восстанавливать состояние карты после чужих последующих эффектов.

### Результаты

| Проверка | После исправления |
|---|---|
| Cancel на source / amount / target / target_amount | **4 PASS**: donor HP18 сохранён, получатель HP20, source открыт, pending/зависший combat отсутствуют |
| Main-phase coins cost=2 из 3 | **PASS**: до cancel 1, после 3, повтор не даёт второй refund |
| Другой wounds item после отменённого | **PASS**: создаётся pending следующего source; ранняя summary не повторяется; после отмены второго combat очищен |
| DB cancel + повтор того же command_id | **PASS**: retry возвращает ту же committed journal row и **не отменяет следующий item** |
| DB refund + retry | **PASS**, coins=3 |
| Cancel до ACK основного результата + автоматический ACK | **PASS**: возврат в results, owner ACK, реальный InformationalAutoAck на deadline завершает бой, donor HP18 |

Тесты: группы R2 адресного набора и три DB R2 cases. Между командами используется JSON roundtrip; в DB случаях — реальная загрузка games и штатная транзакция. Новая исходная атака не запускается; прошлые queue items не исполняются повторно.

Изменённые файлы: `ActionResolver.php — cancelPending`, `WoundTransferProcessor.php — cancel`, `InstantProcessor.php — completePausedCombatItem`. Остальная логика pending не рефакторилась.

## R3. Отмена оставшейся Вальхаллы

### Согласование и baseline

В docs/05_cards.md cancel был указан как разрешённый, UI показывал кнопку, но commandTypes handler её не принимал. Нельзя было однозначно решить, менять ли сервер или убрать UI, поэтому решение запрошено у Lead до изменения семантики.

Полученный ответ: **разрешить отмену оставшихся применений; уже выполненные эффекты и расход Рагнара сохранить**.

На baseline адресные проверки ordinary/double cancel, cancel второго повтора, исчезнувших целей, phase continuation и согласованности commandTypes падали: «Ожидается выбор», pending сохранялся. Неверный owner уже отклонялся — этот guard сохранён.

### Исправление

- `Choice/ValhallaPickChoice.php — commandTypes/apply`: принимает cancel_pending и направляет в специальный cancel.
- `ValhallaProcessor.php — cancel`: проверяет owner, удаляет неисполненный остаток pending, завершает принадлежащую активации subtask и вызывает штатный phase resume.
- Не меняет HP/раны/модификаторы уже выполненного применения, не сбрасывает ragnar_valhalla_double_used_this_turn и не возвращает израсходованный repeat.
- Не откатывает первую цель при отмене второго выбора. Повтор без pending отклоняется.

### Проверки

| Сценарий | Фактический результат |
|---|---|
| Ordinary cancel до первого target | PASS, HP20, pending снят |
| Double cancel до первого target | PASS, HP20, удвоение остаётся потраченным |
| Первая активация, затем cancel второй | PASS, первая цель HP19, другая HP20, pending снят, repeat spent |
| Две разные цели без cancel | PASS, HP19 / HP19 |
| После открытия выбора цели исчезли | PASS, cancel завершает stale pending |
| Другой игрок | PASS: отказ, pending сохранён |
| Cancel внутри phase.sub | PASS, subtask удалена, штатная фаза продолжилась/закончилась |
| Повтор cancel | PASS: не исполняет активацию и не даёт refunds |
| Первая цель погибает и больше нет противников | PASS, game_over/graveyard, второго выбора нет, terminal cancel отклоняется |
| DB first pick → cancel → retry command_id | PASS, первая цель HP19, repeat spent, состояние сохранено без отката |
| UI/spec и server commandTypes | PASS, кнопка с cancel_pending соответствует поддерживаемой команде |

Existing test_ragnar также прошёл двойные применения, разные цели, смерть, отсутствие кандидатов и no-target циклы. Изменений в правилах самих эффектов Вальхаллы нет.

## H1. Гидра: End и немедленный end_turn

Механика Гидры **не изменялась**: адресные сценарии H1 проходят как на исходном baseline, так и после R1–R3. Каждый отдельный законченный бой остаётся самостоятельным результатом, без искусственного дополнительного уровня сражений.

### Обязательная трасса без промежуточных действий картами

Синтетическая Гидра, две соседние цели, unanswer; первый удар оставляет первой цели HP16, вторую не затрагивает. Состояния сохранены в after.json; DB-версия — в db-after.json (`DB:H1:*`).

| Точка | closed Гидры | Условия и поле |
|---|---:|---|
| После полного завершения первого боя/ACK | **false** | target2 HP16, target4 HP20, strike отсутствует, pending_attack_series_choice присутствует |
| После добровольного attack_series_end | **true** | pending серии/strike отсутствуют; attack_series flags очищены; прежние раны сохранены |
| Сразу после следующей команды end_turn | **true** | active=player; дополнительных атак/действий карт не было |
| После штатного перехода к следующему игроку | **true** | turn phase завершена, target2 HP16, target4 HP20, нет повторного урона |

В конкретной простой конфигурации переход завершился внутри end_turn; отдельная точка «после команды» и «следующий игрок готов» имеет то же поле. Это не означает, что любой end_turn не имеет дополнительных phase задач. Existing timer/phase suites проверяют другие варианты.

В DB сценарии после каждой команды выполнялась загрузка persisted state; End повторён с **тем же command_id** и не закрыл/не атаковал что-либо повторно. Старый JSON первого боя сохранён отдельной строкой в тесте: в нём closed=false даже после End и end_turn. Он не переписывался.

### Остальные случаи

- **Добровольный End:** сохранены раны первого боя, Гидра закрыта, pending снят, дополнительных ударов нет — PASS.
- **Естественный End:** больше нет допустимых соседних целей, finishAttackSeries закрывает карту при окончании последнего боя — PASS; его итог уже включает closed=true, отдельный technical step не нужен.
- **Гибель Гидры:** после ответа карта ушла в graveyard, продолжения серии нет — PASS.
- **Убийство последнего противника:** game_over внутри серии, дальнейшая атака отклонена, фактический strike может остаться в terminal state — PASS. Никакой «дочистки ради реплея» не добавлено.
- Existing test_hydra и test_turn_timeout PASS: обычные ограничения, невозможность продолжения/конец серии, таймерные guards сохранились.

### Конфликт будущего отображения closed

Трасса доказывает **три разных временных факта**: бой завершён с открытой Гидрой → игрок позже заканчивает серию и закрывает её → позже начинается переход хода. Это не один момент и не дополнительное сражение.

Если одновременно требовать (а) immutable кадр боя сразу после его завершения, (б) никакого дополнительного сохранённого состояния/перехода на End, (в) точное покадровое отображение closed в момент End, требования несовместимы. Исправление игровой механики здесь не нужно и конфликт не устраняет.

Минимальные варианты для Lead:

1. **Строго только реальные Replay Steps, без дополнительного кадра End.** Сохранить факт закрытия в структурированной истории, следующий естественный кадр реального действия/перехода хода содержит closed=true. При просмотре явно сообщать: «После этого боя игрок завершил серию; Гидра закрылась до конца хода». Не выдавать более поздний turn-state за состояние сразу после боя. Недостаток: нет отдельного точного визуального кадра End; это осознанная разреженная история.
2. **Если точная визуальная хронология обязательна:** отдельно согласовать хранение дополнительного состояния поля/transition checkpoint с closed=true, не называя его новым сражением и не изменяя immutable кадр боя. Это изменение контракта реплея, а не скрытый фиктивный шаг «Завершение серии». Оно требует разрешения Lead и сейчас **не реализовано**.

Рекомендуется принять вариант 1 для ограниченного первого релиза при сохранении явного события закрытия, если запрет дополнительного кадра остаётся жёстким. При требовании точного кадра нужен вариант 2 и разрешение дополнительного состояния. Нельзя молча подменить первое поле закрытым либо отнести закрытие только к end_turn. Если после End вообще нет дальнейших действий до terminal, обязательный final-state в будущем также должен отразить фактический closed.

## Итоги регрессии и InnoDB

### Адресные проверки

- `tools/test_stage31_fixes.php` + `stage31_fixtures.php`: **31 case**, baseline **20 FAIL / 11 PASS**, исправленный код **31 PASS**. Это идентичные финальные tests/fixtures на двух версиях src, а не сравнение разных тестов.
- `tools/test_stage31_persistence.php`: **6 PASS** — R1 pause/resume/HP, R2 очередь/retry, R2 refund/retry, R3 partial cancel/retry, Hydra End→end_turn, R2 после cancel с реальным automatic ACK.
- Между in-memory командами выполняется JSON serialization; DB cases используют GameRepository.executeLockedCommand с Engine и настоящими journal rows, reload после каждой команды. Одинаковый command_id не выполняется повторно; новый valid id может обозначать следующее действие.

### Существующие наборы

Все следующие suites завершились exit=0 без SKIP:

| Область | Наборы |
|---|---|
| Гидра/Рагнар/боевые инстанты | test_hydra, test_ragnar, test_combat_instant_phases |
| Дегаска/Coin/вложенные последствия | test_degasca, test_bon_and_berrow (**337 assertions**), test_swamp_children (**399 assertions**), test_nested_combat_ownership (**10 deterministic paths**) |
| Перемещения/раны | test_movement_effects, test_forced_directional_movement, test_volkhv_wound_transfer |
| Терминальные состояния | test_resign_pending (**48 surrender cases**, дополнительные guards/repeats) |
| Persistence/HTTP controller | test_command_journal_repository, test_battle_command_endpoint |
| Ход/таймер/ACK/pending | test_turn_timer, test_informational_wait, test_turn_timeout, test_pending_wait_timer, test_turn_instant_stack |

Проверены синтаксис всех 7 изменённых source-файлов и `git diff --check`. Тесты не запускались в рабочем checkout с реальным config/storage. Изоляция доказана до выполнения.

## Состав изменений

| Файл / методы | Независимая цель |
|---|---|
| src/Core/Engine.php — applyCombatEffect | R1: сохранить разрешённые level transformations |
| src/Core/StrikeResolver.php — resolve/recalcTable | R1: разделить base/result, чистый пересчёт без общего freeze |
| src/Core/ActionResolver.php — cancelPending | R2: направить отмену переноса в её processor |
| src/Core/WoundTransferProcessor.php — cancel | R2: refund/reopen/skip current item/resume |
| src/Core/InstantProcessor.php — completePausedCombatItem | R2: сохранить reopened source при отмене, default успешного применения прежний |
| src/Core/Choice/ValhallaPickChoice.php — commandTypes/apply | R3: согласовать UI-команду и server routing |
| src/Core/ValhallaProcessor.php — cancel | R3: прекратить только оставшиеся применения и продолжить фазу |
| tools/stage31_fixtures.php, test_stage31_fixes.php, test_stage31_persistence.php | Адресные воспроизводимые сценарии, guard изоляции |
| docs/battle-stage3-1-fixes.md, docs/evidence/stage31/* | Отчёт, до/после и доказательства |

GameLog, CommandJournalRepository, GameRepository, порядок фаз InstantProcessor, структура БД, UI-компоненты и игровые правила Гидры не изменены. В рабочие src не переносятся диагностические hooks Stage 3.

## Оставшиеся риски и решения Lead

1. Точное представление late closed Гидры требует выбрать один из двух контрактов выше; никакой реплейной реализации в этом исправлении нет.
2. Legacy состояние сохраняет только ту override-информацию, которая действительно была записана старым движком. Уже перезаписанные и утраченные reductions/defender effects невозможно восстановить постфактум без данных.
3. Не заявлена исчерпывающая проверка всех сочетаний карточных props, multiple nested combat/Coin/Degasca/death. Проверены адресные repro и перечисленные regression suites.
4. Redirect-after-level — тест устойчивости пересчёта на уровне supported handlers, не разрешение менять phase order. Новые неоднозначные timings, особенно late dice превращающие попадание в промах, требуют отдельного решения правил, а не догадки при добавлении карт.
5. В R2/R3 same-command retry защищён существующим Command Journal. Новая команда с новым id не является тем же retry: она может законно отменить следующий актуальный выбор. Общую систему идентичности pending здесь не перерабатывали.
6. Browser click/реальные HTTP network failures, физический lost COMMIT ACK, mysqld crash/power failure не моделировались. Endpoint suite исполняет настоящий controller в CLI subprocess, DB-тесты — настоящий InnoDB, но это не равнозначно всем сетевым сбоям.
7. Не развёрнуто на production и не сверено с неизвестным production HEAD. Merge/deploy — отдельный шаг после review.

Исправления R1–R3 и H1 verification готовы к рассмотрению Project Lead. Продолжения к Stage 4 нет.
