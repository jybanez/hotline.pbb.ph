# PBB SITREP Viewer SDK Implementation Checklist

## Goal

Build a framework-agnostic PHP SDK that can render a generated PBB SITREP payload in any PHP application.

The SDK must remain separate from Hotline generation, Relay transport, database persistence, and app authorization policy.

## Status Summary

| Area | Status | Notes |
| --- | --- | --- |
| Package scaffold | Done | Created `packages/pbb-sitrep-viewer`. |
| Core renderer | Done | Accepts arrays or JSON strings and renders full document or fragment. |
| Default CSS | Done | Ships `assets/sitrep-viewer.css`. |
| Developer manual | Done | Added non-Hotline usage guide. |
| Demo | Done | Plain PHP demo writes HTML output. |
| Tests | Done | Focused PHPUnit coverage added and passing. |

## Boundaries

The SDK owns:

- SITREP payload normalization for optional sections.
- Escaped HTML rendering.
- Full-document and fragment rendering modes.
- Default CSS for screen/PDF-oriented output.
- Plain PHP integration docs and demo.

The host app owns:

- SITREP generation and validation.
- Access control and publication policy.
- Storage and retrieval.
- Transport through Relay or another channel.
- PDF engine selection.
- Any app-specific redaction before rendering.

## Verification

- PHP lint passed for all SDK source files using `C:\wamp64\bin\php\php8.2.29\php.exe`.
- Composer package validation passed for `packages/pbb-sitrep-viewer/composer.json`.
- Demo rendered `packages/pbb-sitrep-viewer/demo/output/sitrep.html`.
- Focused PHPUnit test passed: `SitrepViewerSdkTest` with 3 tests and 9 assertions.
