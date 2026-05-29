# PBB SITREP Consolidator SDK Implementation Checklist

## Goal

Build a transport-agnostic, app-agnostic PHP SDK that accepts generated SITREP JSON payloads and produces a consolidated SITREP payload.

The SDK must be vendorable by non-Hotline PBB PHP apps, including city and province apps that do not install Hotline.

## Status Summary

| Area | Status | Notes |
| --- | --- | --- |
| Proposal | Done | See `docs/pbb-sitrep-consolidator-sdk-proposal.md`. |
| Package scaffold | Done | Created `packages/pbb-sitrep-consolidator`. |
| Core SDK | Done | Normalize, group, stage, consolidate. |
| Developer manual | Done | Added non-Hotline developer usage guide. |
| Working demo | Done | Plain PHP, no Hotline/Laravel/Relay/database. |
| Tests | Done | Focused PHPUnit coverage added and passing. |

## 1. Package Scaffold

- [x] Create `packages/pbb-sitrep-consolidator/composer.json`.
- [x] Use package name `pbb/sitrep-consolidator`.
- [x] Use namespace `Pbb\Sitreps\Consolidation`.
- [x] Keep SDK core free of Hotline `App\...` classes.
- [x] Keep SDK core free of Laravel facades and Eloquent models.
- [x] Add local root autoload wiring for branch tests if needed.

## 2. Core Data Contracts

- [x] Add `SitrepConsolidationResult`.
- [x] Add `SitrepValidationIssue`.
- [x] Add normalized source representation.
- [x] Preserve source hub ID from `source_snapshot.hub_node.snapshot.hub_id`.
- [x] Preserve source deployment from `source_snapshot.hub_node.snapshot.deployment`.
- [x] Preserve source hub name, relay hub ID, sequence, period, generated time, and payload hash.

## 3. Normalization And Validation

- [x] Accept current Hotline SITREP JSON.
- [x] Validate required period fields.
- [x] Validate source deployment exists.
- [x] Validate source hub ID exists.
- [x] Validate source deployment is consistent within a consolidation batch.
- [x] Return structured errors/warnings instead of failing silently.
- [x] Keep stale/missing/readiness policy outside SDK core.

## 4. Grouping

- [x] Implement `groupByDeployment(array $sitreps): array`.
- [x] Group using `source_snapshot.hub_node.snapshot.deployment`.
- [x] Return unclassifiable SITREPs with validation issues.
- [x] Do not consolidate inside grouping.

## 5. Staging

- [x] Define `SitrepStagingStore`.
- [x] Implement in-memory staging store for tests/demo.
- [x] Stage by deployment and PBB HUB HQ `hub_id`.
- [x] Use filename/key shape `<hub_id>.json`.
- [x] Overwrite the latest staged SITREP for the same source hub.
- [x] List latest staged SITREPs for one deployment.
- [x] Remove staged SITREP by deployment and hub ID.
- [x] Do not store history in SDK staging.

## 6. Consolidation

- [x] Implement `SitrepConsolidator::consolidate(array $sitreps, array $context): SitrepConsolidationResult`.
- [x] Reject mixed deployment batches.
- [x] Choose highest alert level using `Critical > Elevated > Normal`.
- [x] Sum additive `summary.supporting_metrics`.
- [x] Merge `summary.status_counts`.
- [x] Merge resource needs by category/resource where available.
- [x] Merge population numeric totals where available.
- [x] Preserve source provenance in output `source_snapshot`.
- [x] Generate consolidated summary headline and basic narrative from merged facts.

## 7. Developer Manual

- [x] Create `packages/pbb-sitrep-consolidator/docs/developer-manual.md`.
- [x] Document install through Composer path repository.
- [x] Document plain PHP usage.
- [x] Document grouping, staging, consolidation, validation issues.
- [x] Document host-owned stale/missing/readiness policy.
- [x] Include city/province app examples.

## 8. Working Demo

- [x] Create `packages/pbb-sitrep-consolidator/demo`.
- [x] Include sample SITREP JSON files.
- [x] Include `demo/consolidate.php`.
- [x] Stage samples into latest-by-hub layout.
- [x] Write consolidated output to `demo/output/city-sitrep.json`.
- [x] Run with `php demo/consolidate.php`.
- [x] Avoid Hotline, Laravel, Relay, database, and web server dependencies.

## 9. Tests

- [x] Test grouping mixed barangay/city SITREPs.
- [x] Test staging overwrite by hub ID.
- [x] Test staging list by deployment.
- [x] Test filesystem staging uses `<hub_id>.json`.
- [x] Test successful consolidation of three barangay SITREPs.
- [x] Test mixed deployment consolidation rejection.
- [x] Test missing deployment rejection.
- [x] Test missing hub ID rejection.
- [x] Test highest alert level selection.
- [x] Test additive metric sums.
- [x] Test provenance output.
- [x] Verify demo script writes output.

## 10. Handoff

- [x] Run focused PHPUnit tests.
- [x] Run demo script.
- [x] Run PHP lint on package files.
- [x] Report branch, commit, tests, demo output, and known risks.
