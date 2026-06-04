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

The affected sections are:

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

The Hotline command API and Blade preview unwrap `rollup` for existing UI compatibility. Exported SITREP JSON and Relay payloads use schema v2.

The framework-agnostic viewer SDK accepts both legacy flat sections and schema v2 sections. The consolidator SDK also accepts both shapes and emits schema v2.

## Legacy Cleanup

Use the cleanup command to convert stored legacy SITREP JSON sections to schema v2:

```bash
php artisan app:normalize-sitrep-payload-schema --dry-run
php artisan app:normalize-sitrep-payload-schema
```

Run the dry run first. The command is idempotent: already-normalized SITREPs are skipped.
