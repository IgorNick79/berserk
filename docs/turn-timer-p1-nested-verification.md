# P1: проверка владельцев вложенных боевых ожиданий

Расхождений в пяти проверенных mapping не обнаружено. Production-код P1
в рамках этой проверки не изменён. Новые изменения проверки — этот отчёт и
tools/test_nested_combat_ownership.php; в основной документ P1 добавлена ссылка.

## Mapping и фактические проверки доступа

Все пять команд передают исходный playerKey из Engine::doApply в указанные
обработчики без подмены участника. ChoiceRegistry проверяет допустимый тип
команды при наличии более приоритетного выбора; P1 не меняет эти проверки.

| Ожидание | Команда | Фактический владелец | Серверная проверка | Источник в BattleWaitResolver | Результат |
| --- | --- | --- | --- | --- | --- |
| strike.waiting_auto_target | choose_auto_target | Владелец источника автоэффекта | Engine.php:873–877: getCard(pending_auto.source_id), source.owner === playerKey | BattleWaitResolver.php:142–143: nestedCardDecision(..., pending_auto, source_id) | Совпадает |
| strike.waiting_push_choice | choose_push_choice | Владелец защищающейся карты | StrikeResolver.php:1784–1787: getCard(pending_push.defender_id), defendCard.owner === playerKey | BattleWaitResolver.php:144–145: pending_push.defender_id | Совпадает |
| strike.waiting_close_or_damage | choose_close_or_damage | Владелец защищающейся карты | StrikeResolver.php:1988–1991: getCard(pending_close_or_damage.defender_id), defCard.owner === playerKey | BattleWaitResolver.php:146–147: pending_close_or_damage.defender_id | Совпадает |
| strike.waiting_ally_modifier | choose_ally_modifier | Владелец источника модификатора | StrikeResolver.php:2084–2087: getCard(pending_ally_modifier.source_id), source.owner === playerKey | BattleWaitResolver.php:148–149: pending_ally_modifier.source_id | Совпадает |
| strike.pending_choice | choose_death_target | Владелец погибшей карты | DamageResolver.php:415–420: getCard(pending_choice.died_id), died.owner === playerKey | BattleWaitResolver.php:112–114: pending_choice.died_id, до родительского results | Совпадает |

Здесь владелец карты — непосредственный источник авторизации команды, а не
эвристика по владельцу атакующего, выбранной цели или battle.active.
ActionResolver и InstantProcessor не переопределяют права этих пяти команд.
InstantProcessor может возвращать боевой lifecycle к results/выбору, но
исполнение перечисленных продолжений остаётся в Engine/StrikeResolver/
DamageResolver. Поэтому менять mapping или проверки прав не требуется.

## Реальные пути Engine

Для каждой стороны (host/player) создаются обычный атакующий, противник,
союзник с магическим действием и удалённая вражеская цель. Таймер принадлежит
атакующему. Все ожидания возникают из Engine::apply(strike), без ручной записи
pending и без вызовов open*Choice из теста. Используется существующий Dice::init
с debug_roll=6,1 и контролируемое серверное время Engine, без реального ожидания.

| Условие карты | Путь к ожиданию |
| --- | --- |
| prop.auto: shot, trigger=strike_hit | strike → results → два confirm_strike → waiting_auto_target |
| strike_effects.push | strike → results → два confirm_strike → waiting_push_choice; следующая клетка свободна |
| strike_effects.close_or_damage | strike → results → два confirm_strike → waiting_close_or_damage |
| strike_effects.grant_ally_modifier | strike → results → два confirm_strike → waiting_ally_modifier; есть подходящий союзник |
| on_death с target=choice у цели с 1 HP | strike убивает цель → results с pending_choice; источник доступен до finalizeDying |

Для каждого пути проверены:

- Read-only проекция реального ожидания до и после JSON-сериализации.
- Отказ фактической команды другому игроку, сохранение pending и его владельца.
- Диагностический unknown без выдуманного владельца при отсутствии именно той
  карты, по которой обработчик авторизует решение; режим таймера сохраняется.
- Решения автоэффекта и модификатора расходуют время атакующего.
- Push, close-or-damage и выбор при смерти цели сохраняют opponent_wait.
- После применения автоэффекта сражение завершается и таймер работает.
- После остальных решений results всё ещё требует обоих участников: пауза не
  снимается преждевременно. ACK противника возобновляет сохранённый остаток,
  ACK владельца завершает сражение через существующий lifecycle.
- period_id и owner таймера не меняются, новый лимит не выдаётся.

Все 10 путей воспроизведены полностью; недостижимых состояний в этом наборе нет.
Конфигурации карт минимальные prop-fixtures для существующих механизмов. Тест
не импортирует каталог карточек и не моделирует все комбинации их эффектов.

## Результаты проверок

Прошли 19 PHP suites: test_nested_combat_ownership, test_pending_wait_timer,
test_turn_timer, test_turn_timer_sync (unit и HTTP), test_turn_instant_stack,
test_combat_instant_phases, test_combat_instant_ui, test_resign_pending,
test_degasca, test_row_spell_pending_dice, test_battle_auto_target_ui,
test_battle_ally_modifier_ui, test_battle_death_choice_ui,
test_cell_marker_cancel, test_damage_lifecycle,
test_command_journal_repository, test_repository_optimistic_locking,
test_battle_command_endpoint, test_battle_sync_endpoint.

Прошли Node suites test_turn_timer_client.js и test_ajax_multi_target.js, PHP
lint и git diff --check. БД/HTTP использовали disposable копию приложения и
изолированную berserk_turn_timer_t2_test на MariaDB/InnoDB :33916, PHP :8796.
Guard проверяет datadir, имя БД и HTTP source tree. Production DB не менялась.

P2/T3, новые таймеры, автоматические подтверждения и изменения прав доступа
не реализованы. Список unknown и существующий приоритет вложенных решений P1
сохранены и повторно проверены test_pending_wait_timer.php.

По явному последнему указанию пользователя P1 и эта проверка отправляются
коммитом в существующую ветку codex/turn-timer-t1. P1 до проверки находился
в рабочем дереве без коммита, поэтому общий diff коммита включает его реализацию.
