export const CITIZEN_EVENT_TYPES = Object.freeze([
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
]);

const CITIZEN_EVENT_TYPE_SET = new Set(CITIZEN_EVENT_TYPES);

export function citizenEventType(eventType) {
    return String(eventType ?? '').trim();
}

export function isCitizenRealtimeEvent(eventType) {
    return CITIZEN_EVENT_TYPE_SET.has(String(eventType ?? '').trim());
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
