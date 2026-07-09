# PBB Hotline Management Review Documentation Index

Status: current as of 2026-07-09  
Audience: management, project leads, Kit, Support, Utility/Vena, Relay, Account, and implementation reviewers

## Purpose

This document identifies the current source-of-truth documents for management review. The `docs/` folder also contains older proposals, migration plans, and historical audit notes. Those older files are retained for traceability, but they should not override the current contracts listed here.

## Current Operating Picture

PBB Hotline is the emergency-call and local incident operations app. Current development centers on:

- citizen emergency call intake;
- operator incident handling and media capture;
- command surface review, SITREP generation, and support requests;
- Relay-backed upward reporting for SITREPs, Support Requests, and live incident snapshots;
- PBB Account SSO and app-admin integration;
- Landing-mediated cross-hub media access;
- Kit-owned install/update packaging from clean `main`.

## Current Source-Of-Truth Docs

### Management And Review

- [README](../README.md) - high-level app overview, local setup, media binaries, session policy, realtime model, and current surfaces.
- [Agent Working Protocol](agent-working-protocol.md) - branch, worktree, review, merge, bundle, and cross-team coordination rules.
- [Management Review Documentation Index](management-review-documentation-index.md) - this document.

### Install, Packaging, And Kit

- [Installer Contract](../installer/docs/hotline-installer-contract.md) - Kit/installer contract for install config and runtime setup.
- [Installer Sample Config](../installer/docs/hotline-install.sample.json) - current sample install config shape.
- [Release Metadata](../release.json) - authoritative release/update metadata consumed by Kit.
- [Baseline Schema](../database/schema/hotline-schema-mysql.sql) - fresh-install MySQL baseline schema.

### Account And Authentication

- [PBB Account SSO Integration](pbb-account-sso-integration.md) - Account SSO, role-aware access, logout, and Account app-admin API settings.

### SITREP

- [SITREP Payload Schema V2](sitrep-payload-schema-v2.md) - canonical exported/relayed SITREP JSON shape.
- [SITREP Relay Upstream Policy](sitrep-relay-upstream.md) - latest-only relay policy and historical source drill-down rule.
- [SITREP Media Access Contract](sitrep-media-access-contract.md) - media reference security and Landing-mediated access.
- [SITREP Viewer SDK Manual](../packages/pbb-sitrep-viewer/docs/developer-manual.md) - PHP/JS viewer SDK usage.
- [SITREP Consolidator SDK Manual](../packages/pbb-sitrep-consolidator/docs/developer-manual.md) - consolidation SDK usage.

### Support Request

- [Support Request Relay Contract](support-request-relay-contract-proposal.md) - Hotline/Support/Relay boundary and message contract.
- [Support Request Implementation Checklist](support-request-implementation-checklist.md) - current Hotline-side implementation scope and remaining UX/lifecycle work.

### Incident Relay For Utility/Vena

- [Hotline Incident Relay Implementation Checklist](hotline-incident-relay-implementation-checklist.md) - current V1 contract for `hotline.incident.upserted`, coalesced outbox, delivery history, media refs, and Utility/Vena ownership.

### Alert Status SDK

- [PBB Hotline Alert SDK Proposal](pbb-hotline-alert-sdk-proposal.md) - proposed read-only JavaScript SDK for other PBB apps to consume Hotline community alert status.
- [PBB Hotline Alert SDK Implementation Checklist](pbb-hotline-alert-sdk-implementation-checklist.md) - proposed implementation checklist for REST bootstrap, Realtime updates, SDK behavior, tests, and cross-team boundaries.

### Media SDK

- [Hotline Media SDK Manual](../packages/pbb-hotline-media-sdk/docs/developer-manual.md) - upstream media retrieval, cache ownership, Relay relationship resolution, and Landing gateway access.
- [Media SDK Demo](../packages/pbb-hotline-media-sdk/demo/README.md) - source-only demo for dry-run and live media resolution.

### Cleanup And Historical Context

- [Orphaned Legacy Artifacts Review](orphaned-legacy-artifacts-review.md) - retained/removal decisions for legacy files.
- [Citizen Protocol Migration Checklist](citizen-protocol-migration-checklist.md) - historical/canonical citizen terminology migration status.

## Historical Or Proposal-Era Docs

Files named `*-proposal.md`, older `pbb-hotline-beta-*` specs, early API/schema inventories, and one-off Helper/Realtime proposal notes are retained as historical design records unless they are explicitly listed above as current source-of-truth docs.

Management reviewers should treat those historical docs as background only. When a historical doc conflicts with a current source-of-truth doc, the current source-of-truth doc wins.

Notable historical groups:

- `pbb-hotline-beta-*` documents from the April/May beta planning phase;
- `pbb-helper-*` proposal notes that were used to coordinate Helper changes;
- `pbb-realtime-*` proposal notes that were used for Realtime coordination;
- early citizen/caller migration inventories retained for traceability.

## Current Cross-App Boundaries

### Hotline

- Owns local incident records, operator/command/citizen surfaces, SITREP generation, explicit Support Request creation, incident relay serialization, and Hotline-owned media access endpoints.

### Relay

- Owns transport, canonical `targets[]`, routing, delivery, handler invocation, retries, and hub relationship resolution.

### Landing

- Owns public local-domain routing and forwards the narrow Hotline media gateway paths. Landing is not the media security authority; source Hotline still authenticates media requests.

### Support

- Owns Support Request intake, triage, lifecycle, and use of SITREP/incident/media context for support planning.

### Utility/Vena

- Owns inbound persistence and map/utility workflow for `hotline.incident.upserted` snapshots.

### Account

- Owns identity, credentials, browser SSO, account status, and Account app-admin calls. Hotline owns app-local session and authorization.

### Kit

- Owns install/update orchestration and bundle consumption. Hotline bundles must come from clean `main`, not feature branches.

## Management Review Notes

- Main is the only approved bundle source.
- Feature branches may update docs, tests, and SDKs, but Kit handoff waits until merge to `main`.
- SITREP, Support Request, and Incident Relay are separate message streams.
- Support Requests are explicit tasking records; SITREPs and incident snapshots are visibility/context records.
- Media refs are metadata only. Raw storage paths and public `/storage/...` URLs are not the integration contract.
