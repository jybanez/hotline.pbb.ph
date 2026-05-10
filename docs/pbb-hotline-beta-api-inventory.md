# PBB Hotline Beta API Inventory

Date: 2026-04-04

Status: Draft initial endpoint inventory, partially superseded by Phase 2 caller-to-citizen compatibility work

Phase 2 migration note:
- `/api/citizen/*` is the canonical public-user API prefix.
- `/api/caller/*` remains a temporary legacy alias until the caller-to-citizen refactor is complete.
- Citizen request/response aliases are canonical when both citizen and caller fields are accepted.

References:
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB API Documentation Baseline](C:/wamp64/www/pbb/hub.ph/docs/pbb-api-documentation-baseline.md)

Purpose:
- define the initial Beta API surface
- give the new team a stable starting point for OpenAPI
- separate public, session, citizen, legacy caller, operator, admin, and Realtime-facing endpoints

## 1. Documentation Rule

This inventory is the source list for the first Beta OpenAPI file.

Recommended next output:
- `docs/openapi/pbb-hotline-beta.yaml`

## 2. Public / Bootstrap Endpoints

### `GET /api/bootstrap`

Purpose:
- startup bootstrap payload for the current surface

Should include:
- authenticated flag
- current user when authenticated
- current alert level
- relevant settings safe for the current surface
- CSRF token/session state as required by the chosen Laravel/session baseline

Auth:
- public, but returns authenticated state when session exists

### `GET /api/public/alert-level`

Purpose:
- public alert-level payload

Minimal response:
- current alert level
- fixed alert-level description

Auth:
- public

## 3. Session / Account Endpoints

### `POST /api/login`

Purpose:
- session login for all roles

Request:
- `email`
- `password`

Auth:
- guest

### `POST /api/logout`

Purpose:
- end current session

Auth:
- authenticated

### `GET /api/user`

Purpose:
- current authenticated user

Response:
- shared User DTO

Auth:
- authenticated

### `POST /api/user`

Purpose:
- update current authenticated account

Expected editable fields:
- `name`
- `email`
- `mobile`
- `avatar`

Auth:
- authenticated

### `POST /api/user/password`

Purpose:
- update current authenticated user's password

Expected fields:
- `current_password`
- `new_password`
- `confirm_password`

Auth:
- authenticated

### `POST /api/reauth`

Purpose:
- re-authenticate expired session context without full redirect

Expected fields:
- `email`
- `password`

Auth:
- session-expired/reauth context

### `GET /api/session/ping`

Purpose:
- lightweight authenticated keepalive request near expiry

Behavior:
- requires normal authenticated browser session
- counts as a normal server touch that extends the session
- may return refreshed CSRF token/session metadata if needed

Auth:
- authenticated

## 4. Caller Endpoints

### `GET /api/caller/home`

Purpose:
- caller home state

Should include:
- current open incident summary if any
- recent incident list excluding current open incident
- current caller availability indicator payload
- caller-surface settings relevant to call hold / alert level

Availability rules:
- backend should return structured availability truth, not only a color string
- backend should decide:
  - `call_service_ready`
  - `available_operator_count`
  - backend-derived green/yellow/red recommendation
- client may still force local `red` if it cannot reach Hotline backend/session truth at all

Auth:
- `caller`

### `POST /api/caller/call-attempts`

Purpose:
- start a new call attempt after press-and-hold passes and green status allows calling

Auth:
- `caller`

Notes:
- do not create incident here
- do not allow this endpoint when caller has yellow/red blocked state

### `POST /api/caller/call-attempts/{attempt}/cancel`

Purpose:
- cancel an unanswered new call attempt

Result:
- attempt outcome becomes `cancelled_by_caller`

Auth:
- `caller`

### `GET /api/caller/incidents/current`

Purpose:
- return current open caller incident if one exists

Response:
- incident workbench/view DTO

Auth:
- `caller`

### `GET /api/caller/incidents/history`

Purpose:
- return caller incident history excluding current open incident

Auth:
- `caller`

### `GET /api/caller/incidents/{incident}`

Purpose:
- read one caller-visible incident view

Auth:
- `caller`

### `POST /api/caller/incidents/{incident}/reconnect`

Purpose:
- request reconnect to the assigned operator

Rules:
- only send if assigned operator runtime state is `available` or `engaged` on the same incident
- if blocked before send, return informative response and create no reconnect-attempt record

Auth:
- `caller`

### `POST /api/caller/call-sessions/{callSession}/cancel`

Purpose:
- cancel an unanswered reconnect after it has already started ringing

Rules:
- applies only while the reconnect call session is still unanswered
- result should set call-session outcome to `cancelled_by_caller`
- once the reconnect has already been answered, normal hang-up rules apply instead

Auth:
- `caller`

## 5. Operator Endpoints

### `GET /api/operator/dashboard`

Purpose:
- operator dashboard bootstrap payload

Should include:
- current alert level
- current operator runtime state
- current operator dashboard lists
- map incident marker set
- archive list
- operator-specific activity feed
- optional live-computed stat chips

Auth:
- `operator`

### `GET /api/operator/incidents`

Purpose:
- incident list feed for operator dashboard

Typical filters:
- `status=active,deferred`
- `status=resolved,discarded`

Auth:
- `operator`

### `GET /api/operator/incidents/{incident}`

Purpose:
- load full operator workbench payload

Overlay restore note:
- this endpoint should support overlay workbench loading and restore from retained client state
- if the operator is no longer allowed to open the incident, return the appropriate forbidden/not-found response so the frontend can fall back to the dashboard and clear retained workbench state

Auth:
- `operator`

### `POST /api/operator/call-attempt-operator-attempts/{attempt}/answer`

Purpose:
- answer a routed new call operator-attempt

Result:
- create incident
- create first call session

Auth:
- `operator`

### `POST /api/operator/call-attempt-operator-attempts/{attempt}/decline`

Purpose:
- explicitly decline unanswered incoming new call

Auth:
- `operator`

### `POST /api/operator/call-sessions/{callSession}/answer`

Purpose:
- answer reconnect call session request when routed to assigned operator

Auth:
- `operator`

Call session payload note:
- call session responses should expose joined users through `participants[]`
- do not rely on a single session-level `operator_id`

### `POST /api/operator/call-sessions/{callSession}/hangup`

Purpose:
- end a live call

Auth:
- `operator`

### `POST /api/operator/call-sessions/{callSession}/mute`

Purpose:
- mute/unmute operator microphone state in app-owned business layer if tracked

Auth:
- `operator`

### `POST /api/operator/incidents/{incident}/status`

Purpose:
- move incident status

Allowed targets in Phase 1:
- `Deferred`
- `Discarded`
- `Resolved`

Rule:
- `Resolved` must be blocked until all team assignments are `Completed` or `Cancelled`

Auth:
- `operator`

### `POST /api/operator/incidents/{incident}/actual-caller`

Purpose:
- update `actual_caller_name` and `actual_caller_relationship`

Auth:
- `operator`

### `POST /api/operator/incidents/{incident}/other-details`

Purpose:
- update `other_details`

Auth:
- `operator`

### `POST /api/operator/incidents/{incident}/incident-type-details`

Purpose:
- create/update grouped incident type details

Auth:
- `operator`

### `POST /api/operator/incidents/{incident}/transfers`

Purpose:
- request transfer to another available operator

Fields:
- `to_operator_id`
- `reason`

Auth:
- `operator`

### `POST /api/operator/transfers/{transfer}/accept`

Purpose:
- accept transfer

Rules:
- acceptance switches incident ownership immediately to the new operator
- reconnect target switches immediately to the new operator
- old operator becomes read-only immediately
- only the new operator may mutate the incident after acceptance, even during call overlap

Auth:
- `operator`

### `POST /api/operator/transfers/{transfer}/reject`

Purpose:
- reject transfer

Auth:
- `operator`

### `POST /api/operator/incidents/{incident}/team-assignments`

Purpose:
- assign team to incident

Auth:
- `operator`

### `POST /api/operator/team-assignments/{assignment}`

Purpose:
- update one team assignment

Auth:
- `operator`

### `DELETE /api/operator/team-assignments/{assignment}`

Purpose:
- delete assignment only when canceling from initial `Assigned` state per business rule

Auth:
- `operator`

## 6. Messaging And Media Endpoints

### `GET /api/incidents/{incident}/messages`

Purpose:
- list incident chat history

Auth:
- caller or operator with access to the incident

### `POST /api/incidents/{incident}/messages`

Purpose:
- create incident message during live call

Rule:
- composer only enabled during live call, but history remains readable after

Auth:
- caller or operator with live-call permission on the incident

### `POST /api/incidents/{incident}/message-attachments`

Purpose:
- finalize persisted message attachment metadata after upload transport succeeds

Auth:
- caller or operator with live-call permission on the incident

### `GET /api/incidents/{incident}/media`

Purpose:
- list incident-level merged media chronology

Auth:
- filtered by role visibility

### `POST /api/media/assembly/complete`

Purpose:
- internal/background endpoint or job hook to finalize merged media availability

Auth:
- internal worker/service auth only

## 7. Admin Endpoints

### `GET /api/admin/summary`

Purpose:
- admin landing summary

Should include:
- current alert level
- counts for users, teams, incident types, resource types

Auth:
- `admin`

### `GET /api/admin/users`
### `POST /api/admin/users`
### `GET /api/admin/users/{user}`
### `POST /api/admin/users/{user}`
### `DELETE /api/admin/users/{user}`

### `GET /api/admin/incident-categories`
### `POST /api/admin/incident-categories`
### `POST /api/admin/incident-categories/{category}`
### `DELETE /api/admin/incident-categories/{category}`

### `GET /api/admin/incident-types`
### `POST /api/admin/incident-types`
### `POST /api/admin/incident-types/{type}`
### `DELETE /api/admin/incident-types/{type}`

### `GET /api/admin/incident-type-fields`
### `POST /api/admin/incident-type-fields`
### `POST /api/admin/incident-type-fields/{field}`
### `DELETE /api/admin/incident-type-fields/{field}`

### `GET /api/admin/team-categories`
### `POST /api/admin/team-categories`
### `POST /api/admin/team-categories/{category}`
### `DELETE /api/admin/team-categories/{category}`

### `GET /api/admin/teams`
### `POST /api/admin/teams`
### `POST /api/admin/teams/{team}`
### `DELETE /api/admin/teams/{team}`

### `GET /api/admin/resource-type-categories`
### `POST /api/admin/resource-type-categories`
### `POST /api/admin/resource-type-categories/{resourceTypeCategory}`
### `DELETE /api/admin/resource-type-categories/{resourceTypeCategory}`

### `GET /api/admin/resource-types`
### `POST /api/admin/resource-types`
### `POST /api/admin/resource-types/{resourceType}`
### `DELETE /api/admin/resource-types/{resourceType}`

### `GET /api/admin/settings`
### `POST /api/admin/settings`

Purpose:
- admin module CRUD and settings updates

Auth:
- `admin`

## 8. Realtime Admission Endpoints

### `POST /api/realtime/admission/caller`

Purpose:
- issue caller-side Realtime admission for current allowed rooms/capabilities

Auth:
- `caller`

### `POST /api/realtime/admission/operator`

Purpose:
- issue operator-side Realtime admission for allowed rooms/capabilities

Auth:
- `operator`

## 9. Future / Later-Phase Endpoints

Later phases should add separate inventories for:
- command dashboard
- announcements
- SITREP generation
- Relay handoff
- post-SITREP invite/conference expansion

## 10. OpenAPI Recommendation

Turn this inventory into an OpenAPI file early.

Recommended first grouped tags:
- `Public`
- `Auth`
- `Account`
- `Caller`
- `Operator`
- `Messaging`
- `Media`
- `Admin`
- `Realtime`
