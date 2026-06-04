# SITREP Payload Schema V2

SITREP payload schema v2 preserves the same report sections used by Hotline and the SDKs, but each operational section is stored and exported as:

```json
{
  "rollup": {},
  "items": [
    {
      "location": {
        "id": "072217029",
        "name": "Guadalupe, Cebu City, Cebu",
        "deployment": "barangay",
        "relay_hub_id": "072217029"
      },
      "data": {}
    }
  ]
}
```

`rollup` is the section content rendered as the main SITREP. `items` preserves per-location source content for consolidated reports and for future upstream rollups. A Hotline-generated single-location SITREP has `location_count = 1`; consolidated SITREPs can contain multiple locations.

Exported and relayed SITREP JSON intentionally wraps these sections:

```text
summary
situation
damage
population
actions
needs
gaps
source_snapshot
data_quality
```

`privacy_redactions` remains a flat section because it describes the payload redaction state rather than an operational location.

## Compatibility

The Hotline runtime remains compatible with existing screens by unwrapping `rollup` in these paths:

- Command API responses.
- Public bootstrap latest SITREP summary.
- Blade preview and public rendering.
- Relay target derivation from `source_snapshot.rollup.hub_node.snapshot.uplinks`.

Exported SITREP JSON and Relay payloads use schema v2.

The framework-agnostic viewer SDK accepts both legacy flat sections and schema v2 sections. The consolidator SDK also accepts both shapes and emits schema v2.

SDK consumers should use the updated Viewer and Consolidator helpers. Consumers must not assume `source_snapshot.hub_node` or operational section fields are always top-level. For schema v2, those fields are under each section's `rollup`.

## Hub Node Metadata

`source_snapshot.rollup.hub_node` and `source_snapshot.rollup.hub_nodes[]` are both canonical, but they mean different things.

`hub_node` is the hub where the current SITREP was generated. For a direct Hotline SITREP, this is the local Hotline hub. For a consolidated SITREP, this is the consolidating city, province, region, NGO, or other receiving app hub.

`hub_nodes[]` is the list of source hubs whose submitted SITREPs were used for consolidation.

Direct Hotline-generated SITREPs include:

```text
source_snapshot.rollup.hub_node
source_snapshot.rollup.hub_nodes = []
```

Direct SITREPs have no submitted source SITREPs, so `hub_nodes[]` is empty. Relay target derivation for direct Hotline SITREPs uses `hub_node.snapshot.uplinks`.

Consolidated SITREPs include:

```text
source_snapshot.rollup.hub_node
source_snapshot.rollup.hub_nodes[]
source_snapshot.items[].data.hub_node
```

Each accepted source hub contributes one `hub_nodes[]` entry. Consumers should use `hub_node` for the current generated SITREP location and `hub_nodes[]` for submitted source SITREP locations.

This document is the source of truth for Support and other PHP apps consuming SITREP JSON from Hotline or from the consolidator SDK.

## Installer And Update Metadata

Schema v2 changes the stored JSON payload shape only. It does not add or alter database tables or columns, so this change does not require a database migration.

The legacy cleanup command is an optional manual/admin maintenance command for transactional SITREP snapshots. It is not part of Hotline Data Prep, which manages fixed reference data and runtime settings. Therefore `release.json.update.requires_data_prep_rerun` remains `false`.

## Legacy Cleanup

Use the cleanup command to convert stored legacy SITREP JSON sections to schema v2:

```bash
php artisan app:normalize-sitrep-payload-schema --dry-run
php artisan app:normalize-sitrep-payload-schema
```

Run the dry run first. The command is idempotent: already-normalized SITREPs are skipped.
