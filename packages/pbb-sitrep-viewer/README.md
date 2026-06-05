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

See `docs/developer-manual.md` for integration notes and `demo/render.php` for a plain PHP example.
