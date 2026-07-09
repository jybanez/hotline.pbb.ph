# PBB Hotline Alert SDK Implementation Checklist

Status: proposal checklist  
Related proposal: [PBB Hotline Alert SDK Proposal](pbb-hotline-alert-sdk-proposal.md)

## Product Boundary

- Hotline remains the source of truth for alert status.
- The SDK is read-only.
- Consuming apps decide how to render or react to alert status.
- Realtime transports updates but does not own alert semantics.
- Helper may own visual widgets later, but the SDK core must remain UI-free.

## 1. Current Runtime Audit

- [ ] Locate the current Hotline alert-level source in settings/runtime services.
- [ ] Confirm all valid current alert levels.
- [ ] Confirm where Command/Admin updates alert level.
- [ ] Confirm the current Realtime event emitted when alert level changes.
- [ ] Confirm which Realtime room consumers can join for alert updates.
- [ ] Confirm whether alert voice and audio graph style should be exposed in the public alert object.

## 2. REST Bootstrap Endpoint

- [ ] Add public read-only endpoint:

```text
GET /api/public/alert-status
```

- [ ] Return only minimal alert posture data.
- [ ] Do not expose full settings.
- [ ] Do not expose user, incident, SITREP, support request, or token data.
- [ ] Normalize response to:

```json
{
  "ok": true,
  "alert": {
    "level": "normal",
    "label": "Normal",
    "severity": 0,
    "updated_at": "2026-07-09T10:15:00+08:00",
    "source": {
      "app": "hotline",
      "hub_id": "13",
      "deployment": "barangay"
    },
    "presentation": {
      "voice": "default",
      "audio_graph_style": "tsunami"
    }
  }
}
```

- [ ] Add tests for normal, elevated, and critical output.
- [ ] Add tests proving sensitive settings are not present.

## 3. Realtime Alert Event Contract

- [ ] Reuse existing alert-change Realtime publishing if already stable.
- [ ] If needed, add or document canonical event type:

```text
hotline.alert.changed
```

- [ ] Use the same normalized alert object as REST.
- [ ] Keep event publishing tied to successful alert-level changes only.
- [ ] Add tests or contract coverage for emitted payload shape.
- [ ] Document room name used for alert updates.

## 4. SDK Package

- [ ] Create source package:

```text
packages/pbb-hotline-alert-sdk/
```

- [ ] Add package files:

```text
packages/pbb-hotline-alert-sdk/
  src/
    HotlineAlertClient.js
    normalizeAlertStatus.js
  demo/
    alert-sdk.html
  docs/
    developer-manual.md
  README.md
  package.json
```

- [ ] Keep SDK framework-agnostic.
- [ ] Do not import Hotline app internals.
- [ ] Do not import Helper in SDK core.
- [ ] Do not expose any alert mutation method.
- [ ] Use dependency injection for `fetchImpl` and `realtimeClient` for tests.

## 5. SDK Client Behavior

- [ ] Implement `bootstrap()` to fetch current alert status.
- [ ] Implement `subscribe()` to listen for Realtime alert changes.
- [ ] Implement `unsubscribe()`.
- [ ] Implement `current()`.
- [ ] Implement event emitter methods:

```text
on(eventName, handler)
off(eventName, handler)
```

- [ ] Emit:

```text
alert.loaded
alert.changed
alert.error
alert.reconnected
```

- [ ] Do not emit duplicate `alert.changed` if the normalized state has not changed.
- [ ] Preserve last known alert on network or Realtime failure.

## 6. Normalization Helpers

- [ ] Normalize level names to lowercase.
- [ ] Map severities:

```text
normal = 0
elevated = 1
critical = 2
```

- [ ] Preserve unknown future levels as raw strings.
- [ ] Provide helper methods:

```text
isNormal()
isElevated()
isCritical()
severityRank()
cssClass()
```

- [ ] Keep helper methods pure and deterministic.

## 7. Demo And Documentation

- [ ] Add browser demo page:

```text
packages/pbb-hotline-alert-sdk/demo/alert-sdk.html
```

- [ ] Demo should:
  - fetch current alert;
  - show normalized alert JSON;
  - show level/severity helper output;
  - optionally connect to Realtime if a client is supplied;
  - not require authenticated Hotline user session.

- [ ] Add developer manual:

```text
packages/pbb-hotline-alert-sdk/docs/developer-manual.md
```

- [ ] Include examples for Support, Utility/Vena, Landing, and MapServer.
- [ ] Document read-only boundary clearly.

## 8. Tests

- [ ] Unit tests for `normalizeAlertStatus`.
- [ ] Unit tests for client bootstrap success.
- [ ] Unit tests for bootstrap failure and last-known fallback.
- [ ] Unit tests for Realtime `alert.changed`.
- [ ] Unit tests for duplicate suppression.
- [ ] Unit tests proving no mutation API exists.
- [ ] Feature test for REST endpoint shape.
- [ ] Feature test proving sensitive settings are not exposed.

## 9. Packaging Boundary

- [ ] Keep SDK source/dev scope unless a consuming app explicitly vendors it.
- [ ] Do not build a Hotline installer bundle from the feature branch.
- [ ] If package metadata changes affect bundle rules, update `release.json` only after review.
- [ ] Main-built bundle handoff to Kit happens only after merge and explicit approval.

## 10. Cross-Team Coordination

- [ ] Inform Support about the read-only alert SDK once the branch is ready.
- [ ] Inform Utility/Vena about the normalized alert object and intended map use.
- [ ] Ask Realtime for confirmation if a new canonical event type or room is needed.
- [ ] Ask Helper only if a shared visual alert component is requested later.
- [ ] Ask Landing only if public gateway routing is needed for cross-app alert bootstrap.

## Acceptance Criteria

- [ ] Other PBB apps can fetch current alert status without knowing Hotline settings internals.
- [ ] Other PBB apps can receive live alert changes through the SDK.
- [ ] SDK does not mutate alert state.
- [ ] SDK does not render UI or inject styles.
- [ ] Sensitive settings and operational records are not exposed.
- [ ] Tests cover REST endpoint, normalization, and Realtime client behavior.
- [ ] Docs and demo are usable by a consuming app developer.
