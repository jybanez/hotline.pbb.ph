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
- Consolidation into a SITREP-like output payload.
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
