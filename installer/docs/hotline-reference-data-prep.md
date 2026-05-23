# Hotline Reference Data Prep

`tools/populate-initial-data.php` is Hotline's Data Prep entrypoint for non-transactional reference data. `tools/data-prep/apply-settings.php` applies runtime Realtime settings supplied by Kit/Realtime. Data Prep does not load operators, users, incident records, call sessions, assignments, dispatch defaults, or other operational history.

## Command

```powershell
php tools\populate-initial-data.php --mode initial --config C:\path\to\kit-config.json --dry-run --report C:\path\to\hotline-reference-data-prepare.json
php tools\populate-initial-data.php --mode initial --config C:\path\to\kit-config.json --report C:\path\to\hotline-reference-data-prepare.json
php tools\data-prep\apply-settings.php --mode initial --config C:\path\to\kit-config.json --report C:\path\to\hotline-apply-settings.json
php tools\data-prep\verify.php --mode initial --config C:\path\to\kit-config.json --report C:\path\to\hotline-reference-data-verify.json
```

Set `hotline.populate.enabled=true` in the config. If no usable source is configured, Hotline falls back to its packaged source file at `resources/data/hotline/reference-data.json`.

In `release.json`, this maps to Data Prep version 1:

- `prepare_data`: `tools/populate-initial-data.php`
- `apply_settings`: `tools/data-prep/apply-settings.php`
- `verify`: `tools/data-prep/verify.php`

The packaged source currently contains 215 reference records. The verify tool checks the nine required reference tables against the packaged minimum counts and, when `hotline.data_prep.apply_settings` is present or `hotline.data_prep.verify.require_realtime_settings=true`, verifies the expected Realtime runtime settings.

## Realtime Apply Settings

Hotline Apply Settings writes these runtime settings into the `settings` table:

- `realtime_url`
- `realtime_client_code`
- `realtime_project_code_server`
- `realtime_project_code_caller`
- `realtime_project_code_operator`
- `realtime_project_code_command`
- `realtime_project_code_media_ingest`
- `realtime_backend_ingress_secret`
- `realtime_media_ingest_secret`
- `realtime_token_signing_secret`

Stable Realtime values for the current Kit contract:

```json
{
  "hotline": {
    "data_prep": {
      "apply_settings": {
        "realtime": {
          "base_url": "https://realtime.pbb.ph",
          "client_code": "clt_PBB_HOTLINE",
          "project_code_server": "prj_HOTLINE_SERVER",
          "project_code_citizen": "prj_HOTLINE_CITIZEN",
          "project_code_operator": "prj_HOTLINE_OPERATOR",
          "project_code_command": "prj_HOTLINE_COMMAND",
          "project_code_media_ingest": "prj_HOTLINE_OPERATOR"
        }
      }
    }
  }
}
```

Media ingest intentionally uses the Hotline Operator project scope: `prj_HOTLINE_OPERATOR`. There is no dedicated `prj_HOTLINE_MEDIA` scope in the current Realtime Data Prep contract.

## Source Contract

Preferred source:

```json
{
  "hotline": {
    "populate": {
      "enabled": true,
      "sources": {
        "reference_data": "resources/data/hotline/reference-data.json"
      },
      "options": {
        "overwrite_existing": true,
        "include_demo_data": false,
        "deactivate_missing": false
      }
    }
  }
}
```

`reference_data` must point to a JSON object with these array properties:

- `incident_categories`: `name`, optional `description`, optional `sort_order`
- `incident_types`: `name`, `category`, optional `description`
- `incident_type_fields`: `incident_type`, `field_key`, `field_label`, `input_type`, optional `options`, `config`, `default_value`, `placeholder`, `unit`, `is_required`, `sort_order`, `min`, `max`, `step`
- `resource_type_categories`: `name`, optional `description`, optional `sort_order`
- `resource_types`: `name`, `category`, optional `unit_label`
- `incident_type_default_resources`: `incident_type`, `resource_type`, optional `quantity_required`, `notes`, `sort_order`
- `team_categories`: `name`, optional `description`, optional `sort_order`
- `teams`: `name`, `category`, optional `status`
- `team_resource_inventories`: `team`, `resource_type`, optional `quantity_available`

The importer resolves relationships by stable names, not numeric IDs. Reruns are idempotent. With `overwrite_existing=true`, existing matching rows are updated; with `false`, existing rows are left untouched and only missing rows are created.

## Split Sources

Kit may also provide split source files:

- `incident_catalog`: `incident_categories`, `incident_types`, `incident_type_fields`, `incident_type_default_resources`
- `resource_catalog`: `resource_type_categories`, `resource_types`
- `teams`: `team_categories`, `teams`, `team_resource_inventories`

`operators` and `dispatch_defaults` source keys are deprecated for this first standalone Data Prep scope and are ignored with a report warning.

## Bundle Handoff

Data Prep changes are operator-facing only after they are rebuilt into the trusted canonical bundle:

```text
pbb-hotline-5.6.1.zip
```

Do not hand Kit Setup suffixed bundles such as `-data-prep`, `-hotfix`, or `-test`. The package name should match the app code and version declared in `release.json`.
