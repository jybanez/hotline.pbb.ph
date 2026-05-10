import { appState, availabilityPillClass, clearCallerPendingState, createIconMarkup, ensureHelperUi, escapeHtml, evaluateDevicePrimer, fetchJson, formatDateTime, formatIncidentStatusHeading, formatStatusLabel, getCallerPendingState, handleCommandBroadcastEnvelope, latestCallSession, logCallFlow, mergeIncidentMediaItems, mountChatComposer, mountChatThread, mountRealtimeCallSession, mountRealtimeIncidentChat, mountSurfaceChrome, primerStatusButton, setCallerPendingState, sharedShell, showToast, trackSurfaceInstance, wirePrimer } from './surfaceShared.js';
import { renderSurface } from './renderSurface.js';
import { buildAppEventPublishPayload, buildPresenceSubscribePayload, buildRoomJoinPayload, listPresenceRosterItems, parseRealtimeEnvelope, reducePresenceRosterEvent, RealtimeSocketClient } from '../../../../realtime/resources/js/sdk/index.js';
import { mountRealtimeSignalStrength } from '../features/realtimeSignalStrength.js';
import { citizenEventType, isLegacyCallerRealtimeEvent, legacyCallerEventType, withCitizenRealtimePayloadAliases } from '../realtime/citizenEvents.js';

const CALL_DISCOVERY_ROOM = 'presence.global.hotline';
const INCIDENT_MEDIA_ROOM_PREFIX = 'hotline.media.incident.';
const INCIDENT_UPDATE_EVENT = 'hotline.incident.updated';
const TERMINAL_INCIDENT_STATUSES = new Set(['Discarded', 'Resolved']);
const CALLER_HANGUP_CONFIRM_TIMEOUT_MS = 3000;
const CALLER_HANGUP_COMPLETE_TIMEOUT_MS = 10000;
const CALLER_DISCOVERY_RESPONSE_TIMEOUT_MIN_MS = 1500;
const CALLER_DISCOVERY_RESPONSE_TIMEOUT_MAX_MS = 4000;
const CALLER_CALL_TIMEOUT_FALLBACK_SECONDS = 30;
const CALLER_LOCATION_SIGNAL_MIN_DISTANCE_METERS = 15;
const CALLER_LOCATION_SIGNAL_MIN_INTERVAL_MS = 5000;
const CALLER_LOCATION_SIGNAL_MIN_HEADING_DEGREES = 30;
const CALLER_LOCATION_SIGNAL_MIN_ALTITUDE_METERS = 5;
const CALLER_LOCATION_SIGNAL_ACCURACY_IMPROVEMENT_RATIO = 0.35;
const CALLER_LOCATION_SIGNAL_MIN_ACCURACY_IMPROVEMENT_METERS = 8;
const CALLER_REALTIME_RECONNECT_MIN_MS = 1000;
const CALLER_REALTIME_RECONNECT_MAX_MS = 15000;

function normalizeHeadingDegrees(value) {
    const heading = Number(value);

    if (!Number.isFinite(heading)) {
        return null;
    }

    return ((heading % 360) + 360) % 360;
}

function headingDeltaDegrees(a, b) {
    const first = normalizeHeadingDegrees(a);
    const second = normalizeHeadingDegrees(b);

    if (first === null || second === null) {
        return Number.POSITIVE_INFINITY;
    }

    const delta = Math.abs(first - second) % 360;

    return Math.min(delta, 360 - delta);
}

function currentCallerOrientation() {
    const runtime = callerLocationRuntime();

    return runtime.orientationHeading === null || runtime.orientationHeading === undefined
        ? null
        : {
            heading: runtime.orientationHeading,
            heading_source: runtime.orientationSource || 'device-orientation',
        };
}

function normalizeCallerLocation(position) {
    const coords = position?.coords ?? position;
    const latitude = Number(coords?.latitude ?? coords?.caller_latitude ?? coords?.lat ?? NaN);
    const longitude = Number(coords?.longitude ?? coords?.caller_longitude ?? coords?.lng ?? NaN);
    const orientation = currentCallerOrientation();
    const heading = normalizeHeadingDegrees(
        coords?.heading
            ?? position?.heading
            ?? orientation?.heading
            ?? null,
    );
    const altitude = Number(coords?.altitude ?? position?.altitude ?? NaN);
    const altitudeAccuracy = Number(coords?.altitudeAccuracy ?? position?.altitude_accuracy ?? position?.altitudeAccuracy ?? NaN);

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        return null;
    }

    if (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
        return null;
    }

    return {
        latitude,
        longitude,
        accuracy: Number.isFinite(Number(coords?.accuracy)) ? Number(coords.accuracy) : null,
        altitude: Number.isFinite(altitude) ? altitude : null,
        altitude_accuracy: Number.isFinite(altitudeAccuracy) ? altitudeAccuracy : null,
        heading,
        heading_source: heading === null
            ? null
            : (
                coords?.heading !== null && coords?.heading !== undefined
                    ? 'gps-course'
                    : (position?.heading_source ?? orientation?.heading_source ?? 'device-orientation')
            ),
        captured_at: position?.timestamp
            ? new Date(position.timestamp).toISOString()
            : new Date().toISOString(),
    };
}

function callerLocationRuntime() {
    if (!appState.runtime.callerLocationRuntime) {
        appState.runtime.callerLocationRuntime = {
            lastLocation: null,
            lastSignalledLocation: null,
            orientationHeading: null,
            orientationSource: '',
            orientationStop: null,
            watchId: null,
            stop: null,
        };
    }

    return appState.runtime.callerLocationRuntime;
}

function callerLocationRequestOptions(timeoutMs = 3500) {
    return {
        enableHighAccuracy: true,
        maximumAge: 15000,
        timeout: Math.max(1000, Number(timeoutMs) || 3500),
    };
}

function updateCallerLocation(position) {
    const location = normalizeCallerLocation(position);

    if (!location) {
        return null;
    }

    const runtime = callerLocationRuntime();
    runtime.lastLocation = location;
    appState.runtime.callerLocation = location;

    return location;
}

function captureCallerLocationOnce({ timeoutMs = 3500 } = {}) {
    if (!navigator.geolocation?.getCurrentPosition) {
        return Promise.resolve(callerLocationRuntime().lastLocation);
    }

    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (position) => resolve(updateCallerLocation(position)),
            () => resolve(callerLocationRuntime().lastLocation),
            callerLocationRequestOptions(timeoutMs),
        );
    });
}

function callerLocationPayload(location = callerLocationRuntime().lastLocation) {
    const nextLocation = normalizeCallerLocation(location);

    if (!nextLocation) {
        return {};
    }

    return {
        caller_latitude: nextLocation.latitude,
        caller_longitude: nextLocation.longitude,
        caller_location: nextLocation,
    };
}

function distanceBetweenCallerLocationsMeters(a, b) {
    const first = normalizeCallerLocation(a);
    const second = normalizeCallerLocation(b);

    if (!first || !second) {
        return Number.POSITIVE_INFINITY;
    }

    const earthRadiusMeters = 6371000;
    const toRadians = (value) => value * Math.PI / 180;
    const lat1 = toRadians(first.latitude);
    const lat2 = toRadians(second.latitude);
    const deltaLat = toRadians(second.latitude - first.latitude);
    const deltaLng = toRadians(second.longitude - first.longitude);
    const h = Math.sin(deltaLat / 2) ** 2
        + Math.cos(lat1) * Math.cos(lat2) * Math.sin(deltaLng / 2) ** 2;

    return 2 * earthRadiusMeters * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

function callerLocationAccuracyMeters(location) {
    const accuracy = Number(location?.accuracy ?? NaN);

    return Number.isFinite(accuracy) && accuracy > 0 ? accuracy : null;
}

function callerLocationMovementThresholdMeters(previousLocation, nextLocation) {
    const accuracies = [callerLocationAccuracyMeters(previousLocation), callerLocationAccuracyMeters(nextLocation)]
        .filter((value) => value !== null);

    if (!accuracies.length) {
        return CALLER_LOCATION_SIGNAL_MIN_DISTANCE_METERS;
    }

    return Math.max(
        CALLER_LOCATION_SIGNAL_MIN_DISTANCE_METERS,
        Math.min(75, Math.max(...accuracies) * 0.75),
    );
}

function callerLocationAccuracyImproved(previousLocation, nextLocation) {
    const previousAccuracy = callerLocationAccuracyMeters(previousLocation);
    const nextAccuracy = callerLocationAccuracyMeters(nextLocation);

    if (previousAccuracy === null || nextAccuracy === null || nextAccuracy >= previousAccuracy) {
        return false;
    }

    return (previousAccuracy - nextAccuracy) >= Math.max(
        CALLER_LOCATION_SIGNAL_MIN_ACCURACY_IMPROVEMENT_METERS,
        previousAccuracy * CALLER_LOCATION_SIGNAL_ACCURACY_IMPROVEMENT_RATIO,
    );
}

function callerLocationAltitudeChanged(previousLocation, nextLocation) {
    const previousAltitude = Number(previousLocation?.altitude ?? NaN);
    const nextAltitude = Number(nextLocation?.altitude ?? NaN);

    if (!Number.isFinite(previousAltitude) || !Number.isFinite(nextAltitude)) {
        return false;
    }

    const altitudeAccuracy = Math.max(
        Number(previousLocation?.altitude_accuracy ?? 0) || 0,
        Number(nextLocation?.altitude_accuracy ?? 0) || 0,
        CALLER_LOCATION_SIGNAL_MIN_ALTITUDE_METERS,
    );

    return Math.abs(previousAltitude - nextAltitude) >= altitudeAccuracy;
}

function shouldSignalCallerLocation(nextLocation) {
    const runtime = callerLocationRuntime();
    const previousLocation = runtime.lastSignalledLocation;

    if (!previousLocation) {
        return true;
    }

    const previousCapturedAt = Date.parse(previousLocation.captured_at ?? '') || 0;
    const nextCapturedAt = Date.parse(nextLocation?.captured_at ?? '') || Date.now();
    const elapsedMs = nextCapturedAt - previousCapturedAt;
    const distanceMeters = distanceBetweenCallerLocationsMeters(previousLocation, nextLocation);
    const movementThresholdMeters = callerLocationMovementThresholdMeters(previousLocation, nextLocation);

    if (elapsedMs < CALLER_LOCATION_SIGNAL_MIN_INTERVAL_MS) {
        return false;
    }

    return distanceMeters >= movementThresholdMeters
        || callerLocationAccuracyImproved(previousLocation, nextLocation)
        || (
            distanceMeters >= Math.max(5, movementThresholdMeters / 2)
            && headingDeltaDegrees(previousLocation?.heading, nextLocation?.heading) >= CALLER_LOCATION_SIGNAL_MIN_HEADING_DEGREES
        )
        || callerLocationAltitudeChanged(previousLocation, nextLocation);
}

function sendCallerLocationSignal(callRuntime, location) {
    const nextLocation = normalizeCallerLocation(location);

    if (!nextLocation || !shouldSignalCallerLocation(nextLocation)) {
        return;
    }

    const sharedEvent = publishCallerLocationUpdate(nextLocation);

    if (!sharedEvent && callRuntime?.sendSignal) {
        callRuntime.sendSignal('caller-location', {
            ...callerLocationPayload(nextLocation),
            meta: {
                source: 'caller-geolocation',
                fallback: 'call-signal',
            },
        });
        callerLocationRuntime().lastSignalledLocation = nextLocation;
        return;
    }

    if (sharedEvent) {
        callerLocationRuntime().lastSignalledLocation = nextLocation;
    }
}

async function requestCallerOrientationAccess() {
    const OrientationEvent = window.DeviceOrientationEvent;

    if (!OrientationEvent) {
        return false;
    }

    if (typeof OrientationEvent.requestPermission !== 'function') {
        return true;
    }

    try {
        return await OrientationEvent.requestPermission() === 'granted';
    } catch {
        return false;
    }
}

function startCallerOrientationWatch(callRuntime) {
    const runtime = callerLocationRuntime();
    runtime.orientationStop?.();

    if (!window.DeviceOrientationEvent || !window.addEventListener) {
        return null;
    }

    const handleOrientation = (event) => {
        const webkitHeading = Number(event?.webkitCompassHeading ?? NaN);
        const alpha = Number(event?.alpha ?? NaN);
        const heading = Number.isFinite(webkitHeading)
            ? normalizeHeadingDegrees(webkitHeading)
            : (Number.isFinite(alpha) ? normalizeHeadingDegrees(360 - alpha) : null);

        if (heading === null) {
            return;
        }

        runtime.orientationHeading = heading;
        runtime.orientationSource = Number.isFinite(webkitHeading) ? 'webkit-compass' : 'device-orientation';

        if (runtime.lastLocation) {
            const location = normalizeCallerLocation({
                ...runtime.lastLocation,
                heading,
                heading_source: runtime.orientationSource,
            });
            runtime.lastLocation = location;
            appState.runtime.callerLocation = location;
            sendCallerLocationSignal(callRuntime, location);
        }
    };

    window.addEventListener('deviceorientationabsolute', handleOrientation);
    window.addEventListener('deviceorientation', handleOrientation);

    runtime.orientationStop = () => {
        window.removeEventListener('deviceorientationabsolute', handleOrientation);
        window.removeEventListener('deviceorientation', handleOrientation);
        runtime.orientationStop = null;
    };

    return runtime.orientationStop;
}

function startCallerLocationWatch(callRuntime) {
    const runtime = callerLocationRuntime();
    runtime.stop?.();

    if (!navigator.geolocation?.watchPosition) {
        return null;
    }

    const signalCurrentLocation = () => {
        const location = runtime.lastLocation ?? appState.runtime.callerLocation ?? null;
        sendCallerLocationSignal(callRuntime, location);
    };

    const orientationStop = startCallerOrientationWatch(callRuntime);
    window.setTimeout(signalCurrentLocation, 1500);

    const watchId = navigator.geolocation.watchPosition(
        (position) => {
            const location = updateCallerLocation(position);
            sendCallerLocationSignal(callRuntime, location);
        },
        () => {},
        callerLocationRequestOptions(10000),
    );

    runtime.watchId = watchId;
    runtime.stop = () => {
        if (runtime.watchId !== null && navigator.geolocation?.clearWatch) {
            navigator.geolocation.clearWatch(runtime.watchId);
        }

        orientationStop?.();
        runtime.watchId = null;
        runtime.stop = null;
    };

    return runtime.stop;
}

function callerDiscoveryClient() {
    return appState.runtime.callerRealtimeStream?.client ?? null;
}

function callerRealtimeReconnectRuntime() {
    if (!appState.runtime.callerRealtimeReconnect) {
        appState.runtime.callerRealtimeReconnect = {
            attempts: 0,
            connecting: false,
            timerId: null,
        };
    }

    return appState.runtime.callerRealtimeReconnect;
}

function clearCallerRealtimeReconnectTimer() {
    const runtime = appState.runtime.callerRealtimeReconnect;

    if (runtime?.timerId) {
        window.clearTimeout(runtime.timerId);
        runtime.timerId = null;
    }

    appState.runtime.callerRealtimeSignal?.setReconnectRuntime?.(runtime);
}

function resetCallerRealtimeJoinState() {
    resetCallerDiscoveryPresence();
    callerMediaRoomsRuntime().joined.clear();
}

function scheduleCallerRealtimeReconnect() {
    if (!appState.bootstrap?.authenticated || !['citizen', 'caller'].includes(appState.activeSurface)) {
        return;
    }

    const runtime = callerRealtimeReconnectRuntime();

    if (runtime.timerId || runtime.connecting) {
        return;
    }

    runtime.attempts = Math.min(runtime.attempts + 1, 8);

    const baseDelay = Math.min(
        CALLER_REALTIME_RECONNECT_MAX_MS,
        CALLER_REALTIME_RECONNECT_MIN_MS * (2 ** (runtime.attempts - 1)),
    );
    const jitter = Math.floor(Math.random() * 350);

    runtime.timerId = window.setTimeout(() => {
        runtime.timerId = null;
        appState.runtime.callerRealtimeSignal?.setReconnectRuntime?.(runtime);
        void connectCallerRealtimeStream({ reconnect: true });
    }, baseDelay + jitter);
    appState.runtime.callerRealtimeSignal?.setReconnectRuntime?.(runtime);
}

function publishCallerCallFlow(eventType, payload = {}) {
    const client = callerDiscoveryClient();
    const canonicalEventType = citizenEventType(eventType);

    logCallFlow('citizen', 'discovery-event-publish-attempt', {
        eventType,
        canonicalEventType,
        incidentId: Number(payload?.incident_id ?? 0) || null,
        callAttemptId: Number(payload?.call_attempt_id ?? 0) || null,
        callSessionId: Number(payload?.call_session_id ?? 0) || null,
        clientOpen: Boolean(client?.isOpen?.()),
    });

    if (!client?.isOpen?.()) {
        logCallFlow('citizen', 'discovery-event-publish-skip-client-closed', {
            eventType,
            canonicalEventType,
        });
        return null;
    }

    return client.sendRequest(
        'app.event.publish',
        CALL_DISCOVERY_ROOM,
        buildAppEventPublishPayload(canonicalEventType, withCitizenRealtimePayloadAliases(payload)),
    );
}

function logLegacyCallerRealtimeEventUsage(envelope) {
    const eventType = String(envelope?.type ?? '').trim();

    if (!isLegacyCallerRealtimeEvent(eventType)) {
        return;
    }

    void fetchJson('/api/realtime/legacy-caller-events', {
        method: 'post',
        data: {
            surface: 'citizen',
            event_type: eventType,
            canonical_event_type: citizenEventType(eventType),
            room: String(envelope?.room ?? '').trim() || null,
        },
    }).catch((error) => {
        console.warn('Legacy caller Realtime event telemetry failed.', error);
    });
}

function callerLocationEventContext() {
    const liveModal = appState.runtime.callerLiveModal ?? null;
    const livePayload = liveModal?.payload && typeof liveModal.payload === 'object'
        ? liveModal.payload
        : null;
    const currentIncident = appState.runtime.callerHome?.current_open_incident ?? null;
    const incident = livePayload ?? currentIncident ?? null;
    const latestSession = latestCallSession(incident);

    return {
        incident_id: Number(incident?.id ?? liveModal?.incidentId ?? 0),
        operator_id: Number(incident?.operator?.id ?? incident?.operator_id ?? liveModal?.operator_id ?? 0),
        call_session_id: Number(liveModal?.latestSessionId ?? latestSession?.id ?? 0),
    };
}

function publishCallerLocationUpdate(location) {
    const nextLocation = normalizeCallerLocation(location);
    const context = callerLocationEventContext();

    if (!nextLocation || !context.incident_id) {
        return null;
    }

    return publishCallerCallFlow('caller.location.updated', {
        caller_id: Number(appState.bootstrap?.user?.id ?? 0),
        caller_name: String(appState.bootstrap?.user?.name ?? 'Caller'),
        ...context,
        ...callerLocationPayload(nextLocation),
        updated_at: nextLocation.captured_at || new Date().toISOString(),
        meta: {
            source: 'caller-geolocation',
        },
    });
}

function callerIncidentMediaRoom(incidentId) {
    const nextIncidentId = Number(incidentId ?? 0);

    return nextIncidentId > 0 ? `${INCIDENT_MEDIA_ROOM_PREFIX}${nextIncidentId}` : '';
}

function callerMediaRoomsRuntime() {
    if (!appState.runtime.callerMediaRooms) {
        appState.runtime.callerMediaRooms = {
            requested: new Set(),
            joined: new Set(),
        };
    }

    return appState.runtime.callerMediaRooms;
}

function joinCallerIncidentMediaRoom(incidentId) {
    const room = callerIncidentMediaRoom(incidentId);

    if (!room) {
        return;
    }

    const mediaRooms = callerMediaRoomsRuntime();
    mediaRooms.requested.add(room);

    const stream = appState.runtime.callerRealtimeStream;
    const client = stream?.client ?? null;

    if (client?.isOpen?.() && !mediaRooms.joined.has(room)) {
        client.sendRequest('room.join.request', room, buildRoomJoinPayload());
    }
}

function patchIncidentCallSession(payload, callSessionId, patch = {}) {
    if (!payload || !callSessionId) {
        return payload;
    }

    return {
        ...payload,
        call_history: (Array.isArray(payload.call_history) ? payload.call_history : []).map((session) => (
            Number(session?.id ?? 0) === Number(callSessionId)
                ? {
                    ...session,
                    ...patch,
                }
                : session
        )),
    };
}

function syncCallerCurrentIncident(nextIncident) {
    appState.runtime.callerHome = {
        ...(appState.runtime.callerHome ?? {}),
        current_open_incident: nextIncident ?? null,
    };

    if (nextIncident?.id) {
        joinCallerIncidentMediaRoom(nextIncident.id);
    }

    if (appState.runtime.callerLiveModal) {
        appState.runtime.callerLiveModal.payload = nextIncident
            ? {
                ...(appState.runtime.callerLiveModal.payload ?? nextIncident),
                ...nextIncident,
            }
            : null;
    }
}

function destroyCallerIncidentOverlay(root) {
    const overlay = root?.querySelector?.('[data-caller-incident-overlay]');
    const runtime = overlay?.__callerIncidentOverlayRuntime ?? null;

    runtime?.tabsApi?.destroy?.();
    runtime?.mediaStripApi?.destroy?.();
    runtime?.incidentTypesApi?.destroy?.();
    runtime?.assignmentsApi?.destroy?.();
    runtime?.chatThreadApi?.destroy?.();

    if (overlay) {
        overlay.__callerIncidentOverlayRuntime = null;
        overlay.remove();
    }
}

function callerWorkbenchLookups() {
    const home = appState.runtime.callerHome ?? {};

    return {
        incidentTypeCategories: Array.isArray(home.incident_type_categories) ? home.incident_type_categories : [],
        incidentTypeCatalog: Array.isArray(home.incident_type_catalog) ? home.incident_type_catalog : [],
        resourceTypes: Array.isArray(home.resource_types) ? home.resource_types : [],
        teamCategories: Array.isArray(home.team_categories) ? home.team_categories : [],
        teams: Array.isArray(home.teams) ? home.teams : [],
    };
}

function currentAudioGraphStyle() {
    return String(appState.bootstrap?.settings?.audio_graph_style ?? 'vu').trim() || 'vu';
}

function applyCallerIncidentPatch(incidentId, patch = {}) {
    const currentIncident = appState.runtime.callerHome?.current_open_incident ?? null;
    const nextIncidentId = Number(incidentId ?? 0);

    if (!currentIncident || nextIncidentId <= 0 || Number(currentIncident?.id ?? 0) !== nextIncidentId) {
        return false;
    }

    const nextIncident = {
        ...currentIncident,
        ...(patch && typeof patch === 'object' ? patch : {}),
    };

    const root = appState.runtime.callerRoot;
    const nextStatus = String(nextIncident?.status ?? '').trim();

    if (TERMINAL_INCIDENT_STATUSES.has(nextStatus)) {
        syncCallerCurrentIncident(null);
        clearCallerPendingState();
        destroyCallerIncidentOverlay(root);
        closeCallerLiveModal(root);
        rerenderCallerInPlace();
        refreshCallerAvailabilityFromPresence();
        return true;
    }

    syncCallerCurrentIncident(nextIncident);
    const overlay = root?.querySelector?.('[data-caller-incident-overlay]');

    if (overlay) {
        syncCallerIncidentOverlayInPlace(overlay, nextIncident, {
            syncMedia: false,
        });
        return true;
    }

    rerenderCallerInPlace();

    return true;
}

function applyCallerIncidentMediaUpdate(incidentId, nextMedia) {
    const currentIncident = appState.runtime.callerHome?.current_open_incident ?? null;
    const nextIncidentId = Number(incidentId ?? 0);

    if (!currentIncident || nextIncidentId <= 0 || Number(currentIncident?.id ?? 0) !== nextIncidentId || !nextMedia) {
        return false;
    }

    const nextIncident = {
        ...currentIncident,
        media: mergeIncidentMediaItems(currentIncident?.media, nextMedia),
    };

    syncCallerCurrentIncident(nextIncident);

    const root = appState.runtime.callerRoot;
    const overlay = root?.querySelector?.('[data-caller-incident-overlay]');

    if (overlay) {
        syncCallerIncidentOverlayInPlace(overlay, nextIncident, {
            syncMedia: true,
        });
    }

    return true;
}

function markCallerLiveConnectionReady(callSessionId, answeredAt) {
    const liveModal = appState.runtime.callerLiveModal;

    if (!liveModal || Number(liveModal.latestSessionId ?? 0) !== Number(callSessionId)) {
        return false;
    }

    liveModal.callRuntime?.setMediaMuted?.(false);

    if (liveModal.payload) {
        liveModal.payload = patchIncidentCallSession(liveModal.payload, callSessionId, {
            answered_at: answeredAt,
        });
        syncCallerCurrentIncident(liveModal.payload);
    }

    return true;
}

function collapseCallerConnectingState(callSessionId, incidentId, answeredAt) {
    const pendingState = getCallerPendingState();
    const nextCallSessionId = Number(callSessionId ?? 0);
    const nextIncidentId = Number(incidentId ?? 0);
    const nextAnsweredAt = String(answeredAt ?? '').trim() || new Date().toISOString();

    if (
        pendingState
        && (
            (
                pendingState.kind === 'new_call'
                && nextCallSessionId > 0
            )
            || (
                pendingState.kind === 'reconnect'
                && (
                    Number(pendingState.incident_id ?? 0) === nextIncidentId
                    || (nextCallSessionId > 0 && Number(pendingState.call_session_id ?? 0) === nextCallSessionId)
                )
            )
        )
    ) {
        clearCallerPendingState();
    }

    const root = appState.runtime.callerRoot;
    root?.querySelector?.('[data-caller-pending-overlay]')?.remove?.();
    markCallerLiveConnectionReady(nextCallSessionId, nextAnsweredAt);
}

function preferredFemaleToastVoiceName() {
    const voices = typeof appState.helper.toast?.getVoices === 'function'
        ? appState.helper.toast.getVoices()
        : (typeof window !== 'undefined' && 'speechSynthesis' in window && typeof window.speechSynthesis.getVoices === 'function'
            ? window.speechSynthesis.getVoices()
            : []);

    if (!Array.isArray(voices) || voices.length === 0) {
        return '';
    }

    const preferredMatchers = [
        /female/i,
        /\bSamantha\b/i,
        /\bKaren\b/i,
        /\bMoira\b/i,
        /\bTessa\b/i,
        /\bAva\b/i,
        /\bAllison\b/i,
        /\bSusan\b/i,
        /\bSerena\b/i,
        /\bVeena\b/i,
        /\bZira\b/i,
        /\bHazel\b/i,
        /\bHeera\b/i,
        /\bCatherine\b/i,
        /\bLinda\b/i,
    ];

    for (const matcher of preferredMatchers) {
        const match = voices.find((voice) => matcher.test(String(voice?.name ?? '')));

        if (match?.name) {
            return String(match.name);
        }
    }

    return '';
}

function callerAlertToastContent(alertLevel) {
    switch (String(alertLevel ?? '').trim()) {
        case 'Elevated':
            return {
                title: 'Warning',
                message: 'Alert level now elevated. Please be alert.',
                tone: 'warn',
            };
        case 'Critical':
            return {
                title: 'Warning',
                message: 'Alert level now critical. Please follow emergency and evacuation protocols',
                tone: 'error',
            };
        case 'Normal':
        default:
            return {
                title: 'System Alert',
                message: 'Alert level back to normal',
                tone: 'info',
            };
    }
}

function ensureCallerSpeechPrimer() {
    if (appState.runtime.callerSpeechPrimed || typeof appState.runtime.callerSpeechPrimerCleanup === 'function') {
        return;
    }

    if (
        typeof window === 'undefined'
        || !('speechSynthesis' in window)
        || typeof window.SpeechSynthesisUtterance !== 'function'
    ) {
        return;
    }

    const prime = () => {
        if (appState.runtime.callerSpeechPrimed) {
            return;
        }

        appState.runtime.callerSpeechPrimed = true;

        try {
            const utterance = new window.SpeechSynthesisUtterance(' ');
            utterance.volume = 0;
            utterance.rate = 1;
            utterance.pitch = 1;
            window.speechSynthesis.speak(utterance);
            window.speechSynthesis.cancel();
        } catch {
            // Ignore warm-up failures; live alert speech can still attempt later.
        }

        cleanup();
    };

    const cleanup = () => {
        window.removeEventListener('pointerdown', prime, true);
        window.removeEventListener('touchstart', prime, true);
        window.removeEventListener('keydown', prime, true);
        appState.runtime.callerSpeechPrimerCleanup = null;
    };

    window.addEventListener('pointerdown', prime, true);
    window.addEventListener('touchstart', prime, true);
    window.addEventListener('keydown', prime, true);
    appState.runtime.callerSpeechPrimerCleanup = cleanup;
}

const CALLER_OPERATOR_UNAVAILABLE_MESSAGE = 'Operator is currently not available. Please try again later.';

async function showCallerOperatorUnavailableAlert(message = CALLER_OPERATOR_UNAVAILABLE_MESSAGE) {
    await ensureHelperUi();

    if (typeof appState.helper.uiAlert === 'function') {
        await appState.helper.uiAlert(message, {
            title: 'Operator Unavailable',
            variant: 'warning',
            speak: true,
        });
        return;
    }

    showToast(message, 'warn', {
        title: 'Operator Unavailable',
        speak: true,
        voiceName: preferredFemaleToastVoiceName(),
    });
}

function callerCallTimeoutMs() {
    const seconds = Number(appState.bootstrap?.settings?.call_timeout_seconds ?? CALLER_CALL_TIMEOUT_FALLBACK_SECONDS);

    return Math.max(5, seconds || CALLER_CALL_TIMEOUT_FALLBACK_SECONDS) * 1000;
}

function callerDiscoveryResponseTimeoutMs() {
    return Math.min(
        CALLER_DISCOVERY_RESPONSE_TIMEOUT_MAX_MS,
        Math.max(CALLER_DISCOVERY_RESPONSE_TIMEOUT_MIN_MS, Math.floor(callerCallTimeoutMs() / 3)),
    );
}

function normalizeOperatorIdList(ids) {
    return Array.from(new Set((Array.isArray(ids) ? ids : [])
        .map((id) => Number(id ?? 0))
        .filter((id) => id > 0)));
}

function callerAvailableOperatorIds(excludedOperatorIds = []) {
    const excluded = new Set(normalizeOperatorIdList(excludedOperatorIds));
    const roster = Array.isArray(appState.runtime.callerHome?.availability?.presence_roster)
        ? appState.runtime.callerHome.availability.presence_roster
        : [];

    return roster
        .filter((entry) => String(entry?.state ?? '').trim() === 'online')
        .map((entry) => Number(entry?.operator_id ?? 0))
        .filter((id) => id > 0 && !excluded.has(id));
}

function clearCallerCallRoutingTimers() {
    if (appState.runtime.callerDiscoveryResponseTimerId) {
        window.clearTimeout(appState.runtime.callerDiscoveryResponseTimerId);
        appState.runtime.callerDiscoveryResponseTimerId = null;
    }

    if (appState.runtime.callerRingingTimeoutTimerId) {
        window.clearTimeout(appState.runtime.callerRingingTimeoutTimerId);
        appState.runtime.callerRingingTimeoutTimerId = null;
    }
}

function callerCallAttemptExhausted(excludedOperatorIds = []) {
    const excluded = new Set(normalizeOperatorIdList(excludedOperatorIds));
    const available = callerAvailableOperatorIds();

    return available.length > 0 && available.every((operatorId) => excluded.has(operatorId));
}

async function closeCallerPendingAndShowUnavailable(message = CALLER_OPERATOR_UNAVAILABLE_MESSAGE) {
    clearCallerCallRoutingTimers();
    await closeCallerPendingOverlay(appState.runtime.callerRoot);
    clearCallerPendingState();
    rerenderCallerInPlace();
    await showCallerOperatorUnavailableAlert(message);
}

function publishCallerOperatorDiscoveryRequest(excludedOperatorIds = []) {
    const excluded = normalizeOperatorIdList(excludedOperatorIds);
    logCallFlow('citizen', 'operator-discovery-start', {
        excludedOperatorIds: excluded,
        callerId: Number(appState.bootstrap?.user?.id ?? 0) || null,
    });

    if (callerCallAttemptExhausted(excluded)) {
        logCallFlow('citizen', 'operator-discovery-exhausted', {
            excludedOperatorIds: excluded,
        });
        void closeCallerPendingAndShowUnavailable(CALLER_OPERATOR_UNAVAILABLE_MESSAGE);
        return false;
    }

    const pending = getCallerPendingState();

    setCallerPendingState({
        ...(pending ?? {}),
        kind: 'new_call',
        attempt_id: null,
        operator_id: null,
        operator_name: null,
        operator_avatar: '',
        operator_attempt_id: null,
        phase: 'discovering',
        excluded_operator_ids: excluded,
        discovery_requested_at: new Date().toISOString(),
    });

    if (appState.runtime.callerDiscoveryResponseTimerId) {
        window.clearTimeout(appState.runtime.callerDiscoveryResponseTimerId);
    }

    appState.runtime.callerDiscoveryResponseTimerId = window.setTimeout(() => {
        appState.runtime.callerDiscoveryResponseTimerId = null;
        const latest = getCallerPendingState();

        if (!latest || latest.kind !== 'new_call' || latest.operator_id || latest.phase !== 'discovering') {
            return;
        }

        void closeCallerPendingAndShowUnavailable(CALLER_OPERATOR_UNAVAILABLE_MESSAGE);
    }, callerDiscoveryResponseTimeoutMs());

    const published = publishCallerCallFlow('caller.operator.available.request', {
        caller_id: Number(appState.bootstrap?.user?.id ?? 0),
        excluded_operator_ids: excluded,
        ...callerLocationPayload(),
        requested_at: new Date().toISOString(),
    });

    if (!published) {
        logCallFlow('citizen', 'operator-discovery-publish-failed', {
            excludedOperatorIds: excluded,
        });
        clearCallerCallRoutingTimers();
    }

    return published;
}

function retryCallerCallDiscoveryAfterMiss(pending) {
    const missedOperatorId = Number(pending?.operator_id ?? 0);
    const missedAttemptId = Number(pending?.attempt_id ?? 0);
    const missedOperatorAttemptId = Number(pending?.operator_attempt_id ?? 0);
    const callerId = Number(appState.bootstrap?.user?.id ?? 0);
    const excluded = normalizeOperatorIdList([
        ...(Array.isArray(pending?.excluded_operator_ids) ? pending.excluded_operator_ids : []),
        missedOperatorId,
    ]);

    if (missedOperatorId > 0) {
        publishCallerCallFlow('caller.call.timed_out', {
            call_attempt_id: missedAttemptId,
            call_attempt_operator_attempt_id: missedOperatorAttemptId,
            caller_id: callerId,
            operator_id: missedOperatorId,
            outcome: 'timed_out',
            ended_at: new Date().toISOString(),
        });
    }

    if (missedAttemptId > 0 && missedOperatorAttemptId > 0) {
        void fetchJson(`/api/citizen/call-attempts/${missedAttemptId}/timeout`, {
            method: 'post',
        }).catch((error) => {
            if (Number(error?.response?.status ?? 0) !== 409) {
                console.warn('Caller call-attempt timeout update failed.', error);
            }
        });
    }

    if (callerCallAttemptExhausted(excluded)) {
        void closeCallerPendingAndShowUnavailable(CALLER_OPERATOR_UNAVAILABLE_MESSAGE);
        return;
    }

    setCallerPendingState({
        ...pending,
        operator_id: null,
        operator_name: null,
        operator_avatar: '',
        operator_attempt_id: null,
        phase: 'discovering',
        excluded_operator_ids: excluded,
    });

    rerenderCallerInPlace();
    void captureCallerLocationOnce({ timeoutMs: 1200 }).then(() => {
        publishCallerOperatorDiscoveryRequest(excluded);
    });
}

function scheduleCallerRingingTimeout(pending) {
    if (appState.runtime.callerRingingTimeoutTimerId) {
        window.clearTimeout(appState.runtime.callerRingingTimeoutTimerId);
    }

    const operatorId = Number(pending?.operator_id ?? 0);

    appState.runtime.callerRingingTimeoutTimerId = window.setTimeout(() => {
        appState.runtime.callerRingingTimeoutTimerId = null;
        const latest = getCallerPendingState();

        if (
            !latest
            || latest.kind !== 'new_call'
            || !['operator_found', 'ringing'].includes(String(latest.phase ?? '').trim())
            || Number(latest.operator_id ?? 0) !== operatorId
        ) {
            return;
        }

        retryCallerCallDiscoveryAfterMiss(latest);
    }, callerCallTimeoutMs() + 1000);
}

function rerenderCallerInPlace() {
    const root = appState.runtime.callerRoot;
    const home = appState.runtime.callerHome;
    const primerReport = appState.runtime.callerPrimerReport ?? evaluateDevicePrimer('citizen');

    if (!root || !home) {
        return false;
    }

    appState.runtime.callerPrimerReport = primerReport;
    renderCaller(root, appState.bootstrap, home, primerReport);
    return true;
}

function applyCallerAvailabilitySnapshot(nextAvailability = {}, { rerender = true } = {}) {
    const home = appState.runtime.callerHome;

    if (!home) {
        return;
    }

    appState.runtime.callerHome = {
        ...home,
        availability: {
            ...(home.availability ?? {}),
            ...nextAvailability,
        },
    };

    if (
        rerender
        && !appState.runtime.callerHome?.current_open_incident
        && !getCallerPendingState()
        && !appState.runtime.callerLiveModal
    ) {
        rerenderCallerInPlace();
    }
}

function callerPresenceRuntime() {
    if (!appState.runtime.callerPresence) {
        appState.runtime.callerPresence = {
            subscribed: false,
            roster: {},
        };
    }

    return appState.runtime.callerPresence;
}

function mergeCallerPresenceRosterEvent(roster, payload) {
    const nextRoster = reducePresenceRosterEvent(roster, payload);
    const subject = payload?.subject && typeof payload.subject === 'object'
        ? payload.subject
        : {};
    const rosterKey = String(subject.session_id || subject.user_id || '').trim();
    const meta = payload?.meta && typeof payload.meta === 'object' && !Array.isArray(payload.meta)
        ? payload.meta
        : null;

    if (!rosterKey || String(payload?.state ?? '').trim() === 'offline' || !meta || !nextRoster?.[rosterKey]) {
        return nextRoster;
    }

    nextRoster[rosterKey] = {
        ...nextRoster[rosterKey],
        meta: {
            ...meta,
        },
    };

    return nextRoster;
}

function refreshCallerAvailabilityFromPresence({ rerender = true } = {}) {
    const runtime = callerPresenceRuntime();
    const rosterItems = listPresenceRosterItems(runtime.roster);
    const currentUserId = String(appState.bootstrap?.user?.id ?? '').trim();
    const discoveryEntries = rosterItems.filter((entry) => (
        String(entry?.userId ?? '').trim() !== currentUserId
    ));
    const availableOperatorCount = discoveryEntries.filter((entry) => (
        String(entry?.state ?? '').trim() === 'online'
    )).length;
    const activeIncidentIds = Array.from(new Set(
        discoveryEntries
            .map((entry) => Number(entry?.meta?.incident_id ?? 0))
            .filter((incidentId) => incidentId > 0),
    ));

    applyCallerAvailabilitySnapshot({
        status: availableOperatorCount > 0 ? 'green' : 'yellow',
        service_reachable: true,
        call_service_ready: true,
        available_operator_count: availableOperatorCount,
        active_incident_ids: activeIncidentIds,
        presence_roster: discoveryEntries.map((entry) => ({
            operator_id: Number(entry?.userId ?? 0),
            state: String(entry?.state ?? '').trim(),
            status_text: String(entry?.statusText ?? '').trim(),
            incident_id: Number(entry?.meta?.incident_id ?? 0) || null,
            workbench_active: Boolean(entry?.meta?.workbench_active),
        })),
    }, { rerender });
}

function subscribeCallerDiscoveryPresence(client) {
    const runtime = callerPresenceRuntime();

    if (runtime.subscribed || !client?.isOpen?.()) {
        return;
    }

    client.sendRequest('presence.subscribe', CALL_DISCOVERY_ROOM, buildPresenceSubscribePayload(CALL_DISCOVERY_ROOM));
    runtime.subscribed = true;
}

function resetCallerDiscoveryPresence() {
    const runtime = appState.runtime.callerPresence;

    if (!runtime) {
        return;
    }

    runtime.subscribed = false;
    runtime.roster = {};
}

async function connectCallerRealtimeStream(options = {}) {
    if (!appState.bootstrap?.authenticated || !['citizen', 'caller'].includes(appState.activeSurface)) {
        return;
    }

    if (appState.runtime.callerRealtimeStream?.client) {
        return;
    }

    const reconnectRuntime = callerRealtimeReconnectRuntime();

    if (reconnectRuntime.connecting) {
        return;
    }

    reconnectRuntime.connecting = true;
    appState.runtime.callerRealtimeSignal?.setReconnectRuntime?.(reconnectRuntime);

    try {
        const admission = await fetchJson('/api/realtime/admission/citizen', {
            method: 'post',
            data: {
                context_type: 'surface_runtime',
                context_id: 0,
            },
        });

        const rooms = Array.isArray(admission?.rooms) ? admission.rooms.filter(Boolean) : [];

        if (!admission?.token || !admission?.websocket_url || rooms.length === 0) {
            reconnectRuntime.connecting = false;
            scheduleCallerRealtimeReconnect();
            return;
        }

        const joinedRooms = new Set();
        let streamRef = null;

        const client = new RealtimeSocketClient({
            websocketUrl: admission.websocket_url,
            token: admission.token,
            requestPrefix: 'citizen_surface',
            onOpen() {
                reconnectRuntime.connecting = false;
                reconnectRuntime.attempts = 0;
                clearCallerRealtimeReconnectTimer();
            },
            onError() {
                if (!streamRef?.destroyed) {
                    client.close();
                }
            },
            onClose() {
                reconnectRuntime.connecting = false;

                if (streamRef?.destroyed) {
                    return;
                }

                if (appState.runtime.callerRealtimeStream?.client === client) {
                    appState.runtime.callerRealtimeStream.client = null;
                }

                resetCallerRealtimeJoinState();
                scheduleCallerRealtimeReconnect();
            },
            onMessage(raw) {
                let envelope;

                try {
                    envelope = parseRealtimeEnvelope(raw);
                } catch {
                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'session.auth.request') {
                    rooms.forEach((room) => {
                        client.sendRequest('room.join.request', room, buildRoomJoinPayload());
                    });
                    callerMediaRoomsRuntime().requested.forEach((room) => {
                        client.sendRequest('room.join.request', room, buildRoomJoinPayload());
                    });
                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'room.join.request') {
                    const joinedRoom = String(envelope?.room ?? '');
                    joinedRooms.add(joinedRoom);

                    if (joinedRoom.startsWith(INCIDENT_MEDIA_ROOM_PREFIX)) {
                        callerMediaRoomsRuntime().joined.add(joinedRoom);
                    }

                    if (joinedRoom === CALL_DISCOVERY_ROOM) {
                        subscribeCallerDiscoveryPresence(client);
                        applyCallerAvailabilitySnapshot({
                            status: 'yellow',
                            service_reachable: true,
                            call_service_ready: true,
                            available_operator_count: 0,
                            active_incident_ids: [],
                            presence_roster: [],
                        }, { rerender: true });
                    }

                    return;
                }

                if (envelope?.phase !== 'event') {
                    return;
                }

                if (handleCommandBroadcastEnvelope(envelope)) {
                    return;
                }

                if (envelope?.type === 'hotline.alert_level.changed') {
                    const nextAlertLevel = String(envelope?.payload?.alert_level ?? '').trim();

                    if (!nextAlertLevel || String(appState.bootstrap?.alert_level ?? '').trim() === nextAlertLevel) {
                        return;
                    }

                    appState.bootstrap = {
                        ...(appState.bootstrap ?? {}),
                        alert_level: nextAlertLevel,
                        settings: {
                            ...(appState.bootstrap?.settings ?? {}),
                            alert_level: nextAlertLevel,
                        },
                    };

                    const toastContent = callerAlertToastContent(nextAlertLevel);
                    const showAlertToast = () => {
                        showToast(toastContent.message, toastContent.tone, {
                            title: toastContent.title,
                            speak: true,
                            voiceName: preferredFemaleToastVoiceName(),
                        });
                    };

                    if (rerenderCallerInPlace()) {
                        showAlertToast();
                        return;
                    }

                    void renderSurface('citizen')
                        .then(showAlertToast)
                        .catch(showAlertToast);
                    return;
                }
                logLegacyCallerRealtimeEventUsage(envelope);

                const eventType = legacyCallerEventType(envelope?.type);
                const eventRoom = String(envelope?.room ?? '').trim();
                const payload = withCitizenRealtimePayloadAliases(envelope?.payload);

                if (
                    eventRoom.startsWith(INCIDENT_MEDIA_ROOM_PREFIX)
                    && ['media.processing', 'media.available'].includes(eventType)
                ) {
                    const currentIncidentId = Number(appState.runtime.callerHome?.current_open_incident?.id ?? 0);
                    const nextIncidentId = Number(payload?.incident_id ?? 0);
                    const nextMedia = payload?.media && typeof payload.media === 'object'
                        ? payload.media
                        : null;

                    if (nextMedia && nextIncidentId > 0 && currentIncidentId > 0 && nextIncidentId === currentIncidentId) {
                        applyCallerIncidentMediaUpdate(nextIncidentId, nextMedia);
                    }

                    return;
                }

                if (!eventType || !joinedRooms.has(CALL_DISCOVERY_ROOM)) {
                    return;
                }

                if (eventType === INCIDENT_UPDATE_EVENT) {
                    const currentIncidentId = Number(appState.runtime.callerHome?.current_open_incident?.id ?? 0);
                    const nextIncidentId = Number(payload?.incident_id ?? 0);
                    const nextCallerId = String(payload?.caller_id ?? '');
                    const currentCallerId = String(appState.bootstrap?.user?.id ?? '');

                    if (
                        nextIncidentId > 0
                        && currentIncidentId > 0
                        && nextIncidentId === currentIncidentId
                        && (!nextCallerId || nextCallerId === currentCallerId)
                    ) {
                        applyCallerIncidentPatch(nextIncidentId, payload?.patch ?? {});
                    }

                    return;
                }

                if (eventType === 'presence.state.event') {
                    const runtime = callerPresenceRuntime();
                    runtime.roster = mergeCallerPresenceRosterEvent(runtime.roster, payload);
                    refreshCallerAvailabilityFromPresence();
                    return;
                }

                if (String(payload?.caller_id ?? '') !== String(appState.bootstrap?.user?.id ?? '')) {
                    return;
                }

                logCallFlow('citizen', 'discovery-event-received', {
                    eventType,
                    canonicalEventType: citizenEventType(eventType),
                    incidentId: Number(payload?.incident_id ?? 0) || null,
                    callAttemptId: Number(payload?.call_attempt_id ?? 0) || null,
                    operatorAttemptId: Number(payload?.call_attempt_operator_attempt_id ?? 0) || null,
                    callSessionId: Number(payload?.call_session_id ?? 0) || null,
                    pendingPhase: String(getCallerPendingState()?.phase ?? ''),
                });

                const pendingState = getCallerPendingState();

                if (eventType === 'caller.operator.available.response') {
                    if (!pendingState || pendingState.kind !== 'new_call' || pendingState.operator_id) {
                        return;
                    }

                    if (normalizeOperatorIdList(pendingState.excluded_operator_ids).includes(Number(payload.operator_id ?? 0))) {
                        return;
                    }

                    clearCallerCallRoutingTimers();

                    const nextPendingState = {
                        ...pendingState,
                        operator_id: Number(payload.operator_id),
                        operator_name: String(payload.operator_name ?? 'Operator'),
                        operator_avatar: String(payload.operator_avatar ?? ''),
                        phase: 'operator_found',
                    };

                    setCallerPendingState(nextPendingState);
                    scheduleCallerRingingTimeout(nextPendingState);

                    void captureCallerLocationOnce({ timeoutMs: 1500 }).then(() => {
                        publishCallerCallFlow('caller.call.request', {
                            caller_id: Number(appState.bootstrap?.user?.id ?? 0),
                            operator_id: Number(payload.operator_id),
                            caller_name: String(appState.bootstrap?.user?.name ?? 'Caller'),
                            caller_avatar: String(appState.bootstrap?.user?.avatar ?? ''),
                            ...callerLocationPayload(),
                            requested_at: new Date().toISOString(),
                        });
                    });

                    rerenderCallerInPlace();
                    return;
                }

                if (eventType === 'caller.call.ringing') {
                    if (!pendingState || pendingState.kind !== 'new_call') {
                        return;
                    }

                    if (normalizeOperatorIdList(pendingState.excluded_operator_ids).includes(Number(payload.operator_id ?? 0))) {
                        return;
                    }

                    const nextPendingState = {
                        ...pendingState,
                        attempt_id: Number(payload.call_attempt_id),
                        operator_attempt_id: Number(payload.call_attempt_operator_attempt_id),
                        operator_id: Number(payload.operator_id),
                        operator_name: String(payload.operator_name ?? pendingState.operator_name ?? 'Operator'),
                        operator_avatar: String(payload.operator_avatar ?? pendingState.operator_avatar ?? ''),
                        phase: 'ringing',
                    };

                    setCallerPendingState(nextPendingState);
                    scheduleCallerRingingTimeout(nextPendingState);

                    rerenderCallerInPlace();
                    return;
                }

                if (eventType === 'caller.reconnect.availability.response') {
                    if (
                        !pendingState
                        || pendingState.kind !== 'reconnect'
                        || String(pendingState.phase ?? '') !== 'availability_check'
                        || Number(pendingState.incident_id ?? 0) !== Number(payload.incident_id ?? 0)
                    ) {
                        return;
                    }

                    if (!payload.available) {
                        clearCallerPendingState();
                        void showCallerOperatorUnavailableAlert(CALLER_OPERATOR_UNAVAILABLE_MESSAGE);
                        return;
                    }

                    setCallerPendingState({
                        ...pendingState,
                        operator_id: Number(payload.operator_id ?? 0),
                        operator_name: String(payload.operator_name ?? 'Operator'),
                        operator_avatar: String(payload.operator_avatar ?? ''),
                        phase: 'requesting',
                    });

                    publishCallerCallFlow('caller.reconnect.request', {
                        caller_id: Number(appState.bootstrap?.user?.id ?? 0),
                        incident_id: Number(payload.incident_id ?? 0),
                        operator_id: Number(payload.operator_id ?? 0),
                        display_id: String(appState.runtime.callerHome?.current_open_incident?.display_id ?? ''),
                        caller_name: String(appState.bootstrap?.user?.name ?? 'Caller'),
                        caller_avatar: String(appState.bootstrap?.user?.avatar ?? ''),
                        requested_at: new Date().toISOString(),
                    });
                    return;
                }

                if (eventType === 'caller.reconnect.ringing') {
                    if (
                        !pendingState
                        || pendingState.kind !== 'reconnect'
                        || Number(pendingState.incident_id ?? 0) !== Number(payload.incident_id ?? 0)
                    ) {
                        return;
                    }

                    setCallerPendingState({
                        ...pendingState,
                        attempt_id: Number(payload.call_attempt_id ?? 0),
                        operator_attempt_id: Number(payload.call_attempt_operator_attempt_id ?? 0),
                        operator_id: Number(payload.operator_id ?? pendingState.operator_id ?? 0),
                        operator_name: String(payload.operator_name ?? pendingState.operator_name ?? 'Operator'),
                        operator_avatar: String(payload.operator_avatar ?? pendingState.operator_avatar ?? ''),
                        phase: 'ringing',
                    });

                    rerenderCallerInPlace();
                    return;
                }

                if (eventType === 'caller.call.answered') {
                    logCallFlow('citizen', 'call-answered-event-handling', {
                        incidentId: Number(payload.incident_id ?? 0) || null,
                        callSessionId: Number(payload.call_session_id ?? 0) || null,
                        hasIncidentPayload: Boolean(payload.incident),
                        pendingKind: String(pendingState?.kind ?? ''),
                        pendingPhase: String(pendingState?.phase ?? ''),
                    });

                    if (!pendingState || pendingState.kind !== 'new_call') {
                        logCallFlow('citizen', 'call-answered-event-ignored-no-pending-new-call', {
                            incidentId: Number(payload.incident_id ?? 0) || null,
                            callSessionId: Number(payload.call_session_id ?? 0) || null,
                        });
                        return;
                    }

                    clearCallerCallRoutingTimers();

                    const currentIncident = appState.runtime.callerHome?.current_open_incident ?? null;
                    const callSessionId = Number(payload.call_session_id ?? 0);
                    const nextIncident = payload.incident
                        ? payload.incident
                        : (
                            currentIncident
                            && Number(currentIncident.id ?? 0) === Number(payload.incident_id ?? 0)
                            ? patchIncidentCallSession(currentIncident, callSessionId, {
                                status: 'in_progress',
                                answered_at: payload.answered_at ?? null,
                            })
                            : null
                        );

                    if (nextIncident) {
                        syncCallerCurrentIncident(nextIncident);
                    }

                    setCallerPendingState({
                        ...pendingState,
                        attempt_id: Number(payload.call_attempt_id ?? pendingState.attempt_id ?? 0),
                        operator_attempt_id: Number(payload.call_attempt_operator_attempt_id ?? pendingState.operator_attempt_id ?? 0),
                        call_session_id: callSessionId,
                        phase: 'connecting',
                    });

                    rerenderCallerInPlace();
                    return;
                }

                if (eventType === 'caller.reconnect.answered') {
                    if (
                        !pendingState
                        || pendingState.kind !== 'reconnect'
                        || Number(pendingState.incident_id ?? 0) !== Number(payload.incident_id ?? 0)
                    ) {
                        return;
                    }

                    const currentIncident = appState.runtime.callerHome?.current_open_incident ?? null;
                    const callSessionId = Number(payload.call_session_id ?? 0);
                    const nextIncident = payload.incident
                        ? payload.incident
                        : (
                            currentIncident
                            && Number(currentIncident.id ?? 0) === Number(payload.incident_id ?? 0)
                            ? {
                                ...patchIncidentCallSession(currentIncident, callSessionId, {
                                    status: 'in_progress',
                                    answered_at: payload.answered_at ?? null,
                                }),
                                current_call_session: {
                                    ...(currentIncident.current_call_session ?? {}),
                                    id: callSessionId,
                                    status: 'in_progress',
                                    answered_at: payload.answered_at ?? null,
                                },
                            }
                            : null
                        );

                    if (nextIncident) {
                        syncCallerCurrentIncident(nextIncident);
                    }

                    setCallerPendingState({
                        ...pendingState,
                        attempt_id: Number(payload.call_attempt_id ?? pendingState.attempt_id ?? 0),
                        operator_attempt_id: Number(payload.call_attempt_operator_attempt_id ?? pendingState.operator_attempt_id ?? 0),
                        call_session_id: callSessionId,
                        phase: 'connecting',
                    });

                    rerenderCallerInPlace();
                    return;
                }

                if (eventType === 'caller.call.ready') {
                    const callSessionId = Number(payload.call_session_id ?? 0);
                    const incidentId = Number(payload.incident_id ?? 0);
                    const answeredAt = String(payload.answered_at ?? '').trim() || new Date().toISOString();
                    logCallFlow('citizen', 'call-ready-event-handling', {
                        incidentId,
                        callSessionId,
                        answeredAt,
                    });
                    const currentIncident = appState.runtime.callerHome?.current_open_incident ?? null;
                    const nextIncident = currentIncident
                        && Number(currentIncident.id ?? 0) === incidentId
                        ? patchIncidentCallSession(currentIncident, callSessionId, {
                            answered_at: answeredAt,
                            status: 'in_progress',
                        })
                        : currentIncident;

                    if (nextIncident) {
                        syncCallerCurrentIncident(nextIncident);
                    }

                    collapseCallerConnectingState(callSessionId, incidentId, answeredAt);
                    logCallFlow('citizen', 'connecting-overlay-collapse-requested', {
                        incidentId,
                        callSessionId,
                    });

                    if (
                        appState.runtime.callerLiveModal
                        && Number(appState.runtime.callerLiveModal.latestSessionId ?? 0) === callSessionId
                    ) {
                        appState.runtime.callerLiveModal.transportOnly = false;
                        setCallerLiveModalTransportOnly(
                            appState.runtime.callerRoot?.querySelector?.('[data-caller-live-modal]'),
                            false,
                        );
                    } else {
                        rerenderCallerInPlace();
                    }
                    return;
                }

                if (eventType === 'caller.reconnect.cancelled') {
                    if (!pendingState || pendingState.kind !== 'reconnect') {
                        return;
                    }

                    void (async () => {
                        await closeCallerPendingOverlay(appState.runtime.callerRoot);
                        clearCallerPendingState();

                        if (String(pendingState.phase ?? '').trim() === 'ringing' || String(pendingState.phase ?? '').trim() === 'connecting') {
                            rerenderCallerInPlace();
                        }
                    })();
                    return;
                }

                if (eventType === 'caller.reconnect.declined') {
                    if (!pendingState || pendingState.kind !== 'reconnect') {
                        return;
                    }

                    void (async () => {
                        await closeCallerPendingOverlay(appState.runtime.callerRoot);
                        clearCallerPendingState();

                        if (String(pendingState.phase ?? '').trim() === 'ringing' || String(pendingState.phase ?? '').trim() === 'connecting') {
                            rerenderCallerInPlace();
                        }

                        await showCallerOperatorUnavailableAlert(CALLER_OPERATOR_UNAVAILABLE_MESSAGE);
                    })();
                    return;
                }

                if (eventType === 'caller.call.cancelled' || eventType === 'caller.call.declined') {
                    if (!pendingState || pendingState.kind !== 'new_call') {
                        return;
                    }

                    if (eventType === 'caller.call.cancelled') {
                        void (async () => {
                            clearCallerCallRoutingTimers();
                            await closeCallerPendingOverlay(appState.runtime.callerRoot);
                            clearCallerPendingState();
                            rerenderCallerInPlace();
                        })();
                        return;
                    }

                    retryCallerCallDiscoveryAfterMiss(pendingState);
                    return;
                }
            },
        });

        streamRef = {
            client,
            destroyed: false,
            destroy() {
                this.destroyed = true;
                clearCallerRealtimeReconnectTimer();
                resetCallerRealtimeJoinState();
                appState.runtime.callerMediaRooms = null;
                client.close();
                if (appState.runtime.callerRealtimeStream === this || appState.runtime.callerRealtimeStream?.client === client) {
                    appState.runtime.callerRealtimeStream = null;
                }
            },
        };
        appState.runtime.callerRealtimeStream = streamRef;
        appState.runtime.callerRealtimeSignal?.setReconnectRuntime?.(reconnectRuntime);
        appState.runtime.callerRealtimeSignal?.bindClient?.(client);
        client.connect();
    } catch (error) {
        reconnectRuntime.connecting = false;
        appState.runtime.callerRealtimeSignal?.setReconnectRuntime?.(reconnectRuntime);
        if (appState.runtime.callerRealtimeStream?.client && !appState.runtime.callerRealtimeStream.client.isOpen?.()) {
            appState.runtime.callerRealtimeStream.client = null;
        }
        console.warn('Citizen Realtime surface stream unavailable.', error);
        scheduleCallerRealtimeReconnect();
    }
}

function renderCallerIncidentOverlay(payload) {
    const canReconnect = ['Active', 'Deferred'].includes(payload.status);
    const statusText = String(payload.status ?? 'Unknown').toUpperCase();

    return `
        <div class="overlay-backdrop" data-caller-incident-overlay>
            <section class="overlay-panel fullscreen caller-incident-overlay-panel">
                <div class="caller-incident-overlay-layout">
                    <header class="caller-incident-overlay-header">
                        <div class="caller-incident-overlay-heading">
                            <div class="caller-incident-overlay-kicker">Incident Details</div>
                            <div class="caller-incident-overlay-title">#${escapeHtml(payload.display_id ?? formatCallerLiveIncidentNumber(payload.id))} - ${escapeHtml(statusText)}</div>
                        </div>
                        <div class="caller-incident-overlay-actions">
                            ${canReconnect
                                ? `<button class="surface-button secondary" type="button" data-caller-reconnect="${payload.id}">Resume Call</button>`
                                : `
                                    <button class="caller-incident-overlay-icon" type="button" data-refresh-caller-incident="${payload.id}" aria-label="Refresh incident">
                                        ${refreshIconMarkup()}
                                    </button>
                                    <button class="caller-incident-overlay-icon" type="button" data-close-caller-incident="1" aria-label="Close incident">
                                        ${createIconMarkup('actions.close', { fallback: closeIconMarkup() })}
                                    </button>
                                `}
                        </div>
                    </header>
                    <section class="caller-incident-overlay-body" data-caller-incident-tabs></section>
                    ${Array.isArray(payload.media) && payload.media.length > 0
                        ? '<section class="caller-incident-overlay-media" data-caller-incident-media></section>'
                        : ''}
                    <div class="notice" data-caller-incident-notice hidden></div>
                </div>
            </section>
        </div>
    `;
}

function closeIconMarkup() {
    return `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M7.8 6.4a1 1 0 0 1 1.4 0L12 9.2l2.8-2.8a1 1 0 1 1 1.4 1.4L13.4 10.6l2.8 2.8a1 1 0 0 1-1.4 1.4L12 12l-2.8 2.8a1 1 0 1 1-1.4-1.4l2.8-2.8-2.8-2.8a1 1 0 0 1 0-1.4Z" fill="currentColor"></path>
        </svg>
    `;
}

function refreshIconMarkup() {
    return `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M18.2 7.4V4.8m0 0h-2.6m2.6 0-3 3A6.8 6.8 0 1 0 18.8 12" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
        </svg>
    `;
}

function normalizeCallerIncidentMediaStripItems(media) {
    return (Array.isArray(media) ? media : [])
        .map((item) => {
            const typeToken = String(item?.type ?? '').toLowerCase();
            const normalizedType = typeToken.includes('video')
                ? 'video'
                : (typeToken.includes('image') || typeToken.includes('photo') ? 'image' : '');

            if (!normalizedType) {
                return null;
            }

            const rawPath = String(item?.path ?? '').trim();
            const srcUrl = rawPath ? `/storage/${rawPath.replace(/^\/+/, '')}` : '';
            const processing = Boolean(item?.processing);
            const metadata = item?.metadata && typeof item.metadata === 'object'
                ? item.metadata
                : {};
            const posterCandidate = normalizedType === 'video'
                ? (() => {
                    const rawPosterPath = String(
                        metadata.thumbnail_path
                        ?? metadata.thumbnail
                        ?? metadata.poster_path
                        ?? metadata.poster
                        ?? ''
                    ).trim();

                    return rawPosterPath ? `/storage/${rawPosterPath.replace(/^\/+/, '')}` : '';
                })()
                : srcUrl;

            if (!srcUrl && !processing) {
                return null;
            }

            return {
                id: String(item.id ?? srcUrl),
                type: normalizedType,
                srcUrl,
                thumbUrl: normalizedType === 'image' ? srcUrl : '',
                posterUrl: posterCandidate,
                title: formatStatusLabel(item.type ?? normalizedType),
                alt: item.peer_label ?? item.peer_role ?? formatStatusLabel(item.type ?? normalizedType),
                duration: Number(item.duration_seconds ?? 0) || 0,
                processing,
                processingLabel: String(item?.processingLabel ?? metadata?.processingLabel ?? '').trim(),
                metadata,
            };
        })
        .filter(Boolean);
}

function syncCallerIncidentOverlayHeader(overlay, payload) {
    if (!overlay || !payload) {
        return;
    }

    const titleNode = overlay.querySelector('.caller-incident-overlay-title');

    if (titleNode) {
        const statusText = String(payload.status ?? 'Unknown').toUpperCase();
        titleNode.textContent = `#${payload.display_id ?? formatCallerLiveIncidentNumber(payload.id)} - ${statusText}`;
    }
}

function ensureCallerIncidentOverlayMediaSection(overlay, payload) {
    const panel = overlay?.querySelector('.caller-incident-overlay-panel');

    if (!panel) {
        return null;
    }

    let mediaHost = overlay.querySelector('[data-caller-incident-media]');
    const hasMedia = Array.isArray(payload?.media) && payload.media.length > 0;

    if (!hasMedia) {
        mediaHost?.remove?.();
        return null;
    }

    if (!mediaHost) {
        mediaHost = document.createElement('section');
        mediaHost.className = 'caller-incident-overlay-media';
        mediaHost.dataset.callerIncidentMedia = '';
        const notice = overlay.querySelector('[data-caller-incident-notice]');

        if (notice) {
            panel.querySelector('.caller-incident-overlay-layout')?.insertBefore(mediaHost, notice);
        } else {
            panel.querySelector('.caller-incident-overlay-layout')?.appendChild(mediaHost);
        }
    }

    return mediaHost;
}

function syncCallerIncidentOverlayMedia(overlay, payload, helper, runtime) {
    const mediaHost = ensureCallerIncidentOverlayMediaSection(overlay, payload);

    if (!mediaHost || !helper?.createMediaStrip) {
        runtime.mediaStripApi?.destroy?.();
        runtime.mediaStripApi = null;
        return;
    }

    const nextItems = normalizeCallerIncidentMediaStripItems(payload.media);

    if (runtime.mediaStripApi?.update) {
        runtime.mediaStripApi.update(nextItems, {
            className: 'caller-incident-overlay-media-strip',
            viewerAudiographStyle: currentAudioGraphStyle(),
            showViewerFooter: false,
        });
        return;
    }

    runtime.mediaStripApi = helper.createMediaStrip(mediaHost, nextItems, {
        className: 'caller-incident-overlay-media-strip',
        viewerAudiographStyle: currentAudioGraphStyle(),
        showViewerFooter: false,
    });
}

function syncCallerIncidentOverlayMountedTab(runtime) {
    const payload = runtime?.payload;
    const lookups = callerWorkbenchLookups();

    if (!payload) {
        return;
    }

    runtime.incidentTypesApi?.update?.({
        id: payload.id,
        incident_types: payload.incident_types ?? [],
        detail_entries: payload.incident_type_details ?? [],
        resources_needed: payload.incident_resources_needed ?? [],
    }, {
        editable: false,
        headerText: '',
        categories: lookups.incidentTypeCategories,
        incidentTypes: lookups.incidentTypeCatalog,
        lookups: {
            resourceTypes: lookups.resourceTypes,
        },
        removeIncidentType: () => {},
        className: 'caller-incident-overlay-helper',
    });

    runtime.assignmentsApi?.update?.({
        team_assignments: payload.team_assignments ?? [],
    }, {
        editable: false,
        headerText: '',
        categories: lookups.teamCategories,
        teams: lookups.teams,
        noticeAlreadyExist: () => {},
        incident_id: payload.id ?? 0,
        operator_id: payload.operator?.id ?? 0,
        className: 'caller-incident-overlay-helper',
    });

    runtime.chatThreadApi?.destroy?.();

    if (runtime.chatHost) {
        runtime.chatHost.replaceChildren();
        runtime.chatThreadApi = mountChatThread(runtime.chatHost, payload.messages ?? [], 'citizen', {
            emptyText: 'No messages yet.',
        });
    } else {
        runtime.chatThreadApi = null;
    }
}

function syncCallerIncidentOverlayInPlace(overlay, payload, options = {}) {
    if (!overlay || !payload) {
        return;
    }

    overlay.classList.add('is-visible');
    void mountCallerIncidentOverlay(overlay, payload, options);
}

async function ensureCallerIncidentOverlayHelpers() {
    await ensureHelperUi();

    if (
        appState.helper.createTabs
        && appState.helper.incidentTypesHelper
        && appState.helper.incidentAssignmentsHelper
        && appState.helper.createMediaStrip
    ) {
        return appState.helper;
    }

    const [
        incidentTypesHelper,
        incidentAssignmentsHelper,
        createMediaStrip,
    ] = await Promise.all([
        appState.helper.uiLoader.get('incident.types'),
        appState.helper.uiLoader.get('incident.teams.assignments'),
        appState.helper.uiLoader.get('ui.media.strip'),
    ]);

    Object.assign(appState.helper, {
        incidentTypesHelper,
        incidentAssignmentsHelper,
        createMediaStrip,
    });

    return appState.helper;
}

async function mountCallerIncidentOverlay(overlay, payload, options = {}) {
    const helper = await ensureCallerIncidentOverlayHelpers();
    const tabsHost = overlay?.querySelector('[data-caller-incident-tabs]');
    const lookups = callerWorkbenchLookups();
    const syncMedia = options.syncMedia !== false;
    const runtime = overlay.__callerIncidentOverlayRuntime ?? {
        activeTabId: 'incident-types',
        tabsApi: null,
        mediaStripApi: null,
        incidentTypesApi: null,
        assignmentsApi: null,
        chatThreadApi: null,
        chatHost: null,
        payload: null,
    };
    overlay.__callerIncidentOverlayRuntime = runtime;
    runtime.payload = payload;

    if (!tabsHost || !helper.createTabs) {
        return;
    }

    syncCallerIncidentOverlayHeader(overlay, payload);
    if (syncMedia) {
        syncCallerIncidentOverlayMedia(overlay, payload, helper, runtime);
    }

    const buildTabs = () => ([
        {
            id: 'incident-types',
            label: 'Incident Types',
            render: (panel) => {
                runtime.activeTabId = 'incident-types';
                runtime.assignmentsApi?.destroy?.();
                runtime.assignmentsApi = null;
                runtime.chatThreadApi?.destroy?.();
                runtime.chatThreadApi = null;
                runtime.chatHost = null;
                panel.replaceChildren();
                const currentPayload = runtime.payload ?? payload;

                if (helper.incidentTypesHelper) {
                    runtime.incidentTypesApi?.destroy?.();
                    runtime.incidentTypesApi = helper.incidentTypesHelper(panel, {
                        id: currentPayload.id,
                        incident_types: currentPayload.incident_types ?? [],
                        detail_entries: currentPayload.incident_type_details ?? [],
                        resources_needed: currentPayload.incident_resources_needed ?? [],
                    }, {
                        editable: false,
                        headerText: '',
                        categories: lookups.incidentTypeCategories,
                        incidentTypes: lookups.incidentTypeCatalog,
                        lookups: {
                            resourceTypes: lookups.resourceTypes,
                        },
                        removeIncidentType: () => {},
                        className: 'caller-incident-overlay-helper',
                    });
                }
            },
        },
        {
            id: 'dispatch',
            label: 'Dispatch',
            render: (panel) => {
                runtime.activeTabId = 'dispatch';
                runtime.incidentTypesApi?.destroy?.();
                runtime.incidentTypesApi = null;
                runtime.chatThreadApi?.destroy?.();
                runtime.chatThreadApi = null;
                runtime.chatHost = null;
                panel.replaceChildren();
                const currentPayload = runtime.payload ?? payload;

                if (helper.incidentAssignmentsHelper) {
                    runtime.assignmentsApi?.destroy?.();
                    runtime.assignmentsApi = helper.incidentAssignmentsHelper(panel, {
                        team_assignments: currentPayload.team_assignments ?? [],
                    }, {
                        editable: false,
                        headerText: '',
                        categories: lookups.teamCategories,
                        teams: lookups.teams,
                        noticeAlreadyExist: () => {},
                        incident_id: currentPayload.id ?? 0,
                        operator_id: currentPayload.operator?.id ?? 0,
                        className: 'caller-incident-overlay-helper',
                    });
                }
            },
        },
        {
            id: 'chat',
            label: 'Chat',
            render: (panel) => {
                runtime.activeTabId = 'chat';
                runtime.incidentTypesApi?.destroy?.();
                runtime.incidentTypesApi = null;
                runtime.assignmentsApi?.destroy?.();
                runtime.assignmentsApi = null;
                panel.innerHTML = '<div class="caller-incident-overlay-chat-host" data-caller-incident-chat-host></div>';
                runtime.chatHost = panel.querySelector('[data-caller-incident-chat-host]');
                const currentPayload = runtime.payload ?? payload;

                if (runtime.chatHost) {
                    runtime.chatThreadApi?.destroy?.();
                    runtime.chatThreadApi = mountChatThread(runtime.chatHost, currentPayload.messages ?? [], 'citizen', {
                        emptyText: 'No messages yet.',
                    });
                }
            },
        },
    ]);

    if (runtime.tabsApi?.update) {
        runtime.activeTabId = String(runtime.tabsApi.getActiveId?.() ?? runtime.activeTabId ?? 'incident-types');
        syncCallerIncidentOverlayMountedTab(runtime);
        return;
    }

    runtime.tabsApi = helper.createTabs(tabsHost, {
        activeId: runtime.activeTabId,
        ariaLabel: 'Caller incident tabs',
        onChange: (_tab, activeId) => {
            runtime.activeTabId = String(activeId ?? runtime.activeTabId ?? 'incident-types');
        },
        tabs: buildTabs(),
    });
}

function callerAvailabilitySummary(availability, primerReport) {
    const status = String(availability?.status ?? 'red').toLowerCase();
    const primerBlocked = (primerReport?.blockingFailed?.length ?? 0) > 0;

    return {
        status,
        label: status.toUpperCase(),
        canCall: status === 'green' && !primerBlocked,
        reason: primerBlocked
            ? 'Local device checks are blocking microphone or playback.'
            : status === 'yellow'
                ? 'All operators are currently busy.'
                : status === 'red'
                    ? 'Hotline backend or network connectivity is unavailable.'
                    : 'Hotline is ready.',
    };
}

function renderCallerHistoryList(items) {
    if (!Array.isArray(items) || items.length === 0) {
        return '<p class="surface-empty">No past incidents yet.</p>';
    }

    return `
        <div class="caller-history-list">
            ${items.map((item) => `
                <button class="caller-history-item" type="button" data-open-caller-incident="${item.id}">
                    <span class="caller-history-id">#${escapeHtml(item.display_id)}</span>
                    <span class="caller-history-meta">${escapeHtml(item.status)} · ${formatDateTime(item.created_at)}</span>
                </button>
            `).join('')}
        </div>
    `;
}

function renderCallerLiveCallModal(payload, latestSession, { transportOnly = false } = {}) {
    const backdropClass = ['overlay-backdrop', 'caller-live-modal-backdrop', transportOnly ? 'is-transport-only' : '']
        .filter(Boolean)
        .join(' ');

    return `
        <div class="${backdropClass}" data-caller-live-modal>
            <section class="overlay-panel fullscreen caller-live-modal-panel">
                ${renderCallerLiveCallContent(payload, latestSession)}
            </section>
        </div>
    `;
}

function cameraIconMarkup() {
    return '<img class="caller-live-action-icon caller-live-action-icon-video" src="/images/video-camera.svg" alt="" aria-hidden="true">';
}

function switchCameraIconMarkup() {
    return '<img class="caller-live-action-icon caller-live-action-icon-switch" src="/images/switch-camera.svg" alt="" aria-hidden="true">';
}

function hangupIconMarkup() {
    return '<img class="caller-live-action-icon caller-live-action-icon-hangup" src="/images/hang-up.svg" alt="" aria-hidden="true">';
}

async function stopCallerCameraStream({ preserveSelection = false } = {}) {
    const stream = appState.runtime.callerCameraStream;

    if (stream instanceof MediaStream) {
        stream.getTracks().forEach((track) => {
            try {
                track.stop();
            } catch {
                // Ignore device teardown failures.
            }
        });
    }

    appState.runtime.callerCameraStream = null;
    appState.runtime.callerCameraDevices = [];
    await appState.runtime.callerLiveModal?.callRuntime?.updateLocalVideoStream?.(null);

    if (!preserveSelection) {
        appState.runtime.callerCameraDeviceId = null;
    }
}

async function enumerateCallerVideoDevices() {
    if (!navigator.mediaDevices?.enumerateDevices) {
        return [];
    }

    const devices = await navigator.mediaDevices.enumerateDevices();

    return devices
        .filter((device) => device.kind === 'videoinput')
        .map((device, index) => ({
            deviceId: String(device.deviceId ?? ''),
            label: String(device.label ?? '').trim() || `Camera ${index + 1}`,
        }))
        .filter((device) => device.deviceId);
}

function callerCameraButtonVisibility(overlay, isStreaming) {
    const startButton = overlay?.querySelector('[data-caller-live-video-toggle]');
    const switchButton = overlay?.querySelector('[data-caller-live-camera-picker]');

    if (startButton) {
        startButton.hidden = isStreaming;
        startButton.classList.toggle('is-hidden', isStreaming);
    }

    if (switchButton) {
        switchButton.hidden = !isStreaming;
        switchButton.classList.toggle('is-hidden', !isStreaming);
    }
}

function closeCallerCameraPicker(overlay) {
    overlay?.querySelector('[data-caller-camera-picker]')?.remove();
}

function mountCallerVideoPreview(overlay, stream) {
    const host = overlay?.querySelector('[data-caller-video-placeholder]');

    if (!host) {
        return;
    }

    host.classList.remove('is-idle');
    host.replaceChildren();

    const video = document.createElement('video');
    video.className = 'caller-live-video-preview';
    video.autoplay = true;
    video.muted = true;
    video.playsInline = true;
    video.srcObject = stream;

    const closeButton = document.createElement('button');
    closeButton.className = 'caller-live-video-close';
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', 'Turn off camera');
    closeButton.innerHTML = createIconMarkup('actions.close', {
        size: 14,
        ariaLabel: '',
    }) || `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M7 7l10 10M17 7 7 17" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2"></path>
        </svg>
    `;
    closeButton.addEventListener('click', () => {
        void (async () => {
            await stopCallerCameraStream();
            closeCallerCameraPicker(overlay);
            resetCallerVideoPreview(overlay);
            callerCameraButtonVisibility(overlay, false);
        })();
    });

    host.appendChild(video);
    host.appendChild(closeButton);

    const playResult = video.play();

    if (playResult && typeof playResult.catch === 'function') {
        playResult.catch(() => {});
    }
}

function resetCallerVideoPreview(overlay) {
    const host = overlay?.querySelector('[data-caller-video-placeholder]');

    if (!host) {
        return;
    }

    host.classList.add('is-idle');
    host.innerHTML = '<span>Video Preview</span>';
}

async function startCallerCameraPreview(overlay, deviceId = null) {
    if (!navigator.mediaDevices?.getUserMedia) {
        showToast('This device does not support camera access.', 'error');
        return false;
    }

    closeCallerCameraPicker(overlay);
    await stopCallerCameraStream({ preserveSelection: true });

    const videoConstraints = deviceId
        ? { deviceId: { exact: deviceId } }
        : { facingMode: 'user' };

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: videoConstraints,
            audio: false,
        });

        appState.runtime.callerCameraStream = stream;

        const activeTrack = stream.getVideoTracks().at(0) ?? null;
        const activeSettings = typeof activeTrack?.getSettings === 'function'
            ? activeTrack.getSettings()
            : {};

        appState.runtime.callerCameraDeviceId = String(activeSettings?.deviceId ?? deviceId ?? '');
        appState.runtime.callerCameraDevices = await enumerateCallerVideoDevices();

        mountCallerVideoPreview(overlay, stream);
        callerCameraButtonVisibility(overlay, true);
        void appState.runtime.callerLiveModal?.callRuntime?.updateLocalVideoStream?.(stream);
        return true;
    } catch (error) {
        resetCallerVideoPreview(overlay);
        callerCameraButtonVisibility(overlay, false);
        showToast(error?.message ?? 'Unable to access the camera.', 'error');
        return false;
    }
}

function renderCallerCameraPicker(devices, selectedDeviceId) {
    const items = devices.map((device) => {
        const isActive = String(device.deviceId) === String(selectedDeviceId ?? '');

        return `
            <button class="caller-camera-picker-option${isActive ? ' is-active' : ''}" type="button" data-caller-camera-device="${escapeHtml(device.deviceId)}">
                ${escapeHtml(device.label)}
            </button>
        `;
    }).join('');

    return `
        <div class="caller-camera-picker" data-caller-camera-picker>
            <div class="caller-camera-picker-sheet">
                <div class="caller-camera-picker-title">Available Cameras</div>
                <div class="caller-camera-picker-list">
                    ${items}
                </div>
                <button class="caller-camera-picker-close" type="button" data-close-caller-camera-picker="1">Close</button>
            </div>
        </div>
    `;
}

async function openCallerCameraPicker(overlay) {
    if (!overlay) {
        return;
    }

    closeCallerCameraPicker(overlay);

    const devices = await enumerateCallerVideoDevices();
    appState.runtime.callerCameraDevices = devices;

    if (devices.length === 0) {
        showToast('No camera devices were found.', 'warn');
        return;
    }

    if (devices.length === 1) {
        showToast('Only one camera is available on this device.', 'info');
    }

    overlay.querySelector('.caller-live-layout')?.insertAdjacentHTML(
        'beforeend',
        renderCallerCameraPicker(devices, appState.runtime.callerCameraDeviceId),
    );

    const picker = overlay.querySelector('[data-caller-camera-picker]');

    picker?.querySelector('[data-close-caller-camera-picker]')?.addEventListener('click', () => {
        closeCallerCameraPicker(overlay);
    });

    picker?.addEventListener('click', (event) => {
        if (event.target === picker) {
            closeCallerCameraPicker(overlay);
        }
    });

    picker?.querySelectorAll('[data-caller-camera-device]').forEach((button) => {
        button.addEventListener('click', async () => {
            const nextDeviceId = String(button.dataset.callerCameraDevice ?? '').trim();

            if (!nextDeviceId) {
                return;
            }

            await startCallerCameraPreview(overlay, nextDeviceId);
            closeCallerCameraPicker(overlay);
        });
    });
}

function renderCallerIncidentTypeCards(items, emptyText) {
    if (!Array.isArray(items) || items.length === 0) {
        return `
            <article class="caller-incident-type-card is-empty">
                <span class="caller-incident-type-value">${escapeHtml(emptyText)}</span>
            </article>
        `;
    }

    return items.map((item) => `
        <article class="caller-incident-type-card">
            <span class="caller-incident-type-label">${escapeHtml(item.label ?? 'Context')}</span>
            <strong class="caller-incident-type-value">${escapeHtml(item.value ?? 'Pending')}</strong>
        </article>
    `).join('');
}

function buildCallerLiveIncidentTypeCards(payload) {
    const details = Array.isArray(payload?.incident_type_details) ? payload.incident_type_details : [];

    if (details.length > 0) {
        return details.map((item) => ({
            label: item.label ?? item.name ?? 'Incident Detail',
            value: item.value ?? item.display_value ?? item.text ?? 'Pending',
        }));
    }

    return [];
}

function syncCallerLiveIncidentTypeCards(overlay, payload) {
    const strip = overlay?.querySelector('.caller-live-incident-strip');

    if (!strip) {
        return;
    }

    strip.innerHTML = renderCallerIncidentTypeCards(
        buildCallerLiveIncidentTypeCards(payload),
        'Waiting for operator incident updates.',
    );
}

function formatCallerLiveIncidentNumber(value) {
    const numeric = Number(value ?? 0);

    if (!Number.isFinite(numeric) || numeric <= 0) {
        return '0000000';
    }

    return String(Math.trunc(numeric)).padStart(7, '0');
}

function renderCallerOperatorAvatar(operator, className = 'caller-live-avatar') {
    const operatorName = String(operator?.name ?? 'Operator').trim() || 'Operator';
    const avatarUrl = String(operator?.avatar ?? '').trim();
    const fallback = operatorName.slice(0, 2).toUpperCase();

    if (avatarUrl) {
        return `<img class="${escapeHtml(className)}" src="${escapeHtml(avatarUrl)}" alt="${escapeHtml(operatorName)}">`;
    }

    return `<span class="${escapeHtml(className)}">${escapeHtml(fallback)}</span>`;
}

function renderCallerPendingOperatorAvatar(operator) {
    const avatarUrl = String(operator?.avatar ?? '').trim();
    const operatorName = String(operator?.name ?? 'Operator').trim() || 'Operator';

    return `
        <span class="caller-pending-avatar-shell">
            ${avatarUrl
                ? `<img class="caller-pending-avatar" src="${escapeHtml(avatarUrl)}" alt="${escapeHtml(operatorName)}">`
                : `<span class="caller-pending-avatar caller-pending-avatar-fallback">${escapeHtml(operatorName.slice(0, 2).toUpperCase())}</span>`}
        </span>
    `;
}

function pendingOperatorIdentity(pending, incident = null) {
    if (incident?.operator) {
        return incident.operator;
    }

    return {
        name: pending?.operator_name ?? 'Operator',
        avatar: pending?.operator_avatar ?? '',
    };
}

function listIconMarkup() {
    return `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M6 7.25h12M6 12h12M6 16.75h12" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"></path>
            <circle cx="3.5" cy="7.25" r="1.1" fill="currentColor"></circle>
            <circle cx="3.5" cy="12" r="1.1" fill="currentColor"></circle>
            <circle cx="3.5" cy="16.75" r="1.1" fill="currentColor"></circle>
        </svg>
    `;
}

function usersIconMarkup() {
    return `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="9" cy="9" r="2.6" fill="none" stroke="currentColor" stroke-width="1.8"></circle>
            <path d="M4.8 17c.55-2.1 2.45-3.6 4.75-3.6S13.75 14.9 14.3 17" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"></path>
            <circle cx="16.7" cy="10" r="2.05" fill="none" stroke="currentColor" stroke-width="1.8"></circle>
            <path d="M14.7 17.2c.42-1.52 1.8-2.6 3.5-2.6 1.02 0 1.98.4 2.7 1.12" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"></path>
        </svg>
    `;
}

function renderCallerAvailabilityDropup(home, availability) {
    return `
        <div class="caller-availability-dropup">
            <div class="caller-availability-head">
                <span class="${availabilityPillClass(availability.status)}">${availability.label}</span>
                <strong>${escapeHtml(availability.reason)}</strong>
            </div>
            <ul class="surface-list compact">
                <li>Operators available: ${escapeHtml(home.availability?.available_operator_count ?? 0)}</li>
                <li>Call service ready: ${home.availability?.call_service_ready ? 'Yes' : 'No'}</li>
                <li>Backend reachable: ${home.availability?.service_reachable ? 'Yes' : 'No'}</li>
            </ul>
        </div>
    `;
}

function renderCallerMediaFooter(payload, includeProcessingHint = false) {
    if (!Array.isArray(payload?.media) || payload.media.length === 0) {
        return `
            <div class="caller-media-strip empty">
                <span>${includeProcessingHint ? 'processing media...' : 'No incident media yet.'}</span>
            </div>
        `;
    }

    return `
        <div class="caller-media-strip">
            ${payload.media.map((item) => `
                <article class="caller-media-card">
                    <strong>${escapeHtml(formatStatusLabel(item.type))}</strong>
                    <span>${escapeHtml(item.peer_label ?? item.peer_role ?? 'Unknown peer')}</span>
                    <small>${item.processing ? 'processing media...' : formatDateTime(item.available_at ?? item.created_at)}</small>
                </article>
            `).join('')}
        </div>
    `;
}

function callerAlertToneClass(alertLevel) {
    const normalized = String(alertLevel ?? '').trim().toLowerCase();

    if (normalized === 'elevated') {
        return 'is-alert-elevated';
    }

    if (normalized === 'critical') {
        return 'is-alert-critical';
    }

    return '';
}

function callerSignalHelpModel(snapshot = null) {
    const state = String(snapshot?.state ?? 'idle').trim().toLowerCase();
    const tone = String(snapshot?.tone ?? 'offline').trim().toLowerCase();
    const level = Number(snapshot?.level ?? 0);
    const label = String(snapshot?.label ?? '').trim();

    if (tone === 'offline' || ['closed', 'error', 'idle'].includes(state)) {
        return {
            tone: 'offline',
            heading: 'Not connected to the barangay hotline',
            summary: 'Connect to the barangay hotline Wi-Fi hotspot, then return to this screen.',
            steps: [
                'Check that Wi-Fi is on.',
                'Choose the nearest barangay hotline Wi-Fi hotspot.',
                'Keep this screen open after reconnecting.',
            ],
        };
    }

    if (state === 'connecting' || state === 'reconnecting') {
        return {
            tone: 'warn',
            heading: 'Connecting to the barangay hotspot',
            summary: 'The hotline is trying to reconnect to the local barangay network.',
            steps: [
                'Stay near the barangay Wi-Fi hotspot.',
                'Do not close this screen while reconnecting.',
                'Move away from thick walls, metal gates, or crowded areas if possible.',
            ],
        };
    }

    if (tone === 'warn' || tone === 'danger' || level <= 2) {
        return {
            tone: tone === 'danger' ? 'danger' : 'warn',
            heading: 'Weak barangay hotspot signal',
            summary: 'Move closer to the barangay Wi-Fi hotspot so your emergency request can send faster.',
            steps: [
                'Move closer to the hotspot location.',
                'Stay near a window or outside if you are indoors.',
                'Avoid thick walls, metal barriers, and crowded areas.',
                'Keep this screen open while connecting.',
            ],
        };
    }

    return {
        tone: 'ok',
        heading: 'Connected to the barangay hotspot',
        summary: label && label !== 'Online'
            ? `Hotline signal is ready. Latest response time: ${label}.`
            : 'Hotline signal is ready. You can call for help now.',
        steps: [
            'Keep this screen open during the call.',
            'Stay near the barangay hotspot if you need to send photos, video, or chat.',
        ],
    };
}

function renderCallerSignalHelpContent(snapshot = null) {
    const model = callerSignalHelpModel(snapshot);

    return `
        <div class="caller-signal-help is-${escapeHtml(model.tone)}">
            <p class="caller-signal-help-summary">${escapeHtml(model.summary)}</p>
            <ul class="caller-signal-help-list">
                ${model.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}
            </ul>
        </div>
    `;
}

function syncCallerSignalDrawer(snapshot = null) {
    const drawer = appState.runtime.callerSignalHelpDrawer;

    if (!drawer?.isOpen?.()) {
        return;
    }

    const model = callerSignalHelpModel(snapshot);
    drawer.title.textContent = model.heading;
    drawer.body.innerHTML = renderCallerSignalHelpContent(snapshot);
}

function openCallerSignalHelpDrawer(snapshot = null) {
    if (typeof appState.helper.createBottomDrawer !== 'function') {
        showToast('Hotline signal help is unavailable right now.', 'info');
        return;
    }

    const model = callerSignalHelpModel(snapshot);
    let drawer = appState.runtime.callerSignalHelpDrawer;

    if (!drawer) {
        drawer = appState.helper.createBottomDrawer({
            title: model.heading,
            ariaLabel: 'Hotline signal help',
            panelClass: 'caller-signal-help-drawer',
            bodyClass: 'caller-signal-help-drawer-body',
            onClose: () => {
                appState.runtime.callerSignalHelpDrawer = null;
            },
        });
        appState.runtime.callerSignalHelpDrawer = drawer;
        trackSurfaceInstance(drawer);
    }

    drawer.title.textContent = model.heading;
    drawer.body.innerHTML = renderCallerSignalHelpContent(snapshot);
    drawer.open(document.body);
}

function renderCallerHomeContent(home, primerReport, alertLevel) {
    const availability = callerAvailabilitySummary(home.availability, primerReport);
    const alertToneClass = callerAlertToneClass(alertLevel);
    const layoutClass = ['caller-home-layout', alertToneClass].filter(Boolean).join(' ');
    const heroClass = ['caller-call-hero', alertToneClass].filter(Boolean).join(' ');
    const buttonClass = ['caller-call-button', `is-${availability.status}`, alertToneClass].filter(Boolean).join(' ');

    return `
        <div class="${layoutClass}">
            <section class="caller-call-stage">
                <div class="${heroClass}">
                    <button class="${buttonClass}" type="button" data-hold-call="1">
                        <span>Call for Help</span>
                    </button>
                    <div class="caller-hold-progress" data-call-hold-track aria-hidden="true">
                        <div class="caller-hold-progress-bar" data-call-hold-progress></div>
                    </div>
                    <p class="caller-hold-caption" data-call-hold-caption>Press and hold to start.</p>
                </div>
            </section>
            <div class="caller-home-utility-bar">
                <div class="caller-home-utility">
                    <div class="caller-home-dropup" data-home-panel="history" hidden>
                        <div class="caller-home-dropup-card">
                            <h3>Incident History</h3>
                            ${renderCallerHistoryList(home.recent_incidents)}
                        </div>
                    </div>
                    <button class="caller-home-utility-button" type="button" data-toggle-home-panel="history" aria-expanded="false" aria-label="Show incident history">
                        ${listIconMarkup()}
                    </button>
                </div>
                <div class="caller-home-utility align-right">
                    <div class="caller-home-dropup" data-home-panel="availability" hidden>
                        <div class="caller-home-dropup-card">
                            <h3>Availability</h3>
                            ${renderCallerAvailabilityDropup(home, availability)}
                        </div>
                    </div>
                    <button class="caller-home-utility-button is-${availability.status}" type="button" data-toggle-home-panel="availability" aria-expanded="false" aria-label="Show availability and connectivity status">
                        ${usersIconMarkup()}
                    </button>
                </div>
            </div>
        </div>
    `;
}

function callerNavbarStatusContent(primerReport) {
    const wrapper = document.createElement('div');
    wrapper.className = 'caller-navbar-status';
    wrapper.innerHTML = `
        <button class="caller-realtime-signal-button caller-navbar-signal-button" type="button" data-caller-signal-help aria-label="Hotline signal. Tap for connection help.">
            <span class="caller-realtime-signal-inline" data-caller-inline-realtime-signal></span>
            <span class="caller-realtime-signal-help-icon" aria-hidden="true">
                ${createIconMarkup('status.info', { size: 14, fallback: '<span class="caller-realtime-signal-help-fallback">i</span>' })}
            </span>
        </button>
        ${primerStatusButton(primerReport)}
    `;

    return wrapper;
}

function renderCallerPendingContent(pending, incident = null) {
    const operator = pendingOperatorIdentity(pending, incident);
    const phase = String(pending?.phase ?? '').trim();
    const statusText = phase === 'connecting' ? 'Connecting ...' : 'Calling ...';

    return `
        <section class="caller-ringing-screen">
            <div class="caller-ringing-stage">
                <div class="ringing-visual has-operator-avatar" aria-hidden="true">
                    <span class="ring ring-a"></span>
                    <span class="ring ring-b"></span>
                    <span class="ring ring-c"></span>
                    ${renderCallerPendingOperatorAvatar(operator)}
                </div>
                <div class="caller-pending-status">${escapeHtml(statusText)}</div>
                <div class="caller-pending-actions">
                    <button class="caller-live-action-button danger" type="button" data-cancel-caller-pending="1" aria-label="Hang up">
                        ${hangupIconMarkup()}
                    </button>
                </div>
            </div>
        </section>
    `;
}

function renderCallerPendingOverlay(pending, incident = null, alertLevel = null) {
    const alertToneClass = callerAlertToneClass(alertLevel);

    return `
        <div class="overlay-backdrop caller-pending-overlay ${alertToneClass}" data-caller-pending-overlay>
            ${renderCallerPendingContent(pending, incident)}
        </div>
    `;
}

function renderCallerIncidentContent(payload, recentIncidents) {
    const canReconnect = ['Active', 'Deferred'].includes(payload.status);
    const includeProcessingHint = Array.isArray(payload.call_history) && payload.call_history.some((session) => session.status === 'ended');

    return `
        <div class="caller-incident-layout">
            <section class="panel-card caller-incident-header-card">
                <p class="ui-eyebrow">${escapeHtml(formatIncidentStatusHeading(payload.status))}</p>
                <div class="caller-incident-header">
                    <div>
                        <h2 class="caller-incident-title">Incident #${escapeHtml(payload.display_id)}</h2>
                        <p class="hero-copy">${escapeHtml(payload.status)} · ${formatDateTime(payload.called_at ?? payload.created_at)}</p>
                    </div>
                    ${canReconnect ? '<button class="surface-button" type="button" data-caller-reconnect-current="1">Resume Call</button>' : ''}
                </div>
            </section>
            <div class="caller-incident-grid">
                <section class="panel-card">
                    <h3>Incident Type Details</h3>
                    <div class="detail-grid">
                        <article class="detail-item"><span class="detail-label">Caller</span><strong>${escapeHtml(payload.actual_caller_name ?? payload.caller?.name ?? 'Unknown caller')}</strong><p class="hero-copy">${escapeHtml(payload.actual_caller_relationship ?? 'Self')}</p></article>
                        <article class="detail-item"><span class="detail-label">Operator</span><strong>${escapeHtml(payload.operator?.name ?? 'Pending')}</strong><p class="hero-copy">${escapeHtml(payload.operator?.level ?? 'Hotline operator')}</p></article>
                        <article class="detail-item"><span class="detail-label">Location</span><strong>${escapeHtml(payload.location || 'Pending')}</strong><p class="hero-copy">${escapeHtml(payload.location_barangay ?? '')}</p></article>
                        <article class="detail-item"><span class="detail-label">Notes</span><strong>${escapeHtml(payload.other_details || 'No notes yet')}</strong></article>
                    </div>
                </section>
                <section class="panel-card">
                    <h3>Conversation</h3>
                    <div data-caller-chat-thread></div>
                </section>
                <section class="panel-card">
                    <h3>Recent History</h3>
                    ${renderCallerHistoryList(recentIncidents)}
                </section>
            </div>
            ${renderCallerMediaFooter(payload, includeProcessingHint)}
        </div>
    `;
}

function renderCallerLiveCallContent(payload, latestSession) {
    const operatorName = payload.operator?.name ?? 'Assigned Operator';
    const incidentTypeCards = buildCallerLiveIncidentTypeCards(payload);
    const incidentNumber = formatCallerLiveIncidentNumber(payload.id);
    const sessionMarkup = latestSession?.id
        ? `<p class="hero-copy">Connected · Session #${escapeHtml(latestSession.id)}</p>`
        : '';

    return `
        <div class="caller-live-layout">
            <section class="caller-live-call-header">
                <div class="caller-live-header-meta">
                    <div class="caller-live-incident-number">Incident No. ${escapeHtml(incidentNumber)}</div>
                    <div class="caller-live-operator">
                        ${renderCallerOperatorAvatar(payload.operator, 'caller-live-avatar')}
                        <div class="caller-live-operator-meta">
                            <span class="caller-live-operator-label">Operator</span>
                            <div class="caller-live-operator-name">${escapeHtml(operatorName)}</div>
                            ${sessionMarkup}
                        </div>
                    </div>
                </div>
                <div class="caller-live-video-placeholder is-idle" data-caller-video-placeholder>
                    <span>Video Preview</span>
                </div>
            </section>
            <section class="caller-live-incident-types">
                <div class="caller-live-incident-strip">
                    ${renderCallerIncidentTypeCards(incidentTypeCards, 'Waiting for operator incident updates.')}
                </div>
            </section>
            <section class="caller-live-thread-host" data-caller-chat-thread></section>
            <section class="caller-live-composer-host">
                <div data-caller-chat-upload-queue></div>
                <div data-caller-chat-composer></div>
            </section>
            <footer class="caller-live-actions">
                <button class="caller-live-action-button" type="button" data-caller-live-video-toggle="1" aria-label="Show camera">
                    ${cameraIconMarkup()}
                </button>
                <button class="caller-live-action-button is-hidden" type="button" data-caller-live-camera-picker="1" aria-label="Select camera" hidden>
                    ${switchCameraIconMarkup()}
                </button>
                <button class="caller-live-action-button danger" type="button" data-caller-live-hangup="1" aria-label="Hang up">
                    ${hangupIconMarkup()}
                </button>
            </footer>
        </div>
    `;
}

function mountCallerConversation(host, incident, emptyText, includeComposer = false) {
    const callerThreadHost = host?.querySelector?.('[data-caller-chat-thread]');
    const callerComposerHost = host?.querySelector?.('[data-caller-chat-composer]');
    let threadApi = null;
    let composerApi = null;

    if (callerThreadHost && incident) {
        threadApi = trackSurfaceInstance(mountChatThread(callerThreadHost, incident.messages, 'citizen', {
            emptyText,
        }));
    }

    if (includeComposer && callerComposerHost) {
        composerApi = trackSurfaceInstance(mountChatComposer(callerComposerHost, {
            accept: 'image/*,video/*',
            helperText: 'Photos and videos only. Transport is still pending in this build.',
            onSend() {
                showToast('Live-call chat transport is still pending in this build.', 'info');
            },
            onFilesSelected() {
                showToast('Attachment upload is still pending in this build.', 'info');
            },
        }));
    }

    return {
        threadApi,
        composerApi,
    };
}

function cleanupCallerLiveModalRuntime() {
    const confirmTimer = appState.runtime.callerLiveModalHangupConfirmTimer;
    const completeTimer = appState.runtime.callerLiveModalHangupCompleteTimer;

    if (confirmTimer) {
        window.clearTimeout(confirmTimer);
        appState.runtime.callerLiveModalHangupConfirmTimer = null;
    }

    if (completeTimer) {
        window.clearTimeout(completeTimer);
        appState.runtime.callerLiveModalHangupCompleteTimer = null;
    }

    const pollTimer = appState.runtime.callerLiveModalPollTimer;

    if (pollTimer) {
        window.clearInterval(pollTimer);
        appState.runtime.callerLiveModalPollTimer = null;
    }

    appState.runtime.callerLiveModal?.chatRuntime?.destroy?.();
    appState.runtime.callerLiveModal?.callRuntime?.destroy?.();
    appState.runtime.callerLiveModal?.locationWatchStop?.();
    appState.runtime.callerLiveModal?.disconnectOverlay?.destroy?.();

    appState.runtime.callerLiveModal = null;
}

function syncCallerDisconnectingUi(overlay, isDisconnecting) {
    const hangupButton = overlay?.querySelector?.('[data-caller-live-hangup]');

    if (hangupButton instanceof HTMLButtonElement) {
        hangupButton.disabled = Boolean(isDisconnecting);
        hangupButton.setAttribute('aria-busy', isDisconnecting ? 'true' : 'false');
        hangupButton.setAttribute('aria-label', isDisconnecting ? 'Ending call' : 'Hang up');
        hangupButton.classList.toggle('is-disconnecting', Boolean(isDisconnecting));
    }
}

function clearCallerLiveHangupTimers() {
    if (appState.runtime.callerLiveModalHangupConfirmTimer) {
        window.clearTimeout(appState.runtime.callerLiveModalHangupConfirmTimer);
        appState.runtime.callerLiveModalHangupConfirmTimer = null;
    }

    if (appState.runtime.callerLiveModalHangupCompleteTimer) {
        window.clearTimeout(appState.runtime.callerLiveModalHangupCompleteTimer);
        appState.runtime.callerLiveModalHangupCompleteTimer = null;
    }
}

function refreshCallerLiveThread(overlay, incident, emptyText) {
    const host = overlay?.querySelector('[data-caller-chat-thread]');

    if (!host) {
        return null;
    }

    appState.runtime.callerLiveModal?.threadApi?.destroy?.();
    host.replaceChildren();

    return trackSurfaceInstance(mountChatThread(host, incident.messages, 'citizen', {
        emptyText,
    }));
}

function syncCallerLiveModalPayload(overlay, payload) {
    if (!overlay || !payload) {
        return;
    }

    joinCallerIncidentMediaRoom(payload.id);

    const latestSession = latestCallSession(payload);

    appState.runtime.callerHome = {
        ...(appState.runtime.callerHome ?? {}),
        current_open_incident: payload,
    };

    appState.runtime.callerLiveModal = {
        ...(appState.runtime.callerLiveModal ?? {}),
        incidentId: Number(payload.id),
        payload,
        latestSessionId: Number(latestSession?.id ?? 0),
    };

    const operatorName = payload.operator?.name ?? 'Assigned Operator';
    const operatorNameNode = overlay.querySelector('.caller-live-operator-name');
    const operatorAvatarNode = overlay.querySelector('.caller-live-avatar');
    const incidentNumberNode = overlay.querySelector('.caller-live-incident-number');
    const connectedText = overlay.querySelector('.caller-live-operator-meta .hero-copy');

    if (operatorNameNode) {
        operatorNameNode.textContent = operatorName;
    }

    if (operatorAvatarNode) {
        operatorAvatarNode.textContent = String((operatorName ?? 'OP').trim().slice(0, 2).toUpperCase());
    }

    if (incidentNumberNode) {
        incidentNumberNode.textContent = `Incident No. ${formatCallerLiveIncidentNumber(payload.id)}`;
    }

    if (connectedText) {
        connectedText.textContent = `Connected · Session #${latestSession?.id ?? ''}`;
    }

    syncCallerLiveIncidentTypeCards(overlay, payload);
    appState.runtime.callerLiveModal.threadApi = refreshCallerLiveThread(
        overlay,
        payload,
        'Live-call chat will appear here.',
    );
}

function closeCallerLiveModal(root) {
    cleanupCallerLiveModalRuntime();
    stopCallerCameraStream();
    fadeOutAndRemove(root.querySelector('[data-caller-live-modal]'));
}

function setCallerLiveModalTransportOnly(overlay, transportOnly) {
    if (!overlay) {
        return;
    }

    overlay.classList.toggle('is-transport-only', Boolean(transportOnly));
}

function suppressNativeOverlayInteractions(target) {
    if (!target) {
        return;
    }

    ['contextmenu', 'dragstart', 'selectstart'].forEach((eventName) => {
        target.addEventListener(eventName, (event) => {
            event.preventDefault();
        });
    });
}

function fadeInOverlay(node) {
    if (!node) {
        return;
    }

    node.classList.remove('is-closing');

    window.requestAnimationFrame(() => {
        node.classList.add('is-visible');
    });
}

function fadeOutAndRemove(node) {
    if (!node) {
        return Promise.resolve();
    }

    node.classList.remove('is-visible');
    node.classList.add('is-closing');

    return new Promise((resolve) => {
        let settled = false;
        const finish = () => {
            if (settled) {
                return;
            }

            settled = true;
            node.removeEventListener('transitionend', handleTransitionEnd);
            node.remove();
            resolve();
        };

        const handleTransitionEnd = (event) => {
            if (event.target !== node) {
                return;
            }

            finish();
        };

        node.addEventListener('transitionend', handleTransitionEnd, { once: true });
        window.setTimeout(finish, 260);
    });
}

function bindCallerLiveModalCommon(overlay, close) {
    suppressNativeOverlayInteractions(overlay);

    overlay?.querySelectorAll('[data-close-caller-live-modal]').forEach((button) => {
        button.addEventListener('click', close);
    });

    overlay?.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });

    callerCameraButtonVisibility(overlay, false);
    resetCallerVideoPreview(overlay);

    overlay?.querySelector('[data-caller-live-video-toggle]')?.addEventListener('click', async () => {
        await startCallerCameraPreview(overlay, appState.runtime.callerCameraDeviceId);
    });

    overlay?.querySelector('[data-caller-live-camera-picker]')?.addEventListener('click', async () => {
        await openCallerCameraPicker(overlay);
    });
}

async function openCallerLiveModal(root, payload, latestSession, { transportOnly = false } = {}) {
    if (!payload) {
        showToast('No active call is available right now.', 'info');
        return;
    }

    await ensureHelperUi();

    const callSessionId = Number(latestSession?.id ?? 0);
    const activeLiveModal = appState.runtime.callerLiveModal ?? null;
    const activeLiveOverlay = root.querySelector('[data-caller-live-modal]');

    if (
        callSessionId > 0
        && Number(activeLiveModal?.latestSessionId ?? 0) === callSessionId
        && activeLiveOverlay
    ) {
        logCallFlow('citizen', 'live-modal-reuse-existing-overlay', {
            incidentId: Number(payload.id ?? 0) || null,
            callSessionId,
            opening: Boolean(activeLiveModal?.opening),
            hasCallRuntime: Boolean(activeLiveModal?.callRuntime),
            hasCallRuntimePromise: Boolean(activeLiveModal?.callRuntimePromise),
        });

        setCallerLiveModalTransportOnly(activeLiveOverlay, transportOnly);
        appState.runtime.callerLiveModal = {
            ...activeLiveModal,
            incidentId: Number(payload.id ?? 0),
            payload,
            latestSessionId: callSessionId,
            transportOnly: Boolean(transportOnly),
        };
        return;
    }

    closeCallerLiveModal(root);
    root.insertAdjacentHTML(
        'beforeend',
        renderCallerLiveCallModal(payload, latestSession, { transportOnly }),
    );

    const overlay = root.querySelector('[data-caller-live-modal]');
    const close = () => {
        cleanupCallerLiveModalRuntime();
        stopCallerCameraStream();
        closeCallerCameraPicker(overlay);
        return fadeOutAndRemove(overlay);
    };

    bindCallerLiveModalCommon(overlay, close);
    fadeInOverlay(overlay);
    setCallerLiveModalTransportOnly(overlay, transportOnly);

    appState.runtime.callerLiveModal = {
        incidentId: Number(payload.id ?? 0),
        payload,
        latestSessionId: callSessionId,
        transportOnly: Boolean(transportOnly),
        opening: true,
        threadApi: null,
        composerApi: null,
        chatRuntime: null,
        callRuntime: null,
        callRuntimePromise: null,
        locationWatchStop: null,
        disconnectOverlay: null,
        disconnectRequested: false,
        hangupConfirmReceived: false,
    };

    overlay?.querySelector('[data-caller-live-hangup]')?.addEventListener('click', () => {
        void (async () => {
            try {
                const runtime = appState.runtime.callerLiveModal ?? {};

                if (runtime.disconnectRequested) {
                    return;
                }

                runtime.disconnectRequested = true;
                runtime.hangupConfirmReceived = false;
                runtime.disconnectRequestedAt = new Date().toISOString();
                syncCallerDisconnectingUi(overlay, true);
                if (!runtime.disconnectOverlay && typeof appState.helper.createBusyOverlay === 'function') {
                    runtime.disconnectOverlay = appState.helper.createBusyOverlay(
                        overlay.querySelector('.caller-live-modal-panel') ?? overlay,
                        {
                            text: 'Ending call...',
                            visible: true,
                            fullscreen: false,
                            blockInteraction: true,
                        },
                    );
                }
                clearCallerLiveHangupTimers();

                appState.runtime.callerLiveModalHangupConfirmTimer = window.setTimeout(() => {
                    if (!appState.runtime.callerLiveModal?.hangupConfirmReceived) {
                        console.warn('Caller hangup confirm timeout.', {
                            callSessionId: Number(latestSession?.id ?? 0),
                            incidentId: Number(payload?.id ?? 0),
                            requestedAt: runtime.disconnectRequestedAt,
                        });
                    }
                }, CALLER_HANGUP_CONFIRM_TIMEOUT_MS);

                appState.runtime.callerLiveModalHangupCompleteTimer = window.setTimeout(() => {
                    console.warn('Caller hangup complete timeout. Disconnecting anyway.', {
                        callSessionId: Number(latestSession?.id ?? 0),
                        incidentId: Number(payload?.id ?? 0),
                        requestedAt: runtime.disconnectRequestedAt,
                        hangupConfirmReceived: Boolean(appState.runtime.callerLiveModal?.hangupConfirmReceived),
                    });
                    void (async () => {
                        clearCallerLiveHangupTimers();
                        await close();
                        await renderSurface('citizen');
                    })();
                }, CALLER_HANGUP_COMPLETE_TIMEOUT_MS);

                runtime.callRuntime?.sendDisconnectRequest?.({
                    reason: 'ended-by-citizen',
                    requested_at: runtime.disconnectRequestedAt,
                });
            } catch (error) {
                showToast(error.response?.data?.message ?? 'Unable to hang up the active call.');
            }
        })();
    });

    if (overlay) {
        const needsConnectionGate = !latestSession?.answered_at;
        const liveConversation = await mountRealtimeIncidentChat({
            incidentId: Number(payload.id ?? 0),
            messages: payload.messages ?? [],
            viewerRole: 'citizen',
            admissionPath: '/api/realtime/admission/citizen',
            currentUserId: String(appState.bootstrap?.user?.id ?? ''),
            currentDisplayName: String(appState.bootstrap?.user?.name ?? 'Citizen'),
            threadHost: overlay.querySelector('[data-caller-chat-thread]'),
            composerHost: overlay.querySelector('[data-caller-chat-composer]'),
            uploadQueueHost: overlay.querySelector('[data-caller-chat-upload-queue]'),
            emptyTitle: 'No chat yet',
            emptyText: 'Live-call chat will appear here.',
            composerOptions: {
                placeholder: 'Type a message...',
                helperText: '',
                showAttachmentButton: true,
                accept: 'image/*,video/*',
            },
            onMediaEvent(_eventType, eventPayload) {
                const nextMedia = eventPayload?.media && typeof eventPayload.media === 'object'
                    ? eventPayload.media
                    : null;

                if (!nextMedia) {
                    return;
                }

                payload.media = mergeIncidentMediaItems(payload.media, nextMedia);

                appState.runtime.callerHome = {
                    ...(appState.runtime.callerHome ?? {}),
                    current_open_incident: {
                        ...(appState.runtime.callerHome?.current_open_incident ?? payload),
                        media: payload.media,
                    },
                };

                if (appState.runtime.callerLiveModal) {
                    appState.runtime.callerLiveModal.payload = {
                        ...(appState.runtime.callerLiveModal.payload ?? payload),
                        media: payload.media,
                    };
                }
            },
        });
        const staticConversation = liveConversation
            ? null
            : mountCallerConversation(overlay, payload, 'Live-call chat will appear here.', false);
        const fallbackComposer = liveConversation
            ? null
            : trackSurfaceInstance(mountChatComposer(overlay.querySelector('[data-caller-chat-composer]'), {
                showAttachmentButton: true,
                helperText: '',
                placeholder: 'Type a message...',
                accept: 'image/*,video/*',
                onSend() {
                    showToast('Live chat is unavailable right now.', 'warn');
                },
                onFilesSelected() {
                    showToast('Attachment transport is unavailable right now.', 'warn');
                },
            }));
        const conversation = liveConversation
            ? liveConversation
            : {
                ...staticConversation,
                composerApi: fallbackComposer,
            };
        const existingRuntime = appState.runtime.callerLiveModal ?? null;
        const existingCallRuntime = Number(existingRuntime?.latestSessionId ?? 0) === callSessionId
            ? existingRuntime.callRuntime ?? null
            : null;
        const existingCallRuntimePromise = Number(existingRuntime?.latestSessionId ?? 0) === callSessionId
            ? existingRuntime.callRuntimePromise ?? null
            : null;

        logCallFlow('citizen', 'live-modal-call-runtime-mount', {
            incidentId: Number(payload.id ?? 0) || null,
            callSessionId: callSessionId || null,
            operatorId: Number(payload?.operator?.id ?? 0) || null,
            needsConnectionGate,
            reused: Boolean(existingCallRuntime || existingCallRuntimePromise),
        });
        const callRuntimePromise = existingCallRuntimePromise
            ?? (existingCallRuntime
                ? Promise.resolve(existingCallRuntime)
                : null)
            ?? (latestSession?.id && payload?.operator?.id
            ? mountRealtimeCallSession({
                callSessionId: Number(latestSession.id),
                viewerRole: 'citizen',
                admissionPath: '/api/realtime/admission/citizen',
                currentUserId: String(appState.bootstrap?.user?.id ?? ''),
                remoteUserId: String(payload.operator.id),
                remoteAudioHost: overlay,
                localVideoStream: appState.runtime.callerCameraStream ?? null,
                startMuted: needsConnectionGate,
                onRemoteStream(stream) {
                    logCallFlow('citizen', 'remote-stream-observed', {
                        incidentId: Number(payload.id ?? 0) || null,
                        callSessionId: Number(latestSession.id ?? 0) || null,
                        audioTrackCount: stream instanceof MediaStream ? stream.getAudioTracks().length : 0,
                        videoTrackCount: stream instanceof MediaStream ? stream.getVideoTracks().length : 0,
                    });
                    if (!(stream instanceof MediaStream)) {
                        return;
                    }
                },
                onStateChange(nextState) {
                    logCallFlow('citizen', 'peer-connection-state', {
                        incidentId: Number(payload.id ?? 0) || null,
                        callSessionId: Number(latestSession.id ?? 0) || null,
                        state: String(nextState ?? ''),
                    });
                },
                onHangupConfirm() {
                    if (appState.runtime.callerLiveModal) {
                        appState.runtime.callerLiveModal.hangupConfirmReceived = true;
                    }
                },
                onHangupComplete() {
                    void (async () => {
                        clearCallerLiveHangupTimers();
                        await close();
                        await renderSurface('citizen');
                    })();
                },
                onHangup() {
                    void (async () => {
                        clearCallerLiveHangupTimers();
                        await close();
                        await renderSurface('citizen');
                    })();
                },
            })
            : null);

        if (callRuntimePromise && !existingCallRuntime && !existingCallRuntimePromise) {
            appState.runtime.callerLiveModal = {
                ...(appState.runtime.callerLiveModal ?? {}),
                callRuntimePromise,
            };
        }

        const callRuntime = callRuntimePromise ? await callRuntimePromise : null;
        const existingLocationWatchStop = existingCallRuntime
            ? existingRuntime?.locationWatchStop ?? null
            : null;
        const currentRuntime = appState.runtime.callerLiveModal ?? null;

        if (Number(currentRuntime?.latestSessionId ?? 0) !== callSessionId || !overlay.isConnected) {
            liveConversation?.destroy?.();
            callRuntime?.destroy?.();
            return;
        }

        appState.runtime.callerLiveModal = {
            ...currentRuntime,
            payload,
            latestSessionId: callSessionId,
            transportOnly: Boolean(transportOnly),
            opening: false,
            threadApi: conversation.threadApi,
            composerApi: conversation.composerApi,
            chatRuntime: liveConversation,
            callRuntime,
            callRuntimePromise: null,
            locationWatchStop: existingLocationWatchStop ?? startCallerLocationWatch(callRuntime),
            disconnectOverlay: currentRuntime.disconnectOverlay ?? null,
            disconnectRequested: Boolean(currentRuntime.disconnectRequested),
            hangupConfirmReceived: Boolean(currentRuntime.hangupConfirmReceived),
        };

        if (!needsConnectionGate) {
            callRuntime?.setMediaMuted?.(false);
        }
    }
}

async function runCallerReconnect(root, incidentId, noticeTarget = null) {
    const nextIncidentId = Number(incidentId ?? 0);

    if (nextIncidentId <= 0) {
        return;
    }

    setCallerPendingState({
        kind: 'reconnect',
        incident_id: nextIncidentId,
        phase: 'availability_check',
        created_at: new Date().toISOString(),
    });

    publishCallerCallFlow('caller.reconnect.availability.request', {
        caller_id: Number(appState.bootstrap?.user?.id ?? 0),
        incident_id: nextIncidentId,
        requested_at: new Date().toISOString(),
    });

    if (noticeTarget) {
        noticeTarget.hidden = true;
    }
}

async function openCallerIncident(root, incidentId) {
    const payload = await fetchJson(`/api/citizen/incidents/${incidentId}`);
    await showCallerIncidentOverlay(root, payload);
}

async function showCallerIncidentOverlay(root, payload) {
    if (!payload) {
        return;
    }

    const existingOverlay = root.querySelector('[data-caller-incident-overlay]');

    if (existingOverlay) {
        syncCallerIncidentOverlayInPlace(existingOverlay, payload);
        return;
    }

    root.insertAdjacentHTML('beforeend', renderCallerIncidentOverlay(payload));

    const overlay = root.querySelector('[data-caller-incident-overlay]');
    const notice = overlay?.querySelector('[data-caller-incident-notice]');
    const close = () => {
        return fadeOutAndRemove(overlay);
    };

    suppressNativeOverlayInteractions(overlay);
    fadeInOverlay(overlay);
    await mountCallerIncidentOverlay(overlay, payload);

    overlay?.querySelector('[data-close-caller-incident]')?.addEventListener('click', close);
    overlay?.querySelector('[data-refresh-caller-incident]')?.addEventListener('click', async () => {
        try {
            const refreshed = await fetchJson(`/api/citizen/incidents/${payload.id}`);
            await showCallerIncidentOverlay(root, refreshed);
        } catch (error) {
            showToast(error.response?.data?.message ?? 'Unable to refresh incident.');
        }
    });
    overlay?.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });

    overlay?.querySelectorAll('[data-caller-reconnect]').forEach((button) => {
        button.addEventListener('click', async () => {
            await runCallerReconnect(root, button.dataset.callerReconnect, notice);
        });
    });
}

function closeCallerPendingOverlay(root) {
    return fadeOutAndRemove(root.querySelector('[data-caller-pending-overlay]'));
}

function showCallerPendingOverlay(root, pending, incident = null, alertLevel = null) {
    const existingOverlay = root.querySelector('[data-caller-pending-overlay]');

    if (existingOverlay) {
        const nextAlertToneClass = callerAlertToneClass(alertLevel);
        existingOverlay.className = ['overlay-backdrop', 'caller-pending-overlay', nextAlertToneClass, 'is-visible']
            .filter(Boolean)
            .join(' ');

        const stage = existingOverlay.querySelector('.caller-ringing-screen');

        if (stage) {
            stage.outerHTML = renderCallerPendingContent(pending, incident);
        }

        return;
    }

    root.insertAdjacentHTML('beforeend', renderCallerPendingOverlay(pending, incident, alertLevel));
    const overlay = root.querySelector('[data-caller-pending-overlay]');
    suppressNativeOverlayInteractions(overlay);
    fadeInOverlay(overlay);
}

function renderCaller(root, bootstrap, home, primerReport) {
    const currentIncident = home.current_open_incident ?? null;
    const pendingState = getCallerPendingState();
    const latestSession = latestCallSession(currentIncident);
    const newCallPendingPhases = ['discovering', 'operator_found', 'requesting', 'ringing', 'connecting'];
    const hasNewCallPending = pendingState?.kind === 'new_call'
        && newCallPendingPhases.includes(String(pendingState?.phase ?? '').trim());
    const reconnectPendingPhases = ['availability_check', 'requesting', 'ringing', 'connecting'];
    const reconnectOverlayPhases = ['ringing', 'connecting'];
    const hasReconnectPending = currentIncident
        && pendingState?.kind === 'reconnect'
        && Number(pendingState?.incident_id) === Number(currentIncident.id)
        && reconnectPendingPhases.includes(String(pendingState?.phase ?? '').trim());
    const showingReconnectCall = currentIncident
        && pendingState?.kind === 'reconnect'
        && Number(pendingState?.incident_id) === Number(currentIncident.id)
        && reconnectOverlayPhases.includes(String(pendingState?.phase ?? '').trim());
    const activePendingState = hasNewCallPending
        ? pendingState
        : showingReconnectCall
            ? pendingState
            : null;
    const shouldMountLiveCall = currentIncident
        && latestSession?.status === 'in_progress'
        && (
            !activePendingState
            || String(activePendingState?.phase ?? '').trim() === 'connecting'
        );
    const showingLiveCall = shouldMountLiveCall && !activePendingState;

    if (!hasReconnectPending && currentIncident && pendingState?.kind === 'reconnect') {
        clearCallerPendingState();
    }

    const shellAlertToneClass = !showingLiveCall && !showingReconnectCall && !activePendingState && !currentIncident
        ? callerAlertToneClass(bootstrap?.alert_level)
        : '';

    appState.runtime.navbarActions = [];

    const citizenPwa = window.HotlineCitizenPwa ?? window.HotlineCallerPwa;

    if (!citizenPwa?.isStandalone?.()) {
        appState.runtime.navbarActions.push({
            id: 'caller-install-app',
            label: 'Install App',
        });
    }

    appState.runtime.navbarOnAction = (action) => {
        if (action?.id === 'caller-install-app') {
            void citizenPwa?.offerInstall?.();
            return;
        }
    };
    appState.runtime.navbarContentEnd = null;
    appState.runtime.navbarStatusContent = () => callerNavbarStatusContent(appState.runtime.callerPrimerReport ?? primerReport);
    appState.runtime.navbarStatusContentLabel = 'Citizen status';

    const content = renderCallerHomeContent(home, primerReport, bootstrap?.alert_level);

    root.innerHTML = sharedShell({
        title: 'Call for help',
        kicker: 'Citizen',
        statusLabel: '',
        content,
        brandHref: '/citizen',
        statusActions: '',
        showHero: false,
        shellClass: ['caller-shell', shellAlertToneClass].filter(Boolean).join(' '),
        mainClass: 'caller-main',
        toolbarClass: 'caller-toolbar',
    });

    mountSurfaceChrome(root, 'citizen', bootstrap);
    const signalHost = root.querySelector('[data-caller-inline-realtime-signal]');

    if (signalHost) {
        const signal = mountRealtimeSignalStrength(signalHost, {
            createSignalStrength: appState.helper.createSignalStrength,
            onSnapshot: (snapshot) => {
                appState.runtime.callerRealtimeSignalSnapshot = snapshot;
                syncCallerSignalDrawer(snapshot);
            },
        });

        signal.setReconnectRuntime(callerRealtimeReconnectRuntime());
        signal.bindClient(appState.runtime.callerRealtimeStream?.client ?? null);
        appState.runtime.callerRealtimeSignal = signal;
        trackSurfaceInstance(signal);
    }

    root.querySelector('[data-caller-signal-help]')?.addEventListener('click', () => {
        openCallerSignalHelpDrawer(appState.runtime.callerRealtimeSignalSnapshot);
    });

    wirePrimer(root, primerReport);

    if (shouldMountLiveCall && currentIncident && latestSession) {
        openCallerLiveModal(root, currentIncident, latestSession, {
            transportOnly: Boolean(
                activePendingState
                && String(activePendingState?.phase ?? '').trim() === 'connecting'
            ),
        });
    } else {
        closeCallerLiveModal(root);
    }

    if (showingReconnectCall || activePendingState) {
        showCallerPendingOverlay(root, activePendingState ?? pendingState, currentIncident, bootstrap?.alert_level);
    } else {
        closeCallerPendingOverlay(root);
    }

    if (currentIncident && !showingLiveCall && !showingReconnectCall && !activePendingState) {
        void showCallerIncidentOverlay(root, currentIncident);
    }

    root.querySelectorAll('[data-open-caller-incident]').forEach((button) => {
        if (!button.dataset.openCallerIncident) {
            return;
        }

        button.addEventListener('click', async () => {
            await openCallerIncident(root, button.dataset.openCallerIncident);
        });
    });

    root.querySelectorAll('[data-caller-reconnect]').forEach((button) => {
        button.addEventListener('click', async () => {
            await runCallerReconnect(root, button.dataset.callerReconnect);
        });
    });

    root.querySelector('[data-caller-reconnect-current]')?.addEventListener('click', async () => {
        await runCallerReconnect(root, currentIncident.id);
    });

    root.querySelector('[data-cancel-caller-pending]')?.addEventListener('click', async () => {
        const pending = activePendingState ?? pendingState;

        try {
            if (pending?.kind === 'new_call' && pending.operator_attempt_id && pending.operator_id) {
                publishCallerCallFlow('caller.call.cancel', {
                    call_attempt_id: Number(pending.attempt_id ?? 0),
                    call_attempt_operator_attempt_id: Number(pending.operator_attempt_id),
                    caller_id: Number(appState.bootstrap?.user?.id ?? 0),
                    operator_id: Number(pending.operator_id),
                    cancelled_at: new Date().toISOString(),
                });
            }
            else if (pending?.kind === 'reconnect' && pending.operator_attempt_id && pending.operator_id) {
                publishCallerCallFlow('caller.reconnect.cancel', {
                    call_attempt_id: Number(pending.attempt_id ?? 0),
                    call_attempt_operator_attempt_id: Number(pending.operator_attempt_id),
                    caller_id: Number(appState.bootstrap?.user?.id ?? 0),
                    incident_id: Number(pending.incident_id ?? 0),
                    operator_id: Number(pending.operator_id),
                    cancelled_at: new Date().toISOString(),
                });
            }
            else if (pending?.kind === 'reconnect' && pending.call_session_id) {
                await fetchJson(`/api/citizen/call-sessions/${pending.call_session_id}/cancel`, {
                    method: 'post',
                });
            }

            await closeCallerPendingOverlay(root);
            clearCallerPendingState();
            rerenderCallerInPlace();
        } catch (error) {
            showToast(error.response?.data?.message ?? 'Unable to hang up the current attempt.');
        }
    });

    const homePanelButtons = root.querySelectorAll('[data-toggle-home-panel]');

    if (homePanelButtons.length > 0) {
        const closeHomePanels = () => {
            root.querySelectorAll('[data-home-panel]').forEach((panel) => {
                panel.hidden = true;
            });
            homePanelButtons.forEach((button) => {
                button.setAttribute('aria-expanded', 'false');
            });
        };

        homePanelButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                const nextPanel = root.querySelector(`[data-home-panel="${button.dataset.toggleHomePanel}"]`);
                const willOpen = Boolean(nextPanel?.hidden);

                closeHomePanels();

                if (nextPanel && willOpen) {
                    nextPanel.hidden = false;
                    button.setAttribute('aria-expanded', 'true');
                }
            });
        });

        root.querySelectorAll('[data-home-panel]').forEach((panel) => {
            panel.addEventListener('click', (event) => {
                event.stopPropagation();
            });
        });

        const handleDocumentPointerDown = (event) => {
            const target = event.target;

            if (target instanceof Element && target.closest('.caller-home-utility')) {
                return;
            }

            closeHomePanels();
        };

        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                closeHomePanels();
            }
        };

        document.addEventListener('pointerdown', handleDocumentPointerDown);
        document.addEventListener('keydown', handleEscape);
        trackSurfaceInstance({
            destroy() {
                document.removeEventListener('pointerdown', handleDocumentPointerDown);
                document.removeEventListener('keydown', handleEscape);
            },
        });
    }

    if (!currentIncident && !activePendingState) {
        const holdButton = root.querySelector('[data-hold-call]');
        const progressTrack = root.querySelector('[data-call-hold-track]');
        const progressBar = root.querySelector('[data-call-hold-progress]');
        const caption = root.querySelector('[data-call-hold-caption]');
        const holdMs = Math.max(500, Number(home.call_hold_seconds ?? bootstrap.settings?.call_hold_seconds ?? 0) * 1000);
        const availability = callerAvailabilitySummary(home.availability, primerReport);
        let holdInterval = null;
        let holdStart = 0;
        let triggered = false;

        const resetHold = () => {
            if (holdInterval) {
                window.clearInterval(holdInterval);
                holdInterval = null;
            }

            triggered = false;
            holdStart = 0;

            if (progressBar) {
                progressBar.style.width = '0%';
            }

            progressTrack?.classList.remove('is-active');

            if (caption) {
                caption.textContent = 'Press and hold to start.';
            }
        };

        const startHold = () => {
            if (!availability.canCall) {
                showToast(availability.reason, availability.status === 'yellow' ? 'warn' : 'error');
                return;
            }

            holdStart = Date.now();
            progressTrack?.classList.add('is-active');

            if (caption) {
                caption.textContent = 'Keep holding...';
            }

            void captureCallerLocationOnce({ timeoutMs: 5000 });

            holdInterval = window.setInterval(async () => {
                const elapsed = Date.now() - holdStart;
                const percent = Math.min(100, (elapsed / holdMs) * 100);

                if (progressBar) {
                    progressBar.style.width = `${percent}%`;
                }

                if (percent < 100 || triggered) {
                    return;
                }

                triggered = true;
                window.clearInterval(holdInterval);
                holdInterval = null;

                try {
                    setCallerPendingState({
                        kind: 'new_call',
                        operator_id: null,
                        excluded_operator_ids: [],
                        phase: 'discovering',
                        created_at: new Date().toISOString(),
                    });
                    rerenderCallerInPlace();
                    const location = await captureCallerLocationOnce({ timeoutMs: 1200 });
                    updateCallerLocation(location);

                    const published = publishCallerOperatorDiscoveryRequest([]);

                    if (!published) {
                        throw new Error('Realtime call discovery is not connected yet.');
                    }
                } catch (error) {
                    resetHold();
                    showToast(error.response?.data?.message ?? error.message ?? 'Unable to start a call attempt.');
                }
            }, 40);
        };

        holdButton?.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }

            event.preventDefault();
            resetHold();
            void requestCallerOrientationAccess();
            startHold();
        });
        ['contextmenu', 'dragstart', 'selectstart', 'click', 'auxclick', 'mouseup'].forEach((eventName) => {
            holdButton?.addEventListener(eventName, (event) => {
                event.preventDefault();
            });
        });
        ['pointerup', 'pointerleave', 'pointercancel'].forEach((eventName) => {
            holdButton?.addEventListener(eventName, (event) => {
                event.preventDefault();

                if (!triggered) {
                    resetHold();
                }
            });
        });
    }

    if (currentIncident) {
        mountCallerConversation(
            root,
            currentIncident,
            showingLiveCall ? 'Live-call chat is available from the Active Call button.' : 'Chat history remains visible after the call.',
            false,
        );
    }

}


export async function renderCitizenSurface(root, bootstrap) {
    const primerReport = evaluateDevicePrimer('citizen');
    const home = bootstrap?.surface_payload ?? await fetchJson('/api/citizen/home');
    appState.runtime.callerRoot = root;
    appState.runtime.callerHome = home;
    appState.runtime.callerPrimerReport = primerReport;
    renderCaller(root, bootstrap, home, primerReport);
    ensureCallerSpeechPrimer();
    await connectCallerRealtimeStream();
}
