# Turn Timer P1 — ownership projection and automatic pause/resume

## Architecture

`BattleWaitResolver::current(GameState)` is read-only. It returns null when there
is no mandatory wait, otherwise `{kind, required_players, source, reason}`.
Kinds are decision, informational, unknown. Unknown always has an empty player
list and a diagnostic reason; that empty list is NOT evidence of no opponent wait.
The projection is not persisted, does not advance a queue and executes no action.

The existing ChoiceRegistry selects the active handler. P1 does not modify the
registry, its interface, dispatch order, or card handlers. The resolver reads the
same ownership fields as their current server validation. Nonempty unregistered
pending keys and malformed phases conservatively return unknown. Registered
handlers without an explicit mapping also return unknown, rather than assuming
that an arbitrary owner field is authoritative. A coverage test requires every
registered handler to have a fixture.

After registered choices, legacy panel priority is prophecy, strike, dive, as in
InfoPanel. A nested strike.pending_choice takes priority over the parent result
ACK. Other nested choices require their matching strike state; unknown or
mismatched nested pending cannot be treated as a safe informational result.

## Supported waits

P below is the selected pending. All listed choices are decision waits except
the explicitly informational/unknown rows. Ownership is read from persisted
server data; battle.active is never used as an ownership fallback.

| Pending / stage | Required players / source |
| --- | --- |
| pending_deal_linked_recruit, pending_deal_scout_recruit, pending_deal_variable_recruit | P.owner; preparation normally has no battle clock |
| pending_coin_ability, pending_dice_choice, pending_after_strike_execute | P.owner |
| pending_cell_marker_pick, pending_instant_pick, pending_combat_pick, pending_forced_strike_adjacent | P.owner |
| pending_ranged_attack_redirect, pending_incarnation, pending_valhalla_pick | P.owner |
| pending_whip, pending_kobold_heal, pending_talion_incarnation, pending_holvert_open, pending_anubisar_chain | P.owner |
| pending_wound_transfer, pending_segger_shield, pending_dverg_gift, pending_shaksi_roll | P.owner, including their existing choice stages |
| pending_heal_instead_wound, pending_attack_series_choice, pending_wild_healer_move | P.owner |
| pending_forced_directional_move, pending_forced_strike | P.owner |
| pending_row_pick, pending_opponent_row_marker, pending_row_spell_pick | P.owner |
| pending_gate_pick, pending_greed_teleport, pending_teleport_target | P.owner |
| pending_destroy_self_and_target, pending_particle_pick, pending_blood_storm, pending_life_gift | P.owner |
| pending_coin_spend, pending_multi_heal, pending_multi_discharge, pending_self_wound, pending_blood_tap, pending_grezy | card(P.attacker_id).owner |
| pending_card_choice | card(P.card_id).owner |
| pending_revive, card/cell stages | card(P.healer_id).owner |
| pending_incarnation_wound, pending_nokami_wound, pending_intrigue_start | P[0].owner; later entries do not own the current decision |
| pending_any_death, including optional_effect | card(P[0].source_id).owner |
| pending_degasca_sequence, choose_card | P.owner; other stages are continuation state, not an invented player decision |
| turn_phase task order and subqueue | side selects active_key/passive_key |
| turn_instant_stack, ordering | priority, validated against participants; legacy missing/empty participants uses the existing two-player fallback |
| pending_prophecy: reorder, summon cell/creature; actions offering reorder_start, summon_start, transform_seeker | P.owner; classification uses known operations, never button labels |
| pending_dive | P.owner |
| strike.waiting_defender, waiting_redirect (including Mage options) | opponent of card(attacker_id).owner |
| strike.waiting_instant | instant_priority, validated against instant_participants; legacy fallback respects friendly_fire |
| strike.waiting_choice | choice_winner and attacker owner |
| strike.waiting_auto_target / pending_auto | card(source_id).owner; this is a decision |
| strike.waiting_push_choice / pending_push | card(defender_id).owner |
| strike.waiting_close_or_damage | card(pending_close_or_damage.defender_id).owner |
| strike.waiting_ally_modifier | card(pending_ally_modifier.source_id).owner |
| strike.waiting_coin_block, waiting_segger_shield | corresponding registered pending owner; missing pending is unknown |
| strike.pending_choice, including inside results | card(died_id).owner; overrides informational ACK |
| strike.results, waiting_push_ack without a nested decision | informational; host/player absent from confirmed |

## Unknown waits

- turn_phase.pending_ack, pending_incarnation_ack, turn_instant_result: current
  protocol allows either player to acknowledge; P1 does not assign an owner.
- Prophecy with only close_prophecy/grezy_continue, unknown actions, invalid
  summon step or conflicting modes: ambiguous_prophecy_stage. A Continue button
  is not proof that input is informational or optional.
- Missing/invalid owner, missing card, malformed queue head/participants/phase,
  unknown registered handler or strike stage, unknown pending keys.
- Degasca effects/deck continuation stage without an active choice, unresolved
  turn stack/phase or post-combat continuation without an actionable wait.
- pending_any_death_degasca_sequence, pending_nokami_wound_degasca_sequence and
  pending_degasca_deferred_sequences are continuation storage. They do not mask
  a current registered choice, and alone produce diagnostic unknown.

strike.pending_wounds_resolution is a deferred continuation after the result
ACK, not an independent player decision. Message arrays and instant_result are
not waits. The resolver validates ownership structure, not every gameplay
precondition/candidate; existing resolvers still validate and execute commands.

## Timer integration

Engine::apply already settles elapsed time before command execution. After a
successful command, CoinAbilities::advance and the existing deferred
afterStartPhase callback run first. P1 then calls TurnTimer::reconcileWaiting,
before final settlement/logging and the caller's existing persistence operation.

- If the opponent of turn_timer.owner is required, pause with opponent_wait.
- If a known wait no longer requires that opponent, or there is no wait,
  resume only a pause whose reason is opponent_wait.
- Unknown preserves running/paused mode. Normal settlement still applies to a
  running clock; an already-paused clock remains paused. No guessed ownership.
- start_phase, start_resolution, end_phase, battle_finished and arbitrary other
  special pause reasons are neither overwritten nor resumed.
- Expired clocks cannot restart. Legacy state without turn_timer does not
  acquire one. Period IDs and the 180-second limit are unchanged.

All web gameplay mutations enter through Engine::apply: AJAX battle_command and
the existing non-AJAX command path in www/index.php, including confirm_place /
startBattle, end_turn / afterEndPhase / beginStartPhase. Nested processors and
their automatic continuations return to that outer Engine boundary. No per-card
pause calls were added. Direct calls to individual processors by test/CLI code
do not replace the public Engine boundary.

No timer reconciliation is called by snapshot, sync, command-status, loading or
rendering. The existing journal transaction persists timer changes alongside
the command. Rejected/conflicting commands follow the existing fresh-state
response and do not persist a speculative timer change. No AJAX schema or JS/UI
changes are necessary: T2 already displays the server's paused/running state.

## Validation

Controlled server time, no sleep:

- tools/test_pending_wait_timer.php covers all 56 handlers / 54 keys for host
  and guest, read-only projection, card-vs-owner/active disagreement, registry
  priority, nested decisions, all supported strike states, phase/sub/instant
  priority, ambiguous/malformed waits, special pauses, legacy clocks and expiry.
- Full Engine commands exercise opening/cancelling/selecting a noncombat cell
  marker, both ACK orders, real strike declaration/defender selection, combat
  instant play/target selection, turn/combat priority changes, successive queue
  owners, nested death choice, unknown after a successful command, reload,
  surrender, end/start lifecycle and a new turn's pre-start choice.
- tools/test_turn_timer_sync.php adds HTTP coverage for action -> choice ->
  Engine -> Journal/CAS -> snapshot, both viewers' paused no_change/reload,
  recovery, same-ID replay, stale expected_version and opponent ACK/resume.
  It verifies no game JSON/version or journal row changes on sync/status reads.

Passed existing PHP suites: test_turn_timer, test_turn_instant_stack,
test_combat_instant_phases, test_combat_instant_ui, test_resign_pending,
test_degasca, test_row_spell_pending_dice, test_cell_marker_cancel,
test_battle_cancel_action_ui, test_damage_lifecycle,
test_dynamic_flying_lifecycle, test_forced_directional_movement,
test_volkhv_wound_transfer, test_battle_snapshot_renderer,
test_battle_stage1a_ajax_layout, test_battle_stage1b_ui,
test_command_journal_repository, test_repository_optimistic_locking,
test_battle_command_endpoint, test_battle_sync_endpoint, test_ajax_multi_target.
Passed Node suites: test_turn_timer_client.js, test_ajax_multi_target.js.
PHP lint and git diff --check passed.

Database-dependent and HTTP checks ran against a disposable source copy without
.git and a separate berserk_turn_timer_t2_test database on local MariaDB/InnoDB
:33916, PHP :8796. The HTTP test guards verify datadir, DB name, InnoDB and served
source tree. Production DB was not used.

## Limits and P2/T3 risks

- A battle already waiting when P1 is deployed is not rewritten by read-only
  polling. It reconciles on the next successful Engine command. P1 cannot
  reconstruct when an old wait began or refund time retroactively.
- Ambiguous ownership can intentionally retain an existing opponent_wait pause;
  Project Lead must define the three shared ACK protocols and ambiguous prophecy
  stages before those waits can acquire automatic behavior.
- No wait_id, started_at or auto_ack_at is persisted. P2 needs identity for each
  result stage/queue head, guards against late ACKs and manual/automatic races,
  and must reuse the existing command journal transaction/CAS.
- T3 must re-read period, pause and ownership before safely ending a turn;
  expired is only a timer state. P1 never ends a turn or closes a pending.
- Server wall-clock corrections and T2 temporal projection ordering retain their
  existing behavior. P1 does not introduce a second clock or timer polling.

No P2, 10-second deadline, automatic ACK, T3, card rule, UI, Command Journal or
database schema changes. Nested combat command ownership was additionally
verified through ten real Engine paths; see [the verification report](turn-timer-p1-nested-verification.md).
