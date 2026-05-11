import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const operatorSurface = await readFile(new URL('../../resources/js/surfaces/operatorSurface.js', import.meta.url), 'utf8');
const surfaceShared = await readFile(new URL('../../resources/js/surfaces/surfaceShared.js', import.meta.url), 'utf8');

assert.match(operatorSurface, /const liveMessages = appState\.runtime\.operatorWorkbenchChat\?\.getMessages\?\.\(\)/);
assert.match(operatorSurface, /payload\.messages = liveMessages/);

assert.match(surfaceShared, /function normalizeChatMessageAttachments\(attachments\)/);
assert.match(surfaceShared, /const alreadyNormalized = Object\.prototype\.hasOwnProperty\.call\(message \?\? \{\}, 'direction'\)/);
assert.match(surfaceShared, /senderName: message\.senderName \?\? formatStatusLabel/);
assert.match(surfaceShared, /timestamp: message\.timestamp \?\? formatDateTime\(message\.created_at\)/);
assert.match(surfaceShared, /name: attachment\.name \?\? attachment\.original_filename/);

assert.match(surfaceShared, /const CALL_SESSION_KEEPALIVE_MS = 60 \* 1000/);
assert.match(surfaceShared, /sessionKeepaliveTimerId: null/);
assert.match(surfaceShared, /const stopCallSessionKeepalive = \(\) => \{/);
assert.match(surfaceShared, /const sendCallSessionKeepalive = \(\) => \{[\s\S]+pingSessionKeepalive\(\)/);
assert.match(surfaceShared, /state\.sessionKeepaliveTimerId = window\.setInterval\(sendCallSessionKeepalive, CALL_SESSION_KEEPALIVE_MS\)/);
assert.match(surfaceShared, /startCallHeartbeat\(\);\s+startCallSessionKeepalive\(\);/);
assert.match(surfaceShared, /stopCallHeartbeat\(\);\s+stopCallSessionKeepalive\(\);/);

assert.match(operatorSurface, /function workbenchCallSummaryEndTime\(payload\)/);
assert.match(operatorSurface, /function mountWorkbenchCallSummaryTimer\(overlay, payload\)/);
assert.match(operatorSurface, /isTerminalIncidentStatus\(payload\?\.status\)/);
assert.match(operatorSurface, /data-workbench-call-duration/);
assert.match(operatorSurface, /const endedAtMarkup = isTerminal[\s\S]+Datetime Ended/);
assert.match(operatorSurface, /const callSummaryTimer = mountWorkbenchCallSummaryTimer\(overlay, payload\)/);
