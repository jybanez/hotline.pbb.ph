# PBB SITREP Viewer SDK

Framework-agnostic PHP SDK for rendering generated PBB SITREP payloads.

The SDK accepts exported SITREP arrays or JSON strings and returns either a complete HTML document or an embeddable HTML fragment. It does not depend on Hotline, Laravel, Relay, Eloquent, or a database connection.

## Quick Start

```php
use Pbb\Sitreps\Viewer\SitrepViewer;

$viewer = new SitrepViewer();
$html = $viewer->render($sitrepPayload);
```

For an embeddable fragment:

```php
$fragment = $viewer->render($sitrepPayload, [
    'full_document' => false,
]);

$css = $viewer->css();
```

For custom layouts, render official sections individually:

```php
$summary = $viewer->renderSection($sitrepPayload, 'summary');
$tabs = $viewer->renderSections($sitrepPayload, ['population', 'needs', 'gaps']);
```

For constrained side panels, request the compact layout:

```php
$summary = $viewer->renderSection($sitrepPayload, 'summary', [
    'layout' => 'compact',
]);

$tabs = $viewer->renderSections($sitrepPayload, ['summary', 'population', 'needs'], [
    'layout' => 'compact',
]);
```

For dashboard or map layouts, build framework-agnostic visualization datasets:

```php
$visuals = $viewer->visualizationData($sitrepPayload);
$populationVisuals = $viewer->visualizationSection($sitrepPayload, 'population');
```

The visualization data is plain PHP arrays that can be rendered by app-owned UI
or future Helper components such as `ui.stat.cards`, `ui.charts`,
`ui.map.legend`, and `ui.map.markers`.

## JavaScript Interactive Viewer

The package also includes a framework-agnostic browser viewer for interactive
app surfaces. The PHP renderer remains the authoritative server/export
renderer; the JavaScript viewer is intended for tabs, side panels, comparison
views, and other client-side interactions.

```html
<link rel="stylesheet" href="/packages/pbb-sitrep-viewer/assets/sitrep-viewer.css">
<script type="module">
import { createSitrepViewer } from '/packages/pbb-sitrep-viewer/js/sitrep-viewer.js';

const viewer = createSitrepViewer(document.querySelector('#sitrep-panel'), {
    sitrep,
    layout: 'compact',
    sections: ['summary', 'population', 'needs'],
});

viewer.setSection('gaps');
viewer.scrollToEvidence('summary.gap_cards.people_at_risk.guadalupe');
viewer.highlightEvidence('summary.gap_cards.people_at_risk.guadalupe');
viewer.filterBySource('12');
viewer.update({ layout: 'section' });
viewer.destroy();
</script>
```

Each `createSitrepViewer(...)` call creates an isolated instance. Multiple
instances can render different SITREPs, different sections of the same SITREP,
or different layouts on one page without shared state.

Rendered nodes include stable browser-side anchors for app integration:
`data-sitrep-section`, `data-sitrep-evidence-ref`, `data-source-hub-id`,
`data-source-relay-hub-id`, `data-location-name`, and `data-concern-group`.
Host apps can use `onInteraction`, `onEvidenceClick`, `onSourceClick`, and
`onConcernClick` callbacks to connect map markers, source lists, and strategy
panels to official SITREP content.

See `docs/developer-manual.md` for integration notes and `demo/render.php` for a plain PHP example.
For a Helper-backed browser visualization demo, run PHP's built-in server from
the Hotline repo root and open:

```bash
php -S 127.0.0.1:8097 -t C:\wamp64\www\pbb\hotline
```

```text
http://127.0.0.1:8097/packages/pbb-sitrep-viewer/demo/visualization.php
```

For the JavaScript interactive viewer demo, open:

```text
http://127.0.0.1:8097/packages/pbb-sitrep-viewer/demo/js-viewer.html
```
