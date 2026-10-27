# Stage 3B — результат

27 октября 2026. Реализация ограничена серверным бесплатным резервированием.
Предварительная матрица: [instant-free-stage3b-audit.md](instant-free-stage3b-audit.md).
Она содержит все 62 обработчика ChoiceRegistry и дополнительные ожидания BattleWaitResolver.

## Поведение и точки продолжения

Используется прежний InstantReservation: idle → requested → granted → idle,
один запрос, тот же period_id/request_id и существующие команды. Сервер выбирает
free по явной политике FreeInstantWindows и фактическому владельцу решения.
reason из клиента цену не задаёт. Повтор уже ожидающего платного запроса сохраняет
его ID и платную причину. Бесплатный работает при limit=0 и used=limit.

Origin сохраняет source, серверный choice_id, binding корня, instant_phase и released.
Идентичность первоначального выбора включает игру, период, версию и payload;
неподвижный binding отслеживает карту/владельца источника, а для боя — resolution_id
либо wait_id. Optional origin_choice_id в reserve_instant отвергает запоздавший
клик по уже закрытому бесплатному окну, включая случай, когда теперь допустим paid.
Клиенту будущего UI следует передавать этот токен вместе с expected_version.

Регистрация не переписывает pending, его owner, ресурсы, стадии или сроки ACK.
После успешной команды используется существующий post-command путь Engine:
CoinAbilities.advance → CombatContinuation.settle → Matriarch.advance →
штатное продолжение start → InformationalWaitManager.reconcile →
InstantReservation.afterCommand → TurnTimer.reconcileWaiting.

Две допустимые границы:

1. **S**: закончились весь исходный выбор и его синхронные/вложенные продолжения.
   Нет BattleWaitResolver-ожидания, strike, turn_phase, pending_*, combat_continuation,
   turn-стека/результата, matriarch_order, coin_post_combat или информационного ACK.
   Открывается обычный turn_instant_stack со старым continuation instant_reservation.
2. **N**: продолжение само открыло штатное turn-окно, и приоритет уже принадлежит
   запросившему игроку. Запрос отмечается granted с grant_kind=native; исходный
   стек, его приоритет и continuation не меняются. Дополнительного окна нет.
   Пока активны combat_continuation или coin_post_combat очереди/continuation,
   окно дочернего боя запрос родителя не погашает.

Корень multi-step удерживается до окончания всех его стадий, включая смену owner
в jump → pull. При смене головы очереди released становится true, но S запрещена
до опустошения остальных очередей. Ожидающий free запрещает новое самостоятельное
move/jump/gain_coin/action/uchr/strike/end_turn/open_turn_instants; команды исходного
выбора и штатные внутренние продолжения работают. Это исключает самостоятельный
ход между закрытием выбора и выдачей уже ожидающего приоритета.

При выдаче повторяется общий getInstants Stage 3A. Утрата доступности/целей,
исчезновение исходной карты или смена её владельца, конец периода/матча и истечение
времени безопасно снимают запрос без расхода. Смерть карты, остающейся в игровом
состоянии, обрабатывается исходным handler; независимый живой источник инстанта
по-прежнему может получить приоритет после завершения всех продолжений.

Порядок нападения сохранён: **before → defender → dice/main → результат/wounds/
продолжения → after**. Резерв в выборе защитника ждёт after или полного завершения
атаки. Уже прошедшее before не повторяется. Это описанное в предварительном аудите
минимальное подключение без перестановки боевых фаз.

Results/push ACK допускаются только с валидной native-метаинформацией и до её
deadline; dice-result и незавершённый combat-resolution исключены. Резерв не
перезапускает deadline/wait_id. Обычный journaled InformationalAutoAck проходит
тот же Engine-путь и выдаёт приоритет на той же границе. Отказ от самостоятельно
открытого пустого стека использует decline_instant_priority; для уже существующего
native-окна остаётся обычный pass, чтобы не потерять его continuation.

## Реализованный список

Во всех случаях дополнительно обязательны пассивная сторона, её фактическое
управление через BWR, корректный корень и отсутствие внутреннего combat-resolution.
Следующие 42 ключа входят в явный allowlist внешних решений:

```
pending_coin_ability                 pending_first_strike_jump
pending_coin_spend                   pending_cell_marker_pick
pending_cell_effect_direction        pending_swamp_queen_summon
pending_ranged_attack_redirect       pending_incarnation_wound
pending_incarnation                  pending_valhalla_pick
pending_any_death                    pending_whip
pending_kobold_heal                  pending_talion_incarnation
pending_holvert_open                 pending_wound_transfer
pending_multi_heal                   pending_multi_discharge
pending_self_wound                   pending_card_choice
pending_intrigue_start               pending_dverg_gift
pending_shaksi_roll                  pending_blood_tap
pending_heal_instead_wound            pending_wild_healer_move
pending_revive                       pending_grezy
pending_forced_directional_move       pending_row_pick
pending_opponent_row_marker           pending_row_spell_pick
pending_gate_pick                    pending_greed_teleport
pending_destroy_self_and_target      pending_strangulation
pending_particle_pick                pending_blood_storm
pending_life_gift                    pending_nokami_wound
pending_teleport_target              pending_ranged_neighbor_teleport
```

Уточнения категории B: wound_transfer допускается только вне hermit и с
on_finish=main_phase; heal_instead_wound при живом strike — только внешняя
waiting_resolve_after_heal в waiting_defender. Ranged redirect должен находиться
вне активного strike/внутреннего боевого разрешения.

Дополнительно условно подключены start/passive turn_phase (TurnPhaseChoice/
TurnSubChoice, без pending_ack и sub.parent_type=instants), strike.waiting_defender,
strike.waiting_redirect, strike.results, strike.waiting_push_ack. Последние два
требуют native wait_id, первые два — resolution_id.

## Исключения

Новая регистрация запрещена для:

- DealLinkedRecruit/DealScoutRecruit/DealVariableRecruit — подготовка;
- MatriarchOrder, DiceChoice, AfterStrikeExecute, InstantPick, CombatPick,
  ForcedStrikeAdjacent, DegascaSequence, AnubisarChain, SeggerShield,
  AttackSeriesContinue — внутреннее объявление/разрешение или continuation;
- IncarnationAck и неопределённый TurnAck — неоднозначный владелец/контекст;
- TurnInstantResult/TurnInstantStack — уже существующая инстантная цепочка;
- ForcedStrikeChoice/pending_forced_strike — штатный declare требует активного
  игрока; пассивное право не вводилось. Это уточнение первоначальной классификации;
- legacy prophecy/reorder/summon, dive; неизвестные или неполные payload без корня;
- strike.waiting_choice/auto_target/push_choice/close_or_damage/ally_modifier,
  strike.pending_choice, waiting_instant, instant_resolution, pending_wounds_resolution,
  instant_result_stage=dice;
- хранилища degasca, coin_post_combat и combat_continuation — ожидание уже
  принятого запроса продолжается, нового права регистрации они не дают;
- активная сторона, end/start-active, пауза, просроченный информационный ACK,
  завершённый матч или некорректный таймер.

Присутствие ключа из allowlist не делает активное карточное действие пассивным:
если его реальный producer передаёт управление только активной стороне, free там
фактически недоступен. Карточные условия вызова не расширялись.

## Серверная проекция

BattleSnapshotRenderer и все пять timer/snapshot/status/no_change путей index.php
возвращают instant_reservation: can_free/can_paid/can_reserve, mode, limit/used/
remaining, status/requested, request_owner/reason/id, can_cancel/can_decline,
priority_owner/required_players, origin_choice_id. Она чистая, не раскрывает
payload/binding/candidates, не меняет таймер или владельца исходного выбора.
Кнопки, шаблоны и JavaScript не изменялись.

## Файлы именно Stage 3B

- src/Core/FreeInstantWindows.php — новая явная политика, identity/binding и N.
- src/Core/InstantReservation.php — автоматический режим, tracking free, выдача,
  блокировка самостоятельного действия, чистая controls-проекция.
- src/View/Screen/BattleSnapshotRenderer.php, www/index.php — данные проекции.
- tools/test_free_instant_reservation.php — новые unit/Engine/continuation-сценарии.
- tools/test_free_instant_reservation_sql.php — изолированная InnoDB/конкурентность/auto-ACK/rollback.
- tools/test_free_instant_reservation_http.php — реальные HTTP-команды и две сессии.
- tools/test_instant_reservation.php, tools/test_instant_availability.php — прежние
  fixtures приведены к реально подключённому free и запрету самостоятельного gain_coin.
- docs/instant-free-stage3b-audit.md, docs/instant-free-stage3b-results.md — аудит/отчёт.

Изменения Stage 2/3A, уже находившиеся в рабочем дереве, сохранены.

## Проверки

Последний запуск нового Engine-набора: **877 проверок**. Количество может немного
меняться из-за числа команд штатного случайного боя; утверждения границ постоянны.
Проверены обе ориентации и quota=0/исчерпание, неизменность pending/ресурсов/таймера,
revive card→cell, отмена запроса и штатная отмена исходного выбора, LIFO, reload,
повтор/stale token, jump→pull со сменой owner, очередь any_death, реальная гибель
source через choose_self_wound, исчезновение карты/цели, потеря способности,
период/матч/таймаут, сохранение цены paid, results/push ACK, before/defender/after,
реальные dice/main и объявленное отложенное wounds, принудительный вложенный бой.
Для каждого allowlist-ключа отдельно запущены synthetic policy/registration
fixtures в обеих ориентациях. Они подтверждают admission и неизменность payload;
это не заявление о полном end-to-end прохождении каждой карточной способности.

**Изолированная MariaDB 10.8.4 / InnoDB, порт 33992: 210 проверок**, включая
71 повторную проверку Stage 2. Datadir проверяется по @@datadir и @@port; свежая
схема berserk_ir_* (точное имя записано в sql-evidence.json). Реальные процессы с отдельными соединениями проверяют
обе последовательности reserve/finish и cancel/grant, одновременные команды двух
игроков, duplicate processing/committed, stale version/origin. Проигравший CAS не
входит в Engine; самостоятельное действие при обязательном выборе не исполняется.
Четыре native InformationalAutoAck-кейса: results/push × обе стороны. Ошибка записи
после final choice+grant откатывает game, стек, журнал и history; retry выдаёт один
free и оставляет used=0. Используется обязательный Recorder/история.

**Два реальных HTTP-процесса, порты 18881/18882: 73 проверки**, включая 46 Stage 2.
Free reserve и final choice одного игрока отправлены из разных авторизованных
сессий: обе команды доходят до journal.processing, пока третий коннект держит
game FOR UPDATE. Тем самым исключена ложная сериализация session-lock PHP.
В последнем запуске получены обе последовательности победителя, по одной на
ориентацию: committed/conflict и conflict/committed. Проверены reload/no_change
проекции, повтор после потери ответа и запрет превращения stale free в paid.

Все **27 регрессионных наборов прошли**:

```
free_instant_reservation, instant_reservation (169), instant_availability (332),
turn_instant_stack, combat_instant_phases, combat_instant_ui, combat_continuation,
matriarch_boundaries, pending_wait_timer, informational_wait, turn_timer,
turn_timer_sync, turn_timeout, turn_timeout_review, friendly_fire,
creature_choice_followup, battle_defender_ui, match_pause,
effective_feature_continuations, action_resolution, action_resolution_persistence,
swamp_children, swamp_queen, flesh_stitcher, oyuun_holvert,
soul_eater_shaman, lord_of_the_dead.
```

В turn_timer_sync пройдены unit/snapshot/UI assertions; его отдельная старая
HTTP-ветка пропущена из-за отсутствия TURN_TIMER_TEST_URL. Реальные новые HTTP
проверки выше запущены отдельно. PHP lint всех девяти затронутых PHP-файлов и
git diff --check проходят.

Доказательства: C:/Users/mi/Documents/Berserk/instant-free-stage3b-validation/
regressions.json, free-sql-evidence.json, free-http-evidence.json, sql-evidence.json,
http-evidence.json, baseline/, registry-audit.json и логи тестовых серверов.
Рабочая БД не изменялась; приложение HTTP использовало отдельную копию и отдельные
тестовые credentials. Временные PHP/MariaDB после проверок остановлены.

## Инварианты и ограничения

Engine, InstantProcessor, StrikeResolver, CombatContinuation, InformationalWaitManager,
TurnTimer и ExpiredCommandGuard побайтно совпадают с сохранённой до Stage 3B базой.
Не изменены prop, эффекты карт, LIFO/два паса, боевые фазы, no_close/effectiveProp,
разрешение уже объявленных инстантов и существующие pending/continuation.
Универсального resume_choice и новой системы стеков/таймеров нет.

Новые end-to-end сценарии используют синтетические состояния с реальными
обработчиками; актуальные полные карточные конфигурации рабочей БД заново не
проверялись. Известные карточные fixtures покрыты перечисленными регрессиями.
Конкретные исключённые legacy/внутренние окна требуют отдельного согласования,
если позднее понадобится расширить admission. Новые handlers по умолчанию закрыты.
Упрощённый UI и отдельные кнопки не добавлены; дальнейший UI-этап не начат.
Commit/push/deploy не выполнялись.
