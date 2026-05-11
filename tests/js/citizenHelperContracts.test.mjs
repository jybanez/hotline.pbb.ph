import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const citizenSurface = await readFile(new URL('../../resources/js/surfaces/citizenSurface.js', import.meta.url), 'utf8');
const surfaceShared = await readFile(new URL('../../resources/js/surfaces/surfaceShared.js', import.meta.url), 'utf8');

assert.match(surfaceShared, /function publicViewerRoleAliases\(viewerRole\)/);
assert.match(surfaceShared, /brandSubtitle:\s*bootstrap\?\.app\?\.version \? `v\$\{bootstrap\.app\.version\}` : 'hotline\.pbb\.ph'/);
assert.match(surfaceShared, /const canRetryCsrf = \(\s*status === 419/);
assert.match(surfaceShared, /_hotlineSessionRestoredRetried: true/);
assert.match(surfaceShared, /return window\.axios\(retryConfig\)/);
assert.match(surfaceShared, /function sessionLifetimeMinutes\(\) \{\s+return Math\.max\(1, Number\(appState\.bootstrap\?\.session_lifetime_minutes \?\? 15\) \|\| 15\);\s+\}/);
assert.doesNotMatch(surfaceShared, /return Math\.max\(lifetime, 43200\)/);
assert.match(surfaceShared, /function logSessionKeepaliveDecision\(step, detail = \{\}\)/);
assert.match(surfaceShared, /hotlineSessionDebug/);
const csrfRetryBlock = surfaceShared.slice(
  surfaceShared.indexOf('const canRetryCsrf = ('),
  surfaceShared.indexOf('if (canRetryCsrf)')
);
assert.doesNotMatch(csrfRetryBlock, /!url\.includes\('\/api\/logout'\)/);
assert.match(surfaceShared, /\['citizen', 'caller'\]\.includes\(viewerRole\)/);
assert.match(surfaceShared, /function isPublicViewerRole\(viewerRole\)/);
assert.match(surfaceShared, /viewerRoleAliases\.includes\(message\.sender_role\)/);
assert.match(surfaceShared, /isPublicViewerRole\(viewerRole\) && state\.joinedRoom/);

assert.match(citizenSurface, /mountChatThread\(runtime\.chatHost, payload\.messages \?\? \[\], 'citizen'/);
assert.match(citizenSurface, /mountChatThread\(callerThreadHost, incident\.messages, 'citizen'/);
assert.match(citizenSurface, /mountRealtimeIncidentChat\(\{\s+incidentId:[\s\S]+viewerRole: 'citizen'/);
assert.match(citizenSurface, /mountRealtimeCallSession\(\{\s+callSessionId:[\s\S]+viewerRole: 'citizen'/);
assert.match(citizenSurface, /currentDisplayName: String\(appState\.bootstrap\?\.user\?\.name \?\? 'Citizen'\)/);
assert.match(citizenSurface, /reason: 'ended-by-citizen'/);

assert.match(citizenSurface, /helper\.createMediaStrip\(mediaHost, nextItems/);
assert.match(citizenSurface, /helper\.incidentTypesHelper\(panel/);
assert.match(citizenSurface, /helper\.incidentAssignmentsHelper\(panel/);
assert.match(citizenSurface, /helper\.createTabs\(tabsHost/);
assert.match(citizenSurface, /trackSurfaceInstance\(mountChatComposer/);
assert.match(citizenSurface, /async function openCallerIncident\(root, incidentId\) \{[\s\S]+status === 401 \|\| status === 419[\s\S]+return;/);
assert.match(citizenSurface, /button\.addEventListener\('click', \(\) => \{\s+void openCallerIncident\(root, button\.dataset\.openCallerIncident\);/);

assert.doesNotMatch(citizenSurface, /viewerRole: 'caller'/);
assert.doesNotMatch(citizenSurface, /mountChatThread\([^;\n]+, 'caller'/);
assert.doesNotMatch(citizenSurface, /reason: 'ended-by-caller'/);
