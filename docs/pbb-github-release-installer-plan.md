# PBB GitHub Release Installer Plan

## Purpose

Capture the packaging and installer direction for later work. This is intentionally parked while `/command` SITREP work resumes.

## Direction

Use GitHub Releases as the distribution source for PBB app install bundles.

Each app release should publish a versioned archive, such as:

```text
pbb-hotline-v1-5.6.1.zip
```

The version format should include the PBB milestone and app version:

```text
v{milestone}-{major}.{minor}.{patch}
```

Examples:

- `v1-5.6.1` for Milestone 1, Emergency Hotline
- `v2-9.1.2` for Milestone 2, Evacuation Management
- `v3-10.0.1` for Milestone 3, Utility Integration
- `v4-12.3.3` for Milestone 4, Responder Integration

## Release Bundle Contents

Each GitHub Release artifact should include:

- application source and production build assets
- `release.json` manifest
- migration and install scripts
- checksum file
- dependency constraints for PHP, database, Realtime, Helper, and other PBB services
- install notes and rollback notes

## Release Manifest Shape

Draft `release.json`:

```json
{
  "app": "pbb-hotline",
  "name": "PBB Hotline",
  "milestone": 1,
  "milestone_name": "Emergency Hotline",
  "version": "5.6.1",
  "display_version": "v1-5.6.1",
  "release_name": "Citizen Live Call Readiness",
  "release_date": "2026-05-12",
  "requires": {
    "php": ">=8.2",
    "mysql": ">=8.0",
    "realtime": ">=1.0.0",
    "helper": ">=1.0.0"
  },
  "install": {
    "entrypoint": "install.php",
    "healthcheck": "/api/bootstrap?surface=public"
  }
}
```

## Web Installer Launcher

The app-level installer should be a small web UI that can:

- select or accept a target release version
- download the release artifact from GitHub Releases
- verify checksum before extraction
- collect installation inputs from the user
- write `.env`
- generate app keys and secrets
- configure database, Realtime, Helper, mail/SMS, and public URLs
- run migrations
- run health checks
- show the resulting admin, command, operator, citizen, and public URLs

The installer should avoid assuming it is the only PBB installer. It should expose a predictable contract so a larger ecosystem installer can run it as one module.

## Ecosystem Installer Integration

A larger PBB installer can orchestrate:

- PBB Realtime
- PBB Hotline
- PBB Helper assets
- PBB HQ or Command modules
- future evacuation, utility, and responder applications

Each app installer should support:

- unattended mode with a config file
- interactive web mode
- install status output
- health check output
- rollback or cleanup guidance

## Open Decisions

- Whether release bundles include `vendor/` and built frontend assets or only source plus install-time build steps.
- How release checksums are generated and verified.
- Whether release artifacts should be signed in addition to checksums.
- Whether the ecosystem installer owns shared service secrets or each app installer generates its own.
- Where local install state is stored.
- Whether updater support is part of V1 or deferred after first-install support.

