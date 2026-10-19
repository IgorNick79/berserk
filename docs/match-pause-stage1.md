# Match pause: Stage 1 server contract

Base: main `3e312de`, after the read-only audit at `e948f71`.

No UI, JavaScript, CSS, settings, migrations, new tables, configuration changes or scheduler.

## Commands

POST the existing `ajax=battle_command` endpoint with `command_id` and
`expected_version`. `request_pause` takes `duration_seconds` (60..600, step 60).
`accept_pause`, `reject_pause`, `cancel_pause_request` and `pause_ready` take
`request_id`. Only the recipient accepts/rejects, only the initiator cancels.
Read the journal after an uncertain response; retrying an existing ID must retain
its original payload and expected version. A CAS conflict requires a fresh state
and a new command identity. Ready sets a boolean; it never toggles it.

The ordinary battle GET command path remains disabled. Server commands are also
blocked inside Engine, so bypassing the AJAX client does not bypass suspension.
All AJAX commands now execute against the locked persisted state via the existing
journal/CAS transaction, including ordinary game actions that race a pause.

## Lifecycle and time

`battle.match_pause` is independent of ChoiceRegistry. Requested freezes play for
20 seconds; accepted starts the selected interval from acceptance. Two accepted
requests per initiator. Reject, cancel and request timeout apply a 300 second
cooldown to the initiator. Only acceptance consumes the quota.

Both ready and active deadline use the same finish operation. Expiry is lazy:
HTTP polling/full-page/command-result/recovery paths materialize it, never a
background process. Logical expiry uses the saved deadline, so reconnecting late
does not refund the elapsed post-pause turn time. A late POST is rejected, then
its response reconciles the deadline; it does not silently execute the action.

The settled timer period, owner, status, reason and remaining budget are saved.
Running clocks resume in the same period; stopped/expired clocks retain their
mode. Existing opponent-wait reconciliation runs only after releasing the pause.
Informational ACK retains its identity and remaining seconds; its timestamps are
shifted with the original ten-second duration invariant intact. An already due
ACK restores zero remaining time. No combat pending or continuation is consumed.

Pause commands bypass Engine post-processing. ACK and turn-timeout producers,
including their locked revalidation, refuse suspended matches. Resign uses the
ordinary terminal state transition but bypasses combat continuation processing;
it closes pause state without resuming the clock or creating a resume event.

## Projection and limitations

`match_pause` is included in snapshots, no_change, status and command recovery.
Full pages contain the same data in `#match-pause-state` application/json, including
completed matches. Internal suspended timer/ACK records are not exposed.
`last_resume` supplies a stable `resume_event_id`, effective `resumed_at` and
`reason`. Sound playback and deduplication belong to Stage 2.

Unknown waits, absent/invalid timers and unbound informational wait metadata are
rejected, not repaired. Known registered mandatory choices may be suspended.
Legacy games without a turn timer cannot request a pause. There is intentionally
no physical DB update without HTTP traffic. Unknown transaction outcomes retain
the existing journal policy for other commands. Stage 1.1 recovers only expire_pause
under journal/game locks; uncertainty stops the current HTTP request.

## Verification

`tools/test_match_pause.php` is a DB-free deterministic state-machine suite.
`tools/test_match_pause_http.php` reuses timer-suite guards: disposable source tree
(no .git), exact TURN_TIMER_TEST_DATADIR, loopback TURN_TIMER_TEST_URL, dedicated
berserk_turn_timer_t2_test database and InnoDB tables. Do not run endpoint suites
against the working database. `test_match_pause_race.php` is a bounded child test
process, not a runtime worker. Repository constructors can create schema; guards
run before constructing repositories.

The HTTP suite covers journal replay, CAS, concurrent requests/ready/deadlines,
late HTTP without refunds, ACK suppression/restoration, surrender, GET blocking,
full-page/recovery/no_change projection and definite-failure automatic retry.

## Stage 1.1: recovery and certainty proof

Client command IDs reserve `mpx:` case-insensitively alongside `p2a:`/`t3e:`.
All public registration paths pass requestCommandId before constructing a journal
registration. Manual locked execution explicitly requests command_rejected;
automatic callers keep automatic_command_rejected. Response shapes are unchanged.

HttpBattleProgress is instantiated once per HTTP request and shared by sync,
snapshot generation and command_status. Any uncertain result or exception latches
an irrevocable stop for that request, including if a reread already shows idle.
No recovery, auto-ACK or auto-end may run after that stop in the same request.
A later independent request may reconcile the durable journal/state.

Recovery is limited to the exact server-generated expire_pause journal entry:

1. Reuse its command ID, expected version, empty payload and initiator. Never infer
   failure from elapsed time or allocate another ID for a processing attempt.
2. executeLockedCommand takes the journal row FOR UPDATE, then games FOR UPDATE,
   using the existing lock order and the same DB connection/transaction.
3. If the original executor is live and holds the lock, recovery waits for it.
   If the original transaction committed, the journal is terminal and recovery
   observes it without executing the transition. A rolled-back transaction leaves
   the original processing row available for revalidation.
4. If recovery acquires the locks before the original executor starts, recovery
   may finish the SAME journal entry. The original executor then sees a terminal
   journal status and cannot apply it again. This also covers process death after
   registration but before BEGIN.
5. Under both locks the executor checks the persistence version, and the callback
   checks request identity, actual mode and server deadline. A changed version
   becomes conflict; terminal state never reactivates. The existing single finish
   function uses the saved deadline and emits the same deterministic event ID.
6. Game JSON/version and journal committed status are saved atomically in InnoDB.
   On a lost acknowledgement this HTTP request stops. A later request either reads
   committed state, or performs the same lock/revalidation protocol; it does not
   guess whether the previous operation executed.

Other command types retain their recovery policy. Lock wait/DB failure can defer
recovery; successful recovery requires an available database and the established
InnoDB atomicity/locking guarantees. Corrupt or incompatible state is not repaired.

Additional tests: test_match_pause_stage11.php (inherits the Stage 1 HTTP suite)
and bounded test_match_pause_fault.php. They exercise reserved-ID poisoning,
manual/automatic error codes, lost COMMIT and ROLLBACK acknowledgements through
actual sync/status entry points, repeated wrappers, auto-ACK/auto-end suppression,
process death before BEGIN, concurrent recovery, and waiting for a live executor.

Final Stage 1.1 verification (2026-10-19): 119 deterministic unit assertions,
45 Stage 1 HTTP/InnoDB checks (the old processing case now verifies locked
recovery), plus 42 Stage 1.1 checks, all passed. Existing turn_timer,
turn_timeout_review, turn_timeout_http (including P1/P2/CAS/concurrency/unknown
outcomes), battle_command_endpoint and client countdown/AJAX/audio suites passed.
PHP lint and git diff --check passed. All integration work used a disposable
source copy and separate loopback MySQL/InnoDB datadir, never the working DB.
