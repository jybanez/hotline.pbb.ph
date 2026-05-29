# SITREP Consolidator Demo

Run:

```bash
php demo/consolidate.php
```

The demo:

1. Loads sample barangay SITREP JSON files from `demo/input/barangay`.
2. Stages them as latest-by-hub files under `demo/staging/barangay`.
3. Consolidates staged barangay SITREPs into a city SITREP.
4. Writes `demo/output/city-sitrep.json`.

It does not require Hotline, Laravel, Relay, a database, or a web server.
