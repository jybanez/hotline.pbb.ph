import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const citizenEntry = await readFile(new URL('../../resources/js/entries/citizen.js', import.meta.url), 'utf8');
const citizenSurface = await readFile(new URL('../../resources/js/surfaces/citizenSurface.js', import.meta.url), 'utf8');
const renderSurface = await readFile(new URL('../../resources/js/surfaces/renderSurface.js', import.meta.url), 'utf8');
const realtimeSignalStrength = await readFile(new URL('../../resources/js/features/realtimeSignalStrength.js', import.meta.url), 'utf8');

assert.match(citizenEntry, /renderSurface\('citizen'\)/);
assert.match(citizenEntry, /navigator\.serviceWorker\.register\('\/citizen-sw\.js'/);
assert.match(citizenEntry, /window\.HotlineCitizenPwa\s*=/);
assert.doesNotMatch(citizenEntry, /HotlineCallerPwa/);
assert.match(citizenEntry, /\/api\/session\/ping\?surface=citizen/);

assert.match(renderSurface, /surface === 'citizen'/);
assert.doesNotMatch(renderSurface, /surface === 'caller'/);
assert.match(renderSurface, /renderCitizenSurface/);

assert.match(citizenSurface, /fetchJson\('\/api\/citizen\/home'\)/);
assert.match(citizenSurface, /fetchJson\('\/api\/realtime\/admission\/citizen'/);
assert.match(citizenSurface, /requestPrefix:\s*'citizen_surface'/);
assert.match(citizenSurface, /admissionPath:\s*'\/api\/realtime\/admission\/citizen'/);
assert.match(citizenSurface, /brandHref:\s*'\/citizen'/);
assert.doesNotMatch(citizenSurface, /HotlineCallerPwa/);
assert.match(citizenSurface, /Citizen Realtime surface stream unavailable\./);
assert.match(citizenSurface, /window\.addEventListener\('offline'/);
assert.match(citizenSurface, /window\.addEventListener\('online'/);
assert.match(citizenSurface, /phase:\s*'network_offline'/);
assert.match(citizenSurface, /Waiting for network \.\.\./);
assert.match(citizenSurface, /function updateCallerPendingOfflineOverlay/);
assert.match(citizenSurface, /processed_miss_key/);
assert.match(citizenSurface, /function callerMissKey/);
assert.match(citizenSurface, /label:\s*'OFFLINE'/);
assert.match(citizenSurface, /This device is offline/);
assert.match(citizenSurface, /data-caller-live-network-banner/);
assert.match(citizenSurface, /live-call-browser-offline/);
assert.match(citizenSurface, /live-call-runtime-unavailable/);
assert.match(citizenSurface, /clearCallerPendingState\(\)/);
assert.match(
  citizenSurface,
  /clearCallerPostCallIncidentReconcileTimers\(\);\s+clearCallerProductQueryRequestsForIncident\(nextIncidentId\);\s+syncCallerCurrentIncident\(null\)/
);
assert.match(citizenSurface, /post-call-incident-reconcile-skipped/);
assert.match(citizenSurface, /currentIncidentId !== expectedIncidentId/);
assert.match(citizenSurface, /product\.query\.request/);
assert.match(citizenSurface, /hotline\.incident\.snapshot/);
assert.match(citizenSurface, /product\.query\.response/);
assert.match(citizenSurface, /post-call-incident-reconcile-query-timeout/);
assert.match(citizenSurface, /pending-overlay-preserved-across-render/);
assert.doesNotMatch(citizenSurface, /src="\/images\/hang-up\.svg"/);
assert.match(realtimeSignalStrength, /navigator\.onLine === false/);
assert.match(realtimeSignalStrength, /state:\s*'browser-offline'/);
assert.match(realtimeSignalStrength, /window\.addEventListener\('offline'/);

assert.doesNotMatch(citizenSurface, /\/api\/caller\//);
assert.doesNotMatch(citizenSurface, /\/api\/realtime\/admission\/caller/);
