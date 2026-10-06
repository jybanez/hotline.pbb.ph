# Citizen stop-intent review

When a citizen hangs up, the existing 10-second fallback can reload an active snapshot before the operator receives the request. The current renderer then reopens that session. Session64 timings support this path, but the actual bootstrap response/snapshot timestamps were not captured; this is a code-path finding, not a proven attribution of that response.

The browser-local guard records intent before signaling and survives modal/surface teardown for the same authenticated citizen. It suppresses that incident/session in rendering and late answered/ready events without marking an unconfirmed server outcome ended. Authoritative ended snapshots are monotonic against later active snapshots. Asynchronous context is invalidated immediately at render/logout transition start, before bootstrap/logout requests. Same-citizen stop records survive an ordinary rerender and its preserved Realtime stream is rebound only after the authenticated bootstrap resolves. Bootstrap render generations reject older responses; citizen context generations reject callbacks and reconciliation responses after an account/surface context change. Distinct sessions remain eligible. Full page reload starts a new browser-local guard; server authorization and reservation checks remain mandatory.

The existing Helper warning toast reports unconfirmed completion after fallback. No new mutation, automatic replay, citizen request, timeout extension, recording format or upload queue change is introduced. Asynchronous runtimes finishing after stop/dismissal use existing destroy APIs.

Validation:
- node tests/js/citizenCallStopIntent.test.mjs: fallback active snapshot without invented end, authoritative terminal followed by stale active, duplicate event state, distinct sessions, logout/account context cancellation.
- node tests/js/citizenStopIntentRenderRace.test.mjs: actual render coordinator with UI/network dependencies replaced; delayed active bootstrap after newer ended snapshot and after another citizen context, old callbacks during a held bootstrap, and old callbacks during a held logout request.
- node tests/js/recordingStartAndCallerHangup.test.mjs and node tests/js/callSignalDelivery.test.mjs: recording-clock and terminal delivery/no-replay regressions.
- npm run build and git diff --check.

Prepared only in isolated review worktree. No deployment or human call. Gateway connection-delay root cause, actual bootstrap-response attribution, and recording acceptance remain separate/open.

Collector evidence is retained in the primary checkout private diagnostics directory callback-delay-20261006T165202Z: original failed capture/error, isolated collector source, and deliberate stalled-child verification. Original capture provides no session64 host coverage. These files are local observer artifacts and are not part of the product commit.
