# Match pause Stage 2

Server baseline: e4c67f9 (`feat(battle): implement server-side match pause`).
Core state machine, journal, locking and recovery are unchanged.

## UI and synchronization

The existing InfoPanel footer contains a pause control for both players. It opens
a native dialog with a 1..10 minute slider. Requests, acceptance, rejection,
cancellation and readiness use the existing AJAX command journal and expected
version. Existing cooldown and quota eligibility comes from the public projection.

The initiator sees cancel; the recipient sees accept/reject. Requested and active
states hide/inert the entire field, flying and pile zones, suppress selected-card
content and debug, and close any open top-layer card image. The opaque mask is
server-rendered, so refresh does not expose the board while JavaScript loads.
Surrender remains in the existing footer. Log remains disabled.

Active pause shows the pause countdown and frozen turn clock; end turn is replaced
by Ready. Own readiness shows the waiting message. At narrow widths the paused
layout becomes one column with all footer controls reachable. Icon groups wrap
inside the normal narrow panel to keep existing tooltips inside the viewport.

battle-match-pause.js projects server_now/deadline using performance.now. A local
zero triggers a forced poll but never locally resumes the match. Duplicate samples
do not reset elapsed time; older versions/samples are ignored. All snapshot,
no_change and command-recovery paths feed the same controller. The pause barrier
also updates when local selection makes an ordinary HTML snapshot temporarily
inapplicable. Normal forced-sync restores the authoritative non-pause controls.

A definitely conflicted pause_ready may retry once, with a NEW command ID, only
after the existing forced snapshot confirms the same active request, own false
readiness and unexpired deadline. Unknown/processing outcomes continue through the
journal recovery path and are never blindly resent. Other commands do not gain
an automatic retry policy.

No pause sounds, deferred-game menu, history implementation, settings changes,
DB schema changes or server architecture changes were added (Stage 3 is separate).

## Tests

`test_match_pause_ui.php` verifies server-rendered controls and read-only rendering.
`test_match_pause_browser.cjs` uses two isolated browser sessions at 1600/800/375px:
slider across polls, request/reject/cancel, lost acceptance response, refresh,
opaque/inert coverage, ready conflict, stale polling, direct API rejection,
card dialog closure, no-JS initial mask, lazy expiry and surrender.

Integration guard: a disposable source copy (no .git), exact test datadir,
berserk_turn_timer_t2_test and InnoDB checks, loopback URL and random HTTP source
probe are required before browser commands. The fixture helper supports controlled
server-clock deadline completion only inside that verified disposable environment.

Existing PNG icon regression checks remain scoped to PNG controls; the new pause
control uses a native symbol. No browser tests are run against the working DB.

Final verification: 14 server UI checks and 66 browser checks passed. Existing
battle UI polish passed at 1600/800/375px. Server regression suites passed with
119 pause unit assertions, 45 HTTP/InnoDB checks and 42 Stage 1.1 checks, plus
turn timer, ACK/auto-end, endpoint/CAS and unknown-outcome tests. Client timer,
PHP/JS syntax checks and git diff --check passed. Core has no Stage 2 diff.
Stage 2 remains uncommitted for review; the accepted server implementation is
committed separately as e4c67f9.


## Stage 2.1 review fixes

The countdown now stores an absolute expiry on the performance.now() clock.
Its identity is request_id + mode + server deadline. Samples for the same clock
can only move that expiry earlier; persistence-version changes, duplicate seconds,
and delayed samples cannot extend it. requested -> active and a new request start
independent clocks. Zero keeps the server barrier and only requests synchronization.
No rendered-string clamping or server deadline changes are involved.

Without JavaScript, noscript hides the pause action controls and displays a short
notice. The server-rendered opaque/inert barrier remains. The old GET surrender
link does not execute battle commands without JS, so the fallback supplies a
surrender-only POST form to the existing battle_command endpoint, with a random
command_id and expected_version. No alternative pause-control forms were added.
The html_resign flag changes response presentation only: a committed result uses
303 to the match page, a conflict/rejection is explicit, and unknown/processing
provides a same-ID command_status link without automatic resubmission. Existing
JSON responses and journal/transaction execution are unchanged. With JavaScript
enabled the fallback has no visible content.

Added deterministic countdown tests for both phases: duplicate seconds with a new
version, delayed samples, repeated/equal versions, stale timestamps/versions,
shortening, hidden-tab wake, refresh, independent requests, phase/deadline changes,
and zero without commands or local resume. Browser coverage now checks no-JS idle,
requested (both players), active, and an actual HTTP surrender without JavaScript.

Stage 2.1 verification is recorded separately from the original Stage 2 totals.

New deterministic countdown suite: 44 checks passed. Server UI: 18 checks passed.
Client timer regression, UI polish at 1600/800/375px, PHP/JS syntax and the existing
battle command endpoint regression passed on the isolated source copy. No Core,
production configuration or database schema changes were made.

Final Stage 2.1 browser run: 96 checks passed at 1600/800/375px, including no-JS
HTTP surrender, explicit CAS conflict and unknown same-ID status recovery.
The disposable HTTP/MySQL servers were stopped after testing.


## Stage 2.2: bind HTML surrender confirmation to the journal

HTML success no longer trusts html_resign or committed metadata alone. The
response reads the journal using the current game_id, player_key and command_id,
then requires an actual resign row with committed status, plus a successful
terminal response. A different command type is refused with HTTP 409 and no
redirect. Missing rows and read failures cannot produce 303. Processing/unknown
continues to offer same-ID status recovery without resubmission.

Player identification, Core, journal execution, CAS and transaction logic are
unchanged. Ordinary JSON requests do not enter the HTML-only lookup path.

New test_match_pause_html_resign.cjs performs 39 isolated HTTP/InnoDB checks:
actual committed resign, committed end_turn, ID reuse, rejected/conflict/failed,
processing/unknown, JSON responses, player/game isolation and a lost COMMIT
acknowledgement with failed immediate recovery read. The latter faults Db only
in the existing isolated test child in memory; runtime Core is not edited.
Only later journal recovery confirms that transaction and returns 303.

Stage 2.2 results: new 39 checks passed; existing endpoint regression, 44 countdown
checks, 18 server UI checks, 45 HTTP/InnoDB checks and 42 Stage 1.1 checks passed.

Stage 2.2 browser regression: 96 checks passed at 1600/800/375px. PHP/JS syntax
and git diff --check passed. Disposable MySQL/PHP servers stopped after testing.


## Stage 2.2 follow-up: verify the saved terminal result

CommandJournalRepository::find returns identity, type, input hash, expected/before/
after versions, lifecycle status, errors and timestamps. There is no persisted
Result.success/Result.events blob. GameLog is best-effort and outside the SQL
transaction, so it cannot be used as the authority for HTTP confirmation.

Normal battle_command: registerProcessing -> executeLockedCommand -> journal lock
-> game lock/version check -> Engine::resign. A failed Result marks rejected;
a successful resign sets game_over and the opponent as winner. save(game) and
markCommitted(before, after) commit atomically. The response reloads the journal
and game and passes commandJournalMeta into sendBattleAjaxState/sendAjaxJson.
The duplicate/unknown-COMMIT recovery paths also read the persisted journal.

battle_command_status: find(game_id, player_key, command_id) -> sendCommandStatus
-> sendCommandStatusPayload -> sendAjaxJson. Its default ok=true is transport
metadata; committed/terminal come from lifecycle status. Those flags do not
independently prove the saved terminal game result for an inconsistent row.

The HTML-only confirmation now also reads the persisted game and requires exactly
the journal after_version, game_over and the expected opponent winner. The row
must have before=expected, after=before+1, null error fields and finished_at.
Existing payload success/terminal checks remain additional gates, preserving
unknown-outcome policy even when a subsequent read can already see the commit.
Read failures and inconsistent results only offer original-ID recovery; no repair,
retry, schema/State/Core or JSON-contract changes were introduced.

The new negative regression first failed against the previous guard (303 while
battle remained active). Three isolated inconsistent-result fixtures now cover
missing saved result/version, matching version with nonterminal game, and matching
version with the wrong winner. All retain the original command ID and never
execute surrender during status recovery.

Verification: 51 targeted HTTP/InnoDB checks passed, including lost COMMIT
acknowledgement and subsequent status recovery; existing command endpoint tests,
18 server UI checks, 44 countdown checks, PHP/JS lint and git diff --check passed.
Conservative limitation: an out-of-band later version change prevents HTML
confirmation of the historical result rather than attributing a newer snapshot
to this command. Normal terminal surrender persists at its exact after_version.
