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

For dashboard or map layouts, build framework-agnostic visualization datasets:

```php
$visuals = $viewer->visualizationData($sitrepPayload);
$populationVisuals = $viewer->visualizationSection($sitrepPayload, 'population');
```

The visualization data is plain PHP arrays that can be rendered by app-owned UI
or future Helper components such as `ui.stat.cards`, `ui.charts`,
`ui.map.legend`, and `ui.map.markers`.

See `docs/developer-manual.md` for integration notes and `demo/render.php` for a plain PHP example.
