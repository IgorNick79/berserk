# Очи Кронга — Stage 1: Action Resolution Notifications

## База и границы

Архитектурная база: origin/main 1324a00f7700bc77d0cfba65ab5825dff3097e85.
Рабочая ветка: codex/minicard-stage4. Исходный HEAD реализации: 96e24f2a948ba7121960f41e70bbdf1a032e5469.
При продолжении и итоговой проверке HEAD: 21d93c2ad90149e2a5686fba147741e0f812fbb5; он совпадал с локальным origin/main.
Изменения HEAD выполнены вне этой задачи; агент commit/push не выполнял. Между 1324a00 и 21d93c2 tracked Core не менялся.
Отличия этой ветки от origin/main до работы относились к mini-card UI; Core совпадал.
Ветки не переключались, merge/rebase/reset, commit/push не выполнялись.
Способность карты, prop, UI, SQL и правила сохранения не изменены.
CombatContinuation не изменён. Вызовы Recorder не используются для результата уведомлений.

## Контракт

ActionResolution::bind() назначает ID логическому действию; resolve() публикует результат
синхронно в том же GameState. effect() — однократный эффект в уже проверенной native
точке исполнения; performed() — самостоятельная активная способность; healed() —
положительное фактическое восстановление HP.

Событие содержит action_id, parent_id, sequence, type, actor_id, owner, actor_type,
element, origin, nested, outcome, hit, hp_restored, facts. type сохраняет native тип,
в том числе bomb_shot, teleport_target, answer и специализированные способности.
origin: ordinary, forced, passive, instant, response. Вложенность дополнительно
выражается parent_id/nested, не заменяет forced/passive происхождение.

outcome: hit, miss, blocked, cancelled, performed, no_effect.
У initiating strike попадание определяется разрешённым непустым уровнем, отдельно
от предварительных блокировок и итогового урона. answer — отдельный тип, не strike.
У обычного shot учитывается положительная величина до защиты/снижения (включая
положительные бонусы); Dice в этом движке всегда выбирает weak/medium/strong,
отдельного значения dice=miss нет. Нулевая исходная атака без бонуса — miss;
положительная атака, обнулённая последующим предотвращением, остаётся hit.
Для heal значение hp_restored — фактическая разница HP с капом, не заявленная сила.
Замена лечения без HP — no_effect; отдельное открытие наблюдается как open.
Multi-heal — один heal с суммой фактически восстановленного HP; отложенные
замены ранами не прибавляют HP и не создают повторного heal.
Увеличение hpMax с сохранением ран, воскрешение и первичная установка HP не
выдаются автоматически за лечение.

Стихия никогда не используется для предварительного отбора. События нелесных
исполнителей доставляются тем же путём. Stage 2 должен применять forests только
после доставки, вместе с правилами результата, исполнителя и происхождения.

## Состояние и однократность

battle.action_resolution хранит next_id, sequence, tokens и notifications.
_resolution_id переносится в native strike/action/pending/continuation-копиях.
Token содержит неизменную идентичность и delivered. Повторный resolve того же
сохраняемого контекста ничего не доставляет. Порядок задаёт sequence доставки,
а не сравнение action_id. Родитель, возобновлённый после дочернего, не доставляется
повторно. Для turn-instant перед forced child используется краткая serializable
scope_id с восстановлением через finally.

notifications — диагностический набор текущей команды, заменяется следующей
принятой обработкой. Это не журнал и не источник для replay/периодического polling.
Будущий обработчик карты подключается непосредственно внутри resolve(), синхронно,
а не повторно проигрывает сохранённый набор. Обработчика Очей в Stage 1 нет.

На входе команды завершённые tokens без ссылок в игровом battle-контексте удаляются.
Открытые pending и suspended contexts сохраняют ID. Метаданные доставки не растут
с историей партии. Технические ACK/priority/target-selection сами не уведомляют;
choose-метод может уведомить только когда именно в нём native действие исполняется.
При Result rejection notification bookkeeping восстанавливается.

## Фактическое покрытие

| Native тип/семейство | Конечный исполнитель | Момент и однократность |
|---|---|---|
| strike ordinary/forced, серия, приказ | StrikeResolver::declare/declareForcedAdjacent/apply | ID до before; результат до strike consequences и after; native damage_applied + delivered |
| strike blocked/aborted | prophecy branch, apply, InstantProcessor::continueAfterStrikeBeforeWindow | blocked/cancelled, не hit; тот же сохранённый ID при существующем frame |
| answer | StrikeResolver::apply | отдельный response, не initiating strike |
| shot/throw/discharge/magic/cast/tap | ActionResolver::resolveDamage | после redirect/pre-block, до damage consequences; action ID переносится через redirect/coin-block |
| bomb_shot | resolveBombShot | native отдельный путь, hit до предотвращения, facts.attack_type=shot |
| auto shot | Engine::chooseAutoTarget | фактическое исполнение auto, passive, до damage |
| heal single/replacement | resolveHeal | фактический HP итог; replacement wound/open не положительное лечение |
| heal multi | startMultiHeal/chooseMultiHeal | ID в pending action; одна сумма по всем целям после reload |
| vampire, on_strike_self_heal, defender heal | DamageResolver/StrikeResolver | положительная разница HP, passive child |
| deadeat/on-death heal | DamageResolver | реальное восстановление, не прогноз изменения HP |
| instant heal/heal_turn_wounds | Engine::applyInstantEffect | фактическое HP, исполнитель source, passive child |
| optional on-hit/move heal, Shaksi, life_gift | ActionResolver | фактическое положительное восстановление HP |
| regeneration/turn-end heal | TurnPhaseProcessor | действующий игровой turn-phase маршрут |
| move/jump/triggered jump | MovementResolver → MovementEffectResolver::apply | после позиции, до movement consequences; origin distinguishes triggered jump |
| forced_move/teleport | applyForcedPositionChange | один movement на изменение позиции; teleport сохраняет отдельный тип |
| teleport_target/dive | chooseTeleportTargetCell/chooseDiveCell | initiating ability перед дочерним movement с parent ID |
| impact/execute/grant modifier/become fly/delayed marker | соответствующие resolve-методы ActionResolver | после валидации, в native исполнителе; forced execute сохраняет forced |
| steal_strike/sand_claws/self_wound/blood_tap/multi_discharge | соответствующие resolve/choose-методы | одна фактическая способность, не каждый внутренний target/effect |
| revive/grezy/poison_boost/poison_target/damage_poisoned | соответствующие resolve/choose-методы | конечная ветка, включая flyer revive и grezy без целей |
| cell marker/dissonance/give_coin/grant_prop/steal_coin/freeze_moves | соответствующие resolve/choose-методы | native исходный тип, без фильтра стихии |
| row marker/row_spell/particle/life_gift/dverg_gift/damage_ranged | соответствующие choose/resolve-методы | не декларация выбора, а применение |
| blood_storm | startBloodStorm + action в pending | первое необратимое получение допки уже performed; продолжение не доставляет заново |
| gain_coin | Engine::gainCoin | самостоятельное накопление, не каждая пассивная монета |
| strangle | Strangulation::choose | подтверждённое наложение, не ACK |
| matriarch impact | MatriarchOrderProcessor::choose | один приказ перед его отдельными боями |
| wound_transfer | WoundTransferProcessor::finish | одна способность перед переносом; положительное лечение донора отдельно с parent_id переноса, origin учитывает combat instant |
| turn/combat instant | InstantProcessor, damage-cap/on-dice Engine commit points | исходный action_type или native instant; до forced child/внутреннего damage |
| open/phase gates/lantern | native open sites, chooseGate, CellEffectDirectionChoice | фактический эффект с исходным типом и происхождением |

## Изменённые файлы

Новый src/Core/ActionResolution.php — контракт, delivery gate, metadata GC.
Engine.php — граница команды/rejection, самостоятельные coin/auto/open и combat-effect commit points.
StrikeResolver.php — идентичность, hit/miss/blocked, response и HP notifications.
ActionResolver.php — перечисленные конечные активные действия, heal/multi, pending cancel, provenance.
InstantProcessor.php — before abort, завершённые instants, scope вложенного forced combat.
DamageResolver.php — vampire/deadeat/on-death HP.
CoinAbilities.php — положительный сигнал предварительной блокировки damage action.
Movement/MovementContext.php — notification provenance и teleport kind.
Movement/MovementResolver.php — происхождение triggered jump.
Movement/MovementEffectResolver.php — две общие точки movement delivery.
Strangulation.php, MatriarchOrderProcessor.php, WoundTransferProcessor.php — автономные исполнители.
TurnPhaseProcessor.php — фактическая regeneration/turn-end HP.
Choice/CellEffectDirectionChoice.php — только исполнение, UI/spec не изменены.
Новые тесты: test_action_resolution.php, test_action_resolution_persistence.php,
test_action_resolution_parity.php. Этот документ — отчёт.

## Проверки

Финальный запуск — disposable source copy, PHP 8.1, без credentials/реальной БД.
19 suites: action_resolution, action_resolution_persistence, action_resolution_parity,
damage_lifecycle, volkhv_wound_transfer, strangulation, row_spell_pending_dice,
combat_instant_phases, turn_instant_stack, nested_combat_ownership, matriarch_boundaries,
movement_effects, forced_directional_movement, kersam_satyr_wild_healer,
bon_and_berrow, riala_movement, lucktest, lucktest_recorder_atomicity,
live_journal_restoration. Все PASS. PHP syntax всех изменённых/new PHP — PASS.

Новые проверки: hit/miss/pre-block/cancel, фактический нулевой damage при hit,
heal +1/0, multi aggregate/reload, vampire order/provenance, rejected repeat,
nonforest interruption visibility, четыре movement вида, bounded metadata,
настоящий after instant → forced child, reload/ACK и уникальные IDs/sequence;
ответный удар имеет тип answer/response; нелесной shot с предотвращённым damage
остаётся hit; лечение при переносе ран сохраняет родителя, исполнителя и HP после reload.
Parity: исходный HEAD и рабочая версия дают одинаковые native cards/strike DTO
в восьми сценариях, включая vampire и multi-heal, после удаления только новых
notification metadata. Хэш: f2db76fc6b81bcf648aa746e0ae13fc601d82877e0760198e551953de6f6fbdb.

Persistence-тест вызывает настоящий GameRepository::executeLockedCommand с
process-local Db adapter. Инъекции CAS conflict и write failure откатывают DTO и
notification state; retry сохраняет coins=1 и sequence=1. Committed duplicate не
доходит до Engine. SQL schema validation в этом adapter-тесте не проверяется.
Реальная БД и сеть не использовались. Live-journal suite проверяет одинаковые
DTO/results с Recorder и без него, включая move/strike/ACK/end_turn.

## Ограничения и готовность к Stage 2

1. Нет доставки через внешний observer/plugin: предусмотрена синхронная точка
resolve(), к которой Stage 2 добавит собственный reducer. Текущий diagnostic batch
нельзя использовать как долговременную очередь или историю.
2. Неиспользуемый текущими command routes legacy Engine::applyRegeneration(CardInstance)
не имеет GameState в сигнатуре и не инструментирован. Реальная turn-phase
регенерация инструментирована. Прямые низкоуровневые изменения HP/closed вне
перечисленных исполнителей автоматически не перехватываются.
3. Все типы имеют hooks в перечисленных маршрутах, но отдельный notification fixture
не добавлен для каждой комбинации каждого специализированного prop. Широкие native
регрессии не заменяют по-карточную приёмку будущей способности.
4. Нет real-MySQL/FPM/concurrency-интеграции; CAS/rollback/retry проверены через
реальный repository и in-memory adapter. Persistence implementation не менялся.
5. Parent lineage подробно проверен для strike/after/forced child, vampire и wound_transfer. Для
редких составных активных способностей без сохраняемого strike-контекста passive
факт HP доставляется, но parent_id может отсутствовать; исполнитель/origin сохраняются.

Основной механизм и перечисленные command routes подготовлены к подключению Stage 2.
Полную сертификацию всех низкоуровневых входов и lineage всех редких составных эффектов
этот этап не заявляет. При необходимости Stage 2 использовать lineage таких эффектов
следует сначала добавить точечные fixtures и явный parent ID в соответствующий маршрут.

## Итоговая проверка 24 октября 2026

Повторный полный прогон выполнен на 21d93c2 в новой изолированной копии.
19 suites PASS, stderr пуст; syntax 18 PHP-файлов PASS; native parity PASS.
Артефакты: C:/Users/mi/Documents/Berserk/ochi-stage1-validation/20261024-final/
(summary.json, tests.json, syntax.json). Базовый snapshot — git archive текущего HEAD;
рабочий snapshot содержит только файлы Stage 1 поверх него. Пользовательские
незакоммиченные изображения mini-card не переносились в snapshot и не изменялись.

Изменения Stage 1 остаются в working tree, без commit/push. Собственная способность
Очей не реализована. Список покрытия выше описывает реально подключённые endpoints;
ограничения прямых low-level API и metadata lineage вне этих endpoints сохранены явно.
