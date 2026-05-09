# PBB Hotline Command Broadcast Implementation Checklist

## Backend

- [x] Add `command_broadcasts` migration.
- [x] Add `CommandBroadcast` model.
- [x] Add command API endpoint to create broadcasts.
- [x] Validate title/message/tone/audience/expires_at.
- [x] Persist sender, publish timestamp, and Realtime result metadata.
- [x] Extend `RealtimeEventPublishService` with broadcast room/event helper.
- [x] Add route under `auth` + `role:command`.

## Realtime

- [x] Use server publish room `hotline.broadcast.global`.
- [x] Publish event type `hotline.broadcast.created`.
- [x] Ensure client admissions include the broadcast room.
- [x] Ensure server publish policy allows the broadcast room.

## Frontend

- [x] Add Command menu action `Broadcast`.
- [x] Add Helper form modal for composing the broadcast.
- [x] Join `hotline.broadcast.global` on caller, operator, and command surfaces.
- [x] Handle `hotline.broadcast.created` envelopes with dedupe.
- [x] Render receiver notification by tone.
- [x] Avoid showing expired broadcasts.

## Verification

- [x] Run PHP syntax checks for new/changed backend files.
- [x] Run migrations.
- [x] Run focused tests or route/controller checks where available.
- [x] Run `npm run build`.
- [ ] Manually verify Command sends and online surfaces receive.
