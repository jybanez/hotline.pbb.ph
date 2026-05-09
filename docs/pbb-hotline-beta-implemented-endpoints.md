# PBB Hotline Beta Implemented Endpoints

Date: 2026-04-04

Status: Current implementation snapshot for the Laravel Beta app in `C:/wamp64/www/pbb/hotline`

Assigned Beta domain:
- `https://hotline.pbb.ph/`

Purpose:
- show which endpoints are already implemented in code
- separate landed Beta behavior from still-planned inventory/OpenAPI scope
- give manual testing a narrower endpoint target list

## 1. Public / Session

Implemented:
- `GET /api/bootstrap`
- `GET /api/public/alert-level`
- `POST /api/login`
- `POST /api/logout`
- `POST /api/reauth`
- `GET /api/user`
- `POST /api/user`
- `POST /api/user/password`
- `GET /api/session/ping`

Notes:
- login rejects non-`active` users
- role redirects are handled after authentication at the surface level

## 2. Caller

Implemented:
- `GET /api/caller/home`
- `POST /api/caller/call-attempts`
- `POST /api/caller/call-attempts/{attempt}/cancel`
- `POST /api/caller/call-sessions/{callSession}/cancel`
- `GET /api/caller/incidents/current`
- `GET /api/caller/incidents/history`
- `GET /api/caller/incidents/{incident}`
- `POST /api/caller/incidents/{incident}/reconnect`

Notes:
- new incidents are still created only when an operator answers
- caller home excludes the current open incident from recent history
- reconnect creation is blocked when the assigned operator is busy on another active incident

## 3. Operator

Implemented:
- `GET /api/operator/dashboard`
- `GET /api/operator/incidents`
- `GET /api/operator/incidents/{incident}`
- `POST /api/operator/incidents/{incident}/status`
- `POST /api/operator/incidents/{incident}/actual-caller`
- `POST /api/operator/incidents/{incident}/other-details`
- `POST /api/operator/incidents/{incident}/transfers`
- `POST /api/operator/incidents/{incident}/team-assignments`
- `POST /api/operator/call-attempt-operator-attempts/{attempt}/answer`
- `POST /api/operator/call-sessions/{callSession}/answer`
- `POST /api/operator/transfers/{transfer}/accept`
- `POST /api/operator/transfers/{transfer}/reject`
- `POST /api/operator/team-assignments/{assignment}`
- `DELETE /api/operator/team-assignments/{assignment}`
- `GET /api/incidents/{incident}/messages`
- `GET /api/incidents/{incident}/media`

Notes:
- incident status changes currently support `Deferred`, `Discarded`, and `Resolved`
- resolve is blocked while open team assignments still exist
- transfer overlap and live call controls are still pending

## 4. Admin

Implemented:
- `GET /api/admin/summary`
- `GET /api/admin/users`
- `POST /api/admin/users`
- `GET /api/admin/users/{user}`
- `POST /api/admin/users/{user}`
- `DELETE /api/admin/users/{user}`
- `GET /api/admin/settings`
- `POST /api/admin/settings`

Notes:
- admin-created users default to `active`
- user delete is blocked when references exist, and the API returns reference details
- other admin CRUD modules remain pending

## 5. Realtime

Implemented:
- `POST /api/realtime/admission/caller`
- `POST /api/realtime/admission/operator`

Notes:
- admissions are backend-issued only
- the current implementation returns a token, one room name, and capability list
- chat/presence/signaling transport is still pending

## 6. Media Assembly

Implemented:
- `POST /api/media/assembly/complete`

Notes:
- admission is protected by `X-Media-Assembly-Token`
- the endpoint emits final media records only when the assembly worker reports completion

## 7. Manual Test Focus

Recommended immediate manual checks:
- public home bootstrap and alert card render
- caller login and caller home availability state
- caller new-call attempt creation and cancel
- caller reconnect create/cancel and operator reconnect answer
- operator answer path creating incident + first call session
- operator workbench overlay open, restore-after-refresh, and status changes
- operator transfer request accept/reject behavior
- operator team assignment create/update/cancel behavior
- incident message/media list visibility by caller vs operator role
- admin user create/update/delete-block behavior
- admin settings read/update behavior
