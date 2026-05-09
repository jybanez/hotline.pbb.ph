# PBB Hotline Command Broadcast Proposal

## Goal

Give Command a fast, auditable way to send operational announcements or instructions to all online Hotline users during active incidents, drills, and elevated alert states.

## V1 Scope

- Command users can create a broadcast from the Command menu.
- Broadcasts are persisted in Hotline for audit/history.
- Hotline backend publishes a server-originated Realtime event after persistence.
- Online Caller, Operator, and Command surfaces receive the event immediately.
- Receiver UI shows a prominent notification with local dismissal.

## Non-Goals For V1

- Per-user acknowledgement tracking.
- Scheduled broadcasts.
- Rich text, attachments, or incident-thread chat integration.
- Audience segmentation beyond a global everyone-online lane.
- Realtime browser-originated custom events for this feature.

## Authorization

Only authenticated users with the `command` role can create broadcasts in V1. The browser calls Hotline, and Hotline performs authorization, validation, persistence, and Realtime publish. Clients do not publish broadcast events directly.

## Data Shape

`command_broadcasts` stores:

- `id`
- `title`
- `message`
- `tone`: `info`, `warning`, or `urgent`
- `audience`: V1 defaults to `global`
- `created_by_user_id`
- `published_at`
- `expires_at`
- `realtime_status`
- `realtime_meta`
- timestamps

## Realtime Contract

- Room: `hotline.broadcast.global`
- Event type: `hotline.broadcast.created`
- Source: server publish via `RealtimeEventPublishService`
- Payload:
  - `id`
  - `title`
  - `message`
  - `tone`
  - `audience`
  - `created_by`
  - `published_at`
  - `expires_at`

Clients join `hotline.broadcast.global` alongside their existing surface rooms. Receipt should dedupe by `id`.

## Receiver UX

- `info`: normal broadcast toast/card.
- `warning`: stronger visual tone and longer display.
- `urgent`: prominent modal-style notice with clear dismiss.

V1 dismissal is local only. Expired broadcasts should not be displayed from bootstrap/history.

## Failure Handling

If persistence succeeds but Realtime publish is skipped, rejected, or times out, the API returns the broadcast and publish metadata. Command shows a warning that the broadcast was saved but live delivery may be delayed or unavailable.
