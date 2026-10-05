Проект «Берсерк» — обзор
1. Что это
Пошаговая коллекционная карточная игра (ККИ) на PHP. Онлайн-дуэль 1×1. Сервер-авторитарный: все решения принимает PHP, клиент только отправляет команды и рендерит состояние.

Стек: PHP 8+, MySQL 5.7, без JS-фреймворков (только HTML/CSS + GET-переходы). AJAX/WebSocket — в планах.

2. Структура
text
config/
  db.php                      — подключение к БД

src/Core/                     — игровая логика (без HTTP/шаблонов)
  Autoloader.php
  Db.php                      — обёртка над PDO
  GameRepository.php          — сохранение/загрузка GameState
  GameState.php               — состояние партии
  PlayerState.php             — состояние игрока
  CardInstance.php            — одна карта в партии
  CardStats.php               — расчёт характеристик (ova, ovz, ability, ...)
  Command.php                 — входная команда
  Result.php                  — результат (success / error / events)
  GameLog.php                 — лог партии (storage/games/{id}/events.jsonl)

  Engine.php                  — единая точка входа, match по командам
  TurnProcessor.php           — ход, фазы, инкарнация, пророчество
  TurnPhaseProcessor.php      — очередь задач фазы начала/конца хода
  StrikeResolver.php          — сражение (объявление, защитник, кубики, инстанты)
  ActionResolver.php          — все действия кроме strike (heal, impact, ...)
  BattleHelper.php            — move/jump cells, attack targets
  ZoneManager.php             — переходы между зонами, маркеры клеток
  ResourceCalculator.php      — подсчёт ресурсов при сборе отряда
  ValhallaProcessor.php       — Вальхалла (карты на кладбище)
  ProphecyProcessor.php       — пророчество (единая точка входа)
  WoundTransferProcessor.php  — перераспределение ран (Отшельница, Волхв)
  InstantProcessor.php        — окна инстантов (combat-стек, turn-поток)
  DraftProcessor.php          — драфт 3×3 (сетка, взятие, пас, финализация)
  BoosterGenerator.php        — генератор бустера (12 карт)
  DeckView.php                — данные дек для UI
  View.php                    — рендер состояния для клиента

  Choice/                     — реестр хендлеров UI-выборов
    ChoiceHandlerInterface.php
    ChoiceRegistry.php
    CoinSpendChoice, DiceChoiceChoice, CellMarkerChoice,
    InstantPickChoice, CombatPickChoice, IncarnationChoice,
    ValhallaPickChoice, AnyDeathChoice, WhipChoice,
    WoundTransferChoice, MultiHealChoice, MultiDischargeChoice,
    SelfWoundChoice, CardChoice, BloodTapChoice,
    TransferChoice, ReviveChoice, GrezyChoice,
    TurnAckChoice, TurnInstantsChoice, TurnSubChoice, TurnPhaseChoice,
    ForcedStrikeChoice

src/View/
  Template.php                — мини-шаблонизатор ({{key}})
  Ui/
    Form.php                  — формы, radio, checkbox, submit
    Panel.php                 — универсальный рендер PanelSpec
    PanelSpec.php             — DTO для диалога
    Badge.php                 — бейджи маркеров/модификаторов
    TaskCard.php              — карточки-задачи в очереди фазы
  Screen/
    BattleScreen.php          — рендер поля боя
    Battle/InfoPanel.php      — роутер диалоговых окон (тонкий)
    Battle/Choice/            — RadioChoice, CheckboxChoice, ButtonChoice, MultiRadioChoice
    ModeScreen.php, DeckScreen.php, ViewScreen.php, TurnScreen.php,
    SideScreen.php, DraftScreen.php, DealScreen.php, PlaceScreen.php,
    GameOverScreen.php

templates/
  page.tpl
  screen/*.tpl                — по одному на экран (включая mode.tpl, draft.tpl)
  includes/*.tpl              — подшаблоны (карточка, ячейка поля, панель, draft_card, draft_panel)

debug/scenarios/*.json        — сценарии для seed.php
tools/seed.php                — создаёт партию из JSON
tools/test_booster.php        — тест BoosterGenerator
storage/games/{gameId}/events.jsonl — логи партий
3. Жизненный цикл партии
text
mode    — хост выбирает режим (draft / system)
deck    — хост выбирает деку (при system)
draft   — драфт 3×3 (при draft): 5 бустеров, сетка, взятие строк/колонок
view    — оба смотрят свою деку, подтверждают
turn    — бросок кубика, кто первый
side    — победитель выбирает сторону (1 = ходит первым)
deal    — сбор отряда (15 карт, ресурсы gold/silver)
place   — расстановка по уровням клеток
battle  — бой
game_over
Переходы — через Engine::apply, каждое успешное действие → PRG (redirect), чтобы F5 не повторял команду.

4. Модель состояния
GameState (один JSON в БД):

players — host / player (PlayerState)

cards — [instanceId => CardInstance]

cell_markers — маркеры клеток поля

battle — активная фаза, strike, все pending_*

status — стадия партии

mode — draft | system | null

draft — состояние драфта (pool, grid, picked, passed, pass_blocked)

next_instance_id — счётчик

version — растёт с каждым изменением

CardInstance — конкретная карта в партии:

zone — deck | hand | squad | field | flying | graveyard | exile | discard

hp, hpMax, armor, armorMax, coins, move, moveMax

strikeWeak/Medium/Strong

modifiers — временные бонусы (ova, ability_strike, direct, damage_reduction, ...)

markers — визуальные бейджи (poison, stun, incarnation, valhalla, prey, spider_web, ...)

flags — служебные отметки (moved_this_turn, prophecy_done_this_turn, in_stack, dive_used, ...)

prop — свойства из справочника (actions, ability, instants, ...)

ZoneManager — единственный путь переходов между зонами.

5. Движок
Engine::apply($state, $playerKey, $cmd) — единственная точка входа, match по $cmd->type. Оборачивает doApply и логирует в GameLog.

Делегирует в:

InstantProcessor — combat-стек, turn-инстанты, окна before/combat/after

StrikeResolver — strike, choose_defender, confirm_strike, choose_strike_mode, choose_push_choice, choose_redirect, choose_close_or_damage, choose_ally_modifier, combat_instant_*

TurnProcessor — end_turn, choose_incarnation_cell, close_prophecy, transform_seeker, choose_card_option

ActionResolver — action, uchr, все choose_* для действий, give_coin, steal_coin, dive, become_fly, wound_transfer

ProphecyProcessor — peek / commit / close / transformSeeker

WoundTransferProcessor — Отшельница, Волхв

DraftProcessor — pickRow, pickCol, pass

ChoiceRegistry — byCommandType перед match (перехватывает команды хендлеров)

Engine сам — move, jump, place_card, pick_card, gain_coin, resign, start_ack, turn_task, turn_sub, turn_sub_close, valhalla_pick, choose_mode, choose_forced_strike

Порядок фаз хода
text
Бой (turn)
 ├─ pre_turn (Оборотень, choice_on_turn_start) — отложено
 ├─ startTurn — фаза начала хода
 │   ├─ техслой: открытие, броня, сброс счётчиков
 │   ├─ passive_queue: яд → opponent_turn_start → инстанты
 │   └─ active_queue: инстанты → инкарнация → вальхалла → пророчество
 │                    → реген → монеты → turn_start
 ├─ mainPhase — действия игрока
 └─ endTurn — фаза конца хода
     ├─ смена активного
     ├─ раскрытие скрытого ряда (флаг hidden_row_revealed)
     ├─ passive_queue: инстанты → opponent_turn_end
     └─ active_queue: turn_end
         → технический слой (маркеры, модификаторы) в afterEndPhase
         → передача хода
TurnPhaseProcessor — общий движок очередей для обеих фаз. Задачи: bulk (яд, реген, монеты, инкарнация), per-card (whip, turn_start, turn_end, opponent_turn_*), sub (пророчество, вальхалла, инстанты). Паузы — через pending_ack (кнопка «Продолжить») или специализированные pending_*.

6. Сражение (StrikeResolver)
Основной поток
text
declare
 ├─ проверки (атакующий, цель, дистанция, обязательная атака)
 ├─ revealCard цели
 ├─ tryProphecyBlock (Дочь перламутра)
 ├─ создание $strike
 ├─ ОКНО 1 (before) — turn-инстанты
 └─ resolve():
      бросок кубиков + ova/ovz → attack_dice, defend_dice, mod
      strikeTable() → result {attack, defend, winner}
      ├─ ОКНО 2 (combat) — заказ combat-инстантов (Ост, Глорм, Баньши, Ловец, Мэри, Бешеный маг, Отшельница)
      │    resolution по фазам: redirect → dice → power → value → setter
      ↓
      waiting_choice (если оба уровня непустые) → chooseStrikeMode
      ↓
      apply() → урон, ответки, on_death, deadeat, close карт
      │    затем wounds-фаза combat-инстантов
      ↓
      results
      ↓
      confirm_strike (оба) → ОКНО 3 (after) — turn-инстанты
                            ↓
                            закрытие strike
Окна инстантов (InstantProcessor)
Три триггера:

turn — общие (лечение, закрытие, баффы)

combat — только на удар, с обязательным phase (Ост, Глорм, Баньши, Ловец, Мэри, Бешеный маг, Отшельница)

turn — также используется для обычных окон до/после удара через общий turn instant stack.

Заказ сохраняет общий порядок для обоих игроков. Combat-resolution идет по фазам, LIFO действует только внутри одной фазы.

Приоритеты: активный → пассивный → ... пока оба не пасанут.

Авто-пас: если у игрока нет инстантов — автоматически пропускается. Если ни у кого — окно не открывается.

UI: стек отображается LIFO (сверху тот, что разрешится первым). Провалившиеся эффекты — серые с причиной.

Эффекты
damage, heal, marker — базовые

close_target — закрыть карту (Ледовый страж)

strike_level — set/reduce_one (Ост, Баньши)

damage_cap — ограничить урон (Глорм)

damage_cap + self_wound + except_element — Глорм (рана на 1, если цель не степная)

dice_choice — +1/-1/reroll (Ловец)

damage_on_dice — урон при броске N (Мэри)

redirect — перенаправление (Волот, choose_redirect)

grant_ally_modifier — бонус союзнику (Ледяной змей)

redistribute_wounds — Отшельница (окно 3)

7. Ключевые механики
Защиты (hasDefense)
По типу атакующего: zoa (все), zoan (нелетающие), zoal (летающие).
По типу действия: zot/zoda (throw), zov/zoda (shot), zoda/zoz/zom/zor (discharge), zoz/zom (magic), zom (cast).
От яда: zoo.

Игнор: ignore: [zoal, zoz] — массив защит, которые атакующий игнорирует. Работает и как {zoal: true}.

Damage reduction
damage_reduction с фильтрами: types, value, line, attacker_direct, attacker_diagonal, require_no_wounds, max_price, element, attacker_type.

Auras
aura_plains_ranged — Щитоносец: соседние степные существа получают −1 от дальних (8-клеточное соседство).

Условия (checkCondition)
enemy_front_row_empty, ally_opposite_no_wounds

line_count_2_plus — 2+ союзника в строю

more_ally_near_than_enemy — Головорез

Ability bonus
ability с фильтрами: only, level, types, element, prop, position (opposite, row_mirror, enemy_second_row), target_type, target_marker, defender, per_ally, line, closed, condition.

Direct / unanswer
direct — нельзя выбрать защитника (Волот, Кобольд).

unanswer — цель не кидает кубик, не отвечает (Вампир, Уриил, Рамианда, Владыка небес).

Условия: check, condition (isolated_horizontally), target_marker.

Turn-инстанты
Хранятся в prop.instants с trigger: turn. Активный играет кнопкой «Сыграть инстант» в main phase. Пассивный — через task в фазе.

Вальхалла (ValhallaProcessor)
prop.valhalla — активируется при гибели от атаки. Флаг valhalla_active + маркер valhalla на кладбище. В фазе начала хода — task valhalla с подочередью.

Инкарнация (TurnProcessor::processIncarnation)
prop.incarnation (число или {turns, open, abilities}). +1 жетон в начале хода владельца → порог → возврат. Летуны — сразу в ZONE_FLYING. Обычные — pending_incarnation (задний ряд). Флаг incarnated.

Пророчество (ProphecyProcessor)
prop.prophecy: N — автотриггер в начале хода

prop.strike_prophecy — при ударе (Тролль)

prop.prophecy_block — при атаке (Дочь перламутра)

prop.prophecy_transform — Искатель

prop.prophecy_summon — Отряд карателей

prop.prophecy_charge — Исхарская ненасыть (+cap)

prop.prophecy_reorder — Махинатор

Перераспределение ран (WoundTransferProcessor)
Отшельница: combat-инстант в окне 3, донор — damage_taken_this_strike, цель — свой

Волхв: action, донор — любой раненый союзник, цель — враг, max_transfer: 2

Драфт (DraftProcessor)
5 бустеров → 60 карт, перемешаны. Сетка 3×3, взятие строк/колонки. Пас — пропуск хода. Два паса подряд → пас заблокирован. Финализация: 30 карт → deck_cards каждого.

Маркеры клеток
place_cell_marker (Крондак, Огр). Клетка занята — нельзя встать, прыгнуть, инкарнировать. Тик в afterEndPhase по timing: end_of_opponent_turn.

8. Как добавить карту
SQL: UPDATE cards SET prop = '...' WHERE ukid = 's1_XX';

Сценарий: debug/scenarios/xxx.json

Запуск: php tools/seed.php xxx, открыть ?first&game=N&debug=1.

Проверка: debug-панель — modifiers, markers, flags, strike, pending_*.

Помни: seed.php читает prop при создании партии. После SQL — пересоздать партию.

9. Тестовые сценарии (актуальные)
Файл	Что проверяет
master.json	базовый бой
start_phase.json	яд + реген + фаза начала
seeker.json / grezy.json / troll.json	пророчества
znahar.json / valhalla.json	Вальхалла, воскрешение
attacks.json	Минотавр, Барака, Берсерк
undine.json / undead.json	Ундина, Зомби, Вурдалак
kamnedrev.json	Камнедрев, монеты
gryz.json / oury.json / ice_guard.json	разные карты
combat_instant.json / end_instants.json / turn_instants.json	окна инстантов
kron_dak.json	маркеры клеток
chronos.json	Хронос, Пустотник
isha.json	Исхарская ненасыть
machinator.json	Махинатор
otrad.json	Отряд карателей
rezchik.json	Резчик идолов
spider.json	Паук (Этап 1)
ogre.json	Древний огр, Мантикора
naemnik_zmei.json	Наёмник, Мерцающий змей
drakkarh_golovorez.json	Драккарх, Головорез
strazh.json	Страж чертогов
vladyka.json / argv.json	Владыка небес, Аргвальд
shchitonosec.json	Щитоносец, Санкторум
basaarg.json	Гном-басаарг
condor.json	Пустынный кондор
wound_transfer.json	Отшельница, Волхв
10. Backlog
Рефакторинг
ActionResolver → стратегии — поэтапно, по 3-4 стратегии за заход.

renderStrike — разбить на классы/методы. ~600 строк в одном методе, 20+ elseif по kind. → StrikeResultView, StrikeChoiceView, DiceView, InstantStackView.

cancelPending → метод в ChoiceHandlerInterface. Каждый хендлер знает свой cancel().

choose* → процессоры (CoinSpendProcessor, ReviveProcessor, MultiHealProcessor, ...).

Ауры — общий паттерн aura в prop + CardStats::getAuraModifiers.

Badge — унификация рендера модификаторов/маркеров (Badge, buildPileReveal, buildDebug).

Дубли hasAnyStrike — оставить только в CardStats.

InfoPanel::renderProphecy → ProphecyChoice.

Инстанты
Бешеный маг — combat-окно, отложено на рефакторинг стека.

UI стека — LIFO отображение (сделано), авто-пас (сделано).

Ост — перестановка окна 2 и waiting_choice (проверено, работает).

Мэри-Чёрная метка — проверить damage_on_dice.

Окно 3 для turn-инстантов пассивного — работает.

Карты
Паук-пересмешник — Этап 2 (сеть). Маркер spider_web, состояние «не может действовать», снятие в начале хода владельца.

Паук — Этап 3 (атака своих). Отдельная фича.

Хозяин склепа (s1_163) — on_any_death с cause: any, filter: not_flying.

Эльфийский воин (s1_97) — авто-выстрел после атаки (включая промах), фильтры Резчика и zov.

Эорвал (s1_197) — условные модификаторы при 4+ классах рядом.

Орк-бомбардир, Тварь, Варлок, Линнет, Тич — средние/сложные.

Гномий король, Рагнар, Криомант, Призывающая бурю, Тан Ханеранга, Владыка небес, Аргвальд, Тан Ханеранга — горные (часть сделана).

100+ карт из дампа — не тронуты.

Механики
Фаза 0 (pre_turn_start) — Оборотень отдельным шагом.

Фаза конца хода — Алвалинд (выбор цели разряда), порядок переключения фаз.

Открытие существ — перенести в pre-turn.

pending_any_death — при множественной смерти от яда может залипнуть.

Атака своих — общая фича для Паука и др.

seed.php — поддержка coins, flags, markers в сценариях.

zoda — легаси, убрать.

Инфраструктура
game_events в БД — миграция логов из файлов для статистики.

Dice/Rng — единый класс бросков.

Точный реплей — снапшоты + RNG.

AJAX / polling / таймер хода.

Бустер / силед / драфт — драфт работает как MVP.

Унификация layout экранов — layout.tpl с тремя слотами (main/side/bottom), общие CardInfoLoader, CardView, CardInfoPanel, ElementView.

Дизайн
game_over, кладбище/изгнание, иконки действий, маркеры как монетки.

11. Правила работы
Сервер-авторитарный. Клиент только отправляет команды и рендерит state.

ZoneManager — единственный путь переходов между зонами.

Engine::apply — единственная точка входа. PRG-редирект после каждой успешной команды.

Debug-панель включается ?debug=1 (в сессии).

seed.php — быстрый старт партии из JSON для тестов.

После SQL — пересоздать партию (seed.php), иначе prop не обновится.

12. Известные проблемы
Кубики в окне 2 — отрисовываются самопально, не через strike_dice.tpl.

Открытие существ в начале хода — должно быть до фазы начала, сейчас внутри.

pending_any_death — при смерти нескольких карт от яда может потребовать нескольких раундов.

Реген — если карта целая, но с ядом, wouldRegenerate учитывает (исправлено).

Раскрытие скрытого ряда — флаг hidden_row_revealed (исправлено).

UI-регресс с mode после immediate-действий (gain_coin и т.п.) — подсветка целей остаётся.

Миграция старых партий — новые поля в battle/state отсутствуют (нужен ?? null при чтении).

Что обновлено
Структура: добавлены InstantProcessor, ProphecyProcessor, WoundTransferProcessor, DraftProcessor, BoosterGenerator, GameLog, Choice/ (полный список хендлеров), ModeScreen, DraftScreen, Panel, PanelSpec, шаблоны драфта и режима, tools/test_booster.php, storage/.

Жизненный цикл: добавлены стадии mode и draft.

Модель состояния: добавлены status, mode, draft, hidden_row_revealed.

Движок: переписан раздел делегирования, ChoiceRegistry::byCommandType встроен в Engine::apply.

Сражение: окна инстантов описаны точно, эффекты дополнены.

Ключевые механики: добавлены защиты (zoa/zoan/zoal, ignore), damage_reduction с фильтрами, ауры, новые условия, ability с расширенными фильтрами, direct/unanswer, turn-инстанты, драфт, перераспределение ран.

Сценарии: актуализирован список (30+).

Беклог: сгруппирован, убрано сделанное, добавлены новые задачи.

Известные проблемы: добавлены реген, скрытый ряд, миграция.

