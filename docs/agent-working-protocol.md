# Agent Working Protocol

Date: 2026-05-29

This protocol keeps Hotline work clean when multiple agents or contributors are active at the same time.

## Start Point

Every new task starts from the latest `main`.

```powershell
git checkout main
git pull --ff-only origin main
```

Do not start new work from an old feature branch unless the task is explicitly to continue that branch.

## Branch Naming

Use meaningful branch names with this shape:

```text
<category>/<task-name>
```

The category should describe the work group. The task name should describe the specific change.

Good examples:

```text
sitrep/manual-generation
maps/boundary-fit
installer/maintenance-upgrade
media/finalize-ffmpeg
realtime/admission-token-alignment
docs/agent-working-protocol
```

Avoid vague names:

```text
fixes
update
new-work
test
```

## Worktree Folder Naming

When a separate checkout is needed, keep it under Hotline's branch workspace so it does not get mixed with other PBB apps. Inside that folder, make the path match the branch category and task.

Preferred structure:

```text
C:\wamp64\www\pbb\hotline-branches\<category>\<task-name>
```

Examples:

```text
C:\wamp64\www\pbb\hotline-branches\sitrep\manual-generation
C:\wamp64\www\pbb\hotline-branches\maps\boundary-fit
C:\wamp64\www\pbb\hotline-branches\installer\maintenance-upgrade
```

If the worktree must be served through a local domain, use a DNS-friendly folder name:

```text
C:\wamp64\www\pbb\hotline-branches\<category>-<task-name>
```

Example:

```text
C:\wamp64\www\pbb\hotline-branches\sitrep-manual-generation
```

The matching test domain should be explicit and not replace the main RC domain unless intended:

```text
hotline-sitrep.pbb.ph
hotline-maps.pbb.ph
hotline-installer.pbb.ph
```

## Branch Databases

Each served branch worktree should use its own database unless explicitly approved to share the main RC database.

Database names should follow the branch category and task:

```text
pbb_hotline_<category>_<task_name>
```

MySQL database names are limited to 64 characters. If the full branch task name would make the database name too long, shorten the task segment while keeping it meaningful.

Examples:

```text
pbb_hotline_sitrep_manual_generation
pbb_hotline_maps_boundary_fit
pbb_hotline_installer_maintenance_upgrade
pbb_hotline_realtime_token_secret
```

Create branch databases by cloning the current main RC Hotline database, then point the branch `.env` to the copied database.

Do not rerun the full migration and seeder pipeline for a normal branch worktree. The copied database keeps realistic RC data and avoids accidental drift from main.

If the branch adds new migrations, run only the required migrations against the copied branch database:

```powershell
php artisan migrate
```

Only use the main RC database directly for explicitly approved read-only verification or when the task specifically requires testing against the live RC dataset.

## Task Isolation

One branch should solve one task.

Do not mix unrelated work into the same branch:

- no unrelated cleanup
- no unrelated formatting
- no installer changes inside a frontend-only task
- no documentation drift unless it is part of the task

If a second issue is discovered, document it and create a separate branch or follow-up task.

## Committing And Pushing

Approved work should not be left only in a local working tree.

Expected finish state:

```text
working tree clean
branch pushed
PR opened or merged when approved
```

Use focused commits with messages that describe the behavior changed.

## Versioning

During RC hardening, do not bump the Hotline semantic version for every fix.

Current rule:

```text
Hotline version remains 5.6.1 until the RC line is formally advanced.
```

Fresh bundles may update build id, checksum, release metadata, ZIP hash, and release asset URL without changing the semantic version.

## Bundle Handoff

Hotline bundle creation is owned by `main`.

Feature branches and branch worktrees should not hand bundles to Kit directly. They should finish, be reviewed, and merge into `main` first. After `main` contains the approved change, create the bundle from the clean `main` checkout and hand that main-built bundle to Kit.

Create a fresh Hotline bundle from `main` when a merged change affects:

- installer output
- runtime files
- config defaults
- vendored assets
- frontend build output
- release metadata
- update/install behavior

After creating a main-built bundle, inform Kit with:

```text
Bundle path:
Version:
Build id:
SHA256:
Main commit:
Release URL:
Tests run:
Notes for installer:
```

## Cross-Team Boundaries

Agents may inspect other PBB repositories to understand behavior, but should not edit code owned by another team.

If Realtime, Relay, Mapserver, or Kit needs a change, post a clear request in the shared chat log with:

- observed behavior
- expected behavior
- affected endpoint or file, if known
- trace id or log excerpt, if available
- what Hotline needs from that team

Keep chat log messages brief. If the message is long, such as a proposal, design note, investigation report, or multi-step integration request, create a document in the appropriate repo and post a short chat message that links to the document path.

## Stale PRs

Do not merge old PRs directly when they are far behind `main`.

For stale PRs:

- inspect the intent
- check whether the feature is still wanted
- close if superseded
- recreate useful parts from current `main` if needed

## Agent Handoff

Each agent should end with this handoff block:

```text
Branch:
Commit:
Pushed:
PR:
Tests run:
Bundle created: yes/no
Kit informed: yes/no
Cross-team messages: yes/no
Known risks:
```
