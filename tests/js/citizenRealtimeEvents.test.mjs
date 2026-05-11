import assert from 'node:assert/strict';

import {
    CITIZEN_EVENT_TYPES,
    citizenEventType,
    isCitizenRealtimeEvent,
    withCitizenRealtimePayloadAliases,
} from '../../resources/js/realtime/citizenEvents.js';

const requiredEvents = [
    'citizen.operator.available.request',
    'citizen.operator.available.response',
    'citizen.operator.availability.probe',
    'citizen.call.request',
    'citizen.call.ringing',
    'citizen.call.cancel',
    'citizen.call.cancelled',
    'citizen.call.declined',
    'citizen.call.answered',
    'citizen.call.ready',
    'citizen.call.timed_out',
    'citizen.location.updated',
    'citizen.reconnect.availability.request',
    'citizen.reconnect.availability.response',
    'citizen.reconnect.request',
    'citizen.reconnect.ringing',
    'citizen.reconnect.cancel',
    'citizen.reconnect.cancelled',
    'citizen.reconnect.declined',
    'citizen.reconnect.timed_out',
    'citizen.reconnect.answered',
];

assert.deepEqual(CITIZEN_EVENT_TYPES, requiredEvents);
assert.equal(CITIZEN_EVENT_TYPES.length, new Set(CITIZEN_EVENT_TYPES).size);

for (const eventType of CITIZEN_EVENT_TYPES) {
    assert.equal(citizenEventType(eventType), eventType);
    assert.equal(isCitizenRealtimeEvent(eventType), true);
}

assert.equal(citizenEventType('hotline.incident.updated'), 'hotline.incident.updated');
assert.equal(isCitizenRealtimeEvent('hotline.incident.updated'), false);
assert.equal(isCitizenRealtimeEvent('caller.call.answered'), false);

assert.deepEqual(
    withCitizenRealtimePayloadAliases({
        caller_id: 18,
        caller_name: 'Maria',
        caller_avatar: '/avatar.png',
        caller_location: { latitude: 10.3157, longitude: 123.8854 },
        caller_latitude: 10.3157,
        caller_longitude: 123.8854,
    }),
    {
        caller_id: 18,
        citizen_id: 18,
        caller_name: 'Maria',
        citizen_name: 'Maria',
        caller_avatar: '/avatar.png',
        citizen_avatar: '/avatar.png',
        caller_location: { latitude: 10.3157, longitude: 123.8854 },
        citizen_location: { latitude: 10.3157, longitude: 123.8854 },
        caller_latitude: 10.3157,
        citizen_latitude: 10.3157,
        caller_longitude: 123.8854,
        citizen_longitude: 123.8854,
    },
);

assert.deepEqual(
    withCitizenRealtimePayloadAliases({
        citizen_id: 19,
        citizen_name: 'Juan',
        citizen_location: { latitude: 11.1, longitude: 124.1 },
    }),
    {
        caller_id: 19,
        citizen_id: 19,
        caller_name: 'Juan',
        citizen_name: 'Juan',
        caller_location: { latitude: 11.1, longitude: 124.1 },
        citizen_location: { latitude: 11.1, longitude: 124.1 },
    },
);

assert.deepEqual(
    withCitizenRealtimePayloadAliases({
        citizen_id: 20,
        caller_id: 21,
        citizen_name: 'Canonical',
        caller_name: 'Legacy',
    }),
    {
        citizen_id: 20,
        caller_id: 21,
        citizen_name: 'Canonical',
        caller_name: 'Legacy',
    },
);
