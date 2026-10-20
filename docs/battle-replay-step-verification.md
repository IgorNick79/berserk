# Berserk — Stage 3. Сценарная проверка границ Replay Steps

> **Stage 3.2, окончательное решение Lead:** добровольный End Гидры — самостоятельное действие и отдельный snapshot/Replay Step с closed=true. Предыдущий кадр не меняется; естественный End входит в кадр последнего боя. Предварительные ограничения/варианты H1 ниже сохранены как история исследования и заменены [согласованной спецификацией](battle-replay-action-lifecycle-spec.md).

Дата проверки: 20 октября 2026. Статус: проверка завершена, реализация Recorder/журналов/реплея не выполнялась.

## A. Исходники и изолированное окружение

По указанию пользователя работа ведётся в `C:/Users/mi/Documents/Codex/berserk`. Создана ветка **`codex/battle-replay-stage3` от `main`**, baseline HEAD **`6be7d6875b7ac99c98b88b29061b0eba4dc6ee03`**. До создания ветки рабочее дерево main было чистым. Production HEAD не опрашивался и не подтверждён.

Stage 2 исследовал `0bc26ccbc66fdf734368685a8617fe60a1b9a508` в другом worktree. Diff критических путей `src/Core` и `www/index.php` к текущему main: четыре файла, 105 insertions / 7 deletions — GameRepository, PlayerState, новый PlaymatCatalog и index. Добавлены назначение/смена playmat и соответствующий endpoint; существующие Engine, ActionResolver, StrikeResolver, InstantProcessor, pending и CommandJournalRepository не изменились. В GameRepository добавлен отдельный setPlaymat с собственной транзакцией; прежняя commitJournalCommand не изменена. Этот дополнительный writer подтверждает прежний вывод: увеличение SQL version не обязательно означает игровое действие для реплея.

Тестовая копия: `C:/Users/mi/Documents/Berserk/stage3-verification/app`. Скопированы только src/tools/templates/www/docs. Не копировались исходные config, storage, .git, пользовательские матчи или дамп рабочей БД. Созданы пустые собственные storage и sessions, синтетические карточки/матчи.

| Параметр | Фактически проверено |
|---|---|
| PHP | 8.1.9, `C:/OSPanel/modules/php/PHP_8.1/php.exe`, отдельный php.ini, mysqli/mbstring |
| MariaDB | 10.8.4, новый экземпляр mysqld с `--no-defaults`, bind 127.0.0.1, порт **33387** |
| datadir | `C:/Users/mi/Documents/Berserk/stage3-verification/data/`, подтверждён SELECT @@datadir |
| БД | **berserk_stage3**, создана с нуля, utf8mb4 |
| Аккаунт приложения | stage3, права только `berserk_stage3.*`; локальные тестовые credentials не связаны с рабочим конфигом |
| Таблицы | games — InnoDB; battle_command_journal создан штатным test repository в этой БД; синтетические cards/elements — InnoDB |
| Сессии/ошибки/логи | Только lab/sessions, lab/results и lab/app/storage |
| SQL durability | innodb_flush_log_at_trx_commit=1; физический crash/power-loss не проверялся |
| Исходники | Сверены SHA256 **163 PHP-файлов src**: совпадают с baseline, кроме четырёх объявленных диагностических изменений тестовой копии |

Изоляция установлена до запуска. Constructor DDL и GameLog выполнялись только в этой копии. Тестовые сценарии дополнительно отказываются работать при ином пути/config; основной diagnostic runner проверяет фактический порт/datadir. Рабочие src, config, storage и БД не изменялись.

**Временная инструментализация только lab-копии:** Engine — наблюдение перед GameLog/return; TurnProcessor — перед/после applyStartEffects; ValhallaProcessor — после целого применения до повтора; StrikeResolver — после локальной развязки боя перед follow-up. Это вызовы probe, копирующие toArray в диагностический файл. Они не выполняют resume/finalize/новые правила, не создают COMMIT и не добавляют production Recorder. Отдельный callback диагностического теста K4 выполняет два реальных damage effects внутри одной штатной транзакции — это тест, не новая команда приложения.

Артефакты рядом с отчётом:

- `evidence/stage3/summary.json` — результаты 13 наборов и 29 отдельных диагностических сценариев, параметры окружения.
- `evidence/stage3/stage3-evidence.zip` — сценарии, probe/точные инструкции инструментализации, SHA256 manifest, результаты конкурентных процессов, полные диагностические состояния и outputs тестов, инструкция REPRODUCTION.md.
- В zip `results/scenario-trace.json` — финальный диагностический прогон; `trace-test_*.jsonl` — наблюдения существующих тестов. Это исследовательские артефакты, не реализованный формат реплея.

Полная копия lab/datadir сохранена вне рабочего репозитория для воспроизводимости. Репозиторий получает только отчёт и доказательные артефакты; инструментированные PHP-файлы в него не переносятся.

## B. Выполненные сценарии и счёт результатов

Единицы подсчёта указаны явно: существующий набор может содержать много внутренних cases/assertions; они повторно не суммируются с named diagnostic cases.

| Группа | Выполнено | Успешные проверки | Неуспешные assertions финального прогона | Заблокированные запуски финального плана |
|---|---:|---:|---:|---:|
| Отдельные diagnostic cases | 29 | 29 | 0 | 0 |
| Конкурентные раунды, два отдельных процесса | 3 | 3 | 0 | 0 |
| Существующие test suites | 13 | 13 | 0 | 0 |
| Всего учётных единиц | **45** | **45** | **0** | **0** |

В узком смысле именованных сценариев/раундов выполнено **32**, плюс **13 suites**. Число assertions всех suites не выдаётся за число независимых сценариев. Дефектный результат считается успешным *воспроизведением диагностической гипотезы*, а не корректным поведением механики: в 6 probes воспроизведены расхождения групп R1–R3. Непроверенные комбинации перечислены в L и не включены в PASS.

Первый запуск подготовки выявил три ошибки окружения: два отсутствующих docs/cards fixtures (Bon/Berrow, Swamp children) и отсутствующую синтетическую cards table для endpoint suite. Они устранены в lab копированием docs и созданием синтетической схемы. После этого все 13 suites прошли; финальный запуск с probes также прошёл. Первые setup failures не скрываются и не считаются дефектами движка. SKIP не принимался за PASS.

| Существующий тест | Фактический финальный результат | Что подтверждает |
|---|---|---|
| tools/test_hydra.php | exit 0, Hydra regression tests passed | Два удара, pending next, отсутствие уже поражённой цели, естественный конец |
| tools/test_ragnar.php | exit 0, Ragnar tests passed | Двойные/обычные Вальхаллы, разные цели, no-target повтор, гибель, недоступность источников |
| tools/test_combat_instant_phases.php | exit 0, Combat instant phase tests passed | Фазы и same-phase LIFO, dice pause/resume, раны/перенос и промежуточные окна |
| tools/test_degasca.php | exit 0, Degasca tests passed | Startup, strong sequence, внешние pending, продолжения без повторных ран/эффектов |
| tools/test_bon_and_berrow.php | exit 0, **337 assertions passed** | Deferred gain/discharge, after stack, nested forced combat, origin restoration |
| tools/test_swamp_children.php | exit 0, **399 assertions passed** | First-strike protection, deferred jump/pull, вложенные продолжения |
| tools/test_movement_effects.php | exit 0, Movement effect regression tests passed | Эффекты обычного/вынужденного перемещения, бонусы/выборы |
| tools/test_forced_directional_movement.php | exit 0, Forced directional movement regression tests passed | Ответный сдвиг, ограничения/проверки, fallback |
| tools/test_volkhv_wound_transfer.php | exit 0, Volkhv wound transfer tests passed | Донор/цели/распределение, защиты, фактический перенос/лечение |
| tools/test_resign_pending.php | exit 0, **48 surrender cases**, repeats/outsiders/guards | Сдача при разных ожиданиях и недопустимые повторы |
| tools/test_nested_combat_ownership.php | exit 0, **10 deterministic real combat paths** | Вложенные choices через Engine, ownership/ACK и продолжение |
| tools/test_command_journal_repository.php | exit 0, Command journal repository tests passed | Регистрация/retry/CAS/атомарность game+journal |
| tools/test_battle_command_endpoint.php | exit 0, Battle command endpoint tests passed | Настоящий index через дочерние PHP запросы, command ids/status/retry и ограничения legacy GET |

13 suites выполнялись отдельными PHP-процессами с исходным кодом и затем с наблюдательными probes; финальные outputs сохранены. Endpoint suite исполняет controller в CLI subprocess, это не браузерный прогон через реальную сеть.

Новые cases перечислены в summary.json: A1; B1–B4; C1–C2; F1; G1; H1–H2; I1–I2; J1–J3; K1–K5; R1a–R1e; R2, R2-real, R3. Случаи R1c/R1d — наблюдения guard/redirect, не автоматически подтверждённые баги.

## C. Обычное сражение

**A1, PASS, с сохранением каждого шага через GameRepository/Command Journal.** Синтетическая цель закрыта, бросок 6 детерминирован. `strike` → StrikeResolver.declare → resolve → apply оставляет target HP **16 вместо 20**, strike.state=results. После двух confirm_strike strike очищается, target HP остаётся **16**: ACK не повторил урон. В архиве есть computed и вновь загруженное persisted состояние каждой команды.

В этом простом проверенном варианте граница — завершение второго ACK после `StrikeResolver.continueAfterStrikeResultAck`, `CoinAbilities.completeCombat`, очистки strike и `Engine.finalizeDying`, после возврата внешнего Engine.apply. Это **не** общее утверждение, что второй ACK любого боя является completion.

`test_nested_combat_ownership` фактически прошёл 10 детерминированных путей с возникающими вложенными choices, `test_combat_instant_phases` — dice/main/wounds. Эти тесты подтверждают, что ранние точки могут быть промежуточными:

- после бросков ещё разрешаются инстанты и пересчёты;
- после apply/results может быть pending_wounds_resolution;
- ACK dice-результата продолжает main;
- после основных результатов возможны выборы локальных способностей, push/after, Дегаска или дополнительное действие.

**G1, PASS:** броня 2 из запроса damage=4 поглотила 2, HP стал **18**, armor=0. Последующее lethal damage при открытом strike оставило dying=true и zone=field. Лишь после очистки strike и штатного finalizeDying карта перешла в graveyard и это состояние сохранено. Следовательно HP≤0 и damage_applied недостаточны для кадра «гибель завершена».

Выбор защитника/перенаправление покрыты существующими combat/ownership suites; дополнительный R1d отдельно проверяет combat redirect с override. Полная аттестация всех комбинаций защит, instants и death chains не заявляется.

## D. Серия Гидры и повторные способности

### D1. Каждый завершённый бой Гидры — отдельный Replay Step

**B1, PASS:** проп attack_series/unanswer, две соседние цели, их HP=20. После первого `strike` и полного ACK: первая цель **16**, вторая **20**, strike отсутствует, pending_attack_series_choice существует, Гидра ещё открыта. Именно в этом состоянии сохранена неизменяемая диагностическая копия. После `attack_series_next` по второй цели и ACK: обе цели **16**, pending серии отсутствует, Гидра закрыта. Ранее захваченная копия по-прежнему содержит второй target HP=20.

Точка первого результата: `StrikeResolver.continueAfterStrikeResultAck` после local combat cleanup/finalize и формирования next-choice через `openAttackSeriesChoice`, до `ActionResolver.chooseAttackSeriesNext → StrikeResolver.declare`. В чистом проверенном сценарии оставшийся pending относится **следующему сражению**, не препятствует фиксации первого. Каждый бой самостоятельный Replay Step; причинная идентичность серии не вводит дополнительный уровень сражения.

**B2, PASS:** после первого боя `cancel_pending` через AttackSeriesContinueChoice.apply вызывает chooseAttackSeriesEnd: HP первой цели остаётся **16**, второй **20**, Гидра закрывается. Предыдущий результат не отменяется. По уточнению Lead отдельный технический «шаг серии» не нужен. Изменение closed при позднем добровольном End должно войти в итоговую хронологию: рекомендуем событие завершения с привязкой к финализации серии; требуется решение N, как отображать изменившееся поле без искусственного кадра серии.

**B1 и test_hydra PASS:** если больше допустимых целей нет, finishAttackSeries выполняется при закрытии последнего боя: закрытие карты естественно попадает в его итоговый кадр.

**B4, PASS:** Гидра с HP=1, открытый защитник и броски 1/6 погибла от ответа; после штатных ACK source zone=graveyard, pending серии не возник. **B3, PASS:** при убийстве последней карты противника бой завершился сразу: game_over/winner=host, внутри сохранённого terminal JSON ещё остались strike и dying на поле. Ожидать следующий удар или принудительно заканчивать серию нельзя.

Гидра с локальными обязательными последствиями не аттестуется только наличием next-choice. Для её комбинаций с coin/deferred/Degasca/execute правило «каждый бой отдельно» остаётся целевым, но precise capture надо отложить до конца последствий именно этого боя. Такой комбинированный каталог не покрыт исчерпывающе.

### D2. Двойная Вальхалла

**C1, PASS с InnoDB:** живой Рагнар даёт repeat=2; первая команда valhalla_pick уменьшила первую цель **20→19**, сохранила pending с repeat_index=2; вторая команда выбрала другую цель и уменьшила её **20→19**, очистила pending. Это две отдельные законченные целевые активации при отсутствии зависимых реакций. Весь эффект одного применения должен завершиться до capture; следующий pick сам по себе допустим.

**C2, PASS:** первая активация убивает последнюю цель HP=1, сохраняются game_over и graveyard; второй выбор не появляется, поскольку нет кандидатов. `test_ragnar`, PASS, дополнительно проверяет смерть цели первого применения, выбор другой допустимой цели, недоступность Рагнара/целей, повторы в следующие ходы и no-target double execution.

Наблюдение probe в `ValhallaProcessor.chooseTarget` после applyAll и до remaining/phase.resume позволяет различить первое и второе поле. Если применение damage породит незавершённую death/any-death очередь, candidate после applyAll ещё не аттестован — надо дождаться этой зависимости, а не считать любой repeat pending доказательством стабильности.

Для no-target repeat в applyAll оба повтора выполняются в одном цикле; `test_ragnar` прошёл, допустим **объединённый snapshot**. Никаких новых точек управления/остановок внутрь цикла не добавлено.

## E. Инстанты и сложные продолжения

### E1. Combat-инстанты

`test_combat_instant_phases`, PASS: разрешение идёт по фазам redirect/dice/power/value/setter/wounds, внутри фазы sequence DESC; dice-choice приостанавливает queue и продолжает её без повторного применения уже пройденного item. Target choice, priority/pass, declaration sequence и resolution order различаются.

Результат отдельного инстанта не стал основанием snapshot. Probes фиксируют изменения, существующие summaries содержат before/after, но последующий recalc и wounds ещё меняют результат. R1e ниже воспроизвёл это особенно явно: summary уже говорит strong→medium, а итоговый удар снова strong.

### E2. Дегаска

`test_degasca`, PASS, покрывает strong strike после ACK, последовательность изученных entries, deck choice, handoff any-death/Nokami/Anubisar/Talion и возвраты, отсутствие повторного primary damage. В частности assertions после strong sequence ожидают HP цели **7** до и после продолжения; состояние источника/выученных эффектов не откатывается при сериализации.

Фактические контейнеры: pending_degasca_sequence → встроенная pending_*.degasca_sequence либо pending_any_death_degasca_sequence / pending_nokami_wound_degasca_sequence, затем restoreAndResume; при занятости возможны deferred sequences. Исчезновение активного pending не является окончанием. После finishSequence может восстановиться origin и продолжиться исходный ACK.

Для первого внедрения **объединять сложную зависимую sequence в один Replay Step** после завершения её внешних обязательных реакций. Отдельные learned entries/исчезновение pending/deck picker сами по себе не аттестованы как самостоятельные шаги. Существующий suite доказывает исполнимость конкретных цепочек, не универсальную стабильность каждой внутренней точки.

### E3. CoinAbilities / nested forced combat

`test_bon_and_berrow`, PASS, 337 assertions. После after-инстанта реальный forced combat занимает battle.strike; origin остаётся в turn stack continuation, затем coin_post_combat_continuation. После сериализации и ACK origin не перезаписывает незавершённый child. Когда child закончился, origin восстанавливается, его результат сохраняет attacker_id=1/damage_total=2 и открывается pending_coin_ability.kind=discharge. Затем применяется отдельный discharge и его результат проходит собственный lifecycle, включая защищённую цель с нулевым уроном.

Тест использует force_probe — синтетическую prop-конфигурацию для реального пути движка. Не утверждается, что именно такое сочетание карт присутствует в production колодах.

`CoinAbilities.advance` помещает effect в history **перед** открытием выбора. Поэтому history.executed — dispatch, не окончательное применение. strike=null также не доказательство: origin может быть вне основного слота.

Самостоятельность нового combat/discharge подтверждена исполняемым сценарием; универсальная граница каждого вложенного боя при нескольких items turn stack пока **не аттестована — объединять**. Loop resolveTurnStack способен продолжить другие items после запуска combat. Recorder не должен менять этот порядок.

## F. Перемещения и составные действия

**F1, PASS с сохранением:** карта переместилась с (3,2) в (3,3), move исчерпан, возник pending_forced_strike. В этом поле позиция уже окончательно изменена; последующий choose_forced_strike и завершение удара её не отменили, цель получила 4 раны (**20→16**). В данной конфигурации можно иметь отдельный кадр законченного движения и затем кадр законченного боя. Обязательный выбор новой атаки не делает предыдущее перемещение временным.

`test_movement_effects` и `test_forced_directional_movement` прошли: ответное перемещение соперника, допустимость сдвигов/ограничения, fallback. `test_swamp_children`, 399 assertions PASS: deferred jump и возможный pull начинаются после исходного combat/after, а не при первой защите. Это подтверждает необходимость отличать законченный исходный бой от вызванного нового движения.

Для сложного jump+pull с несколькими побочными pending пока объединять зависимые последствия, не объявлять каждый внутренний triggeredMove готовым шагом. Не все сочетания movement bonuses/heal/forced attacks аттестованы.

**H1, PASS:** multi-heal выбранных целей, HP **15→17** и **17→19**, после подтверждений batch завершён. **H2, PASS:** multi-discharge, две цели **20→18**, после ACK strike очищен. Fixtures начинают с валидного сохранённого multi-target pending; не заявляется проверка всех UI-деклараций этих способностей. Цикл по целям не делился на snapshots. Реакции на death/replace-heal требуют окончания зависимости; итог simple batch не доказывает безопасность его середины.

**Перенос ран:** `test_volkhv_wound_transfer`, PASS, проверяет план распределения, защиты цели и фактическое лечение/повреждения. Выбор донора/amount/отдельного target собирает план; результат применяется в WoundTransferProcessor.finish. В combat контексте затем вызывается completePausedCombatItem и resume, поэтому отдельный кадр «инстант перераспределения» не требуется. Отмена отдельным сценарием R2 оказалась дефектной.

## G. Начало боя и смена ходов

**I1, PASS через реальный Engine confirm_place и штатную транзакцию:** после подтверждения второй стороны probe внутри TurnProcessor.startBattle захватил нормализованное поле с HP источника **20**; затем start.get_hp=3 привёл к сохранённому HP **23** в том же command COMMIT. Initial перед applyStartEffects тем самым подтверждён исполнением. В конце confirm_place исходное поле уже потеряно для захвата из текущего mutable state.

Граница initial: после нормализации closed/revealed/броня/открытые flying, установки базового battle/таймера, **до `TurnProcessor.applyStartEffects`**. Это не кадр до нормализации pregame и не результат старта. Указанное в задании правило соблюдается без повторного выполнения.

**I2, PASS:** confirm_place создаёт pending_intrigue_start; choose_intrigue_start по двум целям добавляет каждой poison=1, очищает queue и устанавливает pre_battle_completed. `test_degasca`, PASS, дополнительно проверил prebattle обучение Дегаски и очередь интриги/обязательные выборы обеих сторон. Полностью автоматические start effects допустимо агрегировать; отдельные technical pending не кадры.

**J1, PASS:** обычный end_turn завершил переход, active стал player, turn остался 1, turn_phase в итоговом сохранении отсутствует. Это контроль простого перехода без содержательной задачи, **не доказательство** границ всех фаз.

**J2, PASS:** создана настоящая start phase с regeneration=2: HP **17→19**, остаётся pending_ack задачи. После turn_ack HP остался **19**, phase завершилась. Для этой простой задачи результат устойчив **до ACK**: ACK сам не отдельный игровой шаг. Если задача порождает combat/death/choice, тот же вывод переносить нельзя.

**J3, PASS:** таймерное results-ожидание после ACK владельца дошло до deadline; InformationalAutoAck.checkOutcome с контролируемыми server clock выполнил обычный journaled confirm_strike. Persisted strike очищен, certainty=true, SQL version=6. Никакой новый action только из-за авто-ACK не вводится. Это реальный processor+InnoDB, без ожидания настенных десяти секунд и без сетевого браузера.

Ограничение: end/start эффекты в turn_phase могут работать после раннего изменения active/turn или удаления контейнера. Исследование аттестовало простую regeneration task и простой end-turn; общие фазы с Вальхаллой/пророчеством/инстантами остаются условными до проверки конкретных комбинаций.

## H. Терминальные состояния

**B3:** lethal damage во время серии сохранил game_over/winner, но оставил strike и dying на поле. **C2:** lethal Valhalla без активного strike переместила цель в graveyard и прекратила второй pick. Разная степень очистки — фактическое поведение движка, не основание дорисовывать завершение.

`test_resign_pending`, PASS: 48 случаев surrender при ожиданиях, повторы/неучастники/guards. Resign обрабатывается до активного Choice; не требует закончить выбор. Terminal snapshot должен соответствовать committed результату внешней команды, а не искусственно очищенному состоянию. Нельзя запускать пропущенные инстанты, перенос ран, следующий удар или finalize исключительно ради красивого финала.

Победа может возникнуть внутри damage/loop; вызывающий код ещё способен выполнить оставшийся синхронный участок. Поэтому event «winner установлен» и final capture не обязательно совпадают по строке кода: final — фактически сохранённое состояние после завершения внешнего successful execution. Непройденные scopes помечаются interrupted в будущих metadata; сейчас они не внедрялись.

## I. Воспроизведённые риски R1–R3

### R1. Перезапись уровня удара

**Подтверждено.** Шаги воспроизводятся `stage3_scenarios.php`, cases R1a/R1b/R1e, без исправлений.

| Probe | До | После эффекта | После recalc / итог |
|---|---|---|---|
| R1a, attack reduce_one, dice 6/0 | attack=strong | attack=medium | attack=strong |
| R1b, defender set, dice 4/2 | attack=medium, defend=weak | defend=strong | defend=weak |
| R1e, полный main-phase resolveStack с value item | attack=strong | summary applied=true, strong→medium | final.attack=strong; HP цели 20→16, т.е. урон strong=4 |

Трасса R1e: InstantProcessor.resolveStack → resolveCombatPhases → resolveCombatItem → Engine.applyCombatEffect(strike_level/reduce_one) → append summary → finishCombatResolution → StrikeResolver.recalcTable → apply. Summary фиксирует medium, но последующий recalc перезаписывает результат и применяется strong. Это воспроизведение в исполняемом фазовом пути, а не только ручной вызов двух методов.

Причина: Engine.applyCombatEffect ставит level_overridden только для set атакующей стороны; reduce_one/defender-set без guard, recalcTable пересоздаёт result. Требуется отдельная задача исправления и анализ правил, здесь не менялось.

**R1c/R1d — наблюдение, не самостоятельный доказанный дефект:** attacker set сохраняется после изменения dice и recalc; redirect на допустимого соседнего союзника с level_overridden=true применился, target_id изменился на 3, result остался medium. Это подтверждает поведение глобального guard. Корректность всех set/redirect сочетаний по правилам карт не установлена; не объявлять их все ошибками без дополнительного expected outcome.

Fixtures используют реальные supported effect types с синтетическими source props. Наличие любой такой комбинации у конкретной production карты отдельно не проверено.

### R2. Cancel WoundTransfer оставляет paused combat

**Подтверждено.** Низкоуровневый R2 проверил сохранение queue после cancel; более реалистичный **R2-real** запускает `InstantProcessor.resumeCombatWounds` с настоящим wounds stack item и подтверждённым parent results. Процессор сам создаёт pending_wound_transfer, queue[0] и options.on_finish=combat_resolution.

Далее через Engine/Command Journal:

1. cancel_pending — successful/committed; pending_wound_transfer исчез.
2. instant_resolution.queue по-прежнему содержит **1 item**, confirmed остаётся `[host, player]`.
3. confirm_strike с актуальным token — **rejected**, повторное подтверждение уже выполнено; item не завершён.

ActionResolver.cancelPending возвращает coins/reopens source/unsets pending, но не вызывает completePausedCombatItem/resume в отличие от WoundTransfer.finish. Это воспроизводимый незавершённый resolution. Безопасную границу после cancel этого combat аттестовать нельзя. Recorder не должен сам продолжить очередь и тем самым исправить механику.

R2-real начинает с синтетически сохранённого состояния после primary damage и обоих ACK, затем использует реальный resolver для открытия transfer. Это проверка resume/cancel ветви; полный card declaration→все окна→transfer UI по сети в одном сценарии не выполнялся.

### R3. Отмена Вальхаллы отклоняется

**Подтверждено R3:** при active pending_valhalla_pick Engine получает cancel_pending; Command Journal становится **rejected**, persisted pending остаётся. ValhallaPickChoice.spec содержит UI cancel, commandTypes — только valhalla_pick; Engine.doApply не допускает эту команду активному handler.

Это расхождение интерфейсной команды и routing. До отдельного исправления нельзя проектировать snapshot/lifecycle исходя из предположения «нажатая Отмена завершила активацию». Браузерный click не запускался; фактически проверено исполнение той же серверной команды, которую формирует spec.

Все три группы оформлены здесь как отдельные воспроизводимые findings. Production код, command routing и механика не исправлялись.

## J. Проверенная матрица границ Replay Steps

«Разрешён» относится только к указанному исполненному варианту и **последующему атомарному COMMIT**. Это не готовая запись snapshot и не сертификат всех props-комбинаций. «Условно»/«объединять» не считаются аттестацией самостоятельного шага.

| Сценарий / команда | Точка завершения в коде | Snapshot | Зависимости и допустимый остаток pending | Дополнительные результаты | Проверка / результат / ограничения |
|---|---|---|---|---|---|
| Простая атака, strike + ACK | StrikeResolver.continueAfterStrikeResultAck → finalize, Engine.apply return | **Разрешён** | Все local consequences закончены; в A1 ожиданий нет | Отдельный последующий бой — новый результат | A1 PASS, HP16 неизменно; сложные свойства условно |
| Dice/main combat | InstantProcessor.resolveCombatItem/finishCombatResolution | **Запрещён** после отдельного item/окна | Остались пересчёт/main/choice/wounds | Только semantic events инстантов | combat phases PASS; R1e воспроизвёл позднюю перезапись |
| Первый бой Гидры | continueAfterStrikeResultAck/openAttackSeriesChoice до next declare | **Разрешён** в B1 | Собственные эффекты боя закончены; pending_attack_series_choice допустим как выбор следующего | Второй бой самостоятелен | B1 + test_hydra PASS; coin/death combinations не все покрыты |
| Последний бой Гидры | finishAttackSeries вместе с окончанием боя | **Разрешён** | Нет следующих целей, closed нормализован | Нет технического шага серии | B1 PASS, обе цели16, closed=true |
| Добровольный End | AttackSeriesContinueChoice → chooseAttackSeriesEnd + post-processing, успешный COMMIT | **Отдельный Replay Step и snapshot, решение Stage 3.2** | closed=true; предыдущий snapshot неизменяем; последующий end_turn не поглощает End | Самостоятельное действие, не дополнительное сражение; повтор/rejected без нового действия — без кадра | B2/DB H1 PASS; окончательная спецификация Stage 3.2 |
| Гидра погибла | finalizeDying/нет next choice | **Разрешён** после развязки | Death dependencies закончены | Серия прервана | B4 PASS, graveyard |
| Targeted Valhalla repeat | chooseTarget после applyAll и окончания реакций | **Разрешён** в C1 | Следующий repeat pick может оставаться; unresolved deaths блокируют | Второе применение отдельное | C1 + Ragnar PASS; death chains условно |
| No-target double Valhalla | applyAll после полного цикла | **Объединённый** | Целый повторный batch/реакции | Два применения в событиях | test_ragnar PASS; внутри каждой итерации не аттестовано |
| Сложная Дегаска | finishSequence + внешние handoffs + origin continuation | **Объединять** | ANY/Nokami/embedded/deferred continuations должны закончиться | Learned entries/обучение — события | test_degasca PASS; отдельные entries не аттестованы |
| After forced combat + Coin | Окончание child/origin в существующих continuation paths | **Не аттестовано отдельно — объединять** для сложного nested | Origin может быть вне strike, turn stack ещё влияет | Forced бой и discharge имеют свои результаты | Bon/Berrow337 PASS; несколько items/forced комбинированно не доказаны |
| Deferred coin gain/discharge | CoinAbilities.choose и завершение результата вызванной способности | **Условно** | history insertion не конец; собственный combat/реакции должны закончиться | Новое post-combat применение | Bon/Berrow PASS; без универсальной точки всех очередей |
| Move→forced strike | MovementEffectResolver.apply завершён, до chooseForcedStrike | **Разрешён** в F1 | pending_forced_strike относится новой атаке; прочие effects отсутствуют | Отдельный бой | F1 PASS, позиция (3,3), затем HP цели16 |
| Ответный сдвиг / jump+pull | ForcedMovementResolver/FirstStrikeJump.choose после зависимостей | **Условно / объединять** | Зависимые movement choices могут остаться | Новые движения | movement suites, Swamp399 PASS; универсальная мелкая граница не доказана |
| Multi-heal | chooseMultiHeal + завершение batch/ACK | **Разрешён как batch** H1 | Нет replacements/death в простом H1 | Не шаг на каждую цель | H1 PASS, HP17/19; replacement chain объединять |
| Multi-discharge | chooseMultiDischarge + реакции/ACK | **Разрешён как batch** H2 | Цикл целиком, не середина; reactions/winner требуют отдельной оценки | Per-target события | H2 PASS, HP18/18; lethal внутри цикла условно |
| Wound allocation | chooseSource/Amount/Target/TargetAmount | **Запрещён** как промежуточный выбор | План ещё не применён | Нет законченного переноса | Volkhv PASS, код/проверки finish |
| Combat transfer finish | WoundTransfer.finish → completePausedCombatItem/resume | **Не отдельный instant step** | Тот же combat ещё продолжается | Раны/лечение в итоговом бою | Volkhv/combat suites PASS; cancel R2 не аттестован |
| Lethal HP/dying | DamageResolver.resolveDeath до смены зоны/выборов | **Запрещён как death-final** | On/any-death и flush должны закончиться | Отдельные реакции | G1 PASS: field/dying → graveyard |
| Initial | TurnProcessor.startBattle до applyStartEffects | **Разрешён** | Нормализация расстановки закончена; стартовые эффекты ещё не начаты | Следующий startup результат | I1 PASS, HP20→23 одним COMMIT |
| Интрига | chooseIntrigueStart + continuePreBattleEffects | **Разрешён** для I2 после эффекта | Новый startup task может следовать, не недоделанная интрига | Дальнейшие startup actions | I2 PASS, poison1/1; сложная prebattle Дегаска объединяется |
| Regen task | executeRegen/сохранённый pending_ack до ACK | **Разрешён** для J2 | ACK только подтверждает уже применённое лечение | Следующая task может исполниться в ACK | J2 PASS, HP19 до/после; task combat условно |
| Счётчик end_turn | TurnProcessor.endTurn после active/turn change | **Недостаточно** | End/start queues ещё могут исполняться | Самостоятельные законченные задачи | J1 простой переход PASS, сложные фазы не обобщать |
| Auto-ACK | InformationalAutoAck.executeLockedCommand → старое продолжение | **Не самостоятельный шаг** | Он может закончить настоящий combat | Игровой результат по прежней границе | J3 PASS, journaled ACK clears strike |
| Авто-добивание | openAfterStrikeExecute до executeForced и итог execute | **Возможны два capture в одном COMMIT** | Свои death последствия каждого результата; не просто два API вызова | Первичный бой и добивание | K5 PASS, HP6→0, SQL version +1; все reaction variants условно |
| Победа/сдача | После внешней successful команды перед COMMIT | **Разрешён terminal** | Неразрешённые scopes не доисполняются; viewer не запускает Engine | Interrupted outcome | B3/C2, resign48 PASS; финал фактический |

Практический критерий: закончился **конкретный игровой результат**, его собственные обязательные зависимости больше не способны изменить этот outcome; оставшийся pending понятным образом относится следующему действию. Не требовать глобально пустых pending, но не принимать неизвестную очередь за новую независимую способность без проверки.

## K. Транзакционность, повторы и конкурентные запросы

### K1. Idempotency и потерянный ответ

**K1 diagnostic PASS:** первый move с command_id сохранён, version **2→3**, move стал 0. Повтор registerProcessing с тем же command_id/payload/исходным expected_version вернул ту же committed journal row; Engine повторно не вызывался, version осталась 3. `test_battle_command_endpoint`, PASS, проверил controller retry/status/replay; `test_command_journal_repository`, PASS — repository constraints и payload identity.

«Потеря ответа» моделировалась клиентским повтором после уже состоявшегося COMMIT без использования первого ответа. Физический обрыв HTTP-соединения, потеря TCP пакета или lost COMMIT ACK не моделировались. Эти виды отказа не выдаются за проверенные.

### K2. Версии и конкуренция

**K2 PASS:** сохранение stale копии после успешного move выбросило StaleStateException и не затёрло новый state. **Race PASS, 3 раунда:** два отдельных PHP-процесса, одинаковый baseline expected_version, barrier после регистрации processing; host делает move, player — resign. Каждый раз ровно **одна committed и одна conflict** journal row, games.version выросла ровно на 1. Полные rows/последнее поле/выходы процессов в race-results.json. Это проверка гонки двух участников, не stress-тест и не все варианты межблокировок.

### K3. Ошибка сохранения

**K3 PASS:** временный BEFORE UPDATE trigger только в berserk_stage3 вызывает SQLSTATE 45000. Engine вычислил move, save упал, транзакция откатилась: games.version осталась 2, карта осталась на исходной row=3, journal не стал committed (остался processing). Trigger удалён в finally. Этот тест вызывает repository напрямую: он не утверждает, что controller обязан оставить processing навсегда. Endpoint recovery/failed handling отдельно покрывает существующий suite в пределах его assertions.

GameLog как внешний файл мог зафиксировать вычисленную попытку, хотя games откатился; он не используется как доказательство COMMIT. Не изменялся и не отключался.

### K4. Разные неизменяемые состояния внутри одной команды

**K4 PASS — технический proof:** в одном executeLockedCommand callback два реальных Engine.applyDamage сохранили диагностические JSON-копии HP **19**, затем **17**; games получил только последнее поле и одну новую SQL version. Это доказывает возможность captures без дополнительных COMMIT, но не доказывает, что любые два damage вызова — два самостоятельных игровых действия.

**K5 PASS — естественный игровой путь:** ordinary strike оставил target HP **6**. Второй confirm_strike прошёл local combat cleanup, probe до `openAfterStrikeExecute` захватил HP6; единственная цель добивания была выбрана автоматически, executeForced снизил HP до **0** и создал kind=execute. Один ACK command COMMIT, SQL version выросла на **1**. Это фактические два различимых поля до/после нового вызванного действия внутри одной команды. Старый immutable capture не изменился.

Будущая запись должна buffer-изовать captures в семантических точках и коммитить их вместе с последним games state/journal. Оба capture могут иметь одну after_version, но разные event cursor/capture ordinal. Если снимать только JSON в конце Engine.apply, HP6 будет потерян. Ни callbacks наблюдения, ни архивирование диагностических JSON не являются внедрёнными игровыми snapshots.

Атомарность **будущих event tables/snapshots не тестировалась**, потому что такие сущности запрещено реализовывать на Stage 3. Проверена текущая games+journal транзакция и возможность собирать immutable copies до её единственного COMMIT.

## L. Непроверенные и ограниченные случаи

Следующие случаи **NOT ATTESTED**, они не входят в успешный счёт и не получают безопасную мелкую границу по предположению:

1. Гидра с каждой возможной комбинацией after-инстантов, Coin, execute, Дегаски и нескольких death choices; правило отдельного боя остаётся обязательным, precise point для такой комбинации ещё проверить.
2. Любое standalone nested combat при нескольких исполняемых turn stack items и нескольких forced sources; пока объединять зависимую цепь.
3. Каждая learned entry Дегаски как отдельный step; многократные overlapping deferred sequences в полном пространстве конфигураций.
4. Каждая итерация no-target Вальхаллы; согласован объединённый кадр. Targeted repeat с незакрытой death/replacement queue отдельно не аттестован.
5. Multi-heal с несколькими heal-instead-wound и вложенными реакциями; lethal multi-discharge внутри foreach с оставшимися targets.
6. Полный browser/network flow drag/target picks/отмена R2/R3 и физическая потеря HTTP-ответа. Проверялись реальные серверные команды и CLI controller subprocess.
7. Lost COMMIT/ROLLBACK acknowledgement, mysqld crash, power loss, сетевой разрыв и большой deadlock stress. Существующие обработки исключений не являются доказательством этих испытаний.
8. Runtime snapshots/events atomic storage, историческая visibility и renderer — системы ещё не реализованы, их тестировать как готовые нельзя.
9. Production HEAD/данные/интеграции внешних потребителей GameLog не исследовались.
10. Автоматический timeout end_turn в гонке с реальными пользовательскими запросами этого этапа отдельно не проверен; auto-ACK и обычный end_turn проверены.

Эти ограничения не означают, что безопасная изоляция была заблокирована: окружение успешно создано и использовано. Это неполнота аттестации комбинированных игровых ветвей, требующая отдельного плана, а не ложного PASS.

## M. Рекомендации для ограниченного внедрения

1. Не менять исполнение механик ради будущего реплея. Гидра: один законченный **бой** — один step, причинная связь серии отдельно, без нового уровня сражений и технических кадров серии.
2. Первый whitelist: простой законченный combat, чистые бои Гидры, targeted Вальхалла без незакрытых реакций, обычное movement/проверенный move→new strike, простые multi-target batches, законченная regeneration task, initial и terminal. Условия из J обязательны.
3. Сложные Дегаска/Coin nested/неизвестные dependent queues объединять до доказанного конца зависимой цепи. «Много pending» не универсальный запрет; «pending исчез» не универсальный completion.
4. Collector должен наблюдать существующие boundaries и копировать DTO до следующего действия, затем атомарно сохранять buffered events/captures с games+journal. Не делать отдельные COMMIT на кадр, не исполнять replay Engine повторно.
5. Ошибки R1–R3 завести отдельным решением Lead, с приведёнными repro. Включать связанные спорные границы только после решения о baseline/исправлениях и повторной аттестации; Recorder не должен их скрыто исправлять.
6. Повтор команды связывать с committed command identity и semantic boundary key, а не с новым HTTP/request/ACK id. Pending resume сохраняет причину, новое самостоятельное применение получает свой результат.
7. До rollout добавить отдельные проверки будущей atomic event/snapshot persistence, visibility и reader compatibility; текущая проверка этого не заменяет. Новые event tables, lifecycle ids и UI в этом этапе не создавались.

## N. Вопросы Project Lead

| Вопрос | Рекомендация и последствия |
|---|---|
| Утвердить проверенные границы J как первый whitelist? | Да, с явными условиями и запретом переносить PASS на все сочетания props |
| Как показать закрытие Гидры при добровольном End после уже показанного боя? | Не вводить отдельный технический Replay Step серии; сохранить событие closed/end и согласовать, относится ли поздняя финализация к последнему шагу с вариантом финального поля либо иной модели отображения. Нельзя молча потерять closed или перезаписать неизменяемый capture |
| Принимать два capture одной committed команды? | Да: K5 подтвердил реальный путь, HP6→0 при version+1. Нужны ordinal/event cursor; не создавать промежуточный COMMIT |
| R1–R3 исправлять отдельными задачами до recorder? | Рекомендуется. R1 меняет реальные раны, R2 блокирует continuation, R3 нарушает отмену; это влияет на аттестацию. В Stage 3 не исправлялись |
| Целевую Вальхаллу делить по повтору? | Да для аттестованных завершённых применений; реакции могут отложить capture. No-target — общий кадр |
| Разделять nested Coin/turn combat немедленно? | Для неаттестованных combinations — нет, пока объединять. Потребуются адресные сценарии, без нового scheduler |
| Финал должен быть очищенным или фактическим? | Фактическим: B3 доказал сохранённые strike/dying после winner. Не запускать эффекты для украшения финала |
| Следующий этап — ограниченная реализация или дополнительная аттестация combinations? | Сначала принять J и политику Hydra End, отдельно решить R1–R3; затем разрешить ограниченную реализацию и тесты atomic captures, остальное оставить merged |
| Какой HEAD развёрнут production? | Получить revision/diff перед внедрением. Проверенный baseline main не является автоматически production HEAD |

Исследование остановлено на результатах Stage 3. Никакие production Recorder, игровые event tables, migrations, новые UI или исправления механик не внедрялись.
