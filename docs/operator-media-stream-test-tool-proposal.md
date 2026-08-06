# Operator Media Stream Test Tool Proposal

## Purpose

Hotline needs an operator-facing diagnostic tool that proves the full audio media path before or during operational use:

- operator microphone capture
- MediaRecorder chunking
- local browser queue storage
- server chunk upload or Realtime-backed forwarding
- finalize/assembly
- playable audio review through Helper audio components

The first implementation should be a testing tool, not an operator workbench refactor. Once the test tool is stable, the same modules can be reused by the operator workbench in a separate migration.

## Current Problem

Operator media capture currently works inside the live-call workbench, but the capture/storage/finalize concerns are tightly coupled to the call UI and call lifecycle. This makes it hard to test media readiness without taking an actual call, and it makes failures harder to isolate.

A shallow IndexedDB write/read smoke test is not enough. The operator needs a realistic media test that records actual microphone audio, stores chunks like live media, finalizes the recording, and plays the result.

## Design Direction

Create reusable Hotline-owned media stream modules and use the testing tool as their first consumer.

The operator workbench remains unchanged for now.

## User Journey

1. Operator opens the navbar Tools dropdown.
2. Operator selects `Test Media Stream Storage`.
3. Hotline opens a modal with a `Start` button.
4. On `Start`, Hotline requests microphone access and starts recording.
5. Recording emits chunks every fixed interval, such as one second.
6. Chunks are stored locally through the same browser queue storage used by operator media.
7. The button changes to `Stop`.
8. On `Stop`, Hotline stops recording, flushes/stores remaining chunks, uploads/ingests chunks, and calls finalize.
9. Finalized audio appears in the modal as playable media using Helper audio components.
10. Any failure is shown in the modal with a clear phase and error message.

## Proposed Modules

### Media Stream Session

Owns browser recording lifecycle:

- microphone permission request
- MediaRecorder creation
- chunk interval
- start/stop state
- final chunk handling
- track cleanup
- state events

Suggested states:

- `idle`
- `requesting-device`
- `recording`
- `stopping`
- `storing`
- `uploading`
- `finalizing`
- `ready`
- `failed`

### Media Queue Storage

Wraps existing operator media IndexedDB storage behind a reusable contract:

- put media record
- put chunk
- list chunks
- update chunk status
- cleanup by media id
- recover incomplete rows

The initial implementation may reuse `createOperatorMediaQueueStorage`, but the public module name should not make future reuse depend on the operator workbench.

### Media Ingest/Finalize Client

Owns the server side test-media contract:

- create a test media asset
- upload or forward chunks
- finalize the asset
- return playable media metadata

Recommended endpoint shape:

- `POST /api/operator/media-tests`
- `POST /api/operator/media-tests/{media}/chunks`
- `POST /api/operator/media-tests/{media}/finalize`

This avoids attaching diagnostic media to a real call session and keeps the test usable before answering calls.

### Media Playback Wrapper

Small Hotline-owned wrapper around Helper audio components:

- accepts finalized media metadata/path
- renders Helper audio player/timeline/audiograph
- reports playback load errors
- destroys cleanly when modal closes

This wrapper should be reusable by the operator workbench later.

## Tools Dropdown

Operator navbar should have a `Tools` dropdown using Helper icon `actions.tools`.

Menu items:

- `Device Primer`
- `Test Media Stream Storage`

`Device Primer` should open the existing device primer modal.

`Test Media Stream Storage` should open the new test modal.

## Server Contract

The preferred approach is a dedicated diagnostic media endpoint, not a fake call session.

Reasons:

- works before receiving a call
- avoids polluting real incident/call media
- isolates operational test records from incident evidence
- allows explicit cleanup/retention policy
- makes failures easier to classify

Server-created test media should be marked clearly in metadata, for example:

```json
{
  "diagnostic": true,
  "diagnostic_type": "operator_media_stream_storage",
  "created_by_user_id": 2
}
```

Diagnostic media should not appear in incident SITREPs, incident media references, support-request media references, or incident relay exports.

## Realtime Boundary

Phase 1 may use direct HTTP chunk upload to test Hotline storage and finalize.

Phase 2 can add a mode that sends chunks through Realtime, matching live-call transport more closely:

- direct mode: browser -> Hotline chunk endpoint -> finalize
- realtime mode: browser -> Realtime -> Hotline internal ingest -> finalize

The modal should clearly show which mode is being tested.

## Helper Boundary

Hotline should maximize Helper usage for:

- navbar action/menu icon
- modal shell if compatible
- buttons/form controls
- audio player/timeline/audiograph
- status/toast rendering

Hotline owns the media lifecycle logic. Helper owns generic UI components only.

## Non-Goals For First Pass

- Do not refactor operator workbench recording yet.
- Do not change live-call media behavior.
- Do not require an active call session.
- Do not store diagnostic media as incident evidence.
- Do not include diagnostic media in SITREP or Relay payloads.

## Future Workbench Migration

After the test tool is stable, migrate the operator workbench to consume the same reusable modules:

- replace embedded MediaRecorder setup with Media Stream Session
- reuse Media Queue Storage
- reuse Media Ingest/Finalize Client with call-session adapter
- reuse Media Playback Wrapper for finalized audio review

This should be a separate branch and PR after the diagnostic tool is proven.
