# Operator Media Stream Test Tool Implementation Checklist

## Scope

Build the operator `Test Media Stream Storage` diagnostic tool and reusable media modules. Leave the operator workbench live-call media path unchanged.

## 1. Preparation

- [ ] Start from clean `main`.
- [ ] Create branch `media/operator-stream-test-tool`.
- [ ] Confirm vendored Helper includes `actions.tools` and audio components.
- [ ] Confirm `HELPER_VENDOR_REV` matches the vendored Helper commit.
- [ ] Inspect existing operator media queue/finalizer code before editing.

## 2. Reusable Browser Modules

- [ ] Add a reusable media stream session module.
- [ ] Support microphone-only audio capture.
- [ ] Use `MediaRecorder` with WebM/Opus preference and fallback.
- [ ] Emit chunks on a fixed interval.
- [ ] Track lifecycle states.
- [ ] Stop tracks on success, failure, and modal close.
- [ ] Expose callbacks/events for chunk, state, error, and finalized result.

## 3. Queue Storage Contract

- [ ] Create or wrap a reusable media queue storage module.
- [ ] Reuse current IndexedDB storage where practical.
- [ ] Store media records and chunk rows with stable keys.
- [ ] Keep cleanup methods explicit.
- [ ] Ensure test rows can be cleaned safely without touching live-call rows.
- [ ] Add focused JS tests for write/read/list/delete behavior.

## 4. Diagnostic Server Endpoints

- [ ] Add operator-authenticated diagnostic media routes.
- [ ] Add endpoint to create a diagnostic media asset.
- [ ] Add endpoint to accept diagnostic media chunks.
- [ ] Add endpoint to finalize diagnostic media.
- [ ] Mark diagnostic media clearly in metadata.
- [ ] Prevent diagnostic media from appearing in incident media, SITREP media refs, support-request media refs, and incident relay exports.
- [ ] Add cleanup/retention behavior or document manual cleanup if deferred.

## 5. Test Tool Modal

- [ ] Add Operator navbar `Tools` dropdown using `actions.tools`.
- [ ] Add menu item `Device Primer` wired to the existing primer modal.
- [ ] Add menu item `Test Media Stream Storage`.
- [ ] Open a modal for the media test tool.
- [ ] Show state/status text.
- [ ] Show `Start` in idle state.
- [ ] Request microphone permission on `Start`.
- [ ] Change button to `Stop` while recording.
- [ ] Show chunk count and elapsed recording time while recording.
- [ ] On `Stop`, store/flush chunks, upload, finalize.
- [ ] Disable close/destructive actions during critical finalize unless cancellation is safe.
- [ ] Show errors with phase context.

## 6. Playback

- [ ] Add Hotline playback wrapper around Helper audio components.
- [ ] Render finalized diagnostic audio in the modal.
- [ ] Use Helper audio player/timeline/audiograph when available.
- [ ] Fail visibly if Helper audio component loading fails.
- [ ] Clean up player instances and object URLs when modal closes.

## 7. Direct Mode

- [ ] Implement direct HTTP chunk upload first.
- [ ] Upload each stored chunk to the diagnostic chunk endpoint.
- [ ] Finalize only after all chunks are accepted.
- [ ] Keep failed chunks visible and retryable or fail loudly.

## 8. Optional Realtime Mode

- [ ] Keep Realtime mode out of first pass unless explicitly requested.
- [ ] If added later, reuse the same session/storage/playback modules.
- [ ] Label the tested path clearly as `Realtime`.
- [ ] Confirm downstream ingest success before finalize.

## 9. Validation

- [ ] JS unit/contract tests for media stream session state transitions.
- [ ] JS unit/contract tests for queue storage behavior.
- [ ] Feature tests for diagnostic media endpoints.
- [ ] Feature tests proving diagnostic media is excluded from SITREP/Relay exports.
- [ ] `npm run build`.
- [ ] `git diff --check`.
- [ ] Browser smoke on `/operator`:
  - [ ] Tools dropdown renders.
  - [ ] Device Primer opens.
  - [ ] Test modal opens.
  - [ ] Start records microphone.
  - [ ] Chunks are counted.
  - [ ] Stop finalizes.
  - [ ] Audio is playable.
  - [ ] No console errors.

## 10. Handoff Notes

- [ ] No installer bundle unless requested.
- [ ] Note that operator workbench migration is intentionally deferred.
- [ ] Document any diagnostic media cleanup behavior.
- [ ] If Helper gaps are found, ask Helper instead of implementing generic UI replacements in Hotline.
