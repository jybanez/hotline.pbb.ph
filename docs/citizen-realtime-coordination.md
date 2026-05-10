# Citizen Realtime Coordination

Date: 2026-05-10

Status: Coordination record for the caller-to-citizen Realtime follow-up.

## Current Hotline State

- Hotline canonical admission endpoint is `POST /api/realtime/admission/citizen`.
- `POST /api/realtime/admission/caller` remains a legacy compatibility alias with telemetry.
- Hotline-owned browser publishers emit canonical `citizen.*` app events.
- Hotline browser surfaces accept both canonical `citizen.*` and temporary legacy `caller.*` events during the compatibility window.
- Inbound legacy `caller.*` usage is logged through `/api/realtime/legacy-caller-events`.
- Realtime payloads include canonical `citizen_id`, `citizen_name`, `citizen_avatar`, and `citizen_location` aliases beside legacy caller fields where compatibility still helps deployed clients.

## Realtime Gateway Impact

No gateway runtime change is required for the current Hotline migration.

Reason:
- `citizen.*` event names are app-owned event types carried through Realtime's existing `app.event.publish` lane.
- Realtime does not need to understand Hotline's public-user terminology to route those envelopes.
- Legacy `caller.*` events remain handled app-side by Hotline until decommission.

## Realtime Documentation Impact

Realtime shared docs should prefer citizen terminology for current Hotline examples:

- `C:\wamp64\www\pbb\realtime\docs\pbb-realtime-sdk-hotline-reference-flow.md`
  - change public-user wording such as `caller terminal` and `caller lookup` to citizen terminology.
  - keep any explicit `caller` note only if it is marked as legacy Hotline compatibility.

Realtime ingress docs do not need a Hotline admission-path change because Hotline admission endpoints live in the Hotline app, not the Realtime service.

## Realtime Fixture Impact

Realtime tests and examples currently include Hotline-shaped caller fixtures:

- `tests\Unit\RealtimeGatewayTest.php`
  - `caller.call.request` app-event examples should move to `citizen.call.request` unless a test is intentionally proving legacy caller compatibility.
  - sample user IDs such as `caller_001` can become `citizen_001` where they are generic Hotline example participants.
  - sample `peer_role = caller` should become `peer_role = citizen` where the fixture models the current Hotline media/session contract.
- `tests\Unit\RealtimeMediaChunkDispatcherTest.php`
  - sample segment keys such as `caller-audio-test` should become `citizen-audio-test` where they are not testing legacy media compatibility.
- `tests\Unit\RealtimeTokenValidatorTest.php`
  - sample project code `prj_hotline_caller` should become a citizen/public-user project example unless the fixture is intentionally legacy.

## Removal Coordination

Hotline should not remove legacy caller Realtime handling until:

- deployed Hotline clients have moved to `citizen.*` event names,
- Realtime examples and fixtures no longer teach new integrations to use `caller.*`,
- legacy caller Realtime telemetry shows no material live usage for the agreed compatibility window,
- Realtime owner confirms no shared docs still present `caller.*` as the current Hotline event contract.

## Chat Log

Coordination note posted to `C:\wamp64\www\pbb\chat_log.md` at `2026-05-10 14:32:46`.
