# PBB SITREP Consolidator SDK Proposal

## Purpose

The SITREP Consolidator SDK should merge multiple generated SITREPs into one higher-level consolidated SITREP.

Example:

```text
Barangay 1 SITREP
Barangay 2 SITREP
Barangay 3 SITREP
        ↓
City/Municipality consolidated SITREP
```

The SDK is transport-agnostic. It should not care whether source SITREPs arrived through PBB Relay, email, file upload, direct API import, local database access, or manual operator import.

Its job starts when SITREP payloads are already available in memory, storage, or an intake queue. Its job ends when it returns a consolidated SITREP payload plus validation and data-quality metadata.

The SDK should also be application-agnostic. Hotline is the first SITREP source and the first proving ground, but city, municipality, province, region, and national PBB PHP applications must be able to vendor the SDK without installing Hotline.

## Non-Goals

The SDK should not own:

- Hotline-specific database models, controllers, routes, or UI.
- Relay delivery, retries, inbox/outbox, hub-to-hub auth, or transport envelopes.
- Email parsing, attachment download, or mailbox polling.
- Human approval, publication, or release policy.
- Local incident editing or source hub data correction.
- Media transfer or full raw incident synchronization.
- Stale, missing-report, or readiness policy.
- UI rendering.

These concerns can wrap around the SDK, but should not be inside the SDK core.

## Package Boundary

The consolidator should be designed as a vendorable PHP package, not as a Hotline-only internal service.

Recommended package identity:

```text
pbb/sitrep-consolidator
```

Recommended package namespace:

```text
Pbb\Sitreps\Consolidation
```

Hotline can vendor the package locally during early development, but the package should not depend on Laravel app classes such as Eloquent models, controllers, requests, jobs, or config files.

Allowed dependencies for v0.1:

- PHP arrays and value objects
- date/time parsing through standard PHP or a small explicit dependency
- optional PSR interfaces if needed later

Avoid in SDK core:

- `App\...` namespaces
- Laravel facades
- Eloquent models
- filesystem globals as hidden state
- Relay-specific classes
- Hotline-specific enums

Application adapters can live outside the core package. For example:

```text
HotlineSitrepPayloadAdapter
RelayInboxSitrepAdapter
EmailAttachmentSitrepAdapter
```

Those adapters may belong in their consuming apps. The core consolidator should only require normalized arrays/value objects.

## Core API Shape

The first SDK interface should be small and deterministic:

```php
$result = $consolidator->consolidate(
    sourceSitreps: [$barangayOne, $barangayTwo, $barangayThree],
    context: [
        'target_level' => 'city',
        'target_hub_id' => '072217000',
        'target_hub_name' => 'Cebu City, Cebu',
        'coverage_area' => 'Cebu City, Cebu',
        'period_started_at' => '2026-05-29T16:00:00+08:00',
        'period_ended_at' => '2026-05-29T17:00:00+08:00',
    ],
);
```

The result should contain:

```php
[
    'ok' => true,
    'sitrep' => [/* consolidated SITREP payload */],
    'warnings' => [/* non-blocking issues */],
    'errors' => [/* blocking issues, empty when ok=true */],
    'source_index' => [/* normalized source report inventory */],
]
```

The SDK may later expose convenience methods such as `validate()`, `normalize()`, and `buildBreakdownIndex()`, but `consolidate()` should remain the main entry point.

For intake workflows where SITREPs arrive over time, the SDK should also expose grouping and staging helpers:

```php
$groups = $consolidator->groupByDeployment($sourceSitreps);

$staging->stage($incomingSitrep);
$batch = $staging->forDeployment('barangay');
```

The host app decides whether the latest staged SITREPs are stale, complete enough, or ready to consolidate. The SDK should classify, collect, and consolidate SITREPs, but it should not decide LGU operational readiness policy by itself.

## Input Contract

The SDK should accept current Hotline SITREP JSON as the first supported source format.

Minimum source fields:

- `title`
- `coverage_area`
- `period_started_at`
- `period_ended_at`
- `generated_at`
- `alert_level`
- `summary`
- `situation`
- `damage`
- `population`
- `actions`
- `needs`
- `gaps`
- `source_snapshot`
- `privacy_redactions`
- `data_quality`

Recommended source metadata:

- source SITREP ID or sequence number
- source hub ID from PBB HUB HQ
- source hub name
- source reporting level
- source deployment from `source_snapshot.hub_node.snapshot.deployment`
- source coverage area label
- source content hash
- schema version
- adapter version
- counting rule version

The SDK should normalize every accepted source into an internal `NormalizedSitrep` structure before merging. This prevents the merge rules from depending on one transport or one database schema.

For current Hotline SITREPs, the authoritative source reporting level is:

```text
source_snapshot.hub_node.snapshot.deployment
```

Example value:

```text
barangay
```

For current Hotline SITREPs, the staging filename should use the PBB HUB HQ system-generated hub ID:

```text
source_snapshot.hub_node.snapshot.hub_id
```

Do not use optional geographic or administrative codes such as `relay_hub_id`, `brgy_code`, `citymun_code`, or app-local codes for staging filenames. Those codes may be useful metadata, but `hub_id` is the unique system-generated key.

## Output Contract

The consolidated output should be SITREP-like, not transport-like:

```php
[
    'title' => 'City SITREP - Cebu City, Cebu',
    'coverage_area' => 'Cebu City, Cebu',
    'coverage_level' => 'city',
    'period_started_at' => '2026-05-29T16:00:00+08:00',
    'period_ended_at' => '2026-05-29T17:00:00+08:00',
    'generated_at' => '2026-05-29T17:02:00+08:00',
    'alert_level' => 'Critical',
    'summary' => [],
    'situation' => [],
    'damage' => [],
    'population' => [],
    'actions' => [],
    'needs' => [],
    'gaps' => [],
    'source_snapshot' => [],
    'privacy_redactions' => [],
    'data_quality' => [],
]
```

This payload can later be stored as a local `sitrep_reports` row, relayed onward, rendered in an LGU surface, emailed, or exported.

## Merge Rules

### Reporting Window

The target period should be explicit in the consolidation context.

Source SITREPs should be classified as:

- `inside_period`: source period matches the target period.
- `overlaps_period`: source period overlaps but does not exactly match.
- `outside_period`: source period does not overlap.
- `missing_period`: source period is absent or invalid.

The first implementation should accept exact matches and overlapping reports with warnings. It should reject outside or missing periods unless the caller explicitly permits partial consolidation.

### Source Deployment Level

All source SITREPs in one consolidation batch must come from the same reporting deployment level.

For current Hotline payloads, validate:

```text
source_snapshot.hub_node.snapshot.deployment
```

Examples:

- A city consolidation may merge multiple `barangay` source SITREPs.
- A province consolidation may merge multiple `city` or `municipality` source SITREPs, depending on the parent coverage model.
- A region consolidation may merge multiple `province` source SITREPs.

The SDK must reject a mixed source batch such as:

```text
barangay + city
barangay + municipality
city + province
```

Reason: mixed deployment levels cause double counting and unclear provenance. A city-level SITREP may already include multiple barangay SITREPs, so merging it beside raw barangay SITREPs could count the same incidents twice.

The SDK should return a blocking validation error:

```php
[
    'severity' => 'error',
    'code' => 'mixed_source_deployment',
    'message' => 'Source SITREPs must have the same hub deployment level before consolidation.',
    'path' => 'source_snapshot.hub_node.snapshot.deployment',
    'values' => ['barangay', 'city'],
]
```

Missing deployment should also be a blocking error unless the caller explicitly passes a trusted source-level override during normalization.

Before consolidation, callers may use SDK grouping to split mixed intake batches:

```php
$groups = $consolidator->groupByDeployment([
    $barangayOne,
    $barangayTwo,
    $cityOne,
    $cityTwo,
    $barangayThree,
]);
```

Expected output:

```php
[
    'barangay' => [$barangayOne, $barangayTwo, $barangayThree],
    'city' => [$cityOne, $cityTwo],
]
```

`groupByDeployment()` should not consolidate. It should only normalize enough metadata to classify source SITREPs and return validation issues for unclassifiable inputs.

## Staging Model

In production, source SITREPs will not arrive at the same time. Some hubs will report early, some late, and some may not report during a target consolidation window.

The SDK should support an optional staging model that a host app can back with folders, database rows, object storage, or another persistence layer.

Conceptual staging layout:

```text
staging/
  barangay/
    12.json
    13.json
    14.json
  city/
    21.json
    22.json
  invalid/
    missing-deployment/
    schema-error/
```

Staging should be treated as a latest-by-hub working set, not a historical archive.

When a new SITREP arrives from the same source hub and deployment, it replaces that hub's staged file:

```text
staging/barangay/12.json
```

This keeps the most recent available SITREP for each source hub even if the host app has not consumed it yet. Historical drill-down should go directly to the source hub's Hotline instance or to the app's own intake/archive system, not to the SDK staging folder.

Recommended SDK responsibilities:

- classify an incoming SITREP by deployment level
- compute a stable source key from the PBB HUB HQ `hub_id`
- write or return a staging destination key
- return validation issues for invalid SITREPs
- list staged reports for a deployment
- overwrite the latest staged SITREP for the same source hub

Recommended host app responsibilities:

- choose the actual storage backend
- decide expected source hubs
- decide whether a staged SITREP is stale
- decide whether a staged SITREP belongs to the target consolidation window
- decide whether to consolidate partial or incomplete batches
- decide approval/publication workflow
- decide retention and archival policy

The SDK should define the staging contract, but the first implementation can use an in-memory or filesystem adapter for tests and local workflows.

Suggested interfaces:

```php
interface SitrepStagingStore
{
    public function stage(array $normalizedSitrep): StagedSitrep;
    public function list(string $deployment): array;
    public function forget(string $deployment, string $sourceHubId): void;
}
```

Suggested staged statuses:

- `pending`
- `consolidated`
- `rejected`

The SDK should still allow direct consolidation without staging for simple callers.

### Alert Level

Consolidated alert level should be the highest severity among source SITREPs:

```text
Critical > Elevated > Normal
```

The output should preserve which source hubs contributed to the chosen level.

### Counts And Metrics

Counts can be summed only when the source metric is known to be additive and the source reports are deduplicated.

Safe first-pass additive metrics:

- total incidents
- active/deferred/open reports
- resolved reports
- discarded/excluded reports
- team assignment counts
- requested resource units
- population counts when explicitly numeric

Non-additive or caution metrics:

- dominant incident type
- hotspot area
- posture label
- confidence note
- narrative text
- averages or elapsed time labels

For caution metrics, the SDK should recompute from breakdowns when possible or carry a warning.

### Breakdown Merging

The SDK should merge breakdown arrays by stable keys:

- incident type name or code
- concern group
- resource category
- resource name
- team category
- action/deployment status
- area or hub coverage label
- data-quality issue type

Each merged row should include source provenance:

```php
[
    'resource' => 'Rescue Boat',
    'category' => 'Transport',
    'quantity_requested' => 6,
    'sources' => [
        ['source_hub_id' => '072217029', 'quantity_requested' => 3],
        ['source_hub_id' => '072217030', 'quantity_requested' => 3],
    ],
]
```

### Narrative Text

The SDK should not concatenate child narratives as the primary consolidated narrative.

It should generate new consolidated narrative from merged facts:

- number of reporting hubs included
- alert posture
- open report totals
- top incident types
- top areas or contributing hubs
- leading needs and gaps
- data-quality warnings

Child narratives can be preserved in provenance or appendix data, but not used as the consolidated executive assessment verbatim.

### Deduplication

The SDK should deduplicate source SITREPs before merging.

Preferred dedupe keys, in order:

1. `source_snapshot.summary_hash` or source content hash
2. source hub ID + source sequence number
3. source hub ID + period + generated timestamp
4. source hub ID + title + period

For direct consolidation input, duplicate detection should prevent the same source report from being counted twice. For staged intake, the behavior is simpler: staging keeps only the latest report per source hub and deployment. Historical supersession and freshness policy remain host-owned.

### Missing, Stale, And Partial Sources

The SDK output should preserve enough source metadata for the host app to distinguish:

- `zero_reported`: source reported zero for a metric.
- `not_received`: expected source SITREP is missing.
- `stale`: source SITREP is older than expected.
- `partial`: source report is present but missing required sections.
- `invalid`: source report failed validation.

This is essential for LGU leadership. "No damage reported" and "Barangay did not send a report" are different operational facts.

However, v0.1 SDK core should not decide whether a staged SITREP is stale, whether a source is expected, or whether missing reports should block consolidation. Those are host app policies because they depend on LGU operating rules, expected hub lists, reporting schedules, and leadership discretion.

## Provenance

Every consolidated SITREP should include a `source_snapshot` that records:

- consolidation type and SDK version
- target hub identity
- target reporting level
- target reporting period
- source SITREP count
- source hub count
- accepted source SITREPs
- rejected source SITREPs
- deduplicated source SITREPs
- source hashes or sequence references
- merge rule version
- warnings

Example:

```php
'source_snapshot' => [
    'generation' => [
        'type' => 'consolidated',
        'sdk' => 'pbb-sitrep-consolidator',
        'sdk_version' => '0.1.0',
        'merge_rule_version' => 1,
    ],
    'target' => [
        'hub_id' => '072217000',
        'name' => 'Cebu City, Cebu',
        'level' => 'city',
    ],
    'source_sitreps' => [
        [
            'source_hub_id' => '072217029',
            'source_hub_name' => 'Guadalupe, Cebu City, Cebu',
            'sequence_number' => 55,
            'period_started_at' => '2026-05-29T16:00:00+08:00',
            'period_ended_at' => '2026-05-29T17:00:00+08:00',
            'generated_at' => '2026-05-29T17:01:00+08:00',
            'hash' => '...',
            'status' => 'accepted',
        ],
    ],
]
```

## Validation Severity

Validation should return structured issues instead of throwing for every problem.

Suggested severity levels:

- `error`: cannot consolidate safely.
- `warning`: can consolidate, but output must carry caveat.
- `info`: informational normalization detail.

Examples:

- `error`: missing `period_started_at`
- `error`: invalid SITREP schema version
- `error`: mixed `source_snapshot.hub_node.snapshot.deployment` values in one consolidation batch
- `error`: missing source deployment and no trusted source-level override
- `warning`: overlapping period
- `warning`: missing `needs` section
- `warning`: duplicate source hub report ignored
- `info`: unknown optional metric skipped

## First Implementation Scope

Version 0.1 should implement only the core SDK:

- `SitrepConsolidator`
- `SitrepConsolidationResult`
- `SitrepNormalizer`
- `SitrepValidationIssue`
- deterministic merge rules for summary metrics, needs, actions, population, damage, gaps, and data-quality sections
- `groupByDeployment()` helper
- staging contract with an in-memory or filesystem test adapter
- provenance/source index generation
- focused PHP tests using synthetic barangay SITREP payloads
- developer manual
- working demo that can run without Hotline

Version 0.1 should not implement:

- Relay submission or inbox polling
- email import
- database migrations
- admin UI
- public page rendering
- approval workflow

## Developer Manual

The SDK must ship with a developer manual aimed at PHP application developers who are not working inside Hotline.

Suggested path:

```text
packages/pbb-sitrep-consolidator/docs/developer-manual.md
```

The manual should include:

- package purpose and boundaries
- installation through Composer path repository
- installation as a future standalone Composer package
- minimum supported PHP version
- required input SITREP shape
- how to extract `hub_id` and deployment from current Hotline SITREP payloads
- how to call `groupByDeployment()`
- how to stage latest SITREPs by deployment and hub ID
- how to consolidate one deployment group
- how to inspect validation errors and warnings
- how to read provenance/source index output
- how host apps should own stale/missing/readiness policy
- how host apps can wrap the SDK with Relay, email, upload, or local file intake
- examples for city and province apps that do not have Hotline installed
- troubleshooting section for missing `hub_id`, mixed deployments, invalid periods, and unsupported schema versions

The manual should avoid assuming Laravel. If Laravel examples are useful, place them in a clearly marked optional section.

## Working Demo

The SDK must include a working demo that proves a non-Hotline PHP app can vendor and use it.

Suggested path:

```text
packages/pbb-sitrep-consolidator/demo/
```

Minimum demo contents:

```text
demo/
  README.md
  composer.json
  consolidate.php
  input/
    barangay/
      12.json
      13.json
      14.json
  staging/
    .gitkeep
  output/
    .gitkeep
```

Demo behavior:

1. Load sample SITREP JSON files from `demo/input/barangay`.
2. Stage them into latest-by-hub layout:

```text
demo/staging/barangay/12.json
demo/staging/barangay/13.json
demo/staging/barangay/14.json
```

3. Read staged barangay SITREPs.
4. Consolidate them into a city-level SITREP payload.
5. Write the result to:

```text
demo/output/city-sitrep.json
```

6. Print a short CLI summary:

```text
Sources: 3 barangay SITREPs
Target: city
Alert level: Critical
Output: demo/output/city-sitrep.json
Warnings: 0
```

The demo should run with plain PHP:

```bash
php demo/consolidate.php
```

It should not require:

- Hotline
- Laravel
- Relay
- database access
- web server

The demo may use sample anonymized/generated SITREP JSON fixtures. If real exported SITREPs are used, they must be safe for repository storage.

## Suggested Namespace

Use a package-neutral namespace for the SDK:

```text
Pbb\Sitreps\Consolidation
```

Suggested package files:

```text
packages/pbb-sitrep-consolidator/src/SitrepConsolidator.php
packages/pbb-sitrep-consolidator/src/SitrepNormalizer.php
packages/pbb-sitrep-consolidator/src/SitrepConsolidationResult.php
packages/pbb-sitrep-consolidator/src/SitrepValidationIssue.php
packages/pbb-sitrep-consolidator/src/SitrepMergeRules.php
packages/pbb-sitrep-consolidator/src/Staging/SitrepStagingStore.php
packages/pbb-sitrep-consolidator/src/Staging/InMemorySitrepStagingStore.php
packages/pbb-sitrep-consolidator/docs/developer-manual.md
packages/pbb-sitrep-consolidator/demo/consolidate.php
```

Suggested Composer path repository for local development in Hotline:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/pbb-sitrep-consolidator",
      "options": { "symlink": false }
    }
  ],
  "require": {
    "pbb/sitrep-consolidator": "*"
  }
}
```

Later, the same package can be moved to a standalone repository and required by city/province apps without bringing Hotline along.

## Test Plan

Minimum tests:

- consolidates three barangay SITREPs into one city SITREP
- groups mixed SITREPs by deployment before consolidation
- stages incoming SITREPs into latest-by-hub deployment buckets
- names staged files by PBB HUB HQ `hub_id`, not optional administrative codes
- overwrites a source hub's staged SITREP with its newest received SITREP
- lists latest staged SITREPs for one deployment
- removes a staged SITREP by deployment and source hub
- rejects a mixed source deployment batch such as barangay plus city
- rejects a source SITREP with missing deployment metadata unless a trusted override is supplied
- chooses highest alert level
- sums additive metrics
- merges resource needs by category/resource
- merges population counts
- preserves source provenance
- warns on overlapping period
- rejects missing required period fields
- demo script runs successfully and writes a consolidated output JSON
- developer manual documents install, staging, grouping, consolidation, and validation handling

## Open Decisions

- Should v0.1 accept full Hotline SITREP payloads only, or also define a smaller `sitrep_rollup` input?
- Should consolidated output be persisted immediately as `sitrep_reports`, or should persistence stay outside the SDK?
- What exact system code should consuming apps use when they later wrap the SDK with Relay?
- What source hash should be canonical: full payload hash, summary hash, or section hashes?
- Should the SDK expose helper methods that support host-owned freshness checks without deciding stale/missing policy itself?
- Should the package start as a path repository under Hotline, or should it be created immediately as a standalone PBB package repository?
