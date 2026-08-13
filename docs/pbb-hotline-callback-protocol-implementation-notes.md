# PBB Hotline Callback Protocol Implementation Notes

Implemented branch: `callback/incident-only-protocol`

V1 keeps callbacks strictly incident-bound:

- callback cases reference `incident_id`, `citizen_id`, and `operator_id`;
- no fallback drop fields, `subject_type`, or `subject_id` are used;
- every mutating operator endpoint revalidates the incident's current assigned operator;
- callback responsibility follows incident reassignment because action authority is derived from `incidents.operator_id`.

Operator-created callbacks are implemented in the Operator workbench for `Active` and `Deferred` incidents. Automatic callback creation from dropped live sessions is intentionally not enabled in this branch, so normal call completion and disconnect handling do not create callback cases unless future policy explicitly enables that behavior.

Callback call initiation reuses Hotline's current reconnect-style operator call-attempt path through `CallRoutingService::startReconnectAttempt()`. That creates the ringing `call_attempt` / `call_attempt_operator_attempt` records and the Operator surface publishes the existing `citizen.reconnect.ringing` realtime event. The existing call-session/media flow begins when the citizen/operator answer flow proceeds through the current reconnect handling.

Callback policy defaults are DB-backed runtime settings:

- `callback_first_sla_seconds`: `30`
- `callback_max_additional_attempts`: `2`
- `callback_retry_window_minutes`: `5`
- `callback_overdue_warning_seconds`: `15`

`SettingsSeeder` now inserts missing defaults without overwriting existing runtime settings.
