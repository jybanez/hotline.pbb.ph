# PBB Hotline SITREP Implementation Checklist

This checklist turns the public SITREP proposal into trackable implementation work.

Reference proposal:
- `docs/pbb-hotline-sitrep-public-page-proposal.md`

## 1. Phase Boundary

Initial implementation goals:
- [x] Persist SITREP snapshots.
- [x] Render a read-only public page.
- [x] Use incident-centered aggregation.
- [x] Build explicit section adapters.
- [x] Apply privacy-aware public rendering.
- [x] Keep Relay upload out of the first pass.
- [x] Keep local snapshots compatible with future upstream summary rollups.

Out of initial implementation:
- [x] Defer Relay handoff.
- [x] Defer multi-level aggregation SDK.
- [x] Defer direct drill-down API federation.
- [x] Defer PDF/export.
- [x] Defer full approval workflow beyond basic status/visibility.
- [x] Defer multi-author editing.
- [x] Defer public media release workflow.

## 2. Data Model

Create `sitrep_reports`.

Required fields:
- [x] `id`
- [x] `sequence_number`
- [x] `title`
- [x] `coverage_area`
- [x] `period_started_at`
- [x] `period_ended_at`
- [x] `generated_at`
- [x] `published_at`
- [x] `status`
- [x] `visibility`
- [x] `alert_level`
- [x] `prepared_by_user_id`
- [x] `reviewed_by_user_id`
- [x] `summary_json`
- [x] `situation_json`
- [x] `damage_json`
- [x] `population_json`
- [x] `actions_json`
- [x] `needs_json`
- [x] `gaps_json`
- [x] `source_snapshot_json`
- [x] `privacy_redactions_json`
- [x] `data_quality_json`
- [x] timestamps

Implementation tasks:
- [x] Use JSON columns for section snapshots.
- [x] Keep section JSON stable after generation.
- [x] Make public page read snapshots, not live incident records.
- [x] Support at least `draft` and `published` report statuses.
- [x] Support at least `private` and `public` visibility states.
- [ ] Add explicit source hub/reporting-level metadata when Relay/topology contracts are finalized.
- [ ] Add report hash fields for rollup deduplication and drill-down validation.
- [x] Defer `sitrep_report_items` unless section rows need filtering, manual review, or item-level edits.

## 3. Period Scope

Implement period inclusion rules.

Include incidents that:
- [x] Were created during the period.
- [x] Were active or deferred at any time during the period.
- [x] Changed status during the period.

Track counts:
- [x] New this period.
- [x] Carried over.
- [x] Closed this period.
- [x] Active at close.

Implementation task:
- [x] Create one query/service method that returns scoped incident IDs and inclusion reason metadata.

## 4. Counting Rules

Create a counting rules adapter/service.

Required counts:
- [x] Total distinct incidents.
- [x] Total call sessions.
- [x] Incidents by status.
- [x] Incident type mentions.
- [x] Distinct incidents with each type.
- [x] Team assignments.
- [x] Assignments by status.
- [x] Resource needs.
- [x] Resource posture.
- [x] Incident type field facts.

Important rules:
- [x] Do not count call sessions as incidents.
- [x] Count one incident with multiple incident types once in total incidents.
- [x] Count one incident with multiple types as multiple type mentions.
- [x] Do not infer resource needs from descriptive fields when structured `incident_resources_needed` exists.

## 5. Incident Type Field Mapping

Recommended priority:

```text
explicit sitrep_exclude
    > explicit sitrep_section / sitrep_metric
    > inferred from input_type + unit + label + field_key
    > unmapped / review needed
```

Implementation tasks:
- [x] Define mapping metadata format.
- [x] Add adapter to normalize field answers into section facts.
- [x] Add fallback inference for unmapped fields.
- [x] Mark inferred fields as lower confidence.
- [x] Track unmapped/review-needed fields in `data_quality_json`.

Suggested field metadata:

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

## 6. Resources Needed

Use structured `incident_resources_needed` as the source of truth for Needs.

Implementation tasks:
- [x] Aggregate requested quantity by resource type.
- [x] Group by incident and location/barangay when available.
- [x] Derive assigned/fulfilled/still-open posture from assignments/resource records.
- [x] Surface unassigned or unavailable resources as Gaps.
- [x] Avoid double-counting narrative field answers as resource needs.

## 7. Section Adapters

Create app-owned adapters:
- [x] `sitrepSummaryAdapter()`
- [x] `sitrepSituationAdapter()`
- [x] `sitrepDamageAdapter()`
- [x] `sitrepPopulationAdapter()`
- [x] `sitrepActionsAdapter()`
- [x] `sitrepNeedsAdapter()`
- [x] `sitrepGapsAdapter()`
- [x] `sitrepCountingRulesAdapter()`
- [x] `sitrepPrivacyRedactionAdapter()`
- [x] `sitrepDataQualityAdapter()`
- [x] `sitrepPublicPageAdapter()`
- [x] Defer `sitrepRelayEnvelopeAdapter()` until Relay phase.
- [ ] `sitrepRollupSummaryAdapter()`
- [ ] `sitrepBreakdownIndexAdapter()`

Adapter output:
- [x] Return plain arrays/objects that are safe to serialize into `sitrep_reports`.

## 8. Summary Adapter

Summary must not be just counters.

Required output:
- [x] Headline.
- [x] Operational posture.
- [x] Posture reason.
- [x] Dominant incident type.
- [x] Hotspot area.
- [x] Primary concern.
- [x] Priority watch items.
- [x] Key change since previous SITREP, if available.
- [x] Supporting metrics.
- [x] Confidence/data-quality note.

Posture rules should consider:
- [x] Active unresolved incident count.
- [x] Blocked assignments.
- [x] Critical needs.
- [x] Stale or missing caller locations.
- [x] Unverified damage/population claims.
- [x] Prevent `stable` posture when there are unresolved critical needs or blocked life-safety assignments.

## 9. Situation Adapter

Output:
- [x] Narrative situation paragraph.
- [x] Incident distribution by location/barangay.
- [x] Incident distribution by type/category.
- [x] Multi-type incident count.
- [x] Notable timeline items.
- [x] Optional map/location summary.
- [x] Data confidence notes.

Important:
- [x] Separate total incidents from incident type mentions.
- [x] Label type rows correctly.

## 10. Damage Adapter

Output:
- [x] Confirmed damage.
- [x] Reported damage.
- [x] Unverified damage.
- [x] Affected infrastructure/property.
- [x] Source/confidence notes.

Empty states:
- [x] No confirmed damage reported.
- [x] No damage fields configured.
- [x] Damage data unavailable.
- [x] Render these states differently because they mean different things.

## 11. Population Adapter

Output:
- [x] Callers assisted.
- [x] People injured.
- [x] Missing/persons of concern.
- [x] Households affected.
- [x] Vulnerable population notes.
- [x] Evacuation/relocation counts if available.
- [x] Source/confidence notes.

Important:
- [x] Support `not reported`, `not available`, and `not configured`.
- [x] Show confidence when values are inferred from incident type fields.

## 12. Actions Adapter

Output:
- [x] Team assignment status summary.
- [x] Dispatched resources.
- [x] Completed actions.
- [x] Operator decisions/status changes.
- [x] Call handling activity.
- [x] Transfer activity.

Separate:
- [x] Calls as communication workload.
- [x] Assignments as operational response.
- [x] Incident status changes as case management.

## 13. Needs Adapter

Output:
- [x] Resource needs by type.
- [x] Requested/assigned/fulfilled/pending quantities.
- [x] Needs by location/barangay.
- [x] Priority/urgency if available.

Primary source:
- [x] Use `incident_resources_needed`.
- [x] Do not infer needs from narrative fields unless explicitly mapped and not already represented as structured resource need.

## 14. Gaps Adapter

Output:
- [ ] Unavailable responders.
- [x] Unavailable resources.
- [ ] Communication gaps.
- [x] Missing information.
- [ ] Inaccessible areas.
- [x] Delayed media/evidence processing.
- [x] Unverified claims.
- [x] Stale/missing caller location.
- [x] Unresolved data quality issues.

Public safety:
- [x] Support `public_visible`.
- [x] Apply redaction policy before public rendering.
- [x] Avoid exposing sensitive operational weakness verbatim.

## 15. Data Quality Adapter

Track:
- [x] Missing caller location.
- [x] Stale caller location.
- [x] Incident type fields unmapped to SITREP.
- [x] Damage fields not configured.
- [x] Population fields not configured.
- [x] Unverified damage/population claims.
- [x] Incidents without type.
- [x] Incidents without assignment.
- [x] Incidents with open critical needs.

Output:
- [x] Per-section data quality notes.
- [x] Global report confidence note.
- [x] Machine-readable `data_quality_json`.

## 16. Privacy And Redaction

Default public redactions:
- [x] Caller phone numbers.
- [x] Raw caller chat transcript.
- [x] Exact caller coordinates.
- [x] Sensitive media links.
- [x] Internal operator notes.
- [x] Responder/team availability details that expose operational weakness.

Implementation tasks:
- [x] Create a redaction adapter.
- [x] Apply redaction before storing public snapshot or before rendering public page.
- [x] Record redactions in `privacy_redactions_json`.
- [x] Store public-safe snapshots for first pass and keep source IDs in `source_snapshot_json`.
- [x] Defer separate full internal snapshot unless review workflow needs it.

## 17. Generation Service

Create a server-side service that:
- [x] Accepts reporting period and coverage area.
- [x] Finds scoped incidents.
- [x] Loads call sessions, incident types, field answers, resource needs, assignments, activity, and locations.
- [x] Builds normalized aggregate context.
- [x] Applies counting rules.
- [x] Maps incident type fields.
- [x] Aggregates structured resource needs.
- [x] Applies data quality checks.
- [x] Applies privacy/redaction policy.
- [x] Builds section snapshots.
- [x] Stores `sitrep_reports`.

Suggested class names:
- [x] `SitrepGenerationService`
- [ ] `SitrepContextBuilder`
- [ ] `SitrepCountingRules`
- [ ] `SitrepSectionAdapters`
- [ ] `SitrepRedactionPolicy`

## 18. Routes And Controllers

Generation route:
```text
POST /api/command/sitreps
```

Public route:
```text
GET /sitrep/{sitrep}
```

Preview route:
```text
GET /command/sitreps/{sitrep}/preview
```

Implementation tasks:
- [x] Add generation route/controller.
- [x] Add public route/controller.
- [x] Add preview route/controller.
- [x] Public route only exposes `published` + `public`.
- [x] Preview route requires command auth.
- [x] Draft/review reports are not public by default.

## 19. Public Page UI

Use a public report shell, not operator dashboard shell.

Render sections:
- [x] Header.
- [x] Summary.
- [x] Situation.
- [x] Damage.
- [x] Population.
- [x] Actions.
- [x] Needs.
- [x] Gaps.
- [x] Footer.

UI requirements:
- [x] Readable on desktop and mobile.
- [x] Print-friendly enough for browser print.
- [x] Explicit empty states.
- [x] Visible generated/published timestamps.
- [x] Visible data confidence note.
- [x] No operator-only controls.

Helper components:
- [x] Cards.
- [x] Badges.
- [x] Tables/lists.
- [ ] Timeline.
- [x] Empty states.
- [ ] Progress/summary indicators.

## 20. Tests

Backend tests:
- [ ] Period inclusion rules.
- [x] Multi-call incident counting.
- [x] Multi-type incident counting.
- [x] Resource needs aggregation.
- [x] Incident type field mapping.
- [x] Privacy redaction.
- [x] Public route visibility.
- [x] Draft report not publicly visible.
- [x] Snapshot remains stable after source incident changes.

Frontend/render tests:
- [ ] All sections render with data.
- [ ] Each section renders meaningful empty state.
- [x] Public page does not expose caller phone number.
- [ ] Public page does not expose exact coordinates by default.

Fixture scenarios:
- [ ] Incident with multiple calls.
- [x] Incident with multiple incident types.
- [x] Incident with structured resource needs.
- [x] Incident with damage fields.
- [x] Incident with population fields.
- [ ] Incident with missing caller location.
- [ ] Active carried-over incident.
- [ ] Closed incident during period.

## 21. Future Relay Boundary

Do not implement Relay upload in first pass. Future Relay integration should push compact SITREP summaries and breakdown indexes upward, not the whole barangay-to-national detail tree.

Keep Relay-ready by:
- [x] Store stable snapshots.
- [x] Store source IDs.
- [x] Track adapter/counting-rule version.
- [x] Keep `sitrepRelayEnvelopeAdapter()` as a later adapter over the stored snapshot.
- [ ] Define minimum upstream summary fields for city/province/region/national aggregation.
- [ ] Define breakdown index fields for source hub, reporting period, counts, hashes, and drill-down references.
- [ ] Define stale/missing-report indicators so higher hubs can distinguish zero reports from unavailable reports.

Future Relay tasks:
- [ ] Define Relay envelope for summary rollup and breakdown index.
- [ ] Add delivery status.
- [ ] Add retry behavior.
- [ ] Add acknowledgement tracking.
- [ ] Add signed/approved release workflow if required.
- [ ] Keep full source SITREP and media outside routine upstream rollup payloads.
- [ ] Support background/on-demand detail synchronization for audit and drill-down.

## 22. Future Multi-Level Aggregation SDK

The aggregation SDK should consume SITREP rollups from any reporting level:
- [ ] Barangay to city/municipality.
- [ ] City/municipality to province.
- [ ] Province to region.
- [ ] Region to national.

SDK responsibilities:
- [ ] Validate SITREP schema version.
- [ ] Validate source hub and trust metadata.
- [ ] Normalize reporting levels and coverage areas.
- [ ] Deduplicate by source hub, reporting period, sequence, and content hash.
- [ ] Aggregate totals, needs, gaps, actions, alert posture, and data-quality indicators.
- [ ] Preserve provenance behind every aggregate value.
- [ ] Produce rollup summary for the next reporting level.
- [ ] Track missing, stale, partial, and superseded reports.

SDK non-goals:
- [ ] Do not own local Hotline incident editing.
- [ ] Do not own Relay transport retries.
- [ ] Do not embed raw media or full detail in routine rollups.
- [ ] Do not replace human approval/publication policy.

## 23. Future Drill-Down Access

Drill-down should be API-backed and lazy. Higher hubs should receive enough summary data to act even when live drill-down is unavailable.

Future drill-down tasks:
- [ ] Define authenticated SITREP detail endpoint.
- [ ] Define section-level detail endpoint.
- [ ] Define provenance endpoint for aggregate values.
- [ ] Define authorization scopes by reporting level.
- [ ] Define redaction behavior for upstream users.
- [ ] Return freshness/offline status with drill-down responses.
- [ ] Support direct source-hub API access when stable.
- [ ] Support Relay-assisted or cached fallback when direct access is unavailable.

## 24. Suggested Implementation Order

- [x] Add migrations/model for `sitrep_reports`.
- [x] Implement period scope service.
- [x] Implement context builder and counting rules.
- [x] Implement field mapping and resource aggregation.
- [x] Implement section adapters.
- [x] Implement privacy/data-quality adapters.
- [x] Implement generation endpoint.
- [x] Implement public page route/controller.
- [x] Implement public page UI.
- [x] Add tests and fixture scenarios.
- [ ] Review output with real incident data.
- [x] Design Relay envelope only after local SITREP output is stable.
- [ ] Design summary rollup and breakdown index before implementing upstream transport.
