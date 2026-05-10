import assert from 'node:assert/strict';

import {
    CALLER_TO_CITIZEN_EVENT_TYPES,
    CITIZEN_EVENT_TYPES,
    CITIZEN_TO_CALLER_EVENT_TYPES,
    LEGACY_CALLER_EVENT_TYPES,
    citizenEventType,
    isCitizenRealtimeEvent,
    isLegacyCallerRealtimeEvent,
    legacyCallerEventType,
    withCitizenRealtimePayloadAliases,
} from '../../resources/js/realtime/citizenEvents.js';

const requiredMappings = {
    'caller.operator.available.request': 'citizen.operator.available.request',
    'caller.operator.available.response': 'citizen.operator.available.response',
    'caller.call.request': 'citizen.call.request',
    'caller.call.ringing': 'citizen.call.ringing',
    'caller.call.cancel': 'citizen.call.cancel',
    'caller.call.cancelled': 'citizen.call.cancelled',
    'caller.call.declined': 'citizen.call.declined',
    'caller.call.answered': 'citizen.call.answered',
    'caller.call.ready': 'citizen.call.ready',
    'caller.location.updated': 'citizen.location.updated',
    'caller.reconnect.availability.request': 'citizen.reconnect.availability.request',
    'caller.reconnect.availability.response': 'citizen.reconnect.availability.response',
    'caller.reconnect.request': 'citizen.reconnect.request',
    'caller.reconnect.ringing': 'citizen.reconnect.ringing',
    'caller.reconnect.cancel': 'citizen.reconnect.cancel',
    'caller.reconnect.cancelled': 'citizen.reconnect.cancelled',
    'caller.reconnect.declined': 'citizen.reconnect.declined',
    'caller.reconnect.timed_out': 'citizen.reconnect.timed_out',
    'caller.reconnect.answered': 'citizen.reconnect.answered',
};

assert.deepEqual(
    Object.fromEntries(
        Object.entries(CALLER_TO_CITIZEN_EVENT_TYPES)
            .filter(([callerEvent]) => Object.hasOwn(requiredMappings, callerEvent)),
    ),
    requiredMappings,
);

assert.equal(CITIZEN_EVENT_TYPES.length, LEGACY_CALLER_EVENT_TYPES.length);
assert.equal(CITIZEN_EVENT_TYPES.length, new Set(CITIZEN_EVENT_TYPES).size);
assert.equal(LEGACY_CALLER_EVENT_TYPES.length, new Set(LEGACY_CALLER_EVENT_TYPES).size);

for (const [callerEvent, citizenEvent] of Object.entries(CALLER_TO_CITIZEN_EVENT_TYPES)) {
    assert.equal(CITIZEN_TO_CALLER_EVENT_TYPES[citizenEvent], callerEvent);
    assert.equal(citizenEventType(callerEvent), citizenEvent);
    assert.equal(citizenEventType(citizenEvent), citizenEvent);
    assert.equal(legacyCallerEventType(citizenEvent), callerEvent);
    assert.equal(legacyCallerEventType(callerEvent), callerEvent);
    assert.equal(isLegacyCallerRealtimeEvent(callerEvent), true);
    assert.equal(isCitizenRealtimeEvent(citizenEvent), true);
}

const canonicalFlowEvent = 'citizen.call.answered';
const legacyCompatibilityEvent = 'caller.call.answered';

assert.equal(citizenEventType(canonicalFlowEvent), canonicalFlowEvent);
assert.equal(legacyCallerEventType(canonicalFlowEvent), legacyCompatibilityEvent);
assert.equal(citizenEventType(legacyCompatibilityEvent), canonicalFlowEvent);
assert.equal(legacyCallerEventType(legacyCompatibilityEvent), legacyCompatibilityEvent);

assert.equal(citizenEventType('hotline.incident.updated'), 'hotline.incident.updated');
assert.equal(legacyCallerEventType('hotline.incident.updated'), 'hotline.incident.updated');
assert.equal(isLegacyCallerRealtimeEvent('hotline.incident.updated'), false);
assert.equal(isCitizenRealtimeEvent('hotline.incident.updated'), false);

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
