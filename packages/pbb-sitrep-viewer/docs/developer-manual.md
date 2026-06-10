# PBB SITREP Viewer SDK Developer Manual

## Purpose

`pbb/sitrep-viewer` is a framework-agnostic PHP SDK for rendering generated PBB SITREP JSON payloads.

The SDK is meant for any PHP app that receives or stores a SITREP and needs to show the same readable report shape without depending on Hotline, Laravel Blade, Eloquent, Relay, or a database connection.

## Boundaries

The SDK owns:

- Accepting a generated SITREP array or JSON string.
- Normalizing missing optional sections to empty arrays.
- Rendering a complete HTML document or an embeddable HTML fragment.
- Providing default CSS for screen and PDF-style rendering.
- Escaping all rendered text by default.

The host app owns:

- SITREP generation.
- SITREP storage and retrieval.
- Approval, publication, visibility, and access control.
- Transport through Relay, upload, API, or filesystem.
- PDF generation engine choice.
- Public/private redaction policy before rendering.

## Install During Local Development

When the package lives inside a consuming app as a path repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/pbb-sitrep-viewer",
      "options": { "symlink": false }
    }
  ],
  "require": {
    "pbb/sitrep-viewer": "*"
  }
}
```

Then run:

```bash
composer update pbb/sitrep-viewer
```

## Basic Usage

```php
use Pbb\Sitreps\Viewer\SitrepViewer;

$payload = json_decode(file_get_contents('sitrep.json'), true);

$viewer = new SitrepViewer();
$html = $viewer->render($payload);
```

## Render An Embeddable Fragment

```php
$fragment = $viewer->render($payload, [
    'full_document' => false,
]);

$css = $viewer->css();
```

Use this when the host app already owns the page shell and wants to mount the SITREP report into an existing route.

## Render Official Sections For Custom Layouts

Host applications can render official SITREP sections individually while owning
the surrounding layout. This is useful for tabbed views, split map/report
screens, accordions, or dashboards that need the official section content
without the full long-form document.

```php
$summary = $viewer->renderSection($payload, 'summary');

$tabContent = $viewer->renderSections($payload, [
    'population',
    'actions',
    'needs',
    'gaps',
]);
```

Use compact layout when the official SITREP content is embedded in a narrow
side panel beside a map or dashboard:

```php
$summary = $viewer->renderSection($payload, 'summary', [
    'layout' => 'compact',
]);

$tabContent = $viewer->renderSections($payload, [
    'summary',
    'population',
    'actions',
    'needs',
], [
    'layout' => 'compact',
]);
```

Supported section names are available at runtime:

```php
$sections = $viewer->sectionNames();
```

Current supported sections:

```text
header
summary
situation
damage
population
actions
needs
gaps
period_activity
verification_notes
footer
```

Section rendering still uses the SDK's official labels, schema v2 rollup
handling, source-location shortening, table rules, empty states, and
`location_count` behavior. The host app should only decide where those sections
are placed.

## Render Interactive Browser Instances

The package includes a JavaScript viewer for interactive app surfaces. This is
not a replacement for the authoritative PHP renderer used for public preview,
server export, or PDF-safe output. Use it when a browser app needs tabs,
compact map-side panels, comparison panes, or multiple independent SITREP views
without asking the server to render HTML fragments repeatedly.

```html
<link rel="stylesheet" href="/packages/pbb-sitrep-viewer/assets/sitrep-viewer.css">
<script type="module">
import { createSitrepViewer } from '/packages/pbb-sitrep-viewer/js/sitrep-viewer.js';

const fullViewer = createSitrepViewer(document.querySelector('#current-sitrep'), {
    sitrep,
    layout: 'compact',
    sections: ['header', 'summary', 'population', 'needs', 'gaps'],
    onInteraction: ({ type, ref, sourceHubId, section }) => {
        console.log(type, ref, sourceHubId, section);
    },
});

const sourceViewer = createSitrepViewer(document.querySelector('#source-sitrep'), {
    sitrep: sourceSitrep,
    layout: 'section',
    section: 'summary',
});

sourceViewer.setSection('actions');
fullViewer.update({ layout: 'compact' });
sourceViewer.destroy();
</script>
```

Each `createSitrepViewer(...)` call owns only the container passed to it. There
is no singleton state, so one page may render a current consolidated SITREP, a
source barangay SITREP, and a section-only summary card at the same time.

Public JavaScript API:

```text
createSitrepViewer(container, options)
renderSitrep(sitrep, options)
renderSitrepSection(sitrep, section, options)
sectionNames()
```

Row actions are optional host-owned callbacks for supported evidence rows. The
viewer renders only generic action slots and does not implement app-specific
workflows.

```js
createSitrepViewer(container, {
    sitrep,
    section: 'gaps',
    layout: 'compact',
    rowActions: [{
        id: 'request-support',
        label: 'Request Support',
        title: 'Request outside support for this item',
        appliesTo: ({ section, gap, row }) => section === 'gaps' && gap?.type === 'open_needs',
        onClick: ({ sitrep, section, gap, row, rowIndex, evidenceRef, sourceHubId, sourceRelayHubId, locationName, event }) => {
            // Host app owns the side effect.
        },
    }],
});
```

When `rowActions` is omitted, or no action applies to a row, rendered output
stays unchanged. When actions apply, evidence tables receive an `Actions`
column with buttons. Callback context includes the SITREP, section, owning gap,
evidence row payload, row index, evidence ref, source identifiers, location
name, and click event.

Viewer instance methods:

```text
update(options)
setSitrep(sitrep)
setLayout(layout)
setSection(section)
setSections(sections)
findEvidence(ref)
scrollToEvidence(ref, options)
highlightEvidence(ref, options)
clearHighlight()
filterBySource(sourceHubId)
clearSourceFilter()
getState()
destroy()
```

Interactive render anchors:

```text
data-sitrep-section
data-sitrep-evidence-ref
data-source-hub-id
data-source-relay-hub-id
data-location-name
data-concern-group
```

Callbacks:

```text
onInteraction({ type, ref, sourceHubId, sourceRelayHubId, section, locationName, concernGroup, payload, event })
onEvidenceClick(payload)
onSourceClick(payload)
onConcernClick(payload)
```

`findEvidence`, `scrollToEvidence`, and `highlightEvidence` use the same
`data-sitrep-evidence-ref` values rendered into the DOM. When the SITREP
payload provides `evidence_ref`, `evidenceRef`, `ref`, or `evidence_refs[]`,
that value is preserved; otherwise the viewer emits deterministic section
fallback refs. `filterBySource(sourceHubId)` only hides source-tagged evidence
nodes and can be cleared with `clearSourceFilter()`.

## Build Visualization Data

The SDK can also return framework-agnostic visualization datasets. These arrays
do not render charts or maps by themselves. They describe the official SITREP
data in a shape that host apps can feed into app-owned dashboards or future
Helper components.

```php
$visuals = $viewer->visualizationData($payload);

$populationVisuals = $viewer->visualizationSection($payload, 'population');
```

The top-level dataset declares the Helper component families it is designed to
feed:

```php
$visuals['helper_targets'];
// [
//     'ui.stat.cards',
//     'ui.charts',
//     'ui.map.legend',
//     'ui.map.markers',
// ]
```

Available visualization sections:

```text
summary
situation
population
actions
needs
gaps
map
```

Example population stat cards:

```php
$visuals['sections']['population']['stat_cards'];
// [
//     'component' => 'ui.stat.cards',
//     'title' => 'Affected People',
//     'items' => [
//         ['label' => 'People at Risk', 'value' => 61, ...],
//         ['label' => 'People Helped', 'value' => 8, ...],
//         ['label' => 'Current Records', 'value' => 42, ...],
//     ],
// ]
```

Example chart dataset:

```php
$visuals['sections']['needs']['category_demand'];
// [
//     'component' => 'ui.charts',
//     'type' => 'horizontal-bar',
//     'title' => 'Resource Demand By Category',
//     'data' => [
//         ['label' => 'Rescue and Extraction', 'value' => 38, ...],
//     ],
// ]
```

Example map marker dataset:

```php
$visuals['sections']['map']['incident_markers'];
// [
//     'component' => 'ui.map.markers',
//     'title' => 'Incident Coordinates',
//     'items' => [
//         ['type' => 'incident', 'lat' => 10.32123, 'lng' => 123.89123, ...],
//     ],
// ]
```

The Viewer SDK intentionally does not depend on Helper JavaScript. This keeps
the package usable by plain PHP apps, CLI tools, and server-side renderers. Host
apps decide whether to render these datasets with Helper, another frontend
layer, or simple local HTML.

## Render For PDF

```php
$html = $viewer->render($payload, [
    'pdf' => true,
]);
```

The SDK only produces HTML and CSS. The host app chooses the PDF engine, for example Playwright/Chromium, wkhtmltopdf, or a service-side renderer.

## Accepted Payload Shape

The SDK accepts the current exported Hotline SITREP shape:

```text
id
sequence_number
title
coverage_area
period_started_at
period_ended_at
generated_at
published_at
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

Unknown keys are ignored. Missing optional section keys render as empty states.

The SDK accepts both legacy flat sections and schema v2 `rollup/items`
sections. When a section contains `rollup`, the viewer renders that rollup as
the main document content and ignores the wrapper labels. This lets the same
viewer render direct Hotline SITREPs and consolidated SITREPs without requiring
Laravel, Eloquent, Relay, or database access.

## Payload Reference

The viewer SDK exposes the section/property reference used by the upload demo:

```php
$reference = $viewer->schemaReference();
$referenceHtml = $viewer->schemaReferenceHtml();
```

`schemaReference()` returns an array of sections. Each section contains:

```text
name
description
required[]
optional[]
```

The reference is written from the viewer/schema v2 perspective:

- Current payload sections should provide `rollup` and `items[]`.
- `rollup` is the main rendered section.
- `items[]` carries source/location drill-down, especially for consolidated SITREPs.
- `privacy_redactions` intentionally remains flat.
- Legacy flat sections are still accepted for compatibility, but new producers should emit schema v2 wrappers.

Use `schemaReferenceHtml()` when building an admin/developer surface where operators
or integrators need to inspect which section properties are required or optional.

Schema v2 sections use:

```text
summary.rollup
summary.items[].location
summary.items[].data
```

The same shape applies to `situation`, `damage`, `population`, `actions`,
`needs`, `gaps`, `source_snapshot`, and `data_quality`.

For source metadata, schema v2 producers should provide
`source_snapshot.rollup.hub_node` for the hub where the current SITREP was
generated. Consolidated SITREPs should also provide
`source_snapshot.rollup.hub_nodes[]` for the submitted source hubs used during
consolidation. Viewer consumers should use `hub_node` for current SITREP
identity and `hub_nodes[]` for source-hub lists, and should not assume that
`source_snapshot.hub_node` is always top-level.

The SDK also accepts consolidated SITREPs generated by `pbb/sitrep-consolidator`.
For those payloads, the viewer reads:

```text
source_snapshot.generation.type = consolidated
source_snapshot.generation.sdk
source_snapshot.generation.sdk_version
source_snapshot.generation.merge_rule_version
source_snapshot.target.hub_id
source_snapshot.target.name
source_snapshot.target.level
source_snapshot.source_sitreps
```

When `source_snapshot.target` is present, the document identity uses the target
LGU or organization as the report coverage line. The footer renders the
consolidator SDK/version, merge rule, target hub, and accepted source SITREP
count so downstream users can distinguish a consolidated report from a direct
Hotline-generated report.

## Recommended Producer Contract

Apps that generate SITREPs should export a stable snapshot before passing data to the viewer.

Do not pass live database models directly across app boundaries. The viewer expects a report snapshot, not operational state.

## Demo

Run the demo from the package directory:

```bash
php demo/render.php
```

The demo reads `demo/input/sitrep.json` and writes:

```text
demo/output/sitrep.html
```

For an interactive upload demo, start PHP's built-in server from the package
directory:

```bash
php -S 127.0.0.1:8096 -t demo
```

Then open:

```text
http://127.0.0.1:8096/upload.php
```

The upload demo accepts a generated `.json` SITREP payload, renders it with the
viewer SDK, and does not persist the uploaded file.

For a Helper-backed visualization demo, start PHP's built-in server from the
Hotline repository root so the page can load `public/vendor/helpers.pbb.ph`:

```bash
php -S 127.0.0.1:8097 -t C:\wamp64\www\pbb\hotline
```

Then open:

```text
http://127.0.0.1:8097/packages/pbb-sitrep-viewer/demo/visualization.php
```

`demo/visualization.php` uses `SitrepViewer::visualizationData()` server-side
and renders the returned datasets with Helper `ui.stat.cards`, `ui.charts`,
`ui.map.legend`, and `ui.map.markers`. The SDK still does not require Helper;
the demo is only an integration example for apps that already vendor Helper.

For the framework-agnostic JavaScript viewer demo, use the same server and open:

```text
http://127.0.0.1:8097/packages/pbb-sitrep-viewer/demo/js-viewer.html
```

`demo/js-viewer.html` renders two independent viewer instances from the same
SITREP payload: one compact multi-section report and one compact section-only
panel. Uploading a JSON file updates both instances without a server render.

For a host-owned row actions demo, open:

```text
http://127.0.0.1:8097/packages/pbb-sitrep-viewer/demo/js-viewer-command.html
```

`demo/js-viewer-command.html` renders the Gaps section with generic row actions
and a demo-owned Request Support preview dialog. It is an SDK integration
example only; it does not submit to Hotline.
