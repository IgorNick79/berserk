# Turn Timer — T1

The server clock limit is `TurnTimer::LIMIT_SECONDS = 180`. No UI, timeout command,
automatic pending resolution, battle timer, background job, schema change or journal
change is included.

## Persisted state

`GameState.battle.turn_timer` is part of the existing JSON serialization:

```json
{
  "period_id": 1,
  "owner": "host",
  "status": "running",
  "remaining_seconds": 180,
  "anchor_at": 1792100000,
  "pause_reason": null
}
```

IDs increase within a game for both players' individual periods; the external identity
is `(game_id, period_id)`. The guest's internal key is `player`. Status is `running`,
`paused` or `expired`. `remaining_seconds` is the balance at `anchor_at` (server Unix
seconds), not necessarily the balance at read time. Paused/expired clocks have a null
anchor. A paused period retains its budget; expiry is terminal for that period.
The fixed limit is defined once in code and included in snapshot output.

## Integration

- `TurnProcessor::startBattle` prepares period 1, paused for `start_phase`.
- `TurnProcessor::afterStartPhase`, called by the existing phase processor, starts
  the clock. Repeated callbacks do not reset the budget or allocate a new period.
- An accepted `endTurn` freezes the old clock before `battle.active` changes.
  `afterEndPhase` prepares the next period, including before pre-turn choices.
- `Engine::apply` settles elapsed time before/after the existing command lifecycle
  and freezes the clock when the match ends. `Engine::serverNow` uses `time()`;
  its optional server-side Closure allows controlled test time, with no HTTP input.
- `GameState::toArray/fromArray` and `GameRepository` already preserve `battle`.
  They require no changes and do not create periods or replace anchors on reload.
- `TurnTimer::snapshot` is a read-only projection with `server_now` and
  `limit_seconds`, ready for later AJAX integration. `settle` updates the model;
  existing successful command persistence saves that state using the existing CAS
  and Command Journal transaction. No timer-only DB writes are introduced.

## Deliberate limitations and T2/T3 work

`ChoiceHandlerInterface` has no common authoritative actor/wait contract. Examples:
`CombatPickChoice` reads `owner`, `AnyDeathChoice` derives the chooser from a card,
and `TurnPhaseChoice` derives it from the phase side. `ChoiceRegistry::current`
selects a handler, but does not expose wait ownership or instant activity.

Therefore automatic pending/instant pause detection is deferred. Explicit
`pause(state, periodId, reason, now)` and matching `resume(...)` are implemented
and tested; no `battle.active` ownership heuristic is used. Own pending currently
continues to consume time, while foreign pending/instants are not yet automatically
excluded. Before enabling those pauses, add a shared server wait descriptor
with actor, wait identity, and timer policy (including instant lifetime), then connect
it at authoritative lifecycle boundaries. Handle overlapping waits explicitly;
the current single pause reason is not a stack or set of blockers.

Old saved battles without a timer remain without one on load/snapshot. The next
actual start-phase completion/next turn initializes it. No historical start time can
be reliably recovered for an already running old turn.

Expiry is evaluated lazily on server settlement/snapshot. Read-only AJAX sync
currently neither exposes nor persists it; a successful gameplay command persists
settlement. T2 must include timer projection even when persistence version has not
changed (`battle_sync` currently returns `no_change`), without turning polling into
journal commands. T3 must check the period ID, settle under existing concurrency
controls, and safely end turns around pending/instant windows. Expiry currently
does not reject gameplay commands or invoke endTurn.

Wall-clock seconds allow cross-request persistence. A backwards adjustment does
not refund settled time; a forward adjustment consumes time. Server clock
synchronization remains an operational requirement.

## Validation

`tools/test_turn_timer.php` uses an injected clock and covers first host/guest periods,
serialization of running/paused/expired clocks, read-only snapshots, idempotent
start/pause/resume/settlement, stale period operations, exact expiry, backwards time,
normal turn transitions, delayed phases with an early active switch, surrender,
and legacy state. No real waiting is used.

PHP 8.1 CLI, disposable source copy: 76 PHP test scripts launched, 73 exited zero.
Of those, four DB-only scripts reported skipped, and three others skipped DB
subchecks because `config/db.php` is absent. Two failures (`test_cards.php`,
`test_booster.php`) require that missing configuration. The UI assertion in
`test_battle_vampire_hp_ui.php` also fails with unmodified HEAD Engine/TurnProcessor,
confirming it is pre-existing. HTTP/browser harnesses requiring an isolated server
and database were not run. The new target test passes; `git diff --check` passes.
