# PBB Hotline SITREP Public Page Proposal

## Purpose

This proposal defines the first structured public page for a periodic Hotline SITREP before Relay handoff is implemented.

The goal is to create a stable, readable reporting product that can later become the source for:
- internal command review
- public/status sharing
- Relay payload generation
- downstream reporting summaries
- upstream summary rollups and lazy drill-down access

This proposal intentionally focuses on page structure and data shape. It does not require Relay integration yet.

## Review Notes

This document treats SITREP as a reporting product, not a live dashboard.

Design principles:
- incident report is the primary reporting unit
- calls are communication/workload records
- incident types are classification dimensions
- incident type fields are contextual facts
- resources needed are explicit quantified needs
- team assignments are operational response records
- public output must be snapshot-based, privacy-aware, and stable after publication
- upstream reporting should push compact summaries first and fetch deeper detail only when needed

## Upstream Rollup Architecture

SITREP exchange between hubs should use progressive rollups instead of sending the entire barangay-to-national detail tree in one transaction.

Recommended hierarchy:

```text
Barangay Hotline -> City/Municipality Hub -> Province Hub -> Region Hub -> National Hub
```

Each level should publish a compact rollup for the next level while preserving enough provenance to support authorized drill-down.

Recommended payload tiers:

| Tier | Contents | Movement |
| --- | --- | --- |
| Operational summary | Totals, alert posture, affected areas, priority needs, critical gaps, freshness, data-quality notes | Always pushed upward through Relay |
| Breakdown index | Source hub IDs, area codes, reporting window, section hashes, source counts, drill-down API references | Pushed upward with the summary |
| Full detail | Source SITREP snapshots, incident references, attachments/media references, audit trail | Fetched directly or through Relay-assisted APIs on demand |

This keeps national and regional views fast during large incidents while still allowing deeper investigation when connectivity and authorization allow it.

Important rules:
- a higher-level hub should be able to make command decisions from the summary without waiting for full lower-level detail
- drill-down should degrade gracefully when a lower hub is offline or the link is unstable
- full detail and media should not be embedded in routine upstream rollups
- every aggregate number should preserve provenance back to the contributing hub and reporting period
- summaries should include stale, missing, or partial-report indicators so users can tell the difference between "zero reported" and "not yet received"

Example flow:

```text
Barangay sends local SITREP summary + source snapshot reference.
City consolidates barangay summaries and sends city rollup + barangay breakdown index.
Province consolidates city/municipality rollups and sends province rollup + city breakdown index.
Region consolidates province rollups and sends regional rollup + province breakdown index.
National consumes regional rollups and pulls deeper detail only when needed.
```

## Aggregation SDK Boundary

The future SITREP aggregation SDK should consume SITREP summaries and breakdown indexes from any reporting level, not only barangays.

The SDK should own:
- schema validation and version compatibility
- source trust metadata checks
- normalization across reporting levels
- deterministic aggregation of totals, needs, gaps, actions, and data-quality indicators
- provenance trees for drill-down
- deduplication by source hub, reporting period, sequence, and content hash
- stale/missing-report detection
- rollup generation for the next level

The SDK should not own:
- local Hotline incident editing
- Relay transport guarantees
- lower-hub data ownership
- media attachment transfer
- human approval or publication policy

Hotline should remain responsible for generating the local SITREP snapshot. Relay should remain responsible for delivery, retry, trust envelope, and transport. The aggregation SDK should sit between those concerns and the city/province/region/national presentation layers.

## Reporting Unit

The primary reporting unit for SITREP should be the incident report, not the call.

A call is a communication/session record. An incident report is the operational case being handled.

One incident can have:
- multiple call sessions
- multiple incident types
- multiple incident type field answers
- multiple structured resource needs
- multiple team assignments
- multiple messages/media records
- multiple caller location updates

Recommended conceptual model:

```text
Incident Report
├─ Call Sessions
│  ├─ initial call
│  ├─ reconnect/follow-up call
│  └─ media/chat per call
├─ Incident Types
│  ├─ Rescue
│  └─ Medical
├─ Incident Type Fields
│  ├─ reason for rescue
│  ├─ people injured
│  └─ current condition
├─ Incident Resources Needed
│  ├─ ambulance: 1
│  ├─ medical supplies: 3
│  └─ food/water: 5
├─ Team Assignments
│  ├─ Police / requested
│  ├─ EMS / en route
│  └─ Rescue / on scene
└─ Status / location / population / damage / needs / gaps
```

SITREP should not count call sessions as incidents. A caller reconnecting three times should still be one incident unless a separate incident report is created.

## Counting Rules

Use explicit counting labels so the report does not misrepresent multi-type incidents.

Recommended rules:
- `Total incidents`: distinct incident reports in scope.
- `Total call sessions`: distinct call sessions attached to scoped incidents.
- `Incidents by status`: distinct incident reports grouped by current status at snapshot time.
- `Incident type mentions`: count each incident-type assignment; one incident with two types contributes two mentions.
- `Incidents with type {x}`: distinct incident reports tagged with a specific type.
- `Team assignments`: distinct assignment records attached to scoped incidents.
- `Assignments by status`: distinct assignment records grouped by current assignment status.
- `Resource needs`: requested resources from structured `incident_resources_needed`; this is the primary source for the Needs section.
- `Resource posture`: derived from requested resources plus assignment/resource records to determine assigned, fulfilled, blocked, or still-open posture.
- `Incident type field facts`: descriptive or quantitative answers from incident type fields; these feed Situation, Damage, Population, Actions, Gaps, and sometimes Summary.

Important presentation rule:
- when reporting by incident type, avoid implying the type buckets add up to total incidents unless the report label says "mentions"
- if percentages are shown by type, label them as share of type mentions or share of incidents with type, depending on the formula
- do not double-count a need just because it appears in both a free-text/descriptive incident type field and the structured resources-needed input

## Incident Type Fields vs Resources Needed

Incident type fields and incident resources needed are intentionally separate inputs and should stay separate in SITREP generation.

### Incident Type Fields

Incident type fields describe the facts and context of a selected incident type.

Examples:
- reason for rescue
- people injured
- current patient condition
- damage description
- access constraints
- safety notes
- missing information

SITREP placement:
- `Situation`: classification context, hazard details, current condition, narrative facts
- `Damage`: damage descriptions, severity, affected infrastructure/property
- `Population`: people injured, households affected, vulnerable groups, missing persons
- `Actions`: actions already taken or requested as descriptive facts
- `Gaps`: blockers, unavailable information, access constraints
- `Summary`: only high-signal rollups or watch items

### Incident Resources Needed

Incident resources needed are explicit quantified resource requests.

Examples:
- ambulance: 1
- medical supplies: 3
- food and water supplies: 5
- rescue equipment: 2

SITREP placement:
- `Needs`: primary section for requested resources
- `Actions`: when resources have been assigned, dispatched, or fulfilled
- `Gaps`: when requested resources are still unassigned, unavailable, or blocked
- `Summary`: high-signal resource posture only

Important rule:
- The `Needs` section should primarily come from `incident_resources_needed`, not inferred from arbitrary incident type fields.
- Incident type field inference should not create resource needs unless a field is explicitly mapped that way and no structured resource need exists for the same concept.

## Incident Type Field SITREP Mapping

Use explicit SITREP metadata when available. Use input type, unit, label, and field key only as fallback inference.

Recommended priority:

```text
explicit sitrep_exclude
    > explicit sitrep_section / sitrep_metric
    > inferred from input_type + unit + label + field_key
    > unmapped / review needed
```

Suggested optional field metadata:

```json
{
  "field_key": "people_injured",
  "label": "People injured",
  "input_type": "number",
  "unit": "people",
  "sitrep_section": "population",
  "sitrep_metric": "injured_people_count",
  "sitrep_aggregation": "sum"
}
```

Fallback inference examples:
- `number` + unit `people/persons/households` -> likely `Population`
- `number` + label contains `injured`, `missing`, `affected`, `evacuated` -> likely `Population`
- `text/textarea` + label contains `damage`, `destroyed`, `loss` -> likely `Damage`
- `text/textarea` + label contains `blocked`, `unavailable`, `constraint`, `unknown` -> likely `Gaps`
- `select/multiselect` + label contains `severity`, `condition`, `status` -> likely `Situation`
- `boolean` + label contains `confirmed`, `verified`, `urgent` -> likely `Situation` or `Gaps`, depending on label

Inference warning:
- input type and unit should guide defaults, but should not be treated as final truth
- admin review/override should be supported before using inferred fields for official reporting

## Period Inclusion Rules

SITREP scope must be explicit because incident lifetimes can cross reporting windows.

Recommended default:
- include incidents that were created during the reporting period
- include incidents that were active at any time during the reporting period
- include incidents that changed status during the reporting period

Recommended labels:
- `New this period`: incidents created inside the period
- `Carried over`: incidents created before the period but still active/deferred during the period
- `Closed this period`: incidents resolved/discarded during the period
- `Active at close`: incidents still active/deferred at period end

This avoids a common reporting error where long-running incidents disappear from later SITREPs because they were created before the current reporting window.

## Scope

Initial page type:
- public periodic SITREP page
- read-only
- incident-aware but not incident-editor-driven
- generated from Hotline incident, assignment, caller location, media, and operator activity data

Out of scope for first pass:
- Relay upload
- full approval workflow
- multi-author editing
- PDF/export formatting
- per-agency acknowledgement tracking

Still required for first pass:
- a release state so public pages only expose published SITREPs by default
- a private preview route or permission gate for draft/review states

## Page Route

Proposed public route:

```text
/sitrep/{sitrep}
```

Alternative route if SITREP is generated by period instead of stored records:

```text
/sitrep/{period}
/sitrep/{period}/{sequence}
```

Recommendation:
- create a persisted `sitrep_reports` record and use `/sitrep/{sitrep}` for stable links
- keep generated fields snapshot-based so historical reports do not change when incident records are later edited
- public route should only expose `published` reports unless a signed preview token or authenticated permission is present

## Page Layout

Use a public report layout, not the operator dashboard shell.

Recommended structure:

```text
SITREP Header
Summary
Situation
Damage
Population
Actions
Needs
Gaps
Report Footer
```

### Header

Purpose:
- identify the report period, area, alert level, and release status

Suggested fields:
- report title
- report number / sequence
- coverage area
- reporting period start/end
- generated at
- alert level
- prepared by
- source system: PBB Hotline
- release state: draft, reviewed, published
- data confidence / completeness note

Visual notes:
- large title block
- compact metadata grid
- alert-level badge
- optional official seal/brand block

### Summary

Purpose:
- provide a fast executive situation picture.
- answer "what is happening, where, how serious is it, and what is the current operational posture?"

Suggested content:
- one executive narrative paragraph
- dominant incident type/category
- most affected area or barangay
- highest concern / priority issue
- operating posture: stable, monitoring, strained, critical
- active unresolved incident count
- incidents needing follow-up
- major changes since previous SITREP
- total incidents and call sessions as supporting metrics
- team assignment posture: available, stretched, blocked
- resource need posture: sufficient, limited, shortage, unknown

Recommended UI:
- executive narrative block at the top
- compact "current picture" cards
- supporting metrics row
- trend chips only if the comparison window is stable
- "watch items" list for risks needing command attention

Example narrative:

```text
Hotline activity is concentrated in Barangay Guadalupe and nearby upland areas, 
with rescue and medical assistance making up most reported incidents. 
Operations are currently in monitoring posture: two incidents remain active, 
one team is en route, and no critical resource shortage has been confirmed.
```

Recommended summary fields:
- `headline`: one-line report headline
- `posture`: stable, monitoring, strained, critical
- `posture_reason`: short explanation
- `dominant_incident_type`: most frequent or most operationally significant incident type
- `hotspot_area`: most affected area/barangay
- `priority_watch_items`: list of current risks
- `key_change_since_previous`: short comparison if previous SITREP exists
- `active_unresolved_incidents`: count
- `blocked_assignments`: count
- `critical_needs`: count
- `confidence_note`: short data-quality note

Example summary card layout:

```text
Headline
"Rescue and medical reports concentrated in Guadalupe upland area"

Operational Posture
Monitoring
"Active incidents remain manageable; no confirmed resource shortage."

Primary Concern
2 active rescue-related incidents still awaiting field confirmation

Hotspot
Barangay Guadalupe / upland access roads

Watch Items
- caller location unavailable for 1 active incident
- 1 assignment still en route
- no confirmed damage report yet
```

Supporting metrics should still exist, but they should not be the whole summary:
- total incidents
- incidents by status
- total call sessions
- incidents with multiple call sessions
- incident type mentions
- team assignments by status
- resource needs by status

Summary generation guidance:
- generate the headline from dominant type, hotspot, and operational posture
- generate posture from unresolved incident count, blocked assignments, critical needs, and stale/missing location count
- avoid declaring "stable" if there are unresolved critical needs or blocked life-safety assignments
- include a data-quality caveat when core data is missing or unverified

### Situation

Purpose:
- describe the operational context and current field picture.

Suggested content:
- incident distribution by location/barangay
- incident distribution by type/category
- multi-type incident count
- timeline of notable events
- map snapshot or location summary
- current operating conditions
- data confidence and verification notes

Recommended UI:
- narrative block
- incident category table
- location table
- optional map preview

Data source candidates:
- incidents
- incident types/categories
- caller location history
- operator activity log
- team assignment events

Counting note:
- show `Total incidents` separately from `Incident type mentions`
- if one incident is both `Rescue` and `Medical`, it appears in both type rows but still counts once in total incidents

Recommended separation:
- use Situation for "what and where"
- use Actions for "what has been done"
- use Needs for "what is requested"
- use Gaps for "what prevents closure"

### Damage

Purpose:
- capture reported damage, losses, affected infrastructure, and incident-level impact.

Suggested content:
- damage reports by type
- infrastructure affected
- properties affected
- attachments/media references if available
- confidence/source notes

Recommended UI:
- damage category cards
- table with location, description, severity, source, and time reported
- "No confirmed damage reported" empty state when applicable

Important boundary:
- damage should separate confirmed, reported, and unverified entries.
- if damage fields are not configured yet, the section should explicitly say "No damage fields configured for this reporting period" rather than implying no damage occurred.

### Population

Purpose:
- summarize affected people and population-related assistance context.

Suggested content:
- callers assisted
- households affected if captured
- people injured
- missing/persons of concern if captured
- vulnerable population notes
- evacuation/relocation counts if later added

Recommended UI:
- population metrics row
- grouped table by barangay/location
- notes section for special population concerns

Data caveat:
- current incident fields may not fully support population counts yet.
- initial version should support "not available" and "not reported" states explicitly.
- population numbers should show source and confidence when derived from incident type fields.

### Actions

Purpose:
- show what responders/operators have done during the period.

Suggested content:
- team assignments by status
- dispatched resources
- completed actions
- operator decisions such as resolved/deferred/discarded
- call handling activity
- transfer activity

Recommended UI:
- action timeline
- team assignment status table
- resource/actions table

Data source candidates:
- team assignments
- assignment resources
- assignment notes
- incident status history / activity log
- call sessions
- transfer records

Boundary:
- call sessions should be shown as operator workload and communication activity
- team assignments should be shown as operational response activity
- incident status changes should be shown as case-management activity

### Needs

Purpose:
- communicate current requested or outstanding support needs.

Suggested content:
- resource requests from incident forms
- team/resource shortages
- unmet assignment requirements
- barangay/location-level needs
- urgency/priority
- requested, assigned, fulfilled, and still-open quantities

Recommended UI:
- needs table
- priority chips
- "requested / fulfilled / pending" counts

Important boundary:
- needs should be actionable and tied to an incident, location, or operational area.
- needs should not be inferred from narrative text when structured `incident_resources_needed` exists for the same resource concept.

### Gaps

Purpose:
- identify constraints preventing full resolution.

Suggested content:
- unavailable responders
- unavailable resources
- communication gaps
- missing information
- inaccessible areas
- delayed media/evidence processing
- unresolved incident data quality issues
- unverified damage/population claims
- stale or missing caller location

Recommended UI:
- gap cards grouped by type
- concise explanation and proposed next step

Examples:
- "Caller location unavailable for 3 active incidents."
- "No available medical transport team assigned to 2 rescue incidents."
- "Video evidence still processing for incident #000156."

Public page note:
- some operational gaps may be too sensitive for public release
- gap items should support a `public_visible` flag or redaction policy

## Data Model Direction

Recommended persisted tables:

### `sitrep_reports`

Stores the report header and lifecycle.

Suggested fields:
- `id`
- `sequence_number`
- `title`
- `coverage_area`
- `period_started_at`
- `period_ended_at`
- `generated_at`
- `published_at`
- `status`
- `visibility`
- `alert_level`
- `prepared_by_user_id`
- `reviewed_by_user_id`
- `summary_json`
- `situation_json`
- `damage_json`
- `population_json`
- `actions_json`
- `needs_json`
- `gaps_json`
- `source_snapshot_json`
- `privacy_redactions_json`
- `data_quality_json`

Recommended snapshot summary keys:
- `incident_count`
- `call_session_count`
- `multi_call_incident_count`
- `incident_type_mention_count`
- `team_assignment_count`
- `resource_need_count`
- `new_incident_count`
- `carried_over_incident_count`
- `closed_incident_count`
- `active_at_close_incident_count`
- `blocked_assignment_count`
- `critical_need_count`

### `sitrep_report_items`

Optional if we want normalized rows instead of only JSON snapshots.

Suggested fields:
- `id`
- `sitrep_report_id`
- `section`
- `type`
- `title`
- `body`
- `severity`
- `location_label`
- `incident_id`
- `team_assignment_id`
- `call_session_id`
- `incident_type_id`
- `resource_type_id`
- `metadata_json`
- `occurred_at`
- `public_visible`
- `confidence`
- `source_label`

Recommendation:
- start with `sitrep_reports` snapshot JSON for speed and historical stability
- add `sitrep_report_items` only when reporting rows need filtering, review, or manual adjustment

## Source Snapshot Boundary

The report should store enough source context to explain how it was generated without making the public page depend on live incident state.

Recommended `source_snapshot_json` content:
- report generation filters
- reporting period
- source hub identity and reporting level when known
- parent hub identity when known
- incident IDs included
- call session IDs included
- team assignment IDs included
- resource need IDs included
- incident type field IDs included
- previous SITREP ID used for comparison, if any
- adapter version
- counting rule version
- source snapshot hash for Relay/drill-down comparison
- public/internal drill-down reference when available

This makes historical report output reproducible even after incident records change.

Recommended upstream metadata:
- `source_hub_id`
- `source_hub_name`
- `source_level`: `barangay`, `city`, `municipality`, `province`, `region`, or `national`
- `parent_hub_id`
- `coverage_area_code`
- `coverage_area_label`
- `reporting_period`
- `sitrep_schema_version`
- `summary_hash`
- `breakdown_index_hash`
- `drill_down_url`
- `drill_down_auth_scope`
- `last_successful_sync_at`
- `data_freshness_status`

## Adapter Boundary

SITREP should remain Hotline-owned semantically.

Suggested app-owned adapters:
- `sitrepSummaryAdapter()`
- `sitrepSituationAdapter()`
- `sitrepDamageAdapter()`
- `sitrepPopulationAdapter()`
- `sitrepActionsAdapter()`
- `sitrepNeedsAdapter()`
- `sitrepGapsAdapter()`
- `sitrepCountingRulesAdapter()`
- `sitrepPublicPageAdapter()`
- `sitrepRelayEnvelopeAdapter()`
- `sitrepRollupSummaryAdapter()`
- `sitrepBreakdownIndexAdapter()`
- `sitrepPrivacyRedactionAdapter()`
- `sitrepDataQualityAdapter()`

Helper library usage should stay visual:
- cards
- tables
- badges
- timeline
- empty states
- progress indicators
- public page shell if available

## Public Page Behavior

Public page should:
- be read-only
- render from a stored SITREP snapshot
- clearly show generated/published timestamps
- support empty states per section
- be printable enough even before formal PDF/export exists
- avoid operator-only controls
- not expose private caller phone numbers unless explicitly approved
- clearly distinguish confirmed, reported, unverified, unavailable, and not-configured data

Privacy defaults:
- no raw caller phone numbers
- no sensitive chat transcript
- no exact caller coordinates on public page unless release policy allows it
- aggregate location to barangay/area by default
- media links should be omitted or redacted unless approved
- operator internal notes should be omitted unless explicitly mapped for public release
- responder/team availability gaps should be summarized carefully to avoid exposing operational weakness

## First-Pass Layout Wireframe

```text
+------------------------------------------------------+
| PBB Hotline SITREP                                   |
| Report #0001 | Period | Area | Alert Level | Status  |
+------------------------------------------------------+
| SUMMARY                                              |
| Headline | Posture | Hotspot | Watch Items           |
| Supporting metrics                                   |
+------------------------------------------------------+
| SITUATION                                            |
| Narrative | Location table | Incident type breakdown |
+------------------------------------------------------+
| DAMAGE                                               |
| Damage cards/table or empty state                    |
+------------------------------------------------------+
| POPULATION                                           |
| Population metrics and affected population notes     |
+------------------------------------------------------+
| ACTIONS                                              |
| Timeline + team/resource action table                |
+------------------------------------------------------+
| NEEDS                                                |
| Outstanding resource/support needs                   |
+------------------------------------------------------+
| GAPS                                                 |
| Constraints, missing info, pending blockers          |
+------------------------------------------------------+
| Generated by PBB Hotline | Data snapshot timestamp   |
+------------------------------------------------------+
```

## Generation Flow

Proposed first-pass flow:

1. Command user triggers SITREP generation for a reporting window.
2. Server queries incidents, call sessions, assignments, resources, activities, and caller-location summaries.
3. Server normalizes incident-centered aggregates.
4. Server applies explicit counting rules for calls, incident types, and assignments.
5. Server applies incident type field mappings and structured resource-need aggregation.
6. Server applies privacy and data-quality policies.
7. Server builds section snapshots through app-owned adapters.
8. Server stores one `sitrep_reports` row.
9. Public page renders from the stored snapshot.
10. Later phase builds a Relay-ready summary rollup and breakdown index from the same stored snapshot.
11. Deeper drill-down remains API-backed and fetched on demand instead of embedded into every upstream message.

## Future Relay And Drill-Down Flow

The Relay phase should not be designed as "send the whole SITREP tree upward."

Recommended future flow:

1. Local hub generates and approves a SITREP snapshot.
2. Hotline creates a compact rollup summary and breakdown index.
3. Relay sends the summary/index to the parent hub.
4. Parent hub aggregation SDK validates, deduplicates, and stores the rollup.
5. Parent hub shows the rollup immediately in dashboards and consolidated reports.
6. If a user drills down, the parent hub calls the source hub API for detail when connectivity is available.
7. If the source hub is unavailable, the parent hub shows the latest rollup with a stale/offline indicator.

Future drill-down API should support:
- fetch SITREP by ID, sequence, period, or summary hash
- fetch section-level detail without downloading the whole report
- fetch provenance behind an aggregate number
- fetch source incident references only for authorized users
- return freshness and redaction metadata with every response

## Open Questions

- Should SITREP be generated manually, by schedule, or both?
- What is the initial reporting cadence by alert level?
- Who can publish a SITREP?
- Which fields are safe for public release?
- What is the minimum summary payload required for higher hubs to make decisions without live drill-down?
- Which drill-down fields are available to city, province, region, and national users by default?
- Should drill-down calls go directly to the source hub, through Relay, or support both modes?
- What should be the stale threshold per reporting level when a lower hub is offline?
- Should the first version include a map snapshot or only location tables?
- Do damage/population fields need new incident-type fields before SITREP can be useful?
- Should SITREP include only incidents created during the period, or all incidents active during the period?
- For incident type summaries, should the default chart use type mentions or distinct incidents with type?
- Should reconnect/follow-up calls be shown only as call-session count, or should they also be highlighted when an incident has repeated caller contact?
- What should be the default public visibility for Gaps?
- Do we need `draft`, `review`, and `published` states immediately, or only `draft` and `published`?
- Should SITREP generation store adapter/counting-rule versions for auditability from day one?

## Recommendation

Start with a stored snapshot-based public SITREP page and keep the local report model compatible with upstream rollups.

Implement the sections as app-owned adapters with explicit empty states. Avoid Relay integration until the snapshot content and public privacy rules are stable.

This keeps the first SITREP useful as a local reporting product while preserving a clean path to Relay handoff, multi-level aggregation, and API-backed drill-down later.
