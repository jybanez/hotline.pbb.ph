# PBB Hotline Beta App Layer Map

Date: 2026-04-04

Status: Draft class and responsibility map for Phase 1

References:
- [PBB Hotline Beta Repo Structure Proposal](./pbb-hotline-beta-repo-structure-proposal.md)
- [PBB Hotline Beta Schema Draft](./pbb-hotline-beta-schema-draft.md)
- [PBB Hotline Beta Realtime Spec](./pbb-hotline-beta-realtime-spec.md)

Purpose:
- help the Beta team translate the docs into initial Laravel classes
- keep responsibilities separated early
- prevent controllers from absorbing the domain

## 1. Layering Rule

Recommended Beta layering:

- controllers receive HTTP input and return responses
- form requests validate
- domain actions execute one workflow step
- services coordinate reusable multi-step logic
- queries assemble read models
- policies guard role and record access
- events/broadcasts notify connected clients

## 2. Suggested Core Models

Recommended first model set:

- `App\Domain\Users\Models\User`
- `App\Domain\Settings\Models\Setting`
- `App\Domain\Incidents\Models\Incident`
- `App\Domain\Calls\Models\CallAttempt`
- `App\Domain\Calls\Models\CallAttemptOperatorAttempt`
- `App\Domain\Calls\Models\CallSession`
- `App\Domain\Calls\Models\CallParticipant`
- `App\Domain\Messages\Models\IncidentMessage`
- `App\Domain\Messages\Models\MessageAttachment`
- `App\Domain\Media\Models\Media`
- `App\Domain\Incidents\Models\IncidentCategory`
- `App\Domain\Incidents\Models\IncidentType`
- `App\Domain\Incidents\Models\IncidentTypeField`
- `App\Domain\Incidents\Models\IncidentTypeDefaultResource`
- `App\Domain\Incidents\Models\IncidentTypeDetail`
- `App\Domain\Incidents\Models\IncidentResourceNeeded`
- `App\Domain\Teams\Models\TeamCategory`
- `App\Domain\Teams\Models\Team`
- `App\Domain\Teams\Models\ResourceType`
- `App\Domain\Teams\Models\TeamResourceInventory`
- `App\Domain\Teams\Models\TeamAssignment`
- `App\Domain\Teams\Models\TeamAssignmentAllocatedResource`
- `App\Domain\Incidents\Models\IncidentTransfer`
- `App\Domain\Incidents\Models\ActivityLog`

## 3. Suggested Constants / Enums

Keep these as code constants or enums:

- `UserRole`
- `UserStatus`
- `AlertLevel`
- `IncidentStatus`
- `CallStatus`
- `CallOutcome`
- `TeamAssignmentStatus`
- `TeamAssignmentCancellationReason`

Recommended namespace:

```text
app/Domain/*/Enums/
```

## 4. Suggested Query Classes

Use query classes for dashboard and bootstrap reads that are wider than one model.

Recommended first query set:

- `GetPublicHomeQuery`
- `GetBootstrapPayloadQuery`
- `GetCallerHomeQuery`
- `GetCallerAvailabilityQuery`
- `GetCallerCurrentIncidentQuery`
- `GetCallerIncidentHistoryQuery`
- `GetOperatorDashboardQuery`
- `GetOperatorWorkbenchQuery`
- `GetOperatorActivityLogQuery`
- `GetAdminSummaryQuery`
- `GetBlockedDeleteReferencesQuery`

Use these for:
- pre-shaped frontend payloads
- multi-table dashboard reads
- hiding query complexity from controllers

## 5. Suggested Action Classes

### Session / account actions

- `LoginUserAction`
- `LogoutUserAction`
- `ReauthenticateUserAction`
- `UpdateCurrentUserAccountAction`
- `UpdateCurrentUserPasswordAction`

### Caller / call lifecycle actions

- `StartCallAttemptAction`
- `CancelCallAttemptAction`
- `StartReconnectCallAction`
- `MarkCallAttemptAnsweredAction`
- `CreateIncidentFromAnsweredCallAction`
- `StartCallSessionAction`
- `EndCallSessionAction`

### Operator incident actions

- `AssignIncidentOperatorAction`
- `UpdateIncidentStatusAction`
- `ResolveIncidentAction`
- `DiscardIncidentAction`
- `DeferIncidentAction`
- `AddIncidentTypeDetailAction`
- `UpdateIncidentLocationAction`

### Transfer actions

- `RequestIncidentTransferAction`
- `AcceptIncidentTransferAction`
- `RejectIncidentTransferAction`
- `CompleteIncidentTransferAction`

### Team assignment actions

- `AssignTeamToIncidentAction`
- `UpdateTeamAssignmentStatusAction`
- `CancelTeamAssignmentAction`
- `SyncTeamAssignmentResourcesAction`

### Messaging / media actions

- `CreateIncidentMessageAction`
- `AttachMessageMediaAction`
- `RegisterMediaProcessingAction`
- `MarkMediaAvailableAction`

### Settings / admin actions

- `UpdateSettingAction`
- `CreateUserAction`
- `UpdateUserAction`
- `DeleteUserAction`
- `DeleteReferencedRecordAction` should not exist in Phase 1

## 6. Suggested Service Classes

Use services when the logic is cross-cutting or reused by several actions.

Recommended first service set:

- `AlertLevelService`
- `AvailabilityService`
- `CallRoutingService`
- `ReconnectEligibilityService`
- `IncidentWorkbenchService`
- `WorkbenchRestoreStateService`
- `SessionKeepaliveService`
- `TransferHandoffService`
- `DevicePrimerStatusService`
- `SettingsService`
- `ActivityLogService`
- `BlockedDeleteInspectorService`
- `MediaAssemblyService`

Suggested responsibilities:

- `CallRoutingService`
  - choose operators in runtime state `available` for new calls
  - record per-operator ring attempts
- `AvailabilityService`
  - derive canonical operator runtime state
  - expose routing eligibility from runtime state rather than ownership count
  - derive backend caller availability truth from call-service readiness and available-operator count
- `ReconnectEligibilityService`
  - enforce reconnect business rule
- `WorkbenchRestoreStateService`
  - manage retained client restore state for the operator overlay workbench
  - restore workbench context after refresh and re-auth
- `SessionKeepaliveService`
  - track last successful server touch
  - coordinate near-expiry keepalive attempts
  - fall back to re-auth when keepalive cannot preserve session
- `TransferHandoffService`
  - manage accepted-transfer overlap behavior
  - move old/new operators through `transferring` and `engaged` runtime states
  - switch incident ownership and reconnect target immediately on acceptance
  - enforce read-only old-operator behavior during overlap
- `BlockedDeleteInspectorService`
  - return detailed reference list for admin blocked deletes
- `MediaAssemblyService`
  - accept post-call merge completion and publish `available_at`
  - produce one final audio artifact per peer per call session
  - keep caller video as separate artifact when present

## 7. Suggested Policy Classes

Recommended first policies:

- `IncidentPolicy`
- `CallAttemptPolicy`
- `CallSessionPolicy`
- `TeamAssignmentPolicy`
- `IncidentTransferPolicy`
- `AdminUserPolicy`
- `SettingPolicy`

Key checks that should become policy methods:

- caller can only access own incidents
- operator can only access incidents they own or are currently receiving via accepted transfer flow
- admin-only access to admin modules
- wrong-role page access returns unauthorized state

## 8. Suggested Event / Broadcast Classes

Recommended first event set:

- `AlertLevelChanged`
- `SettingChanged`
- `IncomingCallOffered`
- `IncomingCallMissed`
- `CallAttemptCancelledByCaller`
- `CallSessionStarted`
- `CallSessionEnded`
- `IncidentUpdated`
- `IncidentStatusChanged`
- `IncidentTransferRequested`
- `IncidentTransferAccepted`
- `IncidentTransferRejected`
- `TeamAssignmentUpdated`
- `MessageCreated`
- `MediaProcessingStarted`
- `MediaAvailable`

Recommended broadcast usage:
- keep payloads compact and contract-shaped
- use Realtime room helpers instead of inline room strings everywhere

## 9. Suggested Realtime Classes

Recommended first Realtime layer:

- `CallerRealtimeAdmissionAction`
- `OperatorRealtimeAdmissionAction`
- `RoomNameFactory`
- `RealtimeCapabilityFactory`
- `RealtimeTokenFactory` if the chosen server-side admission flow needs one
- `ConferenceJoinService`
- `SignalingRelayService`

These classes should own:
- room naming
- room admission payloads
- capability grants
- call-session room join rules
- transfer overlap join behavior

## 10. Suggested HTTP Controller Map

### Public / session

- `PublicBootstrapController`
- `PublicAlertLevelController`
- `LoginController`
- `LogoutController`
- `ReauthController`
- `CurrentUserController`
- `CurrentUserPasswordController`

### Caller

- `CallerHomeController`
- `CallerCurrentIncidentController`
- `CallerIncidentHistoryController`
- `CallerCallAttemptController`
- `CallerReconnectController`

### Operator

- `OperatorDashboardController`
- `OperatorIncidentWorkbenchController`
- `OperatorIncidentStatusController`
- `OperatorTransferController`
- `OperatorTeamAssignmentController`

### Admin

- `AdminSummaryController`
- `AdminUserController`
- `AdminIncidentCategoryController`
- `AdminIncidentTypeController`
- `AdminIncidentTypeFieldController`
- `AdminTeamCategoryController`
- `AdminTeamController`
- `AdminResourceTypeController`
- `AdminSettingController`

### Realtime

- `CallerRealtimeAdmissionController`
- `OperatorRealtimeAdmissionController`

## 11. Suggested Frontend Module Map

Recommended frontend feature groups:

- `features/public/`
  - public home
  - public alert card
  - login modal launcher
- `features/caller/`
  - caller home
  - calling state
  - caller incident view
  - caller live call view
- `features/operator/`
  - operator dashboard
  - incoming-call modal
  - workbench overlay
  - transfer modal
  - map integration
- `features/admin/`
  - admin landing
  - module pages
  - settings property editor
- `features/shared/`
  - navbar wrapper
  - user menu
  - session re-auth
  - device primer launcher/status
  - alert-level live updates

## 12. Recommended Minimum Kickoff Slice

If the Beta team wants the smallest useful first slice, build in this order:

1. session/login/bootstrap/account/re-auth
2. public home alert card
3. caller home with Device Primer and blocked-call logic
4. operator dashboard shell with Device Primer and incoming-call modal
5. call attempt -> answered incident creation path
6. workbench overlay skeleton
7. admin landing + users/settings modules

## 13. Bottom Line

The Beta team does not need a deep framework inside Laravel.

They do need a clear split between:
- HTTP delivery
- domain workflows
- shared support/runtime concerns
- Realtime transport responsibilities

This class map is enough to start implementation without falling back into controller-heavy Alpha patterns.
