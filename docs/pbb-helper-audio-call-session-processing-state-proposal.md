# PBB Helper Proposal: Audio Call Session Processing State

## Project
PBB Hotline Beta

## Date
2026-04-29

## Summary
Hotline Beta needs additive pending-audio support in the shared Helper `createAudioCallSession(...)` component so call-session audio can show a busy/preparing state while server-side merge/finalization is still running, then resolve in place when final playable audio becomes available.

This should mirror the existing `ui.media.strip` processing placeholder behavior for image/video media, but applied to audio call-session tracks.

## Current Downstream Situation
Hotline Beta receives server-originated media lifecycle events:

- `media.processing`
- `media.available`

For visual media, Beta passes processing items into `createMediaStrip(...)`. Helper already supports:

- `processing: true`
- `processingLabel`
- placeholder thumbnail with spinner
- non-clickable pending item
- later `update(...)` into resolved media by stable `id`

For audio, Beta renders call-session playback through Helper `createAudioCallSession(...)`. The current Helper audio component builds playable role segments from `incident.media[]`, but it does not expose an equivalent pending/busy state.

Current Helper behavior in `js/ui/ui.audio.callSession.js`:

- filters `incident.media[]` to `type === "audio"`
- parses each audio row through `metadata.recording_role`
- requires a resolved media `path` / URL for playback
- creates role audiographs and a shared timeline player

If audio is still processing and has no final `path`, Beta currently has no native Helper state to show "audio exists but is not playable yet".

## Problem
Without shared pending-audio support:

- call-session cards can look empty even though audio capture has been received and is being merged
- users get no visual confirmation that caller/operator audio is being prepared
- resolved audio appears abruptly instead of transitioning from a known pending state
- downstream apps need local placeholder UI around `AudioCallSession`, which fragments the presentation contract

This gap is more visible now because Hotline Beta presents inactive workbench call sessions in a timeline, with each call-session card containing `AudioCallSession` plus `MediaStrip`.

## Proposed Shared Contract
Add additive support in `createAudioCallSession(...)` for pending audio media rows.

### Suggested Media Row Shape
Allow audio rows like:

```js
{
  id: 789,
  type: "audio",
  path: "",
  processing: true,
  processingLabel: "Preparing audio...",
  peer_role: "caller", // or "operator"
  peer_label: "PBB Caller",
  call_session_id: 166,
  metadata: {
    processing: true,
    recording_role: "caller-166-2026-04-27T00-58-00Z",
    track_kind: "audio"
  }
}
```

Important points:

- `id` remains the stable identity key.
- `processing: true` means the audio row should survive normalization even without a playable URL.
- `metadata.recording_role` should still identify role/session/timeline placement when available.
- `peer_role` / `peer_label` can be fallback labels when `recording_role` is incomplete.

### Expected Behavior
When at least one role has pending audio and no resolved source URL:

- render the audio session shell instead of dropping the component to an empty state
- show a visible busy/preparing state for the affected role track
- show a spinner or compact progress indicator
- show optional `processingLabel` if provided
- keep mute controls disabled or hidden for pending-only tracks
- keep playback disabled if no playable segments exist yet

When one role is playable and another role is pending:

- keep the playable role usable if Helper can do that cleanly
- show the pending role as busy/preparing
- avoid broken playback attempts against missing URLs

When a later `update(...)` call provides the same audio `id` with a real path:

- replace the pending state in place with the playable segment
- preserve role ordering and call-session layout
- recompute duration/timeline normally
- keep existing resolved-audio behavior unchanged

## Narrow Rendering Guidance
This proposal does **not** ask Helper to own media lifecycle, Realtime events, merge status, or backend polling.

Hotline Beta remains responsible for:

- listening to `media.processing`
- listening to `media.available`
- merging media state by `media.id`
- calling `audioCallSession.update(...)`

Helper remains responsible for:

- rendering pending audio rows consistently
- preventing playback/load attempts for missing sources
- transitioning pending rows to playable rows on `update(...)`
- keeping `AudioCallSession` visual behavior aligned with the existing Helper style

## Recommended Minimal Rules
Suggested first-pass rules:

1. `processing: true` allows an audio item to survive normalization even if `path` / `srcUrl` is empty.
2. Pending audio rows render a disabled role-track placeholder with spinner/label.
3. Pending rows are not attached to `<audio>` elements and are not used for playback until resolved.
4. If all rows are pending, the shared player controls should be disabled or clearly busy.
5. `update(...)` should reconcile by stable item `id` where possible so pending audio transitions to available audio without a full visual reset.
6. Existing resolved-audio parsing and playback behavior should remain backward compatible.

## Why This Belongs In Helper
`AudioCallSession` is the shared parent coordinator for call-session playback and role audiographs. Pending audio is a presentation state of that component, similar to how pending image/video media is a presentation state of `MediaStrip`.

If this remains app-local, each downstream app using asynchronous audio finalization will need to duplicate:

- disabled player state
- role-specific preparing placeholders
- pending-to-playable update handling
- styling that should match Helper audio components

## Beta Acceptance Target
Hotline Beta would consider this solved if the following works:

1. Beta passes a pending caller/operator audio media row with `processing: true`, stable `id`, and no `path`.
2. `AudioCallSession` renders a visible preparing/busy role track instead of empty/broken playback.
3. Playback does not attempt to load missing URLs.
4. Beta later calls `update(...)` with the same `id` and a final playable `path`.
5. The pending role track becomes playable in place.
6. Existing normal call-session playback remains unchanged for already-available audio.

## Local Beta Context
Current Beta integration points:

- `resources/js/surfaces/operatorSurface.js`
- `buildWorkbenchAudioSessionPayload(...)`
- `workbenchCallSessionTimelineItems(...)`
- `mountCallSessionTimelineContent(...)`
- `syncWorkbenchMediaViews(...)`

Current related Helper precedent:

- `js/ui/ui.media.strip.js` supports `processing` / `processingLabel`
- Hotline Beta already uses that model for visual media processing state

