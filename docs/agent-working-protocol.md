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

Create a fresh Hotline bundle when a change affects:

- installer output
- runtime files
- config defaults
- vendored assets
- frontend build output
- release metadata
- update/install behavior

After creating a bundle, inform Kit with:

```text
Bundle path:
Version:
Build id:
SHA256:
Git commit:
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
