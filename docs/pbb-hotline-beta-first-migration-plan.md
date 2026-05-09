# PBB Hotline Beta First Migration Plan

Date: 2026-04-04

Status: Draft migration sequencing plan for Phase 1

References:
- [PBB Hotline Beta Schema Draft](./pbb-hotline-beta-schema-draft.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta API Inventory](./pbb-hotline-beta-api-inventory.md)

Purpose:
- turn the schema draft into a practical migration order
- reduce kickoff ambiguity for the Beta team
- highlight which tables should land first and why

## 1. Design Rules

- create foundational tables first
- use foreign keys aggressively in Phase 1
- prefer `restrictOnDelete()` for operational references
- keep constant vocabularies out of the schema
- add indexes for obvious dashboard and workbench reads early

## 2. Migration Batches

Recommended migration batches:

1. users and settings
2. incident/team/resource definition tables
3. incident core and call lifecycle tables
4. messaging and media tables
5. dispatch, transfers, and activity tables
6. optional cleanup/index follow-up migrations

## 3. Batch 1: Auth And Runtime Settings

### 3.1 `users`

Suggested early columns:
- `id`
- `name`
- `avatar_path`
- `mobile`
- `email`
- `password`
- `role`
- `status`
- `last_login_at`
- timestamps

Suggested indexes:
- unique `email`
- index `role`
- index `status`

Reason:
- everything else depends on user identity

### 3.2 `settings`

Suggested columns:
- `id`
- `key`
- `value`
- timestamps

Suggested indexes:
- unique `key`

Reason:
- alert level and runtime settings are needed early for bootstrap and dashboard behavior

## 4. Batch 2: Definition Tables

### 4.1 Incident definitions

Create in this order:

1. `incident_categories`
2. `incident_types`
3. `incident_type_fields`
4. `incident_type_default_resources`

Suggested indexes:
- `incident_types.incident_category_id`
- `incident_type_fields.incident_type_id`
- unique `(incident_type_id, field_key)`

### 4.2 Team and resource definitions

Create in this order:

1. `team_categories`
2. `resource_types`
3. `teams`
4. `team_resource_inventories`

Suggested indexes:
- `teams.team_category_id`
- `team_resource_inventories.team_id`
- `team_resource_inventories.resource_type_id`

Reason:
- operator workbench and admin setup need these definitions before incidents can be fully classified and dispatched

## 5. Batch 3: Incident Core And Call Lifecycle

### 5.1 `incidents`

Suggested columns:
- `id`
- `caller_id`
- `actual_caller_name`
- `actual_caller_relationship`
- `operator_id`
- `status`
- `alert_level`
- `latitude`
- `longitude`
- `location`
- location breakdown columns
- `other_details`
- `called_at`
- `resolved_at`
- timestamps

Suggested indexes:
- `caller_id`
- `operator_id`
- `status`
- `(status, created_at)`
- `(operator_id, status)`
- spatial strategy can be deferred if Phase 1 uses simple lat/lng filtering

### 5.2 `call_attempts`

Suggested columns:
- `id`
- `caller_id`
- `incident_id` nullable
- `answered_by_operator_id` nullable
- `status`
- `outcome`
- `caller_latitude` nullable
- `caller_longitude` nullable
- `started_at`
- `ended_at` nullable
- timestamps

Suggested indexes:
- `caller_id`
- `incident_id`
- `answered_by_operator_id`
- `(caller_id, created_at)`

### 5.3 `call_attempt_operator_attempts`

Suggested columns:
- `id`
- `call_attempt_id`
- `operator_id`
- `status`
- `outcome`
- `started_at`
- `answered_at` nullable
- `ended_at` nullable
- `created_at`

Suggested indexes:
- `call_attempt_id`
- `operator_id`
- `(call_attempt_id, created_at)`

### 5.4 `call_sessions`

Suggested columns:
- `id`
- `incident_id`
- `caller_id`
- `operator_id`
- `status`
- `outcome`
- `started_at`
- `answered_at` nullable
- `ended_at` nullable
- timestamps

Suggested indexes:
- `incident_id`
- `caller_id`
- `operator_id`
- `(incident_id, started_at)`

Reason:
- these tables define the core Phase 1 operating flow

## 6. Batch 4: Messaging And Media

### 6.1 `incident_messages`

Suggested columns:
- `id`
- `incident_id`
- `sender_id`
- `sender_role`
- `sender_name`
- `sender_avatar`
- `body`
- `type`
- `created_at`

Suggested indexes:
- `incident_id`
- `sender_id`
- `(incident_id, created_at)`

### 6.2 `message_attachments`

Suggested columns:
- `id`
- `message_id`
- `type`
- `mime_type`
- `original_filename`
- `stored_path`
- `file_size`
- `thumbnail_path` nullable
- `uploaded_by`
- `created_at`

Suggested indexes:
- `message_id`
- `uploaded_by`

### 6.3 `media`

Suggested columns:
- `id`
- `incident_id`
- `call_session_id`
- `type`
- `path`
- `duration_seconds`
- `metadata_json`
- `created_at`
- `available_at` nullable

Suggested indexes:
- `incident_id`
- `call_session_id`
- `(incident_id, created_at)`
- `(incident_id, available_at)`

Reason:
- message and media review are part of Phase 1 workbench behavior

## 7. Batch 5: Dispatch, Transfers, And Activity

### 7.1 `incident_type_details`

Suggested columns:
- `id`
- `incident_id`
- `incident_type_id`
- `field_id`
- copied field metadata columns
- `field_value`
- timestamps

Suggested indexes:
- `incident_id`
- `incident_type_id`
- `field_id`

### 7.2 `incident_resources_needed`

Suggested columns:
- `id`
- `incident_id`
- `resource_type_id`
- `quantity_needed`
- `notes`
- timestamps

Suggested indexes:
- `incident_id`
- `resource_type_id`

### 7.3 `team_assignments`

Suggested columns:
- `id`
- `incident_id`
- `team_id`
- `assigned_by_operator_id`
- `status`
- `contact_person`
- `cancelled_from_status` nullable
- `cancel_reason_code` nullable
- `cancelled_by_operator_id` nullable
- `assigned_at`
- `accepted_at` nullable
- `enroute_at` nullable
- `arrived_at` nullable
- `completed_at` nullable
- `cancelled_at` nullable
- timestamps

Suggested indexes:
- `incident_id`
- `team_id`
- `assigned_by_operator_id`
- `status`
- unique `(incident_id, team_id)`

### 7.4 `team_assignment_allocated_resources`

Suggested columns:
- `id`
- `team_assignment_id`
- `resource_type_id`
- `resource_name`
- `carry`
- `allocated`
- timestamps

Suggested indexes:
- `team_assignment_id`
- `resource_type_id`

### 7.5 `incident_transfers`

Suggested columns:
- `id`
- `incident_id`
- `from_operator_id`
- `to_operator_id`
- `reason`
- `status`
- `requested_at`
- `accepted_at` nullable
- `rejected_at` nullable
- `cancelled_at` nullable
- `completed_at` nullable
- timestamps

Suggested indexes:
- `incident_id`
- `from_operator_id`
- `to_operator_id`
- `status`

### 7.6 `activity_logs`

Suggested columns:
- `id`
- `incident_id` nullable
- `action_type`
- `message`
- `actor_id` nullable
- `actor_role` nullable
- `created_at`

Suggested indexes:
- `incident_id`
- `actor_id`
- `action_type`
- `(actor_id, created_at)`

Reason:
- these tables complete the operator workbench lifecycle and operator-specific activity view

## 8. Foreign Key Strategy

Recommended delete behavior:

- operational references should default to `restrictOnDelete()`
- child-only join tables may use `cascadeOnDelete()` where safe

Good candidates for `cascadeOnDelete()`:
- `call_attempt_operator_attempts -> call_attempts`
- `message_attachments -> incident_messages`
- `team_assignment_allocated_resources -> team_assignments`

Good candidates for `restrictOnDelete()`:
- `incidents -> users`
- `call_sessions -> incidents`
- `team_assignments -> incidents`
- `incident_transfers -> incidents`
- `teams -> team_categories`
- `team_resource_inventories -> teams`

## 9. Seeder Guidance

Phase 1 seeders should focus on:
- admin bootstrap user
- sample operator user
- sample caller user
- settings defaults
- incident categories/types/fields
- team categories
- resource types
- teams with basic inventories

Do not seed:
- incidents
- call attempts
- call sessions
- messages
- transfers

Those should come from runtime behavior or targeted test fixtures.

## 10. Recommended First Migration File Set

Illustrative file sequence:

1. `create_users_table`
2. `create_settings_table`
3. `create_incident_categories_table`
4. `create_incident_types_table`
5. `create_incident_type_fields_table`
6. `create_team_categories_table`
7. `create_resource_types_table`
8. `create_teams_table`
9. `create_team_resource_inventories_table`
10. `create_incidents_table`
11. `create_call_attempts_table`
12. `create_call_attempt_operator_attempts_table`
13. `create_call_sessions_table`
14. `create_incident_messages_table`
15. `create_message_attachments_table`
16. `create_media_table`
17. `create_incident_type_default_resources_table`
18. `create_incident_type_details_table`
19. `create_incident_resources_needed_table`
20. `create_team_assignments_table`
21. `create_team_assignment_allocated_resources_table`
22. `create_incident_transfers_table`
23. `create_activity_logs_table`

The exact numbering can shift, but the dependency order should stay close to this.

## 11. Bottom Line

The Beta team should not try to migrate every possible later-phase idea immediately.

A good Phase 1 migration set should:
- establish auth/settings first
- establish master definitions second
- establish incidents/calls third
- add messaging/media next
- finish with dispatch, transfer, and activity support

That order is stable enough to start coding.
