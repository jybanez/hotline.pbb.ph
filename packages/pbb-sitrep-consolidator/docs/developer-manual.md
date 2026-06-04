# PBB SITREP Consolidator SDK Developer Manual

## Purpose

`pbb/sitrep-consolidator` is a transport-agnostic PHP SDK for collecting and consolidating generated PBB SITREP JSON payloads.

The SDK does not require Hotline. City, municipality, province, region, and national PBB PHP applications can vendor it and consolidate SITREPs from any intake source.

## Boundaries

The SDK owns:

- SITREP payload normalization.
- Validation errors and warnings.
- Grouping by deployment.
- Latest-by-hub staging helpers.
- Consolidation into a viewer-compatible SITREP output payload.
- Source provenance output.

The host app owns:

- Relay, email, upload, or file intake.
- Stale/missing/readiness policy.
- Expected hub lists.
- Approval and publication workflow.
- Historical drill-down.
- Persistence and retention.

## Install During Local Development

When the package lives inside a consuming app as a path repository:

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

Then run:

```bash
composer update pbb/sitrep-consolidator
```

This branch also wires the namespace directly for local tests:

```text
Pbb\Sitreps\Consolidation\
```

## Required Source Metadata

Current Hotline SITREPs provide the required source metadata under:

```text
source_snapshot.hub_node.snapshot.hub_id
source_snapshot.hub_node.snapshot.deployment
source_snapshot.hub_node.snapshot.name
```

`hub_id` is the PBB HUB HQ system-generated unique ID. Use it for staging filenames:

```text
staging/barangay/12.json
```

Do not use optional administrative codes such as `relay_hub_id`, `brgy_code`, or `citymun_code` as staging filenames.

## Group By Deployment

```php
use Pbb\Sitreps\Consolidation\SitrepConsolidator;

$consolidator = new SitrepConsolidator();
$grouped = $consolidator->groupByDeployment($sitreps);

$barangaySitreps = $grouped['groups']['barangay'] ?? [];
$issues = $grouped['issues'];
```

Grouping does not consolidate. It only classifies SITREPs by:

```text
source_snapshot.hub_node.snapshot.deployment
```

## Stage Latest SITREPs By Hub

```php
use Pbb\Sitreps\Consolidation\SitrepNormalizer;
use Pbb\Sitreps\Consolidation\Staging\FilesystemSitrepStagingStore;

$normalizer = new SitrepNormalizer();
$staging = new FilesystemSitrepStagingStore(__DIR__.'/staging');

$normalized = $normalizer->normalize($incomingSitrep)['normalized'];
$staging->stage($normalized);
```

If another SITREP arrives from the same deployment and `hub_id`, staging overwrites the previous file.

Filesystem staging validates deployment and hub ID as safe path segments before writing files. Values containing path separators or traversal segments are rejected.

Both SDK staging stores return normalized SITREP records from `list($deployment)`. Pass those records directly to `consolidate()`.

Staging is not history. Historical drill-down should go to the source hub's Hotline instance or to the host app's own archive.

## Consolidate One Deployment Group

```php
$result = $consolidator->consolidate($barangaySitreps, [
    'target_level' => 'city',
    'target_hub_id' => '21',
    'target_hub_name' => 'Cebu City, Cebu',
    'coverage_area' => 'Cebu City, Cebu',
    'period_started_at' => '2026-05-29T17:00:00+08:00',
    'period_ended_at' => '2026-05-29T17:15:00+08:00',
]);

if (! $result->ok) {
    print_r($result->toArray()['errors']);
}

$citySitrep = $result->sitrep;
```

`consolidate()` rejects mixed deployment batches. Use `groupByDeployment()` first when the intake set may contain barangay and city SITREPs together.

Direct consolidation also rejects duplicate `hub_id` values. Stage incoming SITREPs first when a host app wants latest-by-hub overwrite behavior.

## Consolidated Output Contract

The output is intended to be passed directly to `pbb/sitrep-viewer` and relayed
upstream as a consolidated SITREP. It includes the normal top-level SITREP
fields:

```text
title
coverage_area
coverage_level
period_started_at
period_ended_at
generated_at
status
visibility
alert_level
summary
situation
damage
population
actions
needs
gaps
source_snapshot
privacy_redactions
data_quality
```

Default `status` is `draft` and default `visibility` is `private`. A host app
may override those in the consolidation context when leadership approves a
different publication workflow.

When context does not provide `period_started_at` or `period_ended_at`, the SDK
uses the earliest source start and latest source end. This prevents a
consolidation batch from accidentally inheriting only the first source period.

`source_snapshot.generation` identifies the consolidator SDK, version, merge
rule, and prepared-by label. `source_snapshot.target` records the receiving hub
or organization, while `source_snapshot.source_sitreps` records accepted source
provenance. If sources contain `source_snapshot.incident_coordinates`, the SDK
rolls them up into `source_snapshot.incident_coordinates` and adds
`source_hub_id` to each coordinate entry.

Population and other numeric fields are summed only for planning awareness. The
SDK emits `population.numeric_total_note`, `population.confidence_note`, and
`data_quality.global_note` to make clear that source values may overlap and
should be validated by the host app before operational use.

## Validation Issues

Each issue includes:

- `severity`: `error`, `warning`, or `info`
- `code`
- `message`
- `path`
- `source_index`

Common errors:

- `missing_required_field`
- `missing_source_deployment`
- `missing_source_hub_id`
- `mixed_source_deployment`
- `duplicate_source_hub`
- `empty_source_batch`

## Demo

Run the demo from the package directory:

```bash
php demo/consolidate.php
```

The demo reads sample barangay SITREPs, stages them by deployment and hub ID, consolidates them into a city SITREP, and writes:

```text
demo/output/city-sitrep.json
```
