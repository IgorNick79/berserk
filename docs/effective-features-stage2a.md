# Stage 2A: эффективные особенности — незавершённая реализация

Статус на 2026-10-25: пять завершающих маршрутов закрыты; Stage 2A готов к приёмке в проверенном CLI + MariaDB/InnoDB контуре. FPM/HTTP не сертифицированы. Итог: effective-features-stage2a-completion.md.
Рабочая копия: `matriarch-luck-main`. До начала работ `git status --short` был пуст.
БД, шаблоны UI, InfoPanel, Recorder, CombatContinuation и правила toArray/fromArray не изменены.
Активные способности Короля-жреца не добавлены. Commit/push не выполнялись.

## Архитектура внесённой части

- `PermanentFeatures::KEYS`: ровно утверждённые 13 ключей.
- `CardInstance::effectiveProp()` -> `EffectiveFeatures::prop()`: при подавлении возвращает пересечение исходного prop с 13 ключами; иначе исходный массив без изменений. Выдачи через grant_prop видны через тот же путь.
- `CardInstance::effectiveModifiers()` -> `EffectiveFeatures::modifiers()`: при подавлении оставляет modifiers со stat из постоянного списка; индексы сохраняются. Исходный массив не удаляется и не заменяется проекцией.
- Инфраструктурный `applyMemoryLoss(state, source, target)` добавляет `target.flags.memory_loss_sources[source.instanceId] = true`. Это НЕ активная способность: нет цены, закрытия, лимита или выбора цели.
- Используется уже сериализуемый flags. Методы сохранения и восстановления партии не менялись. Несколько источников хранятся независимо.
- Присутствие на поле: field или flying. hp=0 и dying сами по себе связь не снимают.
- В ZoneManager снятие выполняется после фактического присваивания внешней зоны: hand, squad, deck, sideboard, graveyard, exile. Уход цели очищает её связи, уход источника — связи всех целей. Возврат их не восстанавливает.
- Сохраняется существующее место проверки Вальхаллы перед присваиванием graveyard и Инкарнации после него. Порядок инстантов не менялся.
- При изменении подавления используется существующий refreshArmor. Его существующее правило изменения максимума брони (восстановление до нового максимума) не менялось; отдельная новая модель расхода брони не введена.
- Записи без эффекта не удаляют strike, instant queue, ACK или CombatContinuation. Для поддержанных pending используются локальное завершение эффекта либо существующая ветка отказа.

## Таблица миграции

| Файлы Core | Назначение игровых чтений и проверки |
|---|---|
| CardStats.php, CardInstance.php | Характеристики, защиты, способности, ауры, строй, modifiers, effectiveMove |
| ActionResolver.php, BattleHelper.php | Поиск actions, выданные действия в flags, цели, реакции лечения и атаки; точечные проверки ряда pending |
| StrikeResolver.php | Боевые особенности, ответ, реакции, configured effects; сохранение ACK при пропуске некоторых follow-up |
| DamageResolver.php | Вампиризм, реакции гибели, яд, щиты, any-death, очередь трупоедства |
| Engine.php | Исполнение cached combat/instant/strike effects, регенерация и производные бонусы |
| InstantProcessor.php | Доступность инстантов, cached stack items, выбор цели и кубика |
| CoinAbilities.php | Доступность уникальных монеточных особенностей и очередей; отказ от подавленной защиты продолжает исходную атаку |
| Movement/MovementResolver.php, Movement/MovementEffectResolver.php | Особые способы движения и реакции перемещения |
| TurnProcessor.php, TurnPhaseProcessor.php | Триггеры фаз, монеты, пророчества, Инкарнация, сохранённые задачи начала хода |
| ZoneManager.php, ValhallaProcessor.php | Игровые особенности при переходах зон и Вальхалла; жизненный цикл воздействия |
| DegascaProcessor.php | Собственные и изученные особенности, последовательности |
| FirstStrikeJump.php, RangedNeighborTeleport.php | Проверки реакции и отложенного исполнения |
| IncarnationTokenProcessor.php | Реакция на жетон Инкарнации |
| MatriarchOrderProcessor.php, Strangulation.php | Чтения actions при активации; применённый приказ остаётся техническим продолжением |
| ProphecyProcessor.php | Игровые свойства пророчества; миграция незавершена из-за блокера ниже |
| WoundTransferProcessor.php | Недоступность активации и существующая отмена перед исполнением переноса |
| Choice/CellEffectDirectionChoice.php, Choice/ForcedDirectionalMoveChoice.php | Только серверные проверки исполнения выбора; шаблоны и PanelSpec не менялись |

## Сознательно оставленные исходные данные

- CardInstance constructor/fromArray/toArray: исходные prop и modifiers для хранения.
- BattleHistory/ReplayStateBuilder: исходные определения и проверка воспроизводимости каталога; Recorder не изменён.
- Prepare/*, ResourceCalculator, DraftCopyRules: подготовка, состав отряда, ресурсные и копийные правила вне боевого подавления.
- Choice/TalionIncarnationChoice::incarnationLabel: описание карты на кладбище; UI оставлен исходным.
- ActionResolver grant_prop: существующая запись полученной особенности в оригинал, а не запись проекции.
- ActionResolver hp_base: сохранённое базовое здоровье для сброса.
- CoinAbilities targeting probe: запись actions в клон для существующей проверки цели.
- ProphecyProcessor transformSeeker: существующее преобразование карты, не механизм подавления.
- Engine syncCoinBonus: обслуживание исходного производного кэша при изменении монет; игровое чтение кэша проходит через effectiveModifiers.
- Очистка, истечение сроков, выдача и сериализация modifiers обслуживают исходные записи. Это не исполнение скрытой особенности.

## Устранённый блокер перестановки Махинатора

По разрешению Project Lead от 2026-10-25 в новые pending добавлен reorder_source_id.
Каждый следующий запрос проверяет именно этот экземпляр через effectiveProp.
Старые активные reorder pending без идентификатора и новые pending с недоступным источником завершаются через TurnProcessor::closeProphecy.
Уже выполненные перестановки сохраняются; неподтверждённые перестановки не выполняются; повторный запрос не повторяет эффект.
Полный отчёт и результаты повторных проверок: machinator-reorder-stage2a.md.

## Закрытие оставшихся маршрутов

Cached death-choice, incarnation-wound/resumeCoinAttack, row_spell, вложенные pending Дегаски и расходы/закрытие подавленных инстантов закрыты в завершающей итерации. Выполнена реальная изолированная MariaDB/InnoDB проверка обязательного live Recorder, CAS, rollback и retry. Полная таблица маршрутов и ограничения FPM приведены в effective-features-stage2a-completion.md.

Осознанное прямое чтение prop в InstantProcessor::combatItemRequiresTap относится только к стоимости закрытия уже объявленного инстанта. Исполнение эффекта по-прежнему использует актуальную effective проверку.

## Проверки первой итерации (история)

- Новый tools/test_effective_features.php: 93 проверки прошли. Покрывает 13 ключей и формы значений; обычные и выданные actions; уникальные бонусы и защиты; исходные modifiers; выдачу во время подавления; регенерацию; cached effects; несколько источников; шесть выходных зон; field/flying; dying vs фактический уход; reload; возврат; часть специализированных predicates; локальный pending без потери ACK.
- 23 существующих скрипта прошли в изолированной копии, включая combat_continuation, matriarch_order, matriarch_boundaries, movement_effects, anubisar, talion, kobold, krovyanitsa, kilsus, soul_eater_shaman, medusa_goblin_borg, swamp_children, hydra, bon_and_berrow, informational_wait, nested_combat_ownership, chaos_germet_poganishche, degasca, strangulation, kersam_satyr_wild_healer, live_battle_journal, journal_gap_causes, stage31_fixes.
- Всего 24 успешно завершённых скрипта. Это не доказательство полноты нового механизма.
- Синтаксис 153 файлов Core проверен до последних дополнений; после дополнений проверены все изменённые PHP-файлы, ошибок нет.
- DB-зависимые тесты сохранения/mandatory journal не запускались: существующая SQL-среда не использовалась и изменений БД нет.
- Первые запуски части тестов в изолированной копии не нашли docs fixtures. После добавления fixtures все 24 скрипта прошли; исходники для исправления тестовой упаковки не менялись.

Результаты: ../stage2a-effective-features-validation/results.json; воспроизводитель и его отдельный ненулевой результат не входят в 24 PASS.

## Изменённые файлы

- `src/Core/ActionResolver.php`
- `src/Core/BattleHelper.php`
- `src/Core/CardInstance.php`
- `src/Core/CardStats.php`
- `src/Core/Choice/CellEffectDirectionChoice.php`
- `src/Core/Choice/ForcedDirectionalMoveChoice.php`
- `src/Core/CoinAbilities.php`
- `src/Core/DamageResolver.php`
- `src/Core/DegascaProcessor.php`
- `src/Core/Engine.php`
- `src/Core/FirstStrikeJump.php`
- `src/Core/IncarnationTokenProcessor.php`
- `src/Core/InstantProcessor.php`
- `src/Core/MatriarchOrderProcessor.php`
- `src/Core/Movement/MovementEffectResolver.php`
- `src/Core/Movement/MovementResolver.php`
- `src/Core/ProphecyProcessor.php`
- `src/Core/RangedNeighborTeleport.php`
- `src/Core/Strangulation.php`
- `src/Core/StrikeResolver.php`
- `src/Core/TurnPhaseProcessor.php`
- `src/Core/TurnProcessor.php`
- `src/Core/ValhallaProcessor.php`
- `src/Core/WoundTransferProcessor.php`
- `src/Core/ZoneManager.php`
- `src/Core/EffectiveFeatures.php`
- `src/Core/PermanentFeatures.php`
- `tools/test_effective_features.php`
