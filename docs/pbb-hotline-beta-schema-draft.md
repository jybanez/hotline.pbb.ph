# PBB Hotline Beta Schema Draft

Date: 2026-04-04

Status: Draft Phase 1 schema direction

References:
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [Alpha Database, Schema, and Models](./database-schema-models.md)

Purpose:
- translate the current Beta contracts into a database-oriented draft
- separate code constants from persisted tables
- give the new team a cleaner starting data design than Alpha

## 1. Design Rules

- keep stable vocabularies in code constants where already decided
- keep settings in a simple key-value table
- keep users generic with role field
- separate pre-incident `call_attempts` from incident-bound `call_sessions`
- preserve local-first persistence
- hard deletes are allowed only when not referenced

## 2. Code Constants, Not Tables

These should be code constants, not DB reference tables in Phase 1:
- roles
- alert levels
- incident statuses
- call statuses
- call outcomes
- operator runtime states
- team assignment statuses
- team assignment cancellation reason codes
- user statuses

Operator availability note:
- Phase 1 should derive operator runtime state in business logic
- Phase 1 should not add a dedicated persisted operator-state table unless later operational needs prove it necessary

Incident lifecycle note:
- Phase 1 should not persist a `New` incident status
- incident rows are created only when a call is answered
- the first persisted incident status should be `Active`

## 3. Core Tables

### `users`

Suggested columns:
- `id`
- `name`
- `avatar_path`
- `mobile`
- `email`
- `password`
- `role`
- `status`
- `last_login_at`
- `created_at`
- `updated_at`

### `settings`

Suggested columns:
- `id`
- `key`
- `value`
- `created_at`
- `updated_at`

Recommended unique index:
- unique on `key`

## 4. Incident Domain Tables

### `incidents`

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
- `location_road`
- `location_suburb`
- `location_barangay`
- `location_citymunicipality`
- `location_country`
- `other_details`
- `called_at`
- `resolved_at`
- `created_at`
- `updated_at`

Suggested foreign keys:
- `caller_id -> users.id`
- `operator_id -> users.id`

### `call_attempts`

Purpose:
- new-call attempts before incident creation

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
- `created_at`
- `updated_at`

### `call_attempt_operator_attempts`

Purpose:
- one row per operator tried under a call attempt

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

### `call_sessions`

Purpose:
- incident-bound calls
- includes reconnects as new rows

Suggested columns:
- `id`
- `incident_id`
- `caller_id`
- `status`
- `outcome`
- `started_at`
- `answered_at` nullable
- `ended_at` nullable
- `created_at`
- `updated_at`

Design note:
- do not keep session-level `operator_id` in Phase 1
- participant membership should live in a child table instead

### `call_participants`

Purpose:
- one row per user who joined one call session

Suggested columns:
- `id`
- `call_session_id`
- `user_id`
- `participant_role`
- `joined_at`
- `left_at` nullable
- `created_at`

Suggested indexes:
- `call_session_id`
- `user_id`
- `(call_session_id, user_id, joined_at)`

## 5. Messaging / Media Tables

### `incident_messages`

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

### `message_attachments`

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

### `media`

Purpose:
- merged post-call media files

Suggested columns:
- `id`
- `incident_id`
- `call_session_id`
- `type`
- `peer_user_id` nullable
- `peer_role` nullable
- `peer_label` nullable
- `path`
- `duration_seconds`
- `metadata_json`
- `created_at`
- `available_at` nullable

Media artifact note:
- audio artifacts should be participant-scoped, not role-collapsed
- for Phase 1, one completed call session may produce:
  - one `audio_peer` row per peer in that session
  - one `caller_video` row when caller video existed
- this keeps isolated replay possible during transfer overlap and future multi-peer sessions

## 6. Incident-Type Definition Tables

### `incident_categories`

Suggested columns:
- `id`
- `name`
- `description`
- `sort_order`
- `created_at`
- `updated_at`

### `incident_types`

Suggested columns:
- `id`
- `incident_category_id`
- `name`
- `description`
- `created_at`
- `updated_at`

### `incident_type_fields`

Suggested columns:
- `id`
- `incident_type_id`
- `field_key`
- `field_label`
- `input_type`
- `options_json` nullable
- `default_value` nullable
- `placeholder` nullable
- `unit` nullable
- `is_required`
- `sort_order`
- `min` nullable
- `max` nullable
- `step` nullable
- `created_at`
- `updated_at`

### `incident_type_default_resources`

Purpose:
- default required resources configured under incident type edit flow

Suggested columns:
- `id`
- `incident_type_id`
- `resource_type_id`
- `quantity_required`
- `notes` nullable
- `created_at`
- `updated_at`

## 7. Incident Detail Tables

### `incident_type_details`

Suggested columns:
- `id`
- `incident_id`
- `incident_type_id`
- `field_id`
- `field_label`
- `field_key`
- `field_value`
- `input_type`
- `options_json` nullable
- `unit` nullable
- `placeholder` nullable
- `is_required`
- `sort_order`
- `created_at`
- `updated_at`

### `incident_resources_needed`

Suggested columns:
- `id`
- `incident_id`
- `resource_type_id`
- `quantity_required`
- `notes` nullable
- `created_at`
- `updated_at`

## 8. Team Definition Tables

### `team_categories`

Suggested columns:
- `id`
- `name`
- `description`
- `sort_order`
- `created_at`
- `updated_at`

### `teams`

Suggested columns:
- `id`
- `team_category_id`
- `name`
- `status`
- `created_at`
- `updated_at`

### `resource_type_categories`

Suggested columns:
- `id`
- `name`
- `description`
- `sort_order`
- `created_at`
- `updated_at`

### `resource_types`

Suggested columns:
- `id`
- `category_id`
- `name`
- `unit_label`
- `created_at`
- `updated_at`

### `team_resource_inventories`

Suggested columns:
- `id`
- `team_id`
- `resource_type_id`
- `quantity_available`
- `created_at`
- `updated_at`

## 9. Team Assignment Tables

### `team_assignments`

Suggested columns:
- `id`
- `incident_id`
- `team_id`
- `assigned_by_operator_id`
- `status`
- `contact_person` nullable
- `cancelled_from_status` nullable
- `cancel_reason_code` nullable
- `cancelled_by_operator_id` nullable
- `assigned_at`
- `accepted_at` nullable
- `enroute_at` nullable
- `arrived_at` nullable
- `completed_at` nullable
- `cancelled_at` nullable
- `created_at`
- `updated_at`

Suggested unique constraint:
- unique on (`incident_id`, `team_id`)

### `team_assignment_allocated_resources`

Suggested columns:
- `id`
- `team_assignment_id`
- `resource_type_id`
- `quantity_allocated`
- `created_at`
- `updated_at`

## 10. Transfer / Activity Tables

### `incident_transfers`

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
- `created_at`
- `updated_at`

### `activity_logs`

Suggested columns:
- `id`
- `incident_id` nullable
- `actor_id` nullable
- `action_type`
- `message`
- `created_at`

## 11. Suggested Indexes

Recommended practical indexes:
- `users.email` unique
- `users.role`
- `users.status`
- `incidents.status`
- `incidents.operator_id`
- `incidents.caller_id`
- `incidents.created_at`
- `call_attempts.caller_id`
- `call_attempts.started_at`
- `call_sessions.incident_id`
- `call_sessions.started_at`
- `call_participants.call_session_id`
- `call_participants.user_id`
- `incident_messages.incident_id`
- `incident_messages.created_at`
- `media.incident_id`
- `media.call_session_id`
- `team_assignments.incident_id`
- `team_assignments.team_id`
- `activity_logs.actor_id`
- `activity_logs.incident_id`
- `activity_logs.created_at`

## 12. Migration Order Suggestion

Recommended migration order:
1. users
2. settings
3. incident categories / incident types / incident type fields
4. resource types
5. team categories / teams / team resource inventories
6. incidents
7. call_attempts / call_attempt_operator_attempts
8. call_sessions
9. incident_type_default_resources
10. incident_type_details / incident_resources_needed
11. incident_messages / message_attachments
12. media
13. team_assignments / team_assignment_allocated_resources
14. incident_transfers
15. activity_logs

## 13. Alpha Cleanup Notes To Consider

Avoid carrying these Alpha patterns forward as-is:
- nullable pseudo-FK references without real constraints where avoidable
- conflating pre-incident call attempts with incident-bound calls
- relying on implicit null timestamp meanings when an explicit outcome field is clearer

## 14. Phase Boundary Note

This schema draft intentionally does not formalize SITREP tables yet.

Reason:
- SITREP is now a later phase
- Phase 1 should stabilize local incident operations first
