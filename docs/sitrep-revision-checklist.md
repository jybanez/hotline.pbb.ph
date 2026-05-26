# SITREP Revision Checklist

This checklist tracks the work to revise Hotline SITREP into a decision-maker-first report while preserving source traceability for command users.

## Status Legend

- `Done` - agreed, verified, or completed
- `In Progress` - currently being worked
- `Pending` - not started
- `Blocked` - waiting on a decision or dependency

## Checklist

| Phase | Item | Status | Notes |
|---|---|---|---|
| 1 | Confirm audience: decision makers first, operators/subordinates second | Done | SITREP should support decisions and delegation, not operator task execution. |
| 1 | Confirm `Deferred` meaning | Done | Deferred reports remain open; response or coordination is underway and the citizen/operator interaction may resume. |
| 1 | Confirm status rules | Done | Active/deferred = current picture; resolved = addressed history; discarded = excluded from operational posture and demand. |
| 1 | Confirm “Command Action” is out for now | Done | Use decision-support language instead of assigning tasks to leaders. |
| 2 | Audit current SITREP 10 against source incidents | Done | Source incidents are 234 through 241. |
| 2 | Identify false headline/hotspot issue | Done | Current headline incorrectly combines dominant type and top location without correlation. |
| 2 | Identify resource counting issue | Done | Current demand should count active/deferred only. Resolved is addressed; discarded is excluded. |
| 2 | Identify assignment counting issue | Done | Current assignment load should count assignments associated with active/deferred incidents only. |
| 2 | Identify population/double-counting risk | Done | Avoid consolidated totals when fields may overlap across shelter, family, patient, and crowd counts. |
| 3 | Define revised content structure | Done | See Content Contract below. |
| 3 | Define exact section order and labels | Done | See Content Contract below. |
| 3 | Define current-vs-history counting rules per section | Done | See Counting Rules by Section below. |
| 3 | Define decision-point language without tasking leaders | Done | Decision Points frame choices and implications, not operational commands. |
| 3 | Define public vs command content differences | Done | Initial implementation keeps one document shape but supports safer public language by excluding discarded/current-only details from top posture. |
| 4 | Implement generator changes | Done | `SitrepGenerationService` now uses current-picture rules and emits executive sections. |
| 4 | Update rendering if needed | Done | `document.blade.php` now renders executive assessment, decision points, current operating picture, areas of concern, resource posture, period activity, and verification notes. |
| 4 | Add or adjust tests for counting and narrative rules | Done | Added regression coverage for active/deferred current demand, resolved history, discarded exclusion, and structured detail formatting. |
| 4 | Generate revised SITREP from same source incident set | Done | Generated report 23 / SITREP #0019 from the same reporting period as SITREP 10. |
| 5 | Review old vs revised SITREP side by side | Done | Jojo completed content review and locked the revised baseline at `/command/sitreps/57/preview`. |
| 5 | Improve gaps section into decision-risk section | Done | Renamed to Response Constraints and Confidence Gaps with decision relevance, evidence, and confidence notes. |
| 5 | Use configured resource categories in resource posture and gaps | Done | Resource rows now carry `resource_type_categories`; Resource Needs includes a Category column and gaps break demand down by official categories. |
| 5 | Render resource category gaps as a mini table | Done | Resource category demand is now carried as structured snapshot data and displayed as a compact two-column table under Evidence. |
| 5 | Rename resource categories for executive readability | Done | Added migration and updated reference data so resource categories use SITREP-friendly names in the DB. |
| 5 | Remove duplicated resource category breakdown from gaps | Done | Gaps now reference Current Resource Posture instead of repeating the category table. |
| 5 | Add Hotline version/build to SITREP snapshot traceability | Done | `source_snapshot_json.hotline` now stores the generating Hotline version/build, and the rendered footer shows Source Snapshot. |
| 5 | Seed Guadalupe sample incident set for generator testing | Done | Seeded 28 `GDLPE-SITREP-*` incidents under a dedicated sample operator: 17 active, 1 deferred, 9 resolved, and 1 discarded. Latest generated sample is report 54 / SITREP #0050. |
| 5 | Simplify Current Areas of Concern | Done | Replaced per-incident cards with grouped operational concern rows and split current need into Assignments and Resource Units columns. |
| 5 | Simplify damage and population sections | Done | Replaced per-incident cards with grouped Damage Summary and Population Summary tables while retaining source detail rows in JSON. |
| 5 | Normalize family member breakdown fields | Done | Population summary now counts variant keys such as `senior_citizens`, `children`, PWD, and pregnant fields. |
| 5 | Separate declared family-member breakdowns from population summary | Done | Member breakdowns now render in a dedicated Declared Member Breakdown table because some family records declare only families/people without age or vulnerable-member detail. |
| 5 | Simplify operational response without hiding team deployment | Done | Replaced the incident-by-incident assignment table with a Team Deployment matrix grouped by official team category and team, with separate columns for every official assignment status and blank cells for zero values. |
| 5 | Add scenario-specific response timing | Done | Added a simplified Assignment Timing table under Response Posture, using assignment milestone timestamps plus Time in Status without averaging unlike scenarios. |
| 5 | Remove redundant resource posture column | Done | Resource Needs now shows only Resource, Category, Quantity, and Incidents because all rows are already current active/deferred demand. |
| 5 | Add Hub Node identity to SITREP snapshot and title | Done | SITREP generation snapshots Relay `hub.json` and renders Hub-aware identity such as Barangay SITREP for Guadalupe, Cebu City, Cebu, with uplink/source topology retained in the snapshot. |
| 5 | Simplify Hub-aware header | Done | Header now keeps only title, Hub location, period, headline, and a compact metadata line; coverage, hub node, deployment, uplink, and label moved to Source Snapshot. |
| 5 | Flatten header metadata | Done | Header now places one compact metadata line at top-right and combines Hub location plus report period into one identity line. |
| 5 | Shorten header metadata line | Done | Header metadata now uses compact form such as `#0044 · Draft / Private · Elevated · May 27, 2026 3:50 AM`. |
| 5 | Simplify Source Snapshot footer | Done | Footer source metadata now renders as separate Hotline, Hub Node, and Uplink lines so the provenance is easier to scan without repeating the report label. |
| 5 | Reframe executive summary around life safety | Done | Summary now leads with People at Risk, Access to Help, and Response Progress instead of operational load metrics. |
| 5 | Add people-helped/accomplishment summary | Done | Resolved reports now surface as a top People Helped card and a positive People Helped and Accomplishments callout, including resolved family/person counts, without adding resolved demand back into the current picture. |
| 5 | Separate gaps and accomplishments in executive summary | Done | Summary now renders a Gaps row with People at Risk, Access to Help, and Response Progress, followed by an Accomplishments row with People Helped, Handled Incidents, and Teams / Resources Deployed. |
| 5 | Demote current operating picture | Done | Current Operating Picture no longer renders as a framed dashboard block; it appears as a compact Current totals line after Decision Points for traceability. |
| 5 | Tune wording with Jojo | Done | Final review settled on separated Gaps and Accomplishments rows, compact Current totals, and leadership/media-friendly people-helped language. |
| 5 | Lock content rules | Done | Content rules are locked as of the reviewed SITREP #0053 / report 57 baseline. Future changes should be treated as new scope or refinements. |
| 6 | Commit SITREP work on isolated branch | Pending | Branch: `codex/sitrep-manual-generation`. |
| 6 | Push branch and open PR | Pending | Only after Jojo confirms revised SITREP direction. |

## Current Position

Phase 5 content review is complete and content rules are locked. A revised SITREP snapshot has been generated and browser-checked as report 24 / SITREP #0020. A Guadalupe sample data run has also been generated as report 57 / SITREP #0053 with simplified Hub-aware Barangay SITREP identity, shortened top-right header metadata, separated Gaps and Accomplishments summary rows, compact Current totals traceability, grouped current concerns, damage, population summaries, a separate declared member breakdown table, grouped team deployment columns for every official assignment status, merged scenario-specific response timing, simplified Resource Needs without the redundant posture column, and a clearer Source Snapshot footer without a repeated label line. Next phase is branch cleanup, commit, push, and PR preparation.

Preview URL: `https://hotline-sitrep.pbb.ph/command/sitreps/24/preview`

Sample Guadalupe Preview URL: `https://hotline-sitrep.pbb.ph/command/sitreps/57/preview`

Verification completed:

- Focused SITREP test suite passes: `10 passed, 188 assertions`.
- Authenticated browser preview loads without console warnings or errors.
- Source Snapshot footer now shows the generating Hotline version/build as `Hotline: v1-5.6.1 · Build source-template`.
- Source Snapshot footer now separates Hub Node and Uplink onto distinct lines and omits the repeated Label line.
- Source snapshot now includes the Relay Hub Node payload from `hub.json`, including deployment, hub identity, uplinks, sources, and snapshot hash.
- Rendered title now uses Hub-aware identity when available, for example `Barangay SITREP` and `Guadalupe, Cebu City, Cebu`.
- Header metadata now appears as one compact line with report, status, alert, and generated time; Hub topology and manual label are retained in Source Snapshot.
- Executive summary now leads with People at Risk, Access to Help, and Response Progress, with Decision Points ordered as life safety, access, then resource posture.
- Executive summary now separates Gaps from Accomplishments: Gaps contains People at Risk, Access to Help, and Response Progress; Accomplishments contains People Helped, Handled Incidents, and Teams / Resources Deployed.
- Executive summary now includes People Helped and Accomplishments for resolved reports, including handled incident type, resolved family/person counts, completed team assignments, and resource units removed from current demand.
- Guadalupe sample report uses exactly 28 source incidents: 17 active, 1 deferred, 9 resolved history, and 1 discarded/excluded.
- Guadalupe sample Addressed This Period now shows `9 resolved reports`, `13 families / 62 people addressed`, `3 patient records`, `13 completed team assignments`, and `83 resource units no longer counted as current demand`.
- Guadalupe sample current posture shows 18 open reports, 23 current assignments, and 183 current requested resource units.
- Current Areas of Concern now renders grouped concern rows instead of one card per open incident.
- Operational Response is now Response Posture, with Team Deployment grouped by official team category and team and split into every official assignment status column: requested, assigned, accepted, en route, on scene, completed, and cancelled.
- Response Posture now includes a single scenario-specific Assignment Timing table with simplified milestone labels and Time in Status; timing is not averaged across different incidents.
- Reported Damage and Affected People now render grouped summary tables instead of individual incident cards.
- Affected People now separates Population Summary from Declared Member Breakdown; the Guadalupe sample shows `24` children and `3` senior citizens only in the breakdown table.
- Rendered report no longer exposes raw structured JSON arrays in damage, population, or road/access sections.
- Damage, affected-family, missing-person, and road/access details render as readable evidence rows.
- Gaps now render as decision-risk items with category, decision relevance, evidence, and confidence notes.
- Resource posture now uses configured resource categories instead of heuristic capability groups.
- Resource Needs no longer repeats `open` in a posture column; current scope is explained by the table note.
- Resource category detail now appears only in Current Resource Posture; gaps reference that section without repeating the table.
- Resource category master data has been renamed through a DB migration and reference-data update.
- `release.json` update flags now require database migration and data prep rerun.

## Key Content Rules Confirmed

- Decision makers need a concise situation assessment, decision points, and implications.
- The SITREP should not include a “Command Action” section yet.
- The executive summary should separate current gaps from accomplishments.
- The Gaps row includes People at Risk, Access to Help, and Response Progress.
- The Accomplishments row includes People Helped, Handled Incidents, and Teams / Resources Deployed.
- Current operating counts are traceability support and should render as a compact Current totals line, not a large dashboard block.
- People helped and resolved accomplishments should be prominent, but the report should not claim “lives saved” unless source fields explicitly support that claim.
- Current operating picture includes active and deferred reports only.
- Resolved reports are addressed history and should not contribute to current resource demand or current assignment load.
- Discarded reports are excluded from operational posture, demand, severity, and public summary.
- Deferred means the report remains open while response or coordination is underway.
- The headline must not combine a dominant type and top location unless they actually co-occur in the source reports.
- Population totals should not be consolidated when source fields may overlap.

## Content Contract

The revised SITREP should present decision meaning first, with supporting evidence lower in the report.

1. `Executive Situation Assessment`
   - Short decision-maker narrative.
   - Based on active/deferred incidents only.
   - Resolved incidents may be mentioned only as addressed period history.
   - Discarded incidents are excluded.

2. `Decision Points`
   - Frames choices leadership may need to make or delegate.
   - Does not prescribe operator-level tasks.
   - Uses language such as "may require", "may affect", "may warrant", and "should be considered".

3. `Current Operating Picture`
   - Active/deferred incident count.
   - Active count.
   - Deferred count.
   - Current assignment load from active/deferred incidents only.
   - Current requested resource units from active/deferred incidents only.
   - Status note defining deferred.

4. `Current Areas of Concern`
   - Area-by-area summaries for active/deferred incidents.
   - Avoids "hotspot" when the data does not prove a true cluster.
   - Does not merge top type and top location unless they co-occur in source incidents.

5. `Resource Posture`
   - Current demand only from active/deferred incidents.
   - Configured resource categories should be preferred over heuristic capability grouping.
   - Raw resource table remains supporting evidence.
   - Resource Needs table includes resource, category, quantity, and incident count.
   - Resolved resource rows are treated as addressed history.
   - Discarded resource rows are excluded.

6. `Population and Life-Safety Signals`
   - Active/deferred only for current posture.
   - Avoids a single consolidated population total when fields may overlap.
   - Uses categorized signals: evacuation, displacement, medical, missing person, vulnerable population.
   - Resolved population impact belongs in period history.
   - Discarded population data is excluded.

7. `Access, Infrastructure, and Continuity`
   - Active/deferred access constraints and infrastructure issues.
   - Resolved access constraints belong in period history unless still materially affecting current posture.

8. `Period Activity`
   - Total valid reports in period.
   - Open at close.
   - Resolved during period.
   - Discarded/excluded count.
   - Resolved incidents are presented as addressed history.

9. `Verification Notes`
   - Specific caveats, not generic warnings.
   - Must identify overlapping population risk, requested-vs-supplied resource meaning, road/access verification, and discarded exclusion.

10. `Response Constraints and Confidence Gaps`
    - Separates operational constraints from information limits.
    - Each item should include category, decision relevance, evidence, and confidence note.
    - Uses this section for resource supply uncertainty, road/access constraints, population overlap risk, location gaps, and status-scope rules.
    - Resource demand evidence should point to Current Resource Posture for category detail instead of duplicating the category table.

11. `Supporting Tables`
    - Incident type mentions.
    - Location distribution.
    - Assignment table.
    - Resource table.
    - Damage/population evidence rows.

12. `Source and Privacy Notes`
    - Source snapshot and privacy redaction defaults.
    - Hotline version/build should appear as snapshot source metadata, not as part of the executive narrative.

## Counting Rules by Section

| Section | Active | Deferred | Resolved | Discarded |
|---|---:|---:|---:|---:|
| Executive Situation Assessment | Include | Include | History only | Exclude |
| Decision Points | Include | Include | Exclude unless residual impact is explicit | Exclude |
| Current Operating Picture | Include | Include | Exclude | Exclude |
| Current Areas of Concern | Include | Include | Exclude | Exclude |
| Resource Posture | Include | Include | Exclude from current demand | Exclude |
| Population and Life-Safety Signals | Include | Include | Exclude from current counts | Exclude |
| Access, Infrastructure, Continuity | Include | Include | Exclude unless residual impact is explicit | Exclude |
| Period Activity | Include | Include | Include as addressed | Count only as excluded |
| Supporting Tables | Include | Include | Include under history/supporting detail | Exclude by default from operational tables |

## Implementation Notes

- Main implementation point: `app/Support/Sitreps/SitrepGenerationService.php`.
- Main rendering point: `resources/views/pages/sitrep/partials/document.blade.php`.
- Main test file: `tests/Feature/Command/SitrepGenerationTest.php`.
- Existing reports are snapshots; to view revised content, generate a new SITREP from the same or comparable incident set.
