export const CALLER_TO_CITIZEN_EVENT_TYPES = Object.freeze({
    'caller.operator.available.request': 'citizen.operator.available.request',
    'caller.operator.available.response': 'citizen.operator.available.response',
    'caller.operator.availability.probe': 'citizen.operator.availability.probe',
    'caller.call.request': 'citizen.call.request',
    'caller.call.ringing': 'citizen.call.ringing',
    'caller.call.cancel': 'citizen.call.cancel',
    'caller.call.cancelled': 'citizen.call.cancelled',
    'caller.call.declined': 'citizen.call.declined',
    'caller.call.answered': 'citizen.call.answered',
    'caller.call.ready': 'citizen.call.ready',
    'caller.call.timed_out': 'citizen.call.timed_out',
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
});

export const CITIZEN_TO_CALLER_EVENT_TYPES = Object.freeze(
    Object.fromEntries(
        Object.entries(CALLER_TO_CITIZEN_EVENT_TYPES)
            .map(([callerEvent, citizenEvent]) => [citizenEvent, callerEvent]),
    ),
);

export const CITIZEN_EVENT_TYPES = Object.freeze(
    Object.values(CALLER_TO_CITIZEN_EVENT_TYPES),
);

export const LEGACY_CALLER_EVENT_TYPES = Object.freeze(
    Object.keys(CALLER_TO_CITIZEN_EVENT_TYPES),
);

export function citizenEventType(eventType) {
    const normalized = String(eventType ?? '').trim();

    return CALLER_TO_CITIZEN_EVENT_TYPES[normalized] ?? normalized;
}

export function legacyCallerEventType(eventType) {
    const normalized = String(eventType ?? '').trim();

    return CITIZEN_TO_CALLER_EVENT_TYPES[normalized] ?? normalized;
}

export function isCitizenRealtimeEvent(eventType) {
    return Object.prototype.hasOwnProperty.call(
        CITIZEN_TO_CALLER_EVENT_TYPES,
        String(eventType ?? '').trim(),
    );
}

export function isLegacyCallerRealtimeEvent(eventType) {
    return Object.prototype.hasOwnProperty.call(
        CALLER_TO_CITIZEN_EVENT_TYPES,
        String(eventType ?? '').trim(),
    );
}

export function withCitizenRealtimePayloadAliases(payload = {}) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return {};
    }

    const aliases = { ...payload };

    aliasValue(aliases, 'caller_id', 'citizen_id');
    aliasValue(aliases, 'caller_name', 'citizen_name');
    aliasValue(aliases, 'caller_avatar', 'citizen_avatar');
    aliasValue(aliases, 'caller_location', 'citizen_location');
    aliasValue(aliases, 'caller_latitude', 'citizen_latitude');
    aliasValue(aliases, 'caller_longitude', 'citizen_longitude');

    return aliases;
}

function aliasValue(target, legacyKey, citizenKey) {
    const legacyValue = target[legacyKey];
    const citizenValue = target[citizenKey];

    if (citizenValue === undefined && legacyValue !== undefined) {
        target[citizenKey] = legacyValue;
    }

    if (legacyValue === undefined && citizenValue !== undefined) {
        target[legacyKey] = citizenValue;
    }
}
