# Incident callback delivery

Implemented scope follows Jonathan's incremental decisions through October 6, 2026. These replace the older callback-case/queue proposal for this delivery.

- Assigned operators can call the citizen of an Active or Deferred incident. The Helper Call back action appears before Close, outside an active call.
- The canonical Helper modal opens immediately, checks the SDK presence roster, then shows Calling and Connecting. Lost presence stops dialing and shows a Helper alert.
- Citizens publish presence in the existing discovery room. Citizen service availability counts operators only.
- Operator HTTP requests create, cancel, and answer attempts. Citizens validate the Realtime gateway's authenticated sender against the loaded incident assignment and respond through Realtime; receiving the callback does not add a citizen verification request.
- Existing call_attempts gain callback (default false) and nullable answered_at. With callback=true, started_at belongs to the operator and answered_at belongs to the citizen. Media readiness continues to use call_sessions.answered_at independently.
- The citizen's existing incoming call screen provides Answer and Decline. An accepted callback reuses the reconnect/media bridge and opens the active operator workbench.
- Assignment, incident status, citizen identity/status, and existing active/pending calls are checked transactionally. Declines record declined_by_citizen; cancellation cannot overwrite an answered attempt. Disposal cleans up a late committed session without reopening another workbench.
- No callback queue, automatic retry, SLA, quota, or fallback policy is introduced. Incident disposition stays unchanged on failure. Mutations are not automatically replayed after uncertain transport outcomes.
- The reviewed Helper submodule is pinned to 7c43b7f (.270). Workbench audio uses the supported chrome:false option; no application audio-frame CSS override remains.

## Verification

- Callback feature tests: 5 tests / 51 assertions, including authorization, duplicate prevention, changed assignment/status/recipient, suspended citizen, decline outcome, and competing pending callback.
- Related inbound call, directed call, reconnect, answer, and Realtime admission tests: 28 tests / 150 assertions.
- Independent-process MySQL 5.7 tests: inbound answer races plus callback create/create, answer/answer, and answer/cancel. Each uses a disposable database that is removed afterward.
- Executed modal orchestration tests: offline citizen, spoofed decline, real decline, disposed in-flight answer, and late creation cancellation.
- Citizen surface/Realtime/Helper and operator media contract checks pass. Incident layout passes source and bundle at 1280 and 1920 widths. Helper audio chrome regression passes source and bundle; registry checks pass.
- Production build and whitespace checks pass. Jonathan confirmed the live callback connection works. A fresh desktop-browser walkthrough could not run because its browser bridge was unavailable; the automated checks do not assert fresh microphone playback.

The served WAMP instance uses the codex/incident-callback-flow worktree. The primary/shared checkout is intentionally unchanged. Review and merge are separate from this local test deployment.
