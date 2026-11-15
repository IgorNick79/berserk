# UI dialogs #46 / #47 — review handoff

Branch: codex/bot-lobby-playtest. Tasks remain open.

One queued dialog service, textContent rendering, Escape cancellation and focus restoration. Battle commands retain the existing transport and are cancelled if their version changes or their source link is replaced. Preparation uses its existing transport and version checks. Duplicate open messages are suppressed; draft polling repeats are suppressed. Existing server validation and command recovery remain unchanged.

Checks: JS syntax checks passed for all six affected scripts. PHP 8.1 lint passed for four affected screens. Friendly-fire regression passed. git diff --check passed. Solomon regression reaches GameRepository save but fails with the existing fixture error `Unexpected fixture query`; this is not a successful end-to-end #43 check.

Browser: opening http://localhost in the available in-app browser failed with net::ERR_CONNECTION_REFUSED. No successful gameplay browser checks are claimed.

Project Lead manual checks required:
- Deal: cancel/confirm reshuffle and squad; no request before confirmation, one request after; double click, Escape and focus return.
- Battle: friendly attack and both resign controls; no request on cancel, one on accept, AJAX retained. Change state through another player while dialog is open: stale action must cancel.
- Card preview: queue a notification while preview is open; preview remains open and notification follows closing it. Verify pending panels and timers continue.
- Draft #43: reproduce impossible 30x30 allocation; verify exact server reason in dialog and no repeated polling dialog. Draft rules unchanged.
- Placement: occupied/unavailable cell, card outside squad, incomplete placement, rejected POST and lost response. Verify server reason is visible and recovery does not replay a command.
- Ordinary pause, connection and progress messages must remain inline.

No live endpoint or authenticated test-game fixtures were supplied for practical gameplay checks. These limitations prevent claiming full acceptance.
