# Stage 3B — предварительный read-only аудит

27 октября 2026. Аудит выполнен до изменения исполняемого кода Stage 3B.
Изучены ChoiceRegistry (62 handlers), BattleWaitResolver, Engine,
InstantReservation, CombatContinuation, InformationalWaitManager/AutoAck,
StrikeResolver и маршруты apply существующих обработчиков.

## Правила матрицы

Владелец хода: battle.active, совпадающий с владельцем периода резерва/таймера.
Владелец обязательного решения берётся из фактического BattleWaitResolver,
не из предположения о стороне хода.

**A**: явный allowlist внешних выборов; без strike, combat_continuation,
объявлений/разрешения инстантов. Наличие pending/required_players само по себе
недостаточно. Нужны корректный owner, идентичность и проверенный контекст.
**B**: дополнительные ограничения конкретной стадии/continuation.
**C**: новая регистрация запрещена; старый обработчик остаётся без изменений.

**ID**: штатный id/choice_id, если есть, либо серверный хеш payload, источника,
периода и версии. Жизненный цикл связывается с корнем выбора, а не с изменяемым
step. Сам pending не дополняется и не приостанавливается.

**S**: конец успешной Engine-команды после штатных синхронных continuation;
все pending/strike/фазы/очереди должны закончиться до открытия нового стека.
**N**: уже открытое штатное turn-окно с приоритетом запросившего. Оно погашает
запрос без второго окна, изменения приоритета или continuation. Granted остаётся
до завершения всей связанной цепочки, а не только текущей команды.
**D**: смерть, подавление, отмена и невалидная цель обрабатываются прежним handler;
резерв не меняет его стоимость/результат. Исчезновение исходной карты вообще,
смена периода/конец матча или отсутствие применимых инстантов снимают запрос.
**Q**: возможны вложенные очереди/синхронные переходы без нового пользовательского
действия. Запрос ждёт S/N. Нового автоматического исполнителя не создаётся.

У всех A/B контекст внутреннего combat-resolution запрещает новую регистрацию.
Уже зарегистрированный запрос сохраняется на время дочерних продолжений.

## Полный реестр

| Handler / ключ | Владелец | Класс / контекст | ID | Команды | Continuation / граница | Смерть, отмена, авто |
|---|---|---|---|---|---|---|
| DealLinkedRecruitChoice / pending_deal_linked_recruit | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_deal_linked_recruit, cancel_pending | штатный apply; S/N | старое поведение |
| DealScoutRecruitChoice / pending_deal_scout_recruit | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | confirm_deal_scout_recruit, close_deal_scout_recruit, cancel_pending | штатный apply; S/N | старое поведение |
| DealVariableRecruitChoice / pending_deal_variable_recruit | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_deal_variable_recruit, cancel_pending | штатный apply; S/N | старое поведение |
| MatriarchOrderChoice / pending_matriarch_order | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_matriarch_order, cancel_pending | штатный apply; S/N | старое поведение |
| CoinAbilityChoice / pending_coin_ability | owner | A — только внешний контекст | ID/корень | choose_coin_ability | gain/block/continue; возможен бой → N/S | D; Q при цепочке; отдельного auto-ACK нет |
| FirstStrikeJumpChoice / pending_first_strike_jump | owner | A — только внешний контекст | ID/корень | choose_first_strike_movement | jump → pull со сменой owner; coin queue → S/N | D; Q при цепочке; отдельного auto-ACK нет |
| CoinSpendChoice / pending_coin_spend | карта attacker_id | A — только внешний контекст | ID/корень | choose_coin_spend | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| DiceChoiceChoice / pending_dice_choice | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_dice_choice | штатный apply; S/N | старое поведение |
| AfterStrikeExecuteChoice / pending_after_strike_execute | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_after_strike_execute | штатный apply; S/N | старое поведение |
| CellMarkerChoice / pending_cell_marker_pick | owner | A — только внешний контекст | ID/корень | choose_cell_marker, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| CellEffectDirectionChoice / pending_cell_effect_direction | owner | A — только внешний контекст | ID/корень | choose_cell_effect_direction | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| InstantPickChoice / pending_instant_pick | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_instant_pick, cancel_pending | штатный apply; S/N | старое поведение |
| CombatPickChoice / pending_combat_pick | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_combat_pick | штатный apply; S/N | старое поведение |
| ForcedStrikeAdjacentChoice / pending_forced_strike_adjacent | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_forced_strike_adjacent, cancel_pending | штатный apply; S/N | старое поведение |
| SwampQueenSummonChoice / pending_swamp_queen_summon | owner | A — только внешний контекст | ID/корень | choose_swamp_queen_card, choose_swamp_queen_cell | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| DegascaSequenceChoice / pending_degasca_sequence | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_degasca_card | штатный apply; S/N | старое поведение |
| RangedAttackRedirectChoice / pending_ranged_attack_redirect | owner | B — проверка внешнего корня атаки | ID/корень | choose_ranged_redirect | возобновление атаки; Q → N/S | D; Q при цепочке; отдельного auto-ACK нет |
| IncarnationWoundChoice / pending_incarnation_wound | [0].owner | A — только внешний контекст | ID/корень | choose_incarnation_wound | очередь/смерть/инкарнация; Q → S/N | D; Q при цепочке; отдельного auto-ACK нет |
| IncarnationAckChoice / pending_incarnation_ack | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | incarnation_ack | штатный apply; S/N | старое поведение |
| IncarnationChoice / pending_incarnation | owner | A — только внешний контекст | ID/корень | choose_incarnation_cell | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| ValhallaPickChoice / pending_valhalla_pick | owner | A — только внешний контекст | ID/корень | valhalla_pick, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| AnyDeathChoice / pending_any_death | [0].source_id.owner | A — только внешний контекст | ID/корень | choose_any_death_target, cancel_pending | очередь/смерть/инкарнация; Q → S/N | D; Q при цепочке; отдельного auto-ACK нет |
| WhipChoice / pending_whip | owner | A — только внешний контекст | ID/корень | choose_whip_target, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| KoboldHealChoice / pending_kobold_heal | owner | A — только внешний контекст | ID/корень | choose_kobold_heal, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| TalionIncarnationChoice / pending_talion_incarnation | owner | A — только внешний контекст | ID/корень | choose_talion_incarnation | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| HolvertOpenChoice / pending_holvert_open | owner | A — только внешний контекст | ID/корень | choose_holvert_open | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| AnubisarChainChoice / pending_anubisar_chain | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_anubisar_chain | штатный apply; S/N | старое поведение |
| WoundTransferChoice / pending_wound_transfer | owner | B — не hermit, только main_phase, вне боя | ID/корень | wt_source, wt_amount, wt_target, wt_target_amount, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| MultiHealChoice / pending_multi_heal | карта attacker_id | A — только внешний контекст | ID/корень | choose_multi_heal, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| MultiDischargeChoice / pending_multi_discharge | карта attacker_id | A — только внешний контекст | ID/корень | choose_multi_discharge, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| SelfWoundChoice / pending_self_wound | карта attacker_id | A — только внешний контекст | ID/корень | choose_self_wound | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| CardChoice / pending_card_choice | карта card_id | A — только внешний контекст | ID/корень | choose_card_option | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| IntrigueStartChoice / pending_intrigue_start | [0].owner | A — только внешний контекст | ID/корень | choose_intrigue_start | очередь/смерть/инкарнация; Q → S/N | D; Q при цепочке; отдельного auto-ACK нет |
| SeggerShieldChoice / pending_segger_shield | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | choose_segger_shield | штатный apply; S/N | старое поведение |
| DvergGiftChoice / pending_dverg_gift | owner | A — только внешний контекст | ID/корень | choose_dverg_gift | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| ShaksiRollChoice / pending_shaksi_roll | owner | A — только внешний контекст | ID/корень | choose_shaksi_roll | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| BloodTapChoice / pending_blood_tap | карта attacker_id | A — только внешний контекст | ID/корень | choose_blood_tap | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| HealInsteadWoundChoice / pending_heal_instead_wound | owner | B — проверка внешнего корня атаки | ID/корень | choose_heal_instead_wound, cancel_pending | возобновление атаки; Q → N/S | D; Q при цепочке; отдельного auto-ACK нет |
| AttackSeriesContinueChoice / pending_attack_series_choice | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | attack_series_next, attack_series_end, cancel_pending | штатный apply; S/N | старое поведение |
| WildHealerMoveChoice / pending_wild_healer_move | owner | A — только внешний контекст | ID/корень | choose_wild_healer_heal, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| ReviveChoice / pending_revive | карта healer_id | A — только внешний контекст | ID/корень | choose_revive_target, choose_revive_cell, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| GrezyChoice / pending_grezy | карта attacker_id | A — только внешний контекст | ID/корень | grezy_pick | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| ForcedDirectionalMoveChoice / pending_forced_directional_move | owner | A — только внешний контекст | ID/корень | choose_forced_directional_move, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| ForcedStrikeChoice / pending_forced_strike | owner (активный) | C — штатный declare требует активного игрока | ID/корень | choose_forced_strike | существующий принудительный бой | Не подключён: права пассивного выбора нет; карточная механика не меняется. |
| RowPickChoice / pending_row_pick | owner | A — только внешний контекст | ID/корень | choose_row, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| OpponentRowMarkerChoice / pending_opponent_row_marker | owner | A — только внешний контекст | ID/корень | choose_opponent_row_marker, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| RowSpellPickChoice / pending_row_spell_pick | owner | A — только внешний контекст | ID/корень | choose_row_spell_targets | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| GatePickChoice / pending_gate_pick | owner | A — только внешний контекст | ID/корень | choose_gate | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| GreedTeleportChoice / pending_greed_teleport | owner | A — только внешний контекст | ID/корень | choose_greed_teleport | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| DestroySelfAndTargetChoice / pending_destroy_self_and_target | owner | A — только внешний контекст | ID/корень | choose_destroy_self_and_target, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| StrangulationChoice / pending_strangulation | owner | A — только внешний контекст | ID/корень | choose_strangulation, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| ParticlePickChoice / pending_particle_pick | owner | A — только внешний контекст | ID/корень | choose_particle_pick, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| BloodStormChoice / pending_blood_storm | owner | A — только внешний контекст | ID/корень | choose_blood_storm, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| LifeGiftChoice / pending_life_gift | owner | A — только внешний контекст | ID/корень | choose_life_gift, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| NokamiWoundChoice / pending_nokami_wound | [0].owner | A — только внешний контекст | ID/корень | choose_nokami_wound, cancel_pending | очередь/смерть/инкарнация; Q → S/N | D; Q при цепочке; отдельного auto-ACK нет |
| TeleportTargetChoice / pending_teleport_target | owner | A — только внешний контекст | ID/корень | choose_teleport_cell, cancel_pending | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| RangedNeighborTeleportChoice / pending_ranged_neighbor_teleport | owner | A — только внешний контекст | ID/корень | choose_ranged_neighbor_teleport | штатный apply; S/N | D; Q при цепочке; отдельного auto-ACK нет |
| TurnAckChoice / turn_phase | side/passive_key/active_key | C — owner ACK не определён | ID/корень | turn_ack | advance/resume/finish → N/S | старое поведение |
| TurnInstantResultChoice / turn_instant_result | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | turn_instant_result_ok | штатный apply; S/N | старое поведение |
| TurnInstantStackChoice / turn_instant_stack | owner | C — подготовка/внутренний бой/инстант/неоднозначный ACK или continuation | ID/корень | play_turn_instant, pass_turn_instant | штатный apply; S/N | старое поведение |
| TurnSubChoice / turn_phase | side/passive_key/active_key | B — только start/passive, не instants | ID/корень | turn_sub, turn_sub_close | advance/resume/finish → N/S | D; Q при цепочке; отдельного auto-ACK нет |
| TurnPhaseChoice / turn_phase | side/passive_key/active_key | B — только start/passive, не instants | ID/корень | turn_task | advance/resume/finish → N/S | D; Q при цепочке; отдельного auto-ACK нет |


## Состояния вне отдельных handlers

| Окно | Владелец / ID | Категория | Завершение и продолжение | Граница / автоматическое завершение |
|---|---|---|---|---|
| strike.waiting_defender | противник attacker.owner; _resolution_id атаки | B | choose_defender/choose_defender_option/choose_mage_redirect → resolve | N в штатном after либо S после всего боя. Не повторять уже завершённое before. |
| strike.waiting_redirect | противник attacker.owner; тот же ID атаки | B | choose_redirect/choose_mage_redirect → выбор защитника/resolve | N/S; без нового окна перед dice/main. |
| strike.results | ещё не подтвердившие стороны; валидный wait_id | B | confirm_strike, в том числе InformationalAutoAck → штатные продолжения | Только финальный result, не dice-result, не wounds/instant_resolution; N/S. Deadline не сбрасывается резервом. |
| strike.waiting_push_ack | ещё не подтвердившие стороны; валидный wait_id | B | confirm_strike/auto-ACK → завершение push | N/S; старый wait_id не применим к новому окну. |
| waiting_choice/auto_target/push_choice/close_or_damage/ally_modifier, strike.pending_choice | BWR по карте/победителю | C | старые choose_* | Внутренние боевые решения не расширяются. |
| waiting_instant/instant_resolution/pending_wounds_resolution/instant_result_stage=dice | боевой приоритет/owner | C | штатные combat-команды | Новый turn-стек запрещён. |
| pending_prophecy, reorder/summon | owner | C | старые prophecy-команды | Неоднозначный legacy-контекст; без новой общей идентичности. |
| pending_dive | owner | C | choose_dive_cell/cancel | Legacy, без отдельной доказанной точки продолжения. |
| pending_any_death_degasca_sequence, pending_nokami_wound_degasca_sequence, pending_degasca_deferred_sequences | хранилище продолжения | C для регистрации | drain/restoreAndResume | Не завершать ожидающий free до опустошения. |
| coin_post_combat_queue/continuation, combat_continuation.frames/stacks | владельцы дочерних окон | C для новой регистрации | штатные advance/settle | Ожидающий запрос ждёт N/S; хранилища не заменяются. |
| неизвестный pending/некорректная структура/неопределённый owner | unknown | C | старое безопасное поведение | Не выводить право из догадок. |

## Решение по защитнику и план

Фактический порядок: declare → strike_before → выбор защитника → dice/main →
результат/продолжения → strike_after. Перенос before после выбора защитника
изменил бы порядок игры и не предлагается. Минимальное решение: free из
waiting_defender/redirect привязан к текущей атаке и ждёт штатное after-окно
либо полное завершение. Существующее after погашает запрос, второго окна нет.

1. Добавить явную чистую политику разрешённых окон и серверный binding.
2. Автоматически выбирать free/paid в reserve_instant; повторный запрос
   не меняет причину/стоимость уже ожидающего. Pending остаётся работоспособным.
3. На существующем post-command пути отслеживать освобождение исходного выбора,
   ждать вложенные продолжения и открывать штатный стек на S или использовать N.
4. Подготовить чистую проекцию режима/остатка/запроса/отмены/приоритета в AJAX
   snapshot/no_change. Кнопок и дизайна не добавлять.
5. Проверить оба игрока, multi-step/очереди/forced combat, defender/results,
   auto-ACK, запреты, отдельную InnoDB и два реальных HTTP-процесса, регрессии.

Фундаментального изменения модели не требуется. Фактически подключённые
контексты и результаты будут отдельно перечислены в итоговом отчёте.
