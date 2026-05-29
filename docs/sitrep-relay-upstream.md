# SITREP Relay Upstream Policy

Hotline submits generated SITREPs to the local Relay app after the SITREP is saved.

Relay handoff is latest-only. The most recent SITREP is the only relay-eligible report because it represents the current source state. Any older SITREP that was not accepted by Relay is intentionally superseded by the newer report and is not retried.

Historical SITREPs remain available at the source Hotline hub. Upstream consumers that need older context should drill down to the source hub instead of expecting Hotline to replay superseded reports.

Hotline marks a delivery as `sent` once the local Relay app accepts `POST /api/v1/messages`. Upstream forwarding and retry after local Relay acceptance are Relay-owned behavior.
