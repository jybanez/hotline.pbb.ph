# Helper chat recording integration

Integrates reviewed Helper release `5c93ad205721f9d9346767b4752157d939b543bd` (`.271`) into the shared operator/citizen incident chat. File, video and audio actions become available only after authenticated room admission. Captured recordings enter the existing validated upload queue, never auto-send, and preserve the typed draft. The composer receives the effective signed policy (also capped at the persisted endpoint's 50 MiB ceiling), current used bytes and validated attachment count.

Jonathan explicitly selected an automatic caption for attachment-only Send: “Use an automatic caption, such as ‘Audio attachment’”. Blank text becomes Audio/Video/Image/File attachment for one admitted file, or N attachments for multiple. Typed text is unchanged; empty messages without attachments remain disabled. The Realtime gateway and message API still receive nonblank text.

The local vendored Realtime attachment splitter now accepts codec parameters in data URLs, such as audio/webm;codecs=opus. Previously these recordings produced no chunks. This is a focused local SDK compatibility fix and should be reconciled with the upstream SDK when refreshing it.

Selection batches are serialized against one policy budget. Preparation failure and disposed contexts revoke owned preview URLs. Audio has a blob preview; send failure retains draft and queued attachments; acknowledged publication clears them. No automatic retry was added for partial or uncertain server persistence. Existing audio session chrome:false remains enabled.

Verification:
- `node tests/js/chatComposerIntegration.test.mjs`: actual shared Hotline mount function with complete Helper components, source and bundle; fake-device audio/video capture, stopped tracks, no auto-send, automatic audio caption and nonempty chunks, failed-transfer draft preservation, acknowledgement cleanup, oversized rejection, concurrent total-budget enforcement, and disposed-context cleanup.
- Helper composer recording regression: source/bundle pass. Audio chrome source/bundle and registry 129 entries/9 groups pass.
- Operator chat contracts, chat sender avatar checks, production build and git diff checks pass.
- HTTP bytes served by https://hotline.pbb.ph match local reviewed composer source, composer CSS and UI bundle, plus rebuilt renderSurface-CA7RiJNT.js containing the integration.

Browser tests use Edge fake media and a controlled Realtime client/admission with the actual SDK chunk functions. They do not claim real microphone/camera capture, live Realtime delivery, or persisted recording playback. The desktop browser bridge remains unavailable. This focused integration is stacked on the unmerged callback branch and does not accept or complete task 134.
