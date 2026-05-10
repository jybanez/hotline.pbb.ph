import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const citizenEntry = await readFile(new URL('../../resources/js/entries/citizen.js', import.meta.url), 'utf8');
const citizenSurface = await readFile(new URL('../../resources/js/surfaces/citizenSurface.js', import.meta.url), 'utf8');
const renderSurface = await readFile(new URL('../../resources/js/surfaces/renderSurface.js', import.meta.url), 'utf8');

assert.match(citizenEntry, /renderSurface\('citizen'\)/);
assert.match(citizenEntry, /navigator\.serviceWorker\.register\('\/citizen-sw\.js'/);
assert.match(citizenEntry, /window\.HotlineCitizenPwa\s*=/);
assert.match(citizenEntry, /window\.HotlineCallerPwa\s*=\s*window\.HotlineCitizenPwa/);
assert.match(citizenEntry, /\/api\/session\/ping\?surface=citizen/);

assert.match(renderSurface, /surface === 'citizen' \|\| surface === 'caller'/);
assert.match(renderSurface, /renderCitizenSurface/);

assert.match(citizenSurface, /fetchJson\('\/api\/citizen\/home'\)/);
assert.match(citizenSurface, /fetchJson\('\/api\/realtime\/admission\/citizen'/);
assert.match(citizenSurface, /requestPrefix:\s*'citizen_surface'/);
assert.match(citizenSurface, /admissionPath:\s*'\/api\/realtime\/admission\/citizen'/);
assert.match(citizenSurface, /brandHref:\s*'\/citizen'/);
assert.match(citizenSurface, /window\.HotlineCitizenPwa \?\? window\.HotlineCallerPwa/);
assert.match(citizenSurface, /Citizen Realtime surface stream unavailable\./);
assert.match(citizenSurface, /window\.addEventListener\('offline'/);
assert.match(citizenSurface, /window\.addEventListener\('online'/);
assert.match(citizenSurface, /phase:\s*'network_offline'/);
assert.match(citizenSurface, /Waiting for network \.\.\./);
assert.match(citizenSurface, /function updateCallerPendingOfflineOverlay/);
assert.doesNotMatch(citizenSurface, /src="\/images\/hang-up\.svg"/);

assert.doesNotMatch(citizenSurface, /\/api\/caller\//);
assert.doesNotMatch(citizenSurface, /\/api\/realtime\/admission\/caller/);
