# PBB Hotline Beta Two-Node Ubuntu Deployment Topology

## Purpose

This note captures the recommended 2-server production packaging layout for the current PBB Hotline Beta stack so it can be reviewed later before implementation.

Current scope considered in this layout:

- Technitium DNS Server
- Relay
- Realtime
- Maestro
- Hotline
- MapServer
- MySQL / MariaDB

This recommendation assumes a packaged on-prem or controlled-LAN deployment using two Ubuntu machines.

Relay is treated here as the site-to-site transport component for SITREP and other inter-node data movement between barangay and city over the Internet.

## Recommendation Summary

Use a 2-node split by traffic role, not by project name.

- Server A: Edge / Realtime
- Server B: App / Data

This is the preferred packaged-system split because Realtime is the most latency-sensitive part of the stack and should not compete directly with Hotline, MySQL, and MapServer during live call handling.

## Server Split

### Server A: Edge / Realtime

Host these components on Server A:

- Technitium DNS Server
- Nginx
- Relay
- Realtime application
- `realtime:serve` websocket daemon
- optional lightweight monitoring / log shipping agent

Primary responsibilities:

- public DNS for the package or internal DNS service where appropriate
- TLS termination for Realtime
- Internet-facing transport and inter-site exchange
- websocket/public ingress
- low-latency call/presence/chat/media relay handling

Why this belongs together:

- Realtime is sensitive to latency and jitter
- Relay is transport-facing and belongs closer to the network edge than to the app/data node
- DNS and reverse proxying are comparatively light workloads
- isolating Realtime and Relay reduces interference from heavier PHP/MySQL/MapServer work

### Server B: App / Data

Host these components on Server B:

- Nginx
- PHP-FPM 8.2
- Hotline
- Maestro
- MapServer
- MySQL or MariaDB
- Laravel queue workers
- Laravel scheduler

Primary responsibilities:

- Hotline web and API traffic
- Maestro web and telemetry traffic
- media ingest and persistence
- database hosting
- map tile / map API hosting
- background jobs and scheduled tasks

Why this belongs together:

- Hotline should stay close to its database
- Maestro is a normal app workload and does not need a whole server alone
- MapServer is heavier than a normal PHP app but is still less latency-sensitive than Realtime

## Why This Split Is Preferred

Do not put Realtime on the same machine as Hotline, MySQL, and MapServer if the goal is stable live caller/operator flow.

Do not split Hotline away from its database in a 2-node package unless there is a strong reason.

Do not dedicate a full node to Maestro alone in a 2-box system.

The intended benefit of this split is:

- Realtime remains protected from app/database spikes
- Relay remains isolated from Hotline/MySQL/MapServer contention during inter-site transmission
- Hotline remains close to MySQL for normal app operations
- the operational model stays simple enough for a packaged deployment

## Suggested Public Domains

### Server A

- `realtime.pbb.ph`
- `relay.pbb.ph`
- optional DNS/admin hostname if exposed separately

### Server B

- `hotline.pbb.ph`
- `maestro.pbb.ph`
- `mapserver.pbb.ph`

If the package is deployed in a private environment, internal DNS aliases may be used instead, but the same role split should still apply.

## Suggested Ports

### Server A

- `53/tcp`
- `53/udp`
- `80/tcp`
- `443/tcp`
- internal websocket daemon port, for example `8080`

### Server B

- `80/tcp`
- `443/tcp`
- local PHP-FPM socket or local-only PHP-FPM TCP port
- `3306/tcp` for MySQL or MariaDB on private/local access only

Do not expose database ports publicly.

Do not expose internal daemon ports publicly.

## Traffic Flow

1. Caller/operator browser loads Hotline from Server B.
2. Hotline backend on Server B issues Realtime admission and other app-owned business responses.
3. Browser connects to Realtime websocket on Server A.
4. Realtime handles presence, call signaling, chat, and media-chunk relay.
5. Relay on Server A handles SITREP and other inter-site exchange over the Internet.
6. Realtime forwards media ingest to Hotline internal endpoint on Server B.
7. Hotline persists media and business data to MySQL on Server B.
8. Maestro on Server B handles telemetry and worker visibility for the packaged system.

## Recommended Runtime Stack

### Server A

- Ubuntu Server
- Nginx
- Technitium DNS Server
- Relay codebase
- PHP 8.2 CLI if Realtime requires PHP runtime
- Realtime codebase
- Relay worker/runtime services if the Relay architecture uses them
- `systemd` service for `realtime:serve`

### Server B

- Ubuntu Server
- Nginx
- PHP-FPM 8.2
- Hotline codebase
- Maestro codebase
- MapServer runtime
- MySQL or MariaDB
- `systemd` workers and timers

## Service Layout

### Server A

Recommended long-running services:

- `nginx.service`
- `technitium-dns.service`
- `relay.service` or equivalent Relay runtime/worker services
- `realtime-serve.service`

Optional future split:

- separate Realtime dispatcher service if the architecture grows later

### Server B

Recommended long-running services:

- `nginx.service`
- `php8.2-fpm.service`
- `mysql.service` or `mariadb.service`
- `hotline-queue.service`
- `maestro-queue.service`
- `cron` or `systemd` timer for Laravel scheduler

## Nginx Role

### Server A

Use Nginx for:

- TLS termination
- Relay virtual host
- proxying `/realtime` to the Realtime daemon
- normal HTTP routing for Realtime APIs if needed

### Server B

Use Nginx for:

- Hotline virtual host
- Maestro virtual host
- MapServer virtual host
- static asset delivery
- PHP request forwarding to PHP-FPM

## Database Placement

Database should live on Server B only.

Recommended shape:

- database server: MySQL or MariaDB
- separate databases per app
  - `pbb_hotline`
  - `pbb_maestro`
  - `pbb_relay` if the packaged Relay deployment uses its own relational database

Keep database local/private to Server B rather than moving it to Server A.

## Relay Placement Note

Relay should be placed on Server A in this 2-node layout.

Reasoning:

- Relay is a transport-facing component for inter-site data exchange
- its operational role is closer to Realtime than to Hotline business logic
- keeping Relay off the app/data node reduces the chance that Hotline, MySQL, or MapServer load interferes with barangay-to-city transmission

Tradeoff:

- if Relay uses MySQL or another relational database, it will talk across the private LAN to the database node on Server B
- that cross-node DB dependency is acceptable in this 2-node package because Relay is less latency-sensitive than live caller/operator traffic
- the cleaner 3-node future shape would still be: edge/transport node, app node, database node

## Internal Networking Expectations

Use private static addresses between the two servers.

Allow only required east-west traffic:

- Server A to Server B for Hotline media ingest, Relay database access if needed, and any app callback traffic
- Server B to Server A for Realtime-related app integration where needed

The goal is to keep public exposure narrow and the packaged deployment predictable.

## Firewall Guidance

### Publicly exposed

Server A:

- `53`
- `80`
- `443`

Server B:

- `80`
- `443`

### Private/internal only

Server B:

- `3306`

Internal daemon ports should remain closed to public access.

## Operational Guidance

Use Linux-native service management and avoid desktop-style background load on the production nodes.

Prefer:

- `Nginx + PHP-FPM`

Avoid:

- desktop WAMP-style production hosting
- unnecessary GUI/background utilities
- sharing Realtime with heavy database/map workloads

This matters because local investigation on the development laptop showed that dynamic Laravel requests can become inconsistent when the machine is under broader host pressure, even when the application code path itself is not slow.

## Monitoring Recommendations

From day one, track at least:

- CPU usage
- RAM usage
- disk I/O
- MySQL connections and slow queries
- PHP-FPM busy workers
- Realtime daemon health
- queue worker health
- websocket connectivity health

Maestro can be used as part of that visibility model, but basic host and process monitoring should still exist independently.

## Future Upgrade Path

If a third server is added later, the first recommended extraction is:

- move MySQL/MariaDB onto its own DB node

At that point the topology becomes:

- Realtime / edge node
- app node
- database node

Until then, the recommended 2-node packaged layout remains:

- Server A: DNS + Nginx + Relay + Realtime
- Server B: Hotline + Maestro + MapServer + MySQL

## Implementation Direction When Ready

When implementation begins, the next useful follow-up document should define:

- hostnames and private IPs
- Nginx site definitions
- `systemd` unit files
- PHP-FPM pools
- MySQL tuning baseline
- internal callback URLs between Realtime and Hotline
- deployment order and rollback steps
