# Hotline Database, Schema, and Models

Date: 2026-04-03

Source basis:
- live MySQL schema `hotline`
- 39 Laravel migrations
- 21 first-party Eloquent models

## Overview

The project uses one primary MySQL schema: `hotline`.

The schema mixes:
- Laravel infrastructure tables
- authentication/session/token tables
- hotline domain tables for incidents, calls, media, teams, and activity logs

Current live table count inside `hotline`: 30

## Entity Map

Primary domain flow:
1. A `users` row with role `user` starts a `call_sessions` record.
2. An `operator` answers and an `incidents` record is created.
3. The incident is classified through `incident_incident_type`.
4. Additional structured details land in `incident_type_details`.
5. Needed resources land in `incident_resources_needed`.
6. Media and chat land in `media` and `incident_messages`.
7. Teams are dispatched through `team_assignments`.
8. Dispatch notes live in `team_assignment_notes`.
9. Transfers between operators live in `incident_transfers`.
10. Operator and system actions are recorded in `activity_logs`.

## Table Inventory

### Core domain tables

#### `users`
Purpose: all authenticated actors.

Columns:
- `id` PK
- `name`, `email`, `password`
- `email_verified_at`, `remember_token`
- `role` default `user`
- `operator_level`
- `is_available`
- `current_call_session_id`
- `current_incident_id`
- `last_seen_at`
- `avatar_path`, `phone`, `address`
- timestamps

Indexes:
- unique: `email`
- indexes: `role`, `operator_level`, `is_available`, `current_call_session_id`, `current_incident_id`, `last_seen_at`

Notes:
- `current_call_session_id` and `current_incident_id` are not foreign-key constrained.

#### `incidents`
Purpose: top-level incident record.

Columns:
- `id` PK
- `caller_id` FK -> `users.id`
- `operator_id` FK -> `users.id`, nullable
- `status`
- `alert_level`
- `actual_caller_name`, `actual_caller_relationship`
- `called_at`
- `location`
- `latitude`, `longitude`
- `details`
- `escalated_to_level`
- `resolved_at`
- address components:
  - `location_road`
  - `location_suburb`
  - `location_neighbourhood`
  - `location_city`
  - `location_municipality`
  - `location_state`
  - `location_postcode`
  - `location_country`
- timestamps

Indexes:
- `status`
- `called_at`
- foreign-key indexes on `caller_id`, `operator_id`

Typical status values seen in code:
- `Active`
- `Deferred`
- `Escalated`
- `Resolved`
- `Discarded`
- `Disconnected`

#### `call_sessions`
Purpose: telephony / live call session records.

Columns:
- `id` PK
- `caller_id` FK -> `users.id`
- `operator_id` FK -> `users.id`, nullable
- `incident_id` FK -> `incidents.id`, nullable
- `status`
- `forwarded_from_operator_id` nullable, indexed
- `metadata` JSON
- `started_at`, `answered_at`, `ended_at`
- timestamps

Indexes:
- `status`
- `started_at`
- `answered_at`
- `ended_at`
- `forwarded_from_operator_id`

Typical status values seen in code:
- `ringing`
- `in_progress`
- `ended`

#### `media`
Purpose: incident-linked photos, audio, and videos.

Columns:
- `id` PK
- `incident_id` FK -> `incidents.id`
- `uploaded_by_user_id` FK -> `users.id`, nullable
- `type`
- `path`
- `duration_seconds`
- `metadata` JSON
- timestamps

Indexes:
- `type`
- FKs on `incident_id`, `uploaded_by_user_id`

Observed media types:
- `photo`
- `audio`
- `video`

#### `incident_messages`
Purpose: incident chat and photo message stream.

Columns:
- `id` PK
- `incident_id` FK -> `incidents.id`
- `sender_id` FK -> `users.id`
- `sender_role`
- `type` default `text`
- `body`
- timestamps

Observed message types:
- `text`
- `photo`

#### `incident_transfers`
Purpose: operator-to-operator handoff requests.

Columns:
- `id` PK
- `incident_id` FK -> `incidents.id`
- `from_operator_id` FK -> `users.id`, nullable
- `to_operator_id` FK -> `users.id`
- `reason`
- `status` default `pending`
- `expires_at`, `responded_at`
- timestamps

Observed status values:
- `pending`
- `accepted`
- `declined`
- `cancelled`
- `expired`

#### `activity_logs`
Purpose: timeline/audit log for incidents and calls.

Columns:
- `id` PK
- `incident_id` FK -> `incidents.id`, nullable
- `call_session_id` FK -> `call_sessions.id`, nullable
- `actor_user_id` FK -> `users.id`, nullable
- `actor_role`
- `action`
- `description`
- `metadata` JSON
- `visibility` default `operator`
- timestamps

Indexes:
- `(incident_id, created_at)`
- `(call_session_id, created_at)`
- `(action, created_at)`

### Incident taxonomy and structured-detail tables

#### `incident_categories`
Purpose: top-level incident grouping.

Columns:
- `id` PK
- `name` unique
- `sort_order`
- `is_active`
- timestamps

#### `incident_types`
Purpose: specific incident types, optionally grouped by category.

Columns:
- `id` PK
- `category_id` FK -> `incident_categories.id`, nullable
- `name` unique
- `sort_order`
- `is_active`
- timestamps

#### `incident_incident_type`
Purpose: many-to-many incident <-> incident type pivot.

Columns:
- `id` PK
- `incident_id` FK -> `incidents.id`
- `incident_type_id` FK -> `incident_types.id`
- timestamps

Constraints:
- unique `(incident_id, incident_type_id)`

#### `incident_type_fields`
Purpose: field definitions for structured incident detail entry.

Columns:
- `id` PK
- `incident_type_id` FK -> `incident_types.id`
- `field_key`
- `field_label`
- `input_type`
- `options` JSON
- `placeholder`
- `help_text`
- `default_value`
- `min`, `max`, `step`
- `unit`
- `sort_order`
- `is_required`
- timestamps

Typical `input_type` values from seeders:
- `text`
- `textarea`
- `number`
- `select`

#### `incident_type_details`
Purpose: stored values entered for an incident/type field.

Columns:
- `id` PK
- `incident_id` FK -> `incidents.id`
- `incident_type_id` FK -> `incident_types.id`, nullable
- `field_key`
- `field_label`
- `field_value`
- `unit`
- `severity`
- `notes`
- timestamps

#### `resource_type_categories`
Purpose: managed category taxonomy for resource types.

Columns:
- `id` PK
- `name` unique
- `description`
- `sort_order`
- timestamps

#### `resource_types`
Purpose: catalog of dispatchable resource kinds.

Columns:
- `id` PK
- `category_id` FK -> `resource_type_categories.id`
- `name`
- `unit_label`
- timestamps

#### `incident_type_resource_defaults`
Purpose: default resource requirements per incident type.

Columns:
- `id` PK
- `incident_type_id` FK -> `incident_types.id`
- `resource_type_id` FK -> `resource_types.id`
- `quantity_needed`
- `notes`
- `sort_order`
- timestamps

#### `incident_resources_needed`
Purpose: actual resource demand attached to an incident.

Columns:
- `id` PK
- `incident_id` FK -> `incidents.id`
- `incident_type_id` FK -> `incident_types.id`, nullable
- `resource_type_id` FK -> `resource_types.id`
- `quantity_needed`
- `quantity_available`
- `notes`
- timestamps

### Team dispatch tables

#### `team_categories`
Purpose: team grouping for dispatch resources.

Columns:
- `id` PK
- `name`
- `sort_order`
- `is_active`
- timestamps

#### `teams`
Purpose: deployable field teams.

Columns:
- `id` PK
- `team_category_id` FK -> `team_categories.id`, nullable
- `name`
- `status` default `available`
- `is_active`
- `base_location`
- `latitude`, `longitude`
- `last_seen_at`
- timestamps

#### `team_resource_inventories`
Purpose: per-team resource capacity.

Columns:
- `id` PK
- `team_id` FK -> `teams.id`
- `resource_type_id` FK -> `resource_types.id`
- `quantity_available`
- `notes`
- `sort_order`
- `is_active`
- timestamps

Constraints:
- unique `(team_id, resource_type_id)`

#### `team_assignments`
Purpose: incident dispatch assignment to a team.

Columns:
- `id` PK
- `incident_id` FK -> `incidents.id`
- `team_id` FK -> `teams.id`
- `assigned_by_operator_id` FK -> `users.id`, nullable
- `contact_person`
- `status` default `requested`
- `cancelled_from_status`
- `cancel_reason_code`
- `cancel_reason_note`
- `notes`
- `allocated_resources` JSON
- `assigned_at`, `accepted_at`, `arrived_at`, `completed_at`, `cancelled_at`
- timestamps
- `cancelled_by_operator_id` FK -> `users.id`, nullable

Indexes:
- `(incident_id, status)`
- `(team_id, status)`

Observed status values:
- `requested`
- `assigned`
- `accepted`
- `en_route`
- `on_scene`
- `completed`
- `cancelled`

#### `team_assignment_notes`
Purpose: chronological notes on a dispatch assignment.

Columns:
- `id` PK
- `team_assignment_id` FK -> `team_assignments.id`
- `author_operator_id` FK -> `users.id`, nullable
- `note`
- timestamps

### Geocoding and configuration tables

#### `geocode_caches`
Purpose: reverse-geocode cache keyed by rounded coordinates.

Columns:
- `id` PK
- `lat`, `lng`
- `name`
- `road`, `suburb`, `neighbourhood`, `city`, `municipality`, `state`, `postcode`, `country`
- timestamps

Constraint:
- unique `(lat, lng)`

#### `settings`
Purpose: operational configuration and reference values.

Columns:
- `id` PK
- `key` unique
- `value` JSON nullable
- `description`
- timestamps

Keys seen in code and seeders:
- `call_hold_seconds`
- `call_timeout_seconds`
- `ringtone_path`
- `transfer_timeout_seconds`
- `reconnect_timeout_seconds`
- `reconnect_attempt_seconds`
- `hangup_retry_max`
- `audio_graph_type`
- `voice_gender`
- `system_alert_level`
- `escalation_rules`
- `caller_relationships`
- `instance_id`
- `hub_address`
- `hub_lat`
- `hub_lon`

### Auth / framework infrastructure tables

#### `password_reset_tokens`
- password reset storage keyed by email

#### `sessions`
- database-backed web sessions

#### `personal_access_tokens`
- Sanctum API tokens

#### `cache`
- database cache store

#### `cache_locks`
- database cache lock store

#### `jobs`
- queued jobs

#### `job_batches`
- queued batch metadata

#### `failed_jobs`
- failed queue jobs

#### `migrations`
- migration ledger

## Foreign-Key Summary

Major relationships:
- `incidents.caller_id` -> `users.id`
- `incidents.operator_id` -> `users.id`
- `call_sessions.caller_id` -> `users.id`
- `call_sessions.operator_id` -> `users.id` (`Alpha` shape only; superseded in Beta planning by `call_participants`)
- `call_sessions.incident_id` -> `incidents.id`
- `media.incident_id` -> `incidents.id`
- `media.uploaded_by_user_id` -> `users.id`
- `incident_messages.incident_id` -> `incidents.id`
- `incident_messages.sender_id` -> `users.id`
- `incident_incident_type.incident_id` -> `incidents.id`
- `incident_incident_type.incident_type_id` -> `incident_types.id`
- `incident_type_fields.incident_type_id` -> `incident_types.id`
- `incident_type_details.incident_id` -> `incidents.id`
- `incident_type_details.incident_type_id` -> `incident_types.id`
- `incident_resources_needed.incident_id` -> `incidents.id`
- `incident_resources_needed.incident_type_id` -> `incident_types.id`
- `resource_types.category_id` -> `resource_type_categories.id`
- `incident_resources_needed.resource_type_id` -> `resource_types.id`
- `incident_type_resource_defaults.incident_type_id` -> `incident_types.id`
- `incident_type_resource_defaults.resource_type_id` -> `resource_types.id`
- `teams.team_category_id` -> `team_categories.id`
- `team_assignments.incident_id` -> `incidents.id`
- `team_assignments.team_id` -> `teams.id`
- `team_assignments.assigned_by_operator_id` -> `users.id`
- `team_assignments.cancelled_by_operator_id` -> `users.id`
- `team_assignment_notes.team_assignment_id` -> `team_assignments.id`
- `team_assignment_notes.author_operator_id` -> `users.id`
- `team_resource_inventories.team_id` -> `teams.id`
- `team_resource_inventories.resource_type_id` -> `resource_types.id`
- `incident_transfers.incident_id` -> `incidents.id`
- `incident_transfers.from_operator_id` -> `users.id`
- `incident_transfers.to_operator_id` -> `users.id`
- `activity_logs.incident_id` -> `incidents.id`
- `activity_logs.call_session_id` -> `call_sessions.id`
- `activity_logs.actor_user_id` -> `users.id`

## Model Reference

### `App\Models\User`
Table: `users`

Fillable:
- `name`, `email`, `password`
- `role`, `operator_level`, `is_available`
- `current_call_session_id`, `current_incident_id`
- `last_seen_at`, `avatar_path`, `phone`, `address`

Casts:
- `email_verified_at` datetime
- `password` hashed
- `is_available` boolean
- `last_seen_at` datetime

Relations:
- `incidentsAsCaller()`
- `incidentsAsOperator()`
- `callSessionsAsCaller()`
- `callSessionsAsOperator()`

### `App\Models\Incident`
Table: `incidents`

Fillable:
- caller/operator/status fields
- actual caller metadata
- location and address components
- `details`
- `escalated_to_level`
- `alert_level`
- `resolved_at`

Casts:
- `called_at`, `resolved_at` datetime

Relations:
- `caller()`, `operator()`
- `incidentTypes()` many-to-many
- `media()`
- `messages()`
- `detailEntries()`
- `resourcesNeeded()`
- `teamAssignments()`
- `activityLogs()`

### `App\Models\CallSession`
Table: `call_sessions`

Fillable:
- `caller_id`, `operator_id`, `incident_id`
- `status`, `forwarded_from_operator_id`
- `metadata`
- `started_at`, `answered_at`, `ended_at`

Casts:
- datetimes for call timestamps
- `metadata` array

Relations:
- `caller()`
- `operator()`
- `incident()`

### `App\Models\Media`
Table: `media`

Fillable:
- `incident_id`
- `uploaded_by_user_id`
- `type`, `path`
- `duration_seconds`
- `metadata`

Casts:
- `metadata` array

Relations:
- `incident()`
- `uploadedBy()`

### `App\Models\IncidentMessage`
Table: `incident_messages`

Fillable:
- `incident_id`
- `sender_id`
- `sender_role`
- `type`
- `body`

Relations:
- `incident()`
- `sender()`

### `App\Models\IncidentTransfer`
Table: `incident_transfers`

Fillable:
- `incident_id`
- `from_operator_id`
- `to_operator_id`
- `reason`
- `status`
- `expires_at`, `responded_at`

Casts:
- `expires_at`, `responded_at` datetime

Relations:
- `incident()`
- `fromOperator()`
- `toOperator()`

### `App\Models\IncidentCategory`
Table: `incident_categories`

Fillable:
- `name`, `sort_order`, `is_active`

Casts:
- `is_active` boolean

Relations:
- `incidentTypes()`

### `App\Models\IncidentType`
Table: `incident_types`

Fillable:
- `category_id`, `name`, `sort_order`, `is_active`

Casts:
- `is_active` boolean

Relations:
- `incidents()` many-to-many
- `category()`
- `fields()`
- `resourceDefaults()`

### `App\Models\IncidentTypeField`
Table: `incident_type_fields`

Fillable:
- `incident_type_id`
- field definition columns including input metadata

Casts:
- `is_required` boolean
- `options` array

Relations:
- `incidentType()`

### `App\Models\IncidentTypeDetail`
Table: `incident_type_details`

Fillable:
- `incident_id`
- `incident_type_id`
- `field_key`, `field_label`, `field_value`
- `unit`, `severity`, `notes`

Relations:
- `incident()`
- `incidentType()`

### `App\Models\ResourceTypeCategory`
Table: `resource_type_categories`

Fillable:
- `name`, `description`, `sort_order`

Relations:
- `resourceTypes()`

### `App\Models\ResourceType`
Table: `resource_types`

Fillable:
- `category_id`, `name`, `unit_label`

Relations:
- `category()`

### `App\Models\IncidentTypeResourceDefault`
Table: `incident_type_resource_defaults`

Fillable:
- `incident_type_id`
- `resource_type_id`
- `quantity_needed`
- `notes`
- `sort_order`

Relations:
- `incidentType()`
- `resourceType()`

### `App\Models\IncidentResourceNeeded`
Table: `incident_resources_needed`

Fillable:
- `incident_id`
- `incident_type_id`
- `resource_type_id`
- `quantity_needed`, `quantity_available`
- `notes`

Relations:
- `incident()`
- `incidentType()`
- `resourceType()`

### `App\Models\TeamCategory`
Table: `team_categories`

Fillable:
- `name`, `sort_order`, `is_active`

Casts:
- `is_active` boolean

Relations:
- `teams()`

### `App\Models\Team`
Table: `teams`

Fillable:
- `team_category_id`
- `name`, `status`, `is_active`
- `base_location`
- `latitude`, `longitude`
- `last_seen_at`

Casts:
- `is_active` boolean
- `last_seen_at` datetime

Relations:
- `category()`
- `assignments()`
- `resourceInventories()`

### `App\Models\TeamAssignment`
Table: `team_assignments`

Fillable:
- `incident_id`, `team_id`
- `assigned_by_operator_id`
- `contact_person`
- cancellation fields
- `notes`
- `allocated_resources`
- state timestamps

Casts:
- assignment timestamps as datetime
- `allocated_resources` array

Relations:
- `incident()`
- `team()`
- `assignedBy()`
- `notesLog()`

### `App\Models\TeamAssignmentNote`
Table: `team_assignment_notes`

Fillable:
- `team_assignment_id`
- `author_operator_id`
- `note`

Relations:
- `assignment()`
- `author()`

### `App\Models\TeamResourceInventory`
Table: `team_resource_inventories`

Fillable:
- `team_id`
- `resource_type_id`
- `quantity_available`
- `notes`
- `sort_order`
- `is_active`

Casts:
- integer casts for quantity/sort
- `is_active` boolean

Relations:
- `team()`
- `resourceType()`

### `App\Models\Setting`
Table: `settings`

Fillable:
- `key`, `value`, `description`

Casts:
- `value` array

### `App\Models\GeocodeCache`
Table: `geocode_caches`

Fillable:
- `lat`, `lng`, `name`
- all address component columns

### `App\Models\ActivityLog`
Table: `activity_logs`

Fillable:
- `incident_id`, `call_session_id`
- `actor_user_id`, `actor_role`
- `action`, `description`
- `metadata`
- `visibility`

Casts:
- `metadata` array

Relations:
- `incident()`
- `callSession()`
- `actor()`

## Seeded Reference Data

Seeders define initial domain vocabulary:
- incident categories
- incident types
- structured detail fields per incident type
- resource types
- default resource demand per incident type
- team categories
- initial users by role
- baseline operational settings

This means the database is not only transactional. It also stores app configuration and reference catalogs that the UI depends on.

## Important Design Notes

### 1. Incident typing is many-to-many
This was migrated away from a single `incident_type_id` on `incidents` to the pivot table `incident_incident_type`.

### 2. Structured details are definition-driven
`incident_type_fields` defines the form shape.
`incident_type_details` stores the entered values.

### 3. Resource planning has two layers
- defaults by incident type: `incident_type_resource_defaults`
- actual incident demand: `incident_resources_needed`

### 4. Team dispatch supports operational timeline tracking
`team_assignments` captures status progression and resource allocation.
`team_assignment_notes` adds commentary/history.

### 5. Settings are operationally critical
The public and operator flows read settings directly at runtime. Missing settings can affect route behavior, UI bootstrap, and incident escalation logic.

## Suggested Maintenance Improvements

Schema-level improvements worth considering:
- add foreign keys for `users.current_call_session_id` and `users.current_incident_id`
- add foreign key for `call_sessions.forwarded_from_operator_id`
- define enum-like constants centrally for status strings now repeated in controllers
- consider a dedicated settings service to reduce direct controller coupling to raw DB reads
