# Operator recording storage recovery

This change establishes application recovery readiness. It does not certify that an affected Chrome profile or backing store has been repaired, and does not recover audio that could not be saved.

## Queue and safety metadata

Media remains in the original `hotline-operator-media-queue` IndexedDB database. No replacement queue, deletion/reset, site-data clearing or automatic uncertain write/upload replay is introduced.

The localStorage key `hotline-operator-media-recovery-v1` contains only `{version:1,generation:<random UUID>}`. It is created on an actual storage failure by the exclusive queue owner, read back synchronously, and retained until explicit durable verification succeeds. Unreadable or malformed safety metadata blocks initialization. A failed marker write/read-back leaves this runtime unavailable; cross-reload preservation cannot be certified if the browser cannot persist the marker. Keep the page open and contact support in that case.

The browser Web Lock `hotline-operator-media-queue-owner-v1` is held for the owning page/queue lifetime, including pauses and failed recovery. A competing operator tab is denied recording-queue admission without changing another owner's marker or media. Browser teardown releases ownership. Unsupported ownership APIs fail closed. This prevents competing participating tabs from interleaving marker retirement or queue consumption. Old application versions that do not participate in this lock are outside this guarantee; upgrade verification must not imply mixed-version origin-wide protection.

Healthy unmarked startup retains normal queue closing/draining. Marked startup atomically fences all retained records and remains unavailable until explicit verification. Recovery fences records in one committed transaction, verifies committed record/chunk probes with separate reads, checks original item preservation and removes only its own probe entries. Failed probes may remain safely isolated under reserved nonnumeric keys; they are never consumers or upload/count items. Holds are separate records; original media and chunk bytes are not rewritten. Marker retirement checks the captured generation while exclusive ownership prevents other participating contexts from creating or clearing it. A newer same-context failure during verification causes recovery to fail and preserves its marker.

Previous held recordings are not automatically released. New capture can become available after verified recovery; the UI separately reports how many previous items remain paused. Stopped clones are not restarted. Per-item reconciliation is future work: support must compare retained IDs/chunk indices against authoritative server receipt/finalization state, then use a separately reviewed explicit operation. No bulk release/delete/retry is provided here.

## Dialog ownership

The complete Helper alert supplies signal, onClose and isActive. The app captures manager identity, page path, dialog owner and failure generation. After awaits it checks ownership before notification changes or success feedback. Explicit context abort disposes UI; it neither cancels storage writes nor replays them. A newer failure cannot be cleared by stale success. Ordinary busy dismissal retains Helper's canonical behavior.

## Verification

Native Chrome fixtures cover multi-record preservation with hold/probe read/write/delete failures and reloads; healthy drain; malformed/unreadable metadata; unavailable marker storage and failed read-back; newer failure during marker retirement; competing tabs and clean ownership handoff. Actual app hook with canonical Helper runs in source and bundle modes for abort, replacement, newer generation, success and late rejection. Adapter and existing capture/chat regressions remain required. Test profiles/queues are isolated; the affected user's Chrome profile remains unverified until user-assisted diagnosis and post-integration checks.

## Harness timing qualification

Independent review of de57cb7 initially timed out on owner handoff under parallel browser-suite load. Standalone rerun and three sequential repetitions passed. The earlier admission retry budget was about one second (100 retries separated by 10 ms), which coupled ownership teardown timing to host load. The harness now uses a bounded 15-second wall-clock deadline and 25 ms polling; it retries only denied ownership admission, before any queue write, then explicitly verifies recovery before reporting readiness. This is a harness tolerance change, not production automatic recovery. Passing isolated/sequential runs do not establish stress-load coverage.
