# Hotline To Helper Mapping

Date: 2026-04-03

Purpose:
- map current Hotline surfaces to `helpers.pbb.ph`
- identify where Hotline should reuse Helper directly
- identify what stays app-owned
- identify gaps that should become Helper proposals before rebuild

Primary references:
- current Hotline routes in [web.php](C:/wamp64/www/hotline/routes/web.php)
- current Hotline views in [resources/views](C:/wamp64/www/hotline/resources/views)
- current Helper playbook in [pbb-refactor-playbook.md](https://github.com/jybanez/helpers.pbb.ph/blob/main/docs/pbb-refactor-playbook.md)

## Core Rule

For the rebuild, Helper should be the default UI contract layer.

Hotline should own:
- domain rules
- local persistence
- sync rules
- API endpoints
- offline behavior
- adapter functions that normalize Hotline payloads into Helper-ready data

Hotline should not own repeated UI primitives if Helper already provides them.

## Current Hotline Surface Inventory

Current top-level surfaces:
- caller surface: [user/home.blade.php](C:/wamp64/www/hotline/resources/views/user/home.blade.php)
- operator surface: [operator/dashboard.blade.php](C:/wamp64/www/hotline/resources/views/operator/dashboard.blade.php)
- operator history: [operator/history.blade.php](C:/wamp64/www/hotline/resources/views/operator/history.blade.php)
- command surface: [command/dashboard.blade.php](C:/wamp64/www/hotline/resources/views/command/dashboard.blade.php)
- admin dashboard: [admin/dashboard.blade.php](C:/wamp64/www/hotline/resources/views/admin/dashboard.blade.php)
- admin incident config: [admin/incidents.blade.php](C:/wamp64/www/hotline/resources/views/admin/incidents.blade.php)
- admin teams config: [admin/teams.blade.php](C:/wamp64/www/hotline/resources/views/admin/teams.blade.php)
- admin resources config: [admin/resources.blade.php](C:/wamp64/www/hotline/resources/views/admin/resources.blade.php)
- admin setup: [admin/setup.blade.php](C:/wamp64/www/hotline/resources/views/admin/setup.blade.php)
- SITREP: [admin/sitrep.blade.php](C:/wamp64/www/hotline/resources/views/admin/sitrep.blade.php)

Behavior-heavy backend surfaces:
- call lifecycle in [CallController.php](C:/wamp64/www/hotline/app/Http/Controllers/CallController.php)
- incident lifecycle in [IncidentController.php](C:/wamp64/www/hotline/app/Http/Controllers/IncidentController.php)
- operator state/bootstrap in [OperatorController.php](C:/wamp64/www/hotline/app/Http/Controllers/OperatorController.php)
- command state/sitrep in [CommandController.php](C:/wamp64/www/hotline/app/Http/Controllers/CommandController.php)
- admin taxonomy/team config in [AdminIncidentController.php](C:/wamp64/www/hotline/app/Http/Controllers/AdminIncidentController.php) and [AdminTeamController.php](C:/wamp64/www/hotline/app/Http/Controllers/AdminTeamController.php)

## Helper Capabilities Most Relevant To Hotline

From the playbook and README, the most relevant existing Helper surfaces are:
- `incidentBase`
- `incidentTeamsAssignments`
- `incidentTeamsAssignmentsEditor`
- `incidentTeamsAssignmentsViewer`
- `incidentTypes`
- `incidentTypesDetailsEditor`
- `incidentTypesDetailsViewer`
- `createChatThread(...)`
- `createChatComposer(...)`
- `createChatUploadQueue(...)`
- `createStatusUpdateFormModal(...)`
- `createReasonFormModal(...)`
- `createFormModal(...)`
- `createLoginFormModal(...)`
- `createReauthFormModal(...)`
- `createMediaViewer(...)`
- `createHierarchyMap(...)`
- `createTreeGrid(...)`
- `ui.loader`
- shared shell/style primitives in `ui.components.css`
- shared audio primitives in `ui.audio.*`

## Mapping By Hotline Surface

### 1. Caller Surface

Current intent:
- citizen on barangay Wi-Fi
- local-first emergency reporting
- active incident visibility
- chat/media exchange with operator
- call state and reconnect state

Use Helper directly for:
- chat thread: `createChatThread(...)`
- chat composer: `createChatComposer(...)`
- photo/video upload queue: `createChatUploadQueue(...)`
- media preview/viewer: `createMediaViewer(...)`
- form modals for incident detail capture: `createFormModal(...)`
- shared shell, badges, labels, cards, buttons via shared UI CSS primitives

Hotline app-owned adapters:
- `callerBootstrapAdapter()`
- `startCallAdapter()`
- `reconnectCallAdapter()`
- `sendMessageAdapter()`
- `uploadMediaAdapter()`
- `incidentBootstrapNormalization()`

App-owned logic:
- whether a citizen can start a new report
- local connectivity messaging
- reconnect policy
- offline session behavior

Likely Helper gap:
- a reusable caller-side local-hub “connection status / local hub available / upstream unavailable” panel

Recommendation:
- propose a generic connectivity banner/panel only if it is needed across multiple PBB apps
- otherwise keep it local to Hotline

### 2. Operator Dashboard

Current intent:
- active incident handling
- call session management
- incident details editing
- resource needed editing
- team assignment management
- chat/media with caller
- operator status and availability

Use Helper directly for:
- incident shell using `incidentBase`
- incident type presentation using `incidentTypes`
- detail editing using `incidentTypesDetailsEditor`
- detail viewing using `incidentTypesDetailsViewer`
- team assignment list/editor using `incidentTeamsAssignmentsEditor`
- team assignment read-only summary using `incidentTeamsAssignmentsViewer`
- status change modal via `createStatusUpdateFormModal(...)`
- cancellation / reject / reason capture via `createReasonFormModal(...)`
- chat thread/composer/upload queue
- media viewer
- shared modal/dialog/toast/select primitives
- audio display via `ui.audio.*` where applicable

Hotline app-owned adapters:
- `operatorBootstrapAdapter()`
- `incidentDetailsAdapter()`
- `incidentResourcesAdapter()`
- `teamAssignmentAdapter()`
- `transferIncidentAdapter()`
- `operatorAvailabilityAdapter()`
- `callSignalAdapter()`

App-owned logic:
- exact report status transitions
- exact team assignment status transitions
- call ownership / authorization
- offline-first local LAN behavior

Likely Helper gaps:
- a strongly opinionated “incident workbench” composition wrapper that binds:
  - incident details
  - structured fields
  - team assignments
  - chat/media
  - activity timeline
- possibly a reusable transfer-request panel/workflow

Recommendation:
- start with composition of existing Helper pieces
- only propose an `incidentWorkbench` wrapper after two real compositions reveal repeated glue

### 3. Operator History

Current intent:
- browse/archive past incidents
- inspect details
- inspect media and final state

Use Helper directly for:
- tree/grid/list presentation with `createTreeGrid(...)` or shared grid primitives
- incident type viewers
- assignment viewers
- media viewer
- shared search shell primitives

Hotline app-owned adapters:
- `operatorIncidentArchiveAdapter()`
- `incidentHistoryNormalization()`

Likely Helper gap:
- none yet; current Helper grid/list/tooling should likely be enough

### 4. Command Dashboard

Current intent:
- system-wide incident watch
- resource coverage visibility
- operator visibility
- team-flow visibility
- command-side reassignment
- alert-level management

Use Helper directly for:
- grids, filters, drawers, search shells
- team assignment viewer/editor
- incident viewers
- status/reason modals
- toast/dialog primitives

Possible Helper primitives to use:
- `createTreeGrid(...)`
- `createHierarchyMap(...)` if geographic/organizational hierarchy views are needed
- `ui.progress`, `ui.tabs`, `ui.strips`, `ui.virtual.list` as needed

Hotline app-owned adapters:
- `commandBootstrapAdapter()`
- `commandIncidentFeedAdapter()`
- `commandTeamFlowAdapter()`
- `commandAlertLevelAdapter()`
- `commandReassignAdapter()`

App-owned logic:
- command permissions
- alert-level state model
- reassignment rules

Likely Helper gap:
- a reusable “operational board” shell for command-style monitoring

Recommendation:
- keep this local first unless the same operational board pattern is needed in HQ, Relay, or Realtime admin surfaces

### 5. SITREP Surface

Current intent:
- operational summary dashboard
- local reporting product
- upstream Relay payload source

Use Helper directly for:
- shell layout primitives
- grid/progress primitives
- cards/panels/badges
- table/list primitives
- hierarchy map if geo/resource sections evolve

Hotline app-owned adapters:
- `sitrepSummaryAdapter()`
- `sitrepCoverageAdapter()`
- `sitrepTrendAdapter()`
- `sitrepUploadEnvelopeAdapter()`

App-owned logic:
- all SITREP formulas
- reporting windows
- upload payload generation
- upstream/downstream sync state

Likely Helper gap:
- none initially if SITREP is mostly cards, tables, and shared shell pieces
- if multiple PBB apps need the same reporting shell, propose a shared reporting-board wrapper later

Important note:
- SITREP should stay app-owned semantically even if it uses Helper heavily for layout and widgets

### 6. Admin Setup

Current intent:
- local configuration
- token setup
- ringtone/system settings
- escalation/system alert setup

Use Helper directly for:
- form modals
- shared password fields
- login/reauth/account wrappers where relevant
- select/toggle/fieldset primitives
- toast/dialogs

Hotline app-owned adapters:
- `settingsFormAdapter()`
- `apiTokenManagementAdapter()`
- `ringtoneUploadAdapter()`

Likely Helper gap:
- maybe none; this looks like standard shared form surface

### 7. Admin Incident Taxonomy

Current intent:
- manage incident categories
- manage incident types
- manage type fields
- manage type-resource defaults

Use Helper directly for:
- `incidentTypes`
- `incidentTypesDetailsEditor`
- shared modal/grid/form/select primitives

Hotline app-owned adapters:
- `incidentCategoryAdminAdapter()`
- `incidentTypeAdminAdapter()`
- `incidentTypeFieldAdminAdapter()`
- `incidentTypeResourceDefaultAdapter()`

Likely Helper gap:
- maybe a shared taxonomy-admin wrapper if multiple PBB projects manage incident taxonomies
- not enough evidence yet

### 8. Admin Team Configuration

Current intent:
- manage teams
- categories
- resource inventories

Use Helper directly for:
- team assignment related viewers where useful
- grid/list/search primitives
- form modals
- reason/status modals where relevant

Hotline app-owned adapters:
- `teamAdminAdapter()`
- `teamCategoryAdminAdapter()`
- `teamInventoryAdminAdapter()`

Likely Helper gap:
- a shared “resource inventory editor” if this pattern exists outside Hotline

## Adapter Boundary Recommendations

Per playbook, Helper callbacks should remain UI-level and Hotline should transform payloads.

Recommended adapter style:

```js
onStatusNext(assignmentId, toStatus) {
  return hotlineApi.updateTeamAssignment(assignmentId, {
    status: normalizeAssignmentStatusForApi(toStatus),
  }).then((response) => {
    assignmentsHelper.setList(normalizeAssignmentsForHelper(response.assignments));
  });
}
```

Rules:
- normalize backend casing before Helper sees data
- normalize Helper callback outputs before API calls
- never leak Hotline transport quirks into Helper render logic

## Canonical Normalization Work Needed Before Rebuild

Current prototype vocabulary is inconsistent with your clarified target vocabulary.

Known mismatches:
- call session in code often uses `ringing`; target says `calling`
- team assignment code uses lowercase underscore values like `en_route`, `on_scene`
- target vocabulary says `En-route`, `On-Scene`
- incident logic still contains `Escalated` in places, but your clarified report statuses are:
  - `New`
  - `Active`
  - `Deferred`
  - `Discarded`
  - `Resolved`

Before adopting Helper contracts broadly, define one canonical normalization layer for:
- report statuses
- call statuses
- team assignment statuses
- incident type/details payload shape
- team inventory/resource payload shape

## Rebuild Recommendation By Layer

### Reuse Helper immediately
- chat/media UI
- shared shells, forms, modals, dialogs, toasts
- incident detail editor/viewer
- team assignment editor/viewer
- grids, lists, tree grids, search shells
- media viewer
- audio primitives where applicable

### Keep Hotline app-owned
- local-first call lifecycle
- offline session and barangay connectivity behavior
- SITREP computation and upload rules
- Relay envelopes
- HQ network/topology awareness
- authorization and role semantics
- all domain transitions

### Proposal-first candidates
- incident workbench composition wrapper
- transfer-request workflow wrapper
- operational board / monitoring shell
- resource inventory editor
- connectivity/offline-state banner, if repeated across PBB apps

## Practical Screen Mapping Matrix

### Caller
- Helper-first: high
- Hotline-local UI: low to medium
- Proposal need: low

### Operator dashboard
- Helper-first: very high
- Hotline-local UI: medium
- Proposal need: medium

### Operator history
- Helper-first: medium to high
- Hotline-local UI: low
- Proposal need: low

### Command dashboard
- Helper-first: medium
- Hotline-local UI: medium to high
- Proposal need: medium

### SITREP
- Helper-first: medium for layout
- Hotline-local UI: high for semantics
- Proposal need: low

### Admin setup
- Helper-first: high
- Hotline-local UI: low
- Proposal need: low

### Admin taxonomy and teams
- Helper-first: medium to high
- Hotline-local UI: medium
- Proposal need: low to medium

## Suggested Next Deliverables

1. Define canonical Hotline DTOs for:
- incident
- call session
- team assignment
- SITREP summary
- sync message envelope

2. Build a Helper integration matrix:
- Helper registry key
- Hotline adapter name
- required normalized shape
- blocking gaps

3. Write proposal stubs only for repeated missing capabilities.

## Bottom Line

Hotline should be rebuilt as:
- domain-local
- offline-first
- Helper-first for UI
- Relay/HQ-aware for sync and topology

Do not preserve current Blade/JS implementation.
Preserve:
- user behavior
- operator workflow
- data semantics
- local operational model

Then express those through Helper contracts and Hotline-owned adapters.
