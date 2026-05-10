import { appState, buildOptions, deriveActiveCallSessionId, ensureHelperUi, escapeHtml, evaluateDevicePrimer, fetchJson, formatDateTime, formatStatusLabel, handleCommandBroadcastEnvelope, INCOMING_MODAL_DISMISS_PREFIX, mergeIncidentMediaItems, mountChatComposer, mountChatThread, mountRealtimeCallSession, mountRealtimeIncidentChat, mountSurfaceChrome, OPERATOR_WORKBENCH_CALL_SESSION_KEY, OPERATOR_WORKBENCH_KEY, padIncidentId, renderAssignments, renderMedia, renderStatChips, renderTransfers, sharedShell, showToast, trackSurfaceInstance, TRANSFER_MODAL_DISMISS_PREFIX, wirePrimer } from './surfaceShared.js';
import { renderSurface } from './renderSurface.js';
import { createOperatorMediaManagers } from '../media/operator.js';
import { createOperatorMediaFinalizer } from '../media/finalizers/operatorMediaFinalizer.js';
import { createOperatorMediaBootstrapTransport } from '../media/transports/bootstrapChunkTransport.js';
import { createOperatorMediaBatchChunkTransport } from '../media/transports/batchChunkTransport.js';
import { createRealtimeOperatorMediaChunkTransport } from '../media/transports/realtimeChunkTransport.js';
import { createDashboardMap } from '../maps/dashboardMap.js';
import { createWorkbenchLocationMap } from '../maps/workbenchLocationMap.js';
import { buildAppEventPublishPayload, buildPresencePublishPayload, buildPresenceSubscribePayload, buildRoomJoinPayload, listPresenceRosterItems, parseRealtimeEnvelope, reducePresenceRosterEvent, RealtimeSocketClient } from '../../../../realtime/resources/js/sdk/index.js';
import { citizenEventType, isLegacyCallerRealtimeEvent, legacyCallerEventType, withCitizenRealtimePayloadAliases } from '../realtime/citizenEvents.js';

const CALL_DISCOVERY_ROOM = 'presence.global.hotline';
const INCIDENT_MEDIA_ROOM_PREFIX = 'hotline.media.incident.';
const OPERATOR_DISCOVERY_PRESENCE_HEARTBEAT_MS = 60000;
const OPERATOR_CALL_TIMEOUT_FALLBACK_SECONDS = 30;
const OPERATOR_REALTIME_RECONNECT_MIN_MS = 1000;
const OPERATOR_REALTIME_RECONNECT_MAX_MS = 15000;
const HOTLINE_MEDIA_DEBUG = true;
const OPERATOR_MEDIA_CONSUMER_ENABLED = true;
const OPERATOR_MEDIA_CHUNK_TRANSPORT = 'realtime-binary';

function operatorDiscoveryClient() {
    return appState.runtime.operatorRealtimeStream?.client ?? null;
}

function operatorRealtimeReconnectRuntime() {
    if (!appState.runtime.operatorRealtimeReconnect) {
        appState.runtime.operatorRealtimeReconnect = {
            attempts: 0,
            connecting: false,
            timerId: null,
        };
    }

    return appState.runtime.operatorRealtimeReconnect;
}

function clearOperatorRealtimeReconnectTimer() {
    const runtime = appState.runtime.operatorRealtimeReconnect;

    if (runtime?.timerId) {
        window.clearTimeout(runtime.timerId);
        runtime.timerId = null;
    }

    appState.runtime.operatorRealtimeSignal?.setReconnectRuntime?.(runtime);
}

function resetOperatorRealtimeJoinState() {
    resetOperatorDiscoveryPresence();
    resetOperatorTransferPresenceRoster();
    operatorMediaRoomsRuntime().joined.clear();
}

function scheduleOperatorRealtimeReconnect(root) {
    if (!appState.bootstrap?.authenticated || appState.activeSurface !== 'operator') {
        return;
    }

    const runtime = operatorRealtimeReconnectRuntime();

    if (runtime.timerId || runtime.connecting) {
        return;
    }

    runtime.attempts = Math.min(runtime.attempts + 1, 8);

    const baseDelay = Math.min(
        OPERATOR_REALTIME_RECONNECT_MAX_MS,
        OPERATOR_REALTIME_RECONNECT_MIN_MS * (2 ** (runtime.attempts - 1)),
    );
    const jitter = Math.floor(Math.random() * 350);

    runtime.timerId = window.setTimeout(() => {
        runtime.timerId = null;
        appState.runtime.operatorRealtimeSignal?.setReconnectRuntime?.(runtime);
        void connectOperatorRealtimeStream(root ?? currentOperatorRoot(), { reconnect: true });
    }, baseDelay + jitter);
    appState.runtime.operatorRealtimeSignal?.setReconnectRuntime?.(runtime);
}

function publishOperatorCallFlow(eventType, payload = {}) {
    const client = operatorDiscoveryClient();

    if (!client?.isOpen?.()) {
        return null;
    }

    return client.sendRequest(
        'app.event.publish',
        CALL_DISCOVERY_ROOM,
        buildAppEventPublishPayload(citizenEventType(eventType), withCitizenRealtimePayloadAliases(payload)),
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
            surface: 'operator',
            event_type: eventType,
            canonical_event_type: citizenEventType(eventType),
            room: String(envelope?.room ?? '').trim() || null,
        },
    }).catch((error) => {
        console.warn('Legacy caller Realtime event telemetry failed.', error);
    });
}

function operatorCallTimeoutMs() {
    const seconds = Number(appState.bootstrap?.settings?.call_timeout_seconds ?? OPERATOR_CALL_TIMEOUT_FALLBACK_SECONDS);

    return Math.max(5, seconds || OPERATOR_CALL_TIMEOUT_FALLBACK_SECONDS) * 1000;
}

function operatorIsExcludedFromCallerDiscovery(payload = {}) {
    const operatorId = Number(appState.bootstrap?.user?.id ?? 0);
    const excluded = Array.isArray(payload?.excluded_operator_ids)
        ? payload.excluded_operator_ids
        : [];

    return operatorId > 0 && excluded.some((id) => Number(id ?? 0) === operatorId);
}

function operatorIncidentMediaRoom(incidentId) {
    const nextIncidentId = Number(incidentId ?? 0);

    return nextIncidentId > 0 ? `${INCIDENT_MEDIA_ROOM_PREFIX}${nextIncidentId}` : '';
}

function operatorMediaRoomsRuntime() {
    if (!appState.runtime.operatorMediaRooms) {
        appState.runtime.operatorMediaRooms = {
            requested: new Set(),
            joined: new Set(),
        };
    }

    return appState.runtime.operatorMediaRooms;
}

function joinOperatorIncidentMediaRoom(incidentId) {
    const room = operatorIncidentMediaRoom(incidentId);

    if (!room) {
        return;
    }

    const mediaRooms = operatorMediaRoomsRuntime();
    mediaRooms.requested.add(room);

    const stream = appState.runtime.operatorRealtimeStream;
    const client = stream?.client ?? null;

    if (client?.isOpen?.() && !mediaRooms.joined.has(room)) {
        client.sendRequest('room.join.request', room, buildRoomJoinPayload());
    }
}

function publishOperatorIncidentUpdate(payload = {}) {
    return publishOperatorCallFlow('hotline.incident.updated', {
        ...payload,
        changed_at: String(payload?.changed_at ?? new Date().toISOString()),
    });
}

function publishOperatorCallerLocationPersisted(incident, fallbackLocation = null) {
    const incidentId = Number(incident?.id ?? incident?.incident_id ?? 0);

    if (!incidentId) {
        return;
    }

    publishOperatorIncidentUpdate({
        incident_id: incidentId,
        caller_id: Number(incident?.caller_id ?? 0),
        scope: 'caller_location',
        patch: {
            latitude: incident?.latitude ?? fallbackLocation?.latitude ?? null,
            longitude: incident?.longitude ?? fallbackLocation?.longitude ?? null,
            caller_location: incident?.caller_location ?? fallbackLocation ?? null,
        },
    });
}

function normalizeCallerLocationPayload(payload = {}) {
    const source = payload?.caller_location && typeof payload.caller_location === 'object'
        ? payload.caller_location
        : payload;
    const latitude = Number(source?.latitude ?? payload?.caller_latitude ?? payload?.latitude ?? NaN);
    const longitude = Number(source?.longitude ?? payload?.caller_longitude ?? payload?.longitude ?? NaN);
    const altitude = Number(source?.altitude ?? payload?.altitude ?? NaN);
    const altitudeAccuracy = Number(source?.altitude_accuracy ?? source?.altitudeAccuracy ?? payload?.altitude_accuracy ?? NaN);
    const heading = Number(source?.heading ?? payload?.heading ?? NaN);

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        return null;
    }

    if (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
        return null;
    }

    return {
        latitude,
        longitude,
        accuracy: Number.isFinite(Number(source?.accuracy)) ? Number(source.accuracy) : null,
        altitude: Number.isFinite(altitude) ? altitude : null,
        altitude_accuracy: Number.isFinite(altitudeAccuracy) ? altitudeAccuracy : null,
        heading: Number.isFinite(heading) ? ((heading % 360) + 360) % 360 : null,
        heading_source: String(source?.heading_source ?? payload?.heading_source ?? '').trim(),
        captured_at: String(source?.captured_at ?? payload?.captured_at ?? payload?.meta?.captured_at ?? '').trim(),
    };
}

function callerLocationAttemptPayload(payload = {}) {
    const location = normalizeCallerLocationPayload(payload);

    if (!location) {
        return {};
    }

    return {
        caller_latitude: location.latitude,
        caller_longitude: location.longitude,
    };
}

function callerLocationPersistPayload(payload = {}) {
    const location = normalizeCallerLocationPayload(payload);

    if (!location) {
        return null;
    }

    return {
        latitude: location.latitude,
        longitude: location.longitude,
        call_session_id: Number(payload.call_session_id ?? payload.callSessionId ?? 0) || null,
        accuracy: location.accuracy,
        altitude: location.altitude,
        altitude_accuracy: location.altitude_accuracy,
        heading: location.heading,
        heading_source: location.heading_source || null,
        source: String(payload.source ?? payload?.meta?.source ?? 'operator-realtime').trim() || 'operator-realtime',
        captured_at: location.captured_at || new Date().toISOString(),
    };
}

async function persistOperatorCallerLocation(incidentId, locationPayload = {}) {
    const nextIncidentId = Number(incidentId ?? 0);
    const payload = callerLocationPersistPayload(locationPayload);

    if (!nextIncidentId || !payload) {
        return null;
    }

    if (!appState.runtime.operatorCallerLocationPersistIds) {
        appState.runtime.operatorCallerLocationPersistIds = {};
    }

    const key = String(nextIncidentId);
    const requestId = Number(appState.runtime.operatorCallerLocationPersistIds[key] ?? 0) + 1;
    appState.runtime.operatorCallerLocationPersistIds[key] = requestId;

    try {
        const response = await fetchJson(`/api/operator/incidents/${nextIncidentId}/citizen-location`, {
            method: 'post',
            data: payload,
        });

        if (appState.runtime.operatorCallerLocationPersistIds?.[key] !== requestId) {
            return null;
        }

        return response?.incident ?? null;
    } catch (error) {
        console.warn('Unable to persist caller location.', error);
        return null;
    }
}

function operatorCallerLocationSignature(incidentId, location = {}) {
    const normalized = normalizeCallerLocationPayload(location);

    if (!incidentId || !normalized) {
        return '';
    }

    return [
        Number(incidentId),
        normalized.latitude,
        normalized.longitude,
        normalized.accuracy ?? '',
        normalized.altitude ?? '',
        normalized.heading ?? '',
        normalized.captured_at ?? '',
    ].join('|');
}

function shouldApplyOperatorCallerLocation(incidentId, location = {}) {
    const signature = operatorCallerLocationSignature(incidentId, location);

    if (!signature) {
        return false;
    }

    if (!appState.runtime.operatorCallerLocationSignatures) {
        appState.runtime.operatorCallerLocationSignatures = {};
    }

    const key = String(Number(incidentId));

    if (appState.runtime.operatorCallerLocationSignatures[key] === signature) {
        return false;
    }

    appState.runtime.operatorCallerLocationSignatures[key] = signature;
    return true;
}

function operatorCanReceiveCallerLocationUpdate(payload = {}) {
    const incidentId = Number(payload?.incident_id ?? 0);
    const targetOperatorId = Number(payload?.operator_id ?? 0);
    const currentOperatorId = Number(appState.bootstrap?.user?.id ?? 0);

    if (!incidentId) {
        return false;
    }

    if (targetOperatorId > 0 && currentOperatorId > 0 && targetOperatorId !== currentOperatorId) {
        return false;
    }

    if (Number(operatorWorkbenchIncidentId() ?? 0) === incidentId) {
        return true;
    }

    return operatorActiveItems().some((item) => Number(item?.id ?? 0) === incidentId);
}

function applyOperatorCallerLocationUpdate(payload = {}) {
    const incidentId = Number(payload?.incident_id ?? payload?.id ?? 0);
    const location = normalizeCallerLocationPayload(payload);

    if (!incidentId || !location || !shouldApplyOperatorCallerLocation(incidentId, location)) {
        return;
    }

    const nextIncident = {
        id: incidentId,
        latitude: location.latitude,
        longitude: location.longitude,
        caller_location: location,
    };

    syncOperatorActiveIncident(currentOperatorRoot(), nextIncident);

    const workbench = appState.runtime.operatorWorkbench;
    const workbenchOverlay = appState.runtime.operatorWorkbenchOverlay;

    if (Number(workbench?.payload?.id ?? 0) === incidentId) {
        workbench.payload = {
            ...workbench.payload,
            ...nextIncident,
        };
        updateWorkbenchCallerLocationView(workbenchOverlay, workbench.payload);
    }

    void persistOperatorCallerLocation(incidentId, {
        ...payload,
        caller_location: location,
    }).then((incident) => {
        if (!incident) {
            return;
        }

        syncOperatorActiveIncident(currentOperatorRoot(), incident);
        publishOperatorCallerLocationPersisted(incident, location);

        const currentWorkbench = appState.runtime.operatorWorkbench;

        if (Number(currentWorkbench?.payload?.id ?? 0) !== incidentId) {
            return;
        }

        currentWorkbench.payload = {
            ...currentWorkbench.payload,
            latitude: incident.latitude ?? location.latitude,
            longitude: incident.longitude ?? location.longitude,
            caller_location: incident.caller_location ?? location,
        };
        updateWorkbenchCallerLocationView(appState.runtime.operatorWorkbenchOverlay, currentWorkbench.payload);
    });
}

function operatorDiscoveryPresenceRuntime() {
    if (!appState.runtime.operatorDiscoveryPresence) {
        appState.runtime.operatorDiscoveryPresence = {
            joined: false,
            lastState: '',
            lastStatusText: '',
            lastMetaKey: '',
            heartbeatTimeoutId: null,
        };
    }

    return appState.runtime.operatorDiscoveryPresence;
}

function operatorTransferPresenceRuntime() {
    if (!appState.runtime.operatorTransferPresence) {
        appState.runtime.operatorTransferPresence = {
            roster: {},
        };
    }

    return appState.runtime.operatorTransferPresence;
}

function resetOperatorTransferPresenceRoster() {
    const runtime = appState.runtime.operatorTransferPresence;

    if (runtime) {
        runtime.roster = {};
    }
}

function normalizeOperatorPresenceUserId(value) {
    const id = Number(value ?? 0);

    return Number.isFinite(id) && id > 0 ? id : 0;
}

function operatorPresenceEntryIsAvailable(entry) {
    const userId = normalizeOperatorPresenceUserId(entry?.userId);
    const currentUserId = normalizeOperatorPresenceUserId(appState.bootstrap?.user?.id);
    const meta = entry?.meta && typeof entry.meta === 'object' ? entry.meta : {};
    const hasOperatorPresenceMeta = Object.prototype.hasOwnProperty.call(meta, 'workbench_active')
        || Object.prototype.hasOwnProperty.call(meta, 'incident_id');

    if (!userId || userId === currentUserId || !hasOperatorPresenceMeta) {
        return false;
    }

    const state = String(entry?.state ?? '').trim().toLowerCase();
    const statusText = String(entry?.statusText ?? '').trim().toLowerCase();
    const workbenchActive = meta.workbench_active === true || String(meta.workbench_active ?? '').toLowerCase() === 'true';
    const incidentId = Number(meta.incident_id ?? 0);

    if (state !== 'online' || statusText !== 'available' || workbenchActive || incidentId > 0) {
        return false;
    }

    const expiresAt = Date.parse(String(entry?.expiresAt ?? ''));

    if (Number.isFinite(expiresAt) && expiresAt > 0) {
        return expiresAt > Date.now();
    }

    const updatedAt = Date.parse(String(entry?.updatedAt ?? ''));

    return !Number.isFinite(updatedAt) || Date.now() - updatedAt <= OPERATOR_DISCOVERY_PRESENCE_HEARTBEAT_MS * 2.5;
}

function operatorAvailableTransferTargets() {
    const dashboardTargets = Array.isArray(appState.operatorDashboard?.available_transfer_targets)
        ? appState.operatorDashboard.available_transfer_targets
        : [];
    const targetIndex = new Map(dashboardTargets
        .map((target) => [normalizeOperatorPresenceUserId(target?.id), target])
        .filter(([id]) => id > 0));

    return listPresenceRosterItems(operatorTransferPresenceRuntime().roster)
        .filter((entry) => {
            const userId = normalizeOperatorPresenceUserId(entry?.userId);

            return targetIndex.has(userId) && operatorPresenceEntryIsAvailable(entry);
        })
        .map((entry) => {
            const userId = normalizeOperatorPresenceUserId(entry.userId);
            const target = targetIndex.get(userId) ?? {};

            return {
                ...target,
                id: userId,
                name: String(target.name ?? entry.displayName ?? `Operator #${userId}`).trim(),
                avatar: target.avatar ?? '',
                status_text: String(entry.statusText ?? 'available').trim(),
                presence_updated_at: String(entry.updatedAt ?? '').trim(),
            };
        })
        .sort((a, b) => String(a.name ?? '').localeCompare(String(b.name ?? '')));
}

function syncOperatorTransferPresenceRoster(envelope) {
    if (envelope?.phase !== 'event' || envelope?.type !== 'presence.state.event') {
        return;
    }

    const runtime = operatorTransferPresenceRuntime();
    runtime.roster = reducePresenceRosterEvent(runtime.roster, envelope.payload);
    refreshOutboundTransferModalTargets();
}

function scheduleOperatorDiscoveryPresenceHeartbeat() {
    const runtime = operatorDiscoveryPresenceRuntime();

    if (runtime.heartbeatTimeoutId) {
        window.clearTimeout(runtime.heartbeatTimeoutId);
    }

    runtime.heartbeatTimeoutId = window.setTimeout(() => {
        runtime.heartbeatTimeoutId = null;
        publishOperatorDiscoveryPresence(true);
    }, OPERATOR_DISCOVERY_PRESENCE_HEARTBEAT_MS);
}

function resetOperatorDiscoveryPresence() {
    const runtime = appState.runtime.operatorDiscoveryPresence;

    if (!runtime) {
        return;
    }

    if (runtime.heartbeatTimeoutId) {
        window.clearTimeout(runtime.heartbeatTimeoutId);
        runtime.heartbeatTimeoutId = null;
    }

    runtime.joined = false;
    runtime.lastState = '';
    runtime.lastStatusText = '';
    runtime.lastMetaKey = '';
}

function operatorWorkbenchIncidentId() {
    const retained = Number(sessionStorage.getItem(OPERATOR_WORKBENCH_KEY) ?? 0);
    return retained > 0 ? retained : 0;
}

function operatorWorkbenchHasActiveCall() {
    return Number(sessionStorage.getItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY) ?? 0) > 0;
}

function clearOperatorWorkbenchCallStatusPoll() {
    if (appState.runtime.operatorWorkbenchCallStatusPollTimerId) {
        window.clearTimeout(appState.runtime.operatorWorkbenchCallStatusPollTimerId);
        appState.runtime.operatorWorkbenchCallStatusPollTimerId = null;
    }
}

function operatorOwnsReconnectIncident(incidentId) {
    const nextIncidentId = Number(incidentId ?? 0);

    if (nextIncidentId <= 0) {
        return false;
    }

    if (operatorWorkbenchIncidentId() === nextIncidentId) {
        return true;
    }

    return Array.isArray(appState.operatorDashboard?.active_items)
        && appState.operatorDashboard.active_items.some((item) => Number(item?.id ?? 0) === nextIncidentId);
}

function operatorActiveItems() {
    return Array.isArray(appState.operatorDashboard?.active_items)
        ? appState.operatorDashboard.active_items
        : [];
}

function operatorArchivedItems() {
    return Array.isArray(appState.operatorDashboard?.archived_items)
        ? appState.operatorDashboard.archived_items
        : [];
}

function currentOperatorRoot() {
    return appState.runtime.operatorWorkbenchRoot ?? document.getElementById('app');
}

function cloneOperatorDashboardValue(value) {
    if (value == null) {
        return value;
    }

    if (typeof structuredClone === 'function') {
        return structuredClone(value);
    }

    return JSON.parse(JSON.stringify(value));
}

function isOperatorActiveIncidentStatus(status) {
    const normalized = String(status ?? '').trim().toLowerCase();

    return normalized === 'active' || normalized === 'deferred';
}

function isOperatorArchivedIncidentStatus(status) {
    const normalized = String(status ?? '').trim().toLowerCase();

    return normalized === 'resolved' || normalized === 'discarded';
}

function refreshOperatorActiveRail(root) {
    const nextRoot = root ?? currentOperatorRoot();
    const panelHost = nextRoot?.querySelector?.('[data-active-items-panel]');
    const panel = panelHost?.closest?.('.ui-tabpanel') ?? panelHost?.parentElement;

    if (panel) {
        mountOperatorActiveList(nextRoot, appState.operatorDashboard ?? {}, panel);
    }
}

function refreshOperatorArchiveRail(root) {
    const nextRoot = root ?? currentOperatorRoot();
    const panelHost = nextRoot?.querySelector?.('[data-archive-items-panel]');
    const panel = panelHost?.closest?.('.ui-tabpanel') ?? panelHost?.parentElement;

    if (panel) {
        mountOperatorArchiveList(panel, nextRoot);
    }
}

function syncOperatorActiveIncident(root, incident) {
    const incidentId = Number(incident?.id ?? 0);

    if (!incidentId || !appState.operatorDashboard) {
        return;
    }

    const currentItems = operatorActiveItems();
    const existing = currentItems.find((item) => Number(item?.id ?? 0) === incidentId) ?? null;
    const shouldKeep = isOperatorActiveIncidentStatus(incident?.status ?? existing?.status);
    const nextIncident = {
        ...(existing ?? {}),
        ...cloneOperatorDashboardValue(incident),
        display_id: String(incident?.display_id ?? existing?.display_id ?? padIncidentId(incidentId)),
    };

    appState.operatorDashboard.active_items = shouldKeep
        ? [
            nextIncident,
            ...currentItems.filter((item) => Number(item?.id ?? 0) !== incidentId),
        ]
        : currentItems.filter((item) => Number(item?.id ?? 0) !== incidentId);

    const nextRoot = root ?? currentOperatorRoot();
    renderOperatorStageItems(nextRoot, appState.operatorDashboard.active_items);
    mountOperatorAssignmentBoard(nextRoot, appState.operatorDashboard);
}

function syncOperatorArchivedIncident(incident) {
    const incidentId = Number(incident?.id ?? 0);

    if (!incidentId || !Array.isArray(appState.operatorDashboard?.archived_items)) {
        return;
    }

    const archivedItems = operatorArchivedItems();
    const existing = archivedItems.find((item) => Number(item?.id ?? 0) === incidentId) ?? null;
    const shouldKeep = isOperatorArchivedIncidentStatus(incident?.status ?? existing?.status);
    const nextIncident = {
        ...(existing ?? {}),
        ...cloneOperatorDashboardValue(incident),
        display_id: String(incident?.display_id ?? existing?.display_id ?? padIncidentId(incidentId)),
    };

    appState.operatorDashboard.archived_items = shouldKeep
        ? [
            nextIncident,
            ...archivedItems.filter((item) => Number(item?.id ?? 0) !== incidentId),
        ]
        : archivedItems.filter((item) => Number(item?.id ?? 0) !== incidentId);
}

function syncOperatorIncidentRails(root, incident) {
    syncOperatorActiveIncident(root, incident);
    syncOperatorArchivedIncident(incident);
    refreshOperatorActiveRail(root);
    refreshOperatorArchiveRail(root);
}

function syncOperatorActiveIncidentAssignments(root, incidentId, teamAssignments) {
    const nextIncidentId = Number(incidentId ?? 0);

    if (!nextIncidentId || !appState.operatorDashboard) {
        return;
    }

    appState.operatorDashboard.active_items = operatorActiveItems().map((item) => (
        Number(item?.id ?? 0) === nextIncidentId
            ? {
                ...item,
                team_assignments: Array.isArray(teamAssignments)
                    ? cloneOperatorDashboardValue(teamAssignments)
                    : [],
            }
            : item
    ));

    mountOperatorAssignmentBoard(root ?? currentOperatorRoot(), appState.operatorDashboard);
}

function operatorReconnectAvailability(incidentId) {
    const nextIncidentId = Number(incidentId ?? 0);

    if (nextIncidentId <= 0 || !operatorOwnsReconnectIncident(nextIncidentId)) {
        return false;
    }

    if (operatorWorkbenchHasActiveCall()) {
        return false;
    }

    const workbenchIncidentId = operatorWorkbenchIncidentId();

    if (workbenchIncidentId > 0 && workbenchIncidentId !== nextIncidentId) {
        return false;
    }

    return true;
}

function upsertIncomingCallInDashboard(item) {
    if (!item || !appState.operatorDashboard) {
        return;
    }

    const incoming = Array.isArray(appState.operatorDashboard.incoming_calls)
        ? appState.operatorDashboard.incoming_calls
        : [];
    const targetKind = String(item.kind ?? '').trim();
    const targetId = String(item.id ?? '').trim();
    const nextItems = incoming.filter((entry) => (
        `${String(entry?.kind ?? '').trim()}:${String(entry?.id ?? '').trim()}` !== `${targetKind}:${targetId}`
    ));

    nextItems.push(item);
    nextItems.sort((left, right) => String(left?.created_at ?? '').localeCompare(String(right?.created_at ?? '')));
    appState.operatorDashboard.incoming_calls = nextItems;
}

function findPendingReconnectCall(callerId, incidentId) {
    const nextCallerId = Number(callerId ?? 0);
    const nextIncidentId = Number(incidentId ?? 0);
    const activeIncoming = appState.runtime.operatorIncomingCallItem;

    if (
        activeIncoming
        && String(activeIncoming.kind ?? '') === 'reconnect'
        && Number(activeIncoming.caller_id ?? 0) === nextCallerId
        && Number(activeIncoming.incident_id ?? 0) === nextIncidentId
    ) {
        return activeIncoming;
    }

    const incoming = Array.isArray(appState.operatorDashboard?.incoming_calls)
        ? appState.operatorDashboard.incoming_calls
        : [];

    return incoming.find((item) => (
        String(item?.kind ?? '') === 'reconnect'
        && Number(item?.caller_id ?? 0) === nextCallerId
        && Number(item?.incident_id ?? 0) === nextIncidentId
    )) ?? null;
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

function currentAudioGraphStyle() {
    return String(appState.bootstrap?.settings?.audio_graph_style ?? 'vu').trim() || 'vu';
}

function operatorAlertToastContent(alertLevel) {
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

function speakOperatorPhrase(message) {
    if (
        typeof window === 'undefined'
        || !message
        || !('speechSynthesis' in window)
        || typeof window.SpeechSynthesisUtterance !== 'function'
    ) {
        return;
    }

    try {
        const utterance = new window.SpeechSynthesisUtterance(String(message));
        const preferredVoice = preferredFemaleToastVoiceName();

        if (preferredVoice && typeof window.speechSynthesis.getVoices === 'function') {
            const voice = window.speechSynthesis.getVoices().find((item) => String(item?.name ?? '') === preferredVoice);

            if (voice) {
                utterance.voice = voice;
            }
        }

        utterance.rate = 1;
        utterance.pitch = 1;
        utterance.volume = 1;
        window.speechSynthesis.cancel();
        window.speechSynthesis.speak(utterance);
    } catch {
        // Ignore speech failures; modal remains usable without spoken assist.
    }
}

function stopOperatorIncomingRingtone() {
    const audio = appState.runtime.operatorIncomingRingtone;

    if (!(audio instanceof Audio)) {
        appState.runtime.operatorIncomingRingtone = null;
        return;
    }

    try {
        audio.pause();
        audio.currentTime = 0;
    } catch {
        // Ignore audio teardown failures.
    }

    appState.runtime.operatorIncomingRingtone = null;
}

function playOperatorIncomingRingtone() {
    stopOperatorIncomingRingtone();

    if (typeof window === 'undefined' || typeof Audio !== 'function') {
        return null;
    }

    try {
        const audio = new Audio('/storage/audio/ringtone.mp3');
        audio.loop = true;
        audio.preload = 'auto';
        audio.volume = 1;
        appState.runtime.operatorIncomingRingtone = audio;

        const playback = audio.play();

        if (playback && typeof playback.catch === 'function') {
            playback.catch(() => {
                if (appState.runtime.operatorIncomingRingtone === audio) {
                    appState.runtime.operatorIncomingRingtone = null;
                }
            });
        }

        return audio;
    } catch {
        return null;
    }
}

function debugMediaCapture(event, detail = {}) {
    if (!HOTLINE_MEDIA_DEBUG || typeof console === 'undefined') {
        return;
    }

    const timestamp = new Date().toISOString();
    const sourceLabel = String(detail?.debugSource ?? '').trim();
    const prefix = sourceLabel
        ? `[hotline.media.debug][${sourceLabel}]`
        : '[hotline.media.debug]';
    const payload = {
        timestamp,
        event,
        ...detail,
    };

    if ('debugSource' in payload) {
        delete payload.debugSource;
    }

    console.info(prefix, payload);
}

function operatorAlertToneClass(alertLevel) {
    const normalized = String(alertLevel ?? '').trim().toLowerCase();

    if (normalized === 'elevated') {
        return 'is-alert-elevated';
    }

    if (normalized === 'critical') {
        return 'is-alert-critical';
    }

    return '';
}

function setOperatorAlertLevel(root, alertLevel) {
    const label = root?.querySelector('[data-operator-alert-level]');
    const command = root?.querySelector('[data-operator-alert-clock]');
    const shell = root?.querySelector('.operator-shell-compact');
    const toneClass = operatorAlertToneClass(alertLevel);

    if (label) {
        label.textContent = `Alert: ${String(alertLevel ?? 'Normal').toUpperCase()}`;
    }

    if (command) {
        command.classList.remove('is-alert-elevated', 'is-alert-critical');

        if (toneClass) {
            command.classList.add(toneClass);
        }
    }

    if (shell) {
        shell.classList.remove('is-alert-elevated', 'is-alert-critical');

        if (toneClass) {
            shell.classList.add(toneClass);
        }
    }
}

async function connectOperatorRealtimeStream(root, options = {}) {
    if (!appState.bootstrap?.authenticated || appState.activeSurface !== 'operator') {
        return;
    }

    if (appState.runtime.operatorRealtimeStream?.client) {
        setOperatorAlertLevel(root, appState.bootstrap?.alert_level);
        return;
    }

    const reconnectRuntime = operatorRealtimeReconnectRuntime();

    if (reconnectRuntime.connecting) {
        return;
    }

    reconnectRuntime.connecting = true;
    appState.runtime.operatorRealtimeSignal?.setReconnectRuntime?.(reconnectRuntime);

    try {
        const admission = await fetchJson('/api/realtime/admission/operator', {
            method: 'post',
            data: {
                context_type: 'surface_runtime',
                context_id: 0,
            },
        });

        const rooms = Array.isArray(admission?.rooms) ? admission.rooms.filter(Boolean) : [];

        if (!admission?.token || !admission?.websocket_url || rooms.length === 0) {
            reconnectRuntime.connecting = false;
            scheduleOperatorRealtimeReconnect(root);
            return;
        }

        const joinedRooms = new Set();
        let streamRef = null;

        const client = new RealtimeSocketClient({
            websocketUrl: admission.websocket_url,
            token: admission.token,
            requestPrefix: 'operator_surface',
            onOpen() {
                reconnectRuntime.connecting = false;
                reconnectRuntime.attempts = 0;
                clearOperatorRealtimeReconnectTimer();
                setOperatorAlertLevel(root, appState.bootstrap?.alert_level);
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

                if (appState.runtime.operatorRealtimeStream?.client === client) {
                    appState.runtime.operatorRealtimeStream.client = null;
                }

                resetOperatorRealtimeJoinState();
                scheduleOperatorRealtimeReconnect(root);
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
                    operatorMediaRoomsRuntime().requested.forEach((room) => {
                        client.sendRequest('room.join.request', room, buildRoomJoinPayload());
                    });
                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'room.join.request') {
                    const joinedRoom = String(envelope?.room ?? '');
                    joinedRooms.add(joinedRoom);

                    if (joinedRoom.startsWith(INCIDENT_MEDIA_ROOM_PREFIX)) {
                        operatorMediaRoomsRuntime().joined.add(joinedRoom);
                    }

                    if (joinedRoom === CALL_DISCOVERY_ROOM) {
                        const presenceRuntime = operatorDiscoveryPresenceRuntime();
                        presenceRuntime.joined = true;
                        client.sendRequest('presence.subscribe', CALL_DISCOVERY_ROOM, buildPresenceSubscribePayload(CALL_DISCOVERY_ROOM));
                        publishOperatorDiscoveryPresence(true);
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

                    setOperatorAlertLevel(root, nextAlertLevel);
                    const toastContent = operatorAlertToastContent(nextAlertLevel);

                    showToast(toastContent.message, toastContent.tone, {
                        title: toastContent.title,
                        speak: true,
                        voiceName: preferredFemaleToastVoiceName(),
                    });
                    return;
                }
                logLegacyCallerRealtimeEventUsage(envelope);

                const eventType = legacyCallerEventType(envelope?.type);
                const eventRoom = String(envelope?.room ?? '').trim();
                const payload = withCitizenRealtimePayloadAliases(envelope?.payload);

                if (eventRoom === CALL_DISCOVERY_ROOM && eventType === 'presence.state.event') {
                    syncOperatorTransferPresenceRoster(envelope);
                    return;
                }

                if (
                    eventRoom.startsWith(INCIDENT_MEDIA_ROOM_PREFIX)
                    && ['media.processing', 'media.available'].includes(eventType)
                ) {
                    const currentIncidentId = Number(operatorWorkbenchIncidentId() ?? 0);
                    const nextIncidentId = Number(payload?.incident_id ?? 0);
                    const nextMedia = payload?.media && typeof payload.media === 'object'
                        ? payload.media
                        : null;

                    if (
                        nextMedia
                        && nextIncidentId > 0
                        && currentIncidentId > 0
                        && nextIncidentId === currentIncidentId
                    ) {
                        const workbench = appState.runtime.operatorWorkbench;

                        if (workbench?.applyMediaEvent) {
                            workbench.applyMediaEvent(nextMedia);
                        } else if (workbench?.payload) {
                            workbench.payload.media = mergeIncidentMediaItems(workbench.payload.media, nextMedia);
                            workbench.syncMediaViews?.();
                        }
                    }

                    return;
                }

                if (!eventType || !joinedRooms.has(CALL_DISCOVERY_ROOM)) {
                    return;
                }

                if (eventType === 'caller.location.updated') {
                    if (operatorCanReceiveCallerLocationUpdate(payload)) {
                        applyOperatorCallerLocationUpdate(payload);
                    }
                    return;
                }

                if (eventType === 'caller.operator.available.request') {
                    if (!operatorCanAnswerDiscoveryRequest() || operatorIsExcludedFromCallerDiscovery(payload)) {
                        return;
                    }

                    publishOperatorCallFlow('caller.operator.available.response', {
                        caller_id: Number(payload.caller_id),
                        operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                        operator_name: String(appState.bootstrap?.user?.name ?? 'Operator'),
                        operator_avatar: String(appState.bootstrap?.user?.avatar ?? ''),
                        responded_at: new Date().toISOString(),
                    });
                    return;
                }

                if (eventType === 'caller.operator.availability.probe') {
                    return;
                }

                if (eventType === 'caller.reconnect.availability.request') {
                    const incidentId = Number(payload.incident_id ?? 0);

                    if (!operatorOwnsReconnectIncident(incidentId)) {
                        return;
                    }

                    publishOperatorCallFlow('caller.reconnect.availability.response', {
                        caller_id: Number(payload.caller_id ?? 0),
                        incident_id: incidentId,
                        operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                        operator_name: String(appState.bootstrap?.user?.name ?? 'Operator'),
                        operator_avatar: String(appState.bootstrap?.user?.avatar ?? ''),
                        available: operatorReconnectAvailability(incidentId),
                        responded_at: new Date().toISOString(),
                    });
                    return;
                }

                if (eventType === 'caller.call.request') {
                    if (String(payload?.operator_id ?? '') !== String(appState.bootstrap?.user?.id ?? '')) {
                        return;
                    }

                    if (!operatorCanAnswerDiscoveryRequest()) {
                        return;
                    }

                    const pendingIncomingItem = {
                        id: `pending-new-call-${Number(payload.caller_id ?? 0)}-${Date.now()}`,
                        kind: 'new_call',
                        pending_call_attempt: true,
                        call_attempt_id: null,
                        caller_id: Number(payload.caller_id),
                        caller_name: String(payload.caller_name ?? `Caller #${payload.caller_id}`),
                        caller_avatar: String(payload.caller_avatar ?? ''),
                        display_id: '',
                        call_session_id: null,
                    };

                    void openIncomingCallModal(root, pendingIncomingItem, 'preparing');

                    fetchJson('/api/operator/call-attempts', {
                        method: 'post',
                        data: {
                            caller_id: Number(payload.caller_id),
                            ...callerLocationAttemptPayload(payload),
                        },
                    }).then((response) => {
                        if (
                            String(appState.runtime.operatorIncomingCallItem?.id ?? '') !== pendingIncomingItem.id
                            || appState.runtime.operatorIncomingCallPhase !== 'preparing'
                        ) {
                            if (response.operator_attempt?.id) {
                                void fetchJson(`/api/operator/call-attempt-operator-attempts/${response.operator_attempt.id}/decline`, {
                                    method: 'post',
                                }).catch((error) => {
                                    console.warn('Stale operator call-attempt cleanup failed.', error);
                                });
                            }
                            return;
                        }

                        appState.runtime.operatorDiscoveryClaimed = true;
                        publishOperatorDiscoveryPresence();
                        publishOperatorCallFlow('caller.call.ringing', {
                            call_attempt_id: Number(response.attempt?.id),
                            call_attempt_operator_attempt_id: Number(response.operator_attempt?.id),
                            caller_id: Number(payload.caller_id),
                            operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                            requested_at: response.attempt?.created_at ?? new Date().toISOString(),
                        });

                        const incomingItem = {
                            id: Number(response.operator_attempt?.id),
                            kind: 'new_call',
                            call_attempt_id: Number(response.attempt?.id),
                            caller_id: Number(payload.caller_id),
                            caller_name: String(payload.caller_name ?? `Caller #${payload.caller_id}`),
                            caller_avatar: String(payload.caller_avatar ?? ''),
                            display_id: '',
                            call_session_id: null,
                        };

                        void openIncomingCallModal(root, incomingItem, 'incoming');
                    }).catch((error) => {
                        if (String(appState.runtime.operatorIncomingCallItem?.id ?? '') === pendingIncomingItem.id) {
                            root.querySelector('[data-incoming-call-overlay]')?.remove();
                            stopOperatorIncomingRingtone();
                            appState.runtime.operatorIncomingCallItem = null;
                            appState.runtime.operatorIncomingCallPhase = null;
                            publishOperatorDiscoveryPresence();
                        }

                        console.warn('Operator call-attempt creation failed.', error);
                    });
                    return;
                }

                if (eventType === 'caller.reconnect.request') {
                    const callerId = Number(payload.caller_id ?? 0);
                    const incidentId = Number(payload.incident_id ?? 0);

                    if (
                        String(payload?.operator_id ?? '') !== String(appState.bootstrap?.user?.id ?? '')
                        || !operatorOwnsReconnectIncident(incidentId)
                    ) {
                        return;
                    }

                    if (!operatorReconnectAvailability(incidentId)) {
                        publishOperatorCallFlow('caller.reconnect.declined', {
                            caller_id: Number(payload.caller_id ?? 0),
                            incident_id: incidentId,
                            operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                            message: 'Operator is currently not available. Please try again later.',
                            ended_at: new Date().toISOString(),
                        });
                        return;
                    }

                    const existingReconnect = findPendingReconnectCall(callerId, incidentId);

                    if (existingReconnect?.call_attempt_id) {
                        publishOperatorCallFlow('caller.reconnect.ringing', {
                            call_attempt_id: Number(existingReconnect.call_attempt_id ?? 0),
                            call_attempt_operator_attempt_id: Number(existingReconnect.id ?? 0),
                            caller_id: callerId,
                            incident_id: incidentId,
                            operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                            operator_name: String(appState.bootstrap?.user?.name ?? 'Operator'),
                            operator_avatar: String(appState.bootstrap?.user?.avatar ?? ''),
                            requested_at: existingReconnect.created_at ?? new Date().toISOString(),
                        });
                        return;
                    }

                    const pendingIncomingItem = {
                        id: `pending-reconnect-${callerId}-${incidentId}-${Date.now()}`,
                        kind: 'reconnect',
                        pending_call_attempt: true,
                        call_attempt_id: null,
                        call_session_id: null,
                        incident_id: incidentId,
                        display_id: String(payload.display_id ?? '').trim() || padIncidentId(incidentId),
                        caller_id: callerId,
                        caller_name: String(payload.caller_name ?? `Caller #${payload.caller_id}`),
                        caller_avatar: String(payload.caller_avatar ?? ''),
                        created_at: new Date().toISOString(),
                    };

                    void openIncomingCallModal(root, pendingIncomingItem, 'preparing');

                    fetchJson('/api/operator/call-attempts', {
                        method: 'post',
                        data: {
                            caller_id: callerId,
                            incident_id: incidentId,
                        },
                    }).then((response) => {
                        const incomingItem = {
                            id: Number(response.operator_attempt?.id ?? 0),
                            kind: 'reconnect',
                            call_attempt_id: Number(response.attempt?.id ?? 0),
                            call_session_id: null,
                            incident_id: incidentId,
                            display_id: String(payload.display_id ?? '').trim() || padIncidentId(incidentId),
                            caller_id: callerId,
                            caller_name: String(payload.caller_name ?? `Caller #${payload.caller_id}`),
                            caller_avatar: String(payload.caller_avatar ?? ''),
                            created_at: response.operator_attempt?.created_at ?? response.attempt?.created_at ?? new Date().toISOString(),
                        };

                        upsertIncomingCallInDashboard(incomingItem);
                        publishOperatorCallFlow('caller.reconnect.ringing', {
                            call_attempt_id: Number(response.attempt?.id ?? 0),
                            call_attempt_operator_attempt_id: Number(response.operator_attempt?.id ?? 0),
                            caller_id: callerId,
                            incident_id: incidentId,
                            operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                            operator_name: String(appState.bootstrap?.user?.name ?? 'Operator'),
                            operator_avatar: String(appState.bootstrap?.user?.avatar ?? ''),
                            requested_at: response.attempt?.created_at ?? new Date().toISOString(),
                        });

                        void openIncomingCallModal(root, incomingItem, 'reconnect');
                    }).catch((error) => {
                        if (String(appState.runtime.operatorIncomingCallItem?.id ?? '') === pendingIncomingItem.id) {
                            root.querySelector('[data-incoming-call-overlay]')?.remove();
                            stopOperatorIncomingRingtone();
                            appState.runtime.operatorIncomingCallItem = null;
                            appState.runtime.operatorIncomingCallPhase = null;
                            publishOperatorDiscoveryPresence();
                        }

                        publishOperatorCallFlow('caller.reconnect.declined', {
                            caller_id: Number(payload.caller_id ?? 0),
                            incident_id: incidentId,
                            operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                            message: 'Operator is currently not available. Please try again later.',
                            ended_at: new Date().toISOString(),
                        });
                        console.warn('Operator reconnect attempt creation failed.', error);
                    });
                    return;
                }

                if (eventType === 'caller.call.cancel') {
                    if (String(payload?.operator_id ?? '') !== String(appState.bootstrap?.user?.id ?? '')) {
                        return;
                    }

                    const activeIncoming = appState.runtime.operatorIncomingCallItem;
                    const timedOutOperatorAttemptId = Number(payload.call_attempt_operator_attempt_id ?? 0);
                    const timedOutCallerId = Number(payload.caller_id ?? 0);

                    if (
                        !activeIncoming
                        || activeIncoming.kind !== 'new_call'
                        || (
                            timedOutOperatorAttemptId > 0
                                ? String(activeIncoming.id ?? '') !== String(timedOutOperatorAttemptId)
                                : Number(activeIncoming.caller_id ?? 0) !== timedOutCallerId
                        )
                    ) {
                        return;
                    }

                    fetchJson(`/api/operator/call-attempt-operator-attempts/${payload.call_attempt_operator_attempt_id}/citizen-cancel`, {
                        method: 'post',
                    }).then(() => {
                        appState.runtime.operatorDiscoveryClaimed = false;
                        root.querySelector('[data-incoming-call-overlay]')?.remove();
                        stopOperatorIncomingRingtone();
                        appState.runtime.operatorIncomingCallItem = null;
                        publishOperatorDiscoveryPresence();
                        publishOperatorCallFlow('caller.call.cancelled', {
                            call_attempt_id: Number(payload.call_attempt_id ?? 0),
                            call_attempt_operator_attempt_id: Number(payload.call_attempt_operator_attempt_id ?? 0),
                            caller_id: Number(payload.caller_id ?? 0),
                            operator_id: Number(payload.operator_id ?? 0),
                            outcome: 'cancelled_by_citizen',
                            ended_at: new Date().toISOString(),
                        });
                    }).catch((error) => {
                        console.warn('Operator citizen-cancel update failed.', error);
                    });
                    return;
                }

                if (eventType === 'caller.call.timed_out') {
                    if (String(payload?.operator_id ?? '') !== String(appState.bootstrap?.user?.id ?? '')) {
                        return;
                    }

                    const activeIncoming = appState.runtime.operatorIncomingCallItem;

                    if (
                        !activeIncoming
                        || activeIncoming.kind !== 'new_call'
                        || String(activeIncoming.id ?? '') !== String(payload.call_attempt_operator_attempt_id ?? '')
                    ) {
                        return;
                    }

                    appState.runtime.operatorDiscoveryClaimed = false;
                    removeIncomingCallFromDashboard(activeIncoming);
                    closeIncomingCallModal(root, activeIncoming);
                    publishOperatorDiscoveryPresence();
                    return;
                }

                if (eventType === 'caller.reconnect.cancel') {
                    if (String(payload?.operator_id ?? '') !== String(appState.bootstrap?.user?.id ?? '')) {
                        return;
                    }

                    const activeIncoming = appState.runtime.operatorIncomingCallItem;

                    if (
                        !activeIncoming
                        || activeIncoming.kind !== 'reconnect'
                        || String(activeIncoming.id ?? '') !== String(payload.call_attempt_operator_attempt_id ?? '')
                    ) {
                        return;
                    }

                    fetchJson(`/api/operator/call-attempt-operator-attempts/${payload.call_attempt_operator_attempt_id}/citizen-cancel`, {
                        method: 'post',
                    }).then(() => {
                        root.querySelector('[data-incoming-call-overlay]')?.remove();
                        stopOperatorIncomingRingtone();
                        appState.runtime.operatorIncomingCallItem = null;
                        appState.runtime.operatorIncomingCallPhase = null;
                        removeIncomingCallFromDashboard(activeIncoming);
                        publishOperatorDiscoveryPresence();
                        publishOperatorCallFlow('caller.reconnect.cancelled', {
                            call_attempt_id: Number(payload.call_attempt_id ?? 0),
                            call_attempt_operator_attempt_id: Number(payload.call_attempt_operator_attempt_id ?? 0),
                            caller_id: Number(payload.caller_id ?? 0),
                            incident_id: Number(payload.incident_id ?? 0),
                            operator_id: Number(payload.operator_id ?? 0),
                            outcome: 'cancelled_by_citizen',
                            ended_at: new Date().toISOString(),
                        });
                    }).catch((error) => {
                        console.warn('Operator reconnect citizen-cancel update failed.', error);
                    });
                }
            },
        });

        streamRef = {
            client,
            destroyed: false,
            destroy() {
                this.destroyed = true;
                clearOperatorRealtimeReconnectTimer();
                resetOperatorRealtimeJoinState();
                appState.runtime.operatorMediaRooms = null;
                client.close();
                if (appState.runtime.operatorRealtimeStream === this || appState.runtime.operatorRealtimeStream?.client === client) {
                    appState.runtime.operatorRealtimeStream = null;
                }
            },
        };
        appState.runtime.operatorRealtimeStream = streamRef;
        appState.runtime.operatorRealtimeSignal?.setReconnectRuntime?.(reconnectRuntime);
        appState.runtime.operatorRealtimeSignal?.bindClient?.(client);
        client.connect();
    } catch (error) {
        reconnectRuntime.connecting = false;
        appState.runtime.operatorRealtimeSignal?.setReconnectRuntime?.(reconnectRuntime);
        if (appState.runtime.operatorRealtimeStream?.client && !appState.runtime.operatorRealtimeStream.client.isOpen?.()) {
            appState.runtime.operatorRealtimeStream.client = null;
        }
        console.warn('Operator Realtime surface stream unavailable.', error);
        scheduleOperatorRealtimeReconnect(root);
    }
}

function operatorCanAnswerDiscoveryRequest() {
    return !appState.runtime.operatorDiscoveryClaimed
        && !appState.runtime.operatorIncomingCallItem
        && Number(operatorWorkbenchIncidentId() ?? 0) <= 0;
}

function publishOperatorDiscoveryPresence(force = false) {
    const client = operatorDiscoveryClient();
    const runtime = operatorDiscoveryPresenceRuntime();

    if (!client?.isOpen?.() || !runtime.joined) {
        return null;
    }

    const available = operatorCanAnswerDiscoveryRequest();
    const nextState = available ? 'online' : 'busy';
    const nextStatusText = available ? 'available' : 'busy';
    const activeIncidentId = Number(operatorWorkbenchIncidentId() ?? 0);
    const nextMeta = {
        operator_id: Number(appState.bootstrap?.user?.id ?? 0) || null,
        operator_name: String(appState.bootstrap?.user?.name ?? 'Operator'),
        operator_avatar: String(appState.bootstrap?.user?.avatar ?? ''),
        workbench_active: activeIncidentId > 0,
        incident_id: activeIncidentId > 0 ? activeIncidentId : null,
    };
    const nextMetaKey = JSON.stringify(nextMeta);

    if (
        !force
        && runtime.lastState === nextState
        && runtime.lastStatusText === nextStatusText
        && runtime.lastMetaKey === nextMetaKey
    ) {
        return null;
    }

    runtime.lastState = nextState;
    runtime.lastStatusText = nextStatusText;
    runtime.lastMetaKey = nextMetaKey;
    scheduleOperatorDiscoveryPresenceHeartbeat();

    return client.sendRequest(
        'presence.publish',
        CALL_DISCOVERY_ROOM,
        {
            ...buildPresencePublishPayload(CALL_DISCOVERY_ROOM, nextState, nextStatusText),
            meta: nextMeta,
        },
    );
}

function installOperatorSessionRestoredPresenceRefresh(root) {
    const handleSessionRestored = () => {
        if (
            appState.activeSurface !== 'operator'
            || !appState.bootstrap?.authenticated
            || appState.bootstrap?.user?.role !== 'operator'
        ) {
            return;
        }

        const client = operatorDiscoveryClient();
        const presenceRuntime = operatorDiscoveryPresenceRuntime();

        if (!client?.isOpen?.()) {
            void connectOperatorRealtimeStream(root ?? currentOperatorRoot(), { reconnect: true });
            return;
        }

        if (!presenceRuntime.joined) {
            return;
        }

        publishOperatorDiscoveryPresence(true);
    };

    window.addEventListener('hotline:session-restored', handleSessionRestored);

    trackSurfaceInstance({
        destroy() {
            window.removeEventListener('hotline:session-restored', handleSessionRestored);
        },
    });
}

function renderTransferRequestSection(dashboard) {
    return `
        <section class="panel-card">
            <h3>Transfer</h3>
            <div class="field-stack">
                <select name="transfer_target_id">
                    ${buildOptions(dashboard.available_transfer_targets, 'No available operators')}
                </select>
                <textarea name="transfer_reason" placeholder="Transfer reason"></textarea>
                <button class="surface-button secondary" type="button" data-request-transfer="1">Request transfer</button>
            </div>
        </section>
    `;
}

function operatorDashboardLookups() {
    const lookups = appState.bootstrap?.surface_payload ?? {};

    return {
        availableTeams: Array.isArray(lookups.teams)
            ? lookups.teams
                .filter((team) => String(team?.status ?? '').toLowerCase() === 'active')
                .map((team) => ({
                    id: team.id,
                    name: team.name,
                }))
            : [],
        resourceTypes: Array.isArray(lookups.resource_types) ? lookups.resource_types : [],
    };
}

function renderTeamComposerSection(dashboard) {
    const lookups = operatorDashboardLookups();

    return `
        <section class="panel-card">
            <h3>Assign Team</h3>
            <div class="field-stack">
                <select name="team_id">
                    ${buildOptions(lookups.availableTeams, 'No active teams')}
                </select>
                <input type="text" name="team_contact_person" placeholder="Contact person">
                <select name="resource_type_id">
                    ${buildOptions(lookups.resourceTypes, 'No resource types', (item) => `${item.name}${item.unit_label ? ` (${item.unit_label})` : ''}`)}
                </select>
                <input type="number" min="1" step="1" name="resource_quantity" value="1" placeholder="Quantity">
                <button class="surface-button secondary" type="button" data-create-assignment="1">Create team assignment</button>
            </div>
        </section>
    `;
}


function incomingCallModalMarkup(item, phase = 'incoming') {
    const callerName = escapeHtml(item.caller_name ?? 'Caller');
    const avatarUrl = String(item.caller_avatar ?? '').trim();
    const subtitle = phase === 'connecting'
        ? 'Connecting...'
        : phase === 'preparing'
            ? (item.kind === 'reconnect' ? 'Reconnection Request' : 'Incoming call')
        : phase === 'reconnect'
            ? 'Reconnection Request'
        : item.kind === 'reconnect'
            ? `Reconnect · Incident ${escapeHtml(item.display_id ?? '')}`
            : 'Incoming call';
    const isPreparing = phase === 'preparing';
    const hasActionButtons = !['connecting', 'preparing'].includes(phase);
    const answerIcon = `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M6.6 4.8c.3-.3.8-.4 1.2-.2l2.6 1.3c.5.2.7.8.5 1.3l-.8 2a1 1 0 0 0 .2 1.1l3.6 3.6a1 1 0 0 0 1.1.2l2-.8c.5-.2 1.1 0 1.3.5l1.3 2.6c.2.4.1.9-.2 1.2l-1.7 1.7c-.8.8-2 1.1-3.1.8-2.6-.7-5.1-2.2-7.2-4.3S4.6 11 3.9 8.4c-.3-1.1 0-2.3.8-3.1l1.9-1.7Z" fill="currentColor"></path>
        </svg>
    `;
    const dismissIcon = `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M7.8 6.4a1 1 0 0 1 1.4 0L12 9.2l2.8-2.8a1 1 0 1 1 1.4 1.4L13.4 10.6l2.8 2.8a1 1 0 0 1-1.4 1.4L12 12l-2.8 2.8a1 1 0 1 1-1.4-1.4l2.8-2.8-2.8-2.8a1 1 0 0 1 0-1.4Z" fill="currentColor"></path>
        </svg>
    `;

    return `
        <div class="overlay-backdrop" data-incoming-call-overlay>
            <section class="overlay-panel incoming operator-incoming-modal">
                <div class="ringing-visual" aria-hidden="true">
                    <span class="ring ring-a"></span>
                    <span class="ring ring-b"></span>
                    <span class="ring ring-c"></span>
                    <span class="operator-incoming-avatar-shell">
                        ${avatarUrl
                            ? `<img class="operator-incoming-avatar" src="${escapeHtml(avatarUrl)}" alt="${callerName}">`
                            : `<span class="operator-incoming-avatar-fallback">${escapeHtml((item.caller_name ?? 'C').slice(0, 1))}</span>`}
                    </span>
                </div>
                <h2 class="overlay-title">${callerName}</h2>
                <p class="hero-copy">${subtitle}</p>
                <div class="operator-incoming-actions${hasActionButtons ? '' : ' is-hidden'}">
                    ${isPreparing
                        ? '<span class="operator-incoming-status">&nbsp;</span>'
                    : hasActionButtons
                        ? `
                        <button class="operator-call-action answer" type="button" data-answer-incoming="1" aria-label="Answer call" title="Answer">
                            ${answerIcon}
                        </button>
                        <button class="operator-call-action dismiss" type="button" data-dismiss-incoming="1" aria-label="Dismiss call" title="Dismiss">
                            ${dismissIcon}
                        </button>
                        `
                        : `
                        <span class="operator-incoming-status">&nbsp;</span>
                        `}
                </div>
                <div class="notice" data-incoming-notice hidden></div>
            </section>
        </div>
    `;
}

function formatWorkbenchDuration(startAt, endAt) {
    const startMs = Date.parse(String(startAt ?? ''));
    const endMs = Date.parse(String(endAt ?? ''));

    if (!Number.isFinite(startMs) || !Number.isFinite(endMs) || endMs < startMs) {
        return '--:--:--:--';
    }

    let remainingSeconds = Math.floor((endMs - startMs) / 1000);
    const days = Math.floor(remainingSeconds / 86400);
    remainingSeconds -= days * 86400;
    const hours = Math.floor(remainingSeconds / 3600);
    remainingSeconds -= hours * 3600;
    const minutes = Math.floor(remainingSeconds / 60);
    const seconds = remainingSeconds - (minutes * 60);

    return [days, hours, minutes, seconds]
        .map((value) => String(value).padStart(2, '0'))
        .join(':');
}

function workbenchCallState(payload, stateOverride = null) {
    if (stateOverride === 'active' || stateOverride === 'inactive') {
        return stateOverride;
    }

    return deriveActiveCallSessionId(payload) ? 'active' : 'inactive';
}

function workbenchIncidentEditable(payload) {
    const status = String(payload?.status ?? '').trim().toLowerCase();

    return status === 'active' || status === 'deferred';
}

function mapTeamAssignmentStatusToApi(status) {
    const normalized = String(status ?? '').trim().toLowerCase().replace(/\s+/g, '_');

    return ({
        assigned: 'assigned',
        requested: 'requested',
        accepted: 'accepted',
        en_route: 'en_route',
        on_scene: 'on_scene',
        completed: 'completed',
        cancelled: 'cancelled',
    })[normalized] ?? 'assigned';
}

function patchIncidentCallSession(payload, callSessionId, patch = {}) {
    if (!payload || !callSessionId) {
        return payload;
    }

    const nextCallHistory = (Array.isArray(payload.call_history) ? payload.call_history : []).map((session) => (
        Number(session?.id ?? 0) === Number(callSessionId)
            ? {
                ...session,
                ...patch,
            }
            : session
    ));
    const nextCurrentCallSession = Number(payload?.current_call_session?.id ?? 0) === Number(callSessionId)
        ? {
            ...(payload.current_call_session ?? {}),
            ...patch,
        }
        : payload?.current_call_session ?? null;

    return {
        ...payload,
        current_call_session: nextCurrentCallSession,
        call_history: nextCallHistory,
    };
}

function workbenchCallerName(payload) {
    return payload.actual_caller_name ?? payload.caller?.name ?? 'Unknown caller';
}

function workbenchCallerMobile(payload) {
    return payload.caller?.mobile ?? 'No mobile recorded';
}

function workbenchCallerAvatar(payload) {
    return String(payload.caller?.avatar ?? '').trim();
}

function resolveWorkbenchMediaUrl(path) {
    const value = String(path ?? '').trim();

    if (!value) {
        return '';
    }

    if (/^https?:\/\//i.test(value) || value.startsWith('/')) {
        return value;
    }

    return `/storage/${value.replace(/^\/+/, '')}`;
}

function normalizeWorkbenchMediaStripItems(media) {
    return (Array.isArray(media) ? media : [])
        .map((item) => {
            const typeToken = String(item?.type ?? '').toLowerCase();
            const normalizedType = typeToken.includes('video')
                ? 'video'
                : (typeToken.includes('image') || typeToken.includes('photo') ? 'image' : '');

            if (!normalizedType) {
                return null;
            }

            const srcUrl = resolveWorkbenchMediaUrl(item.path);
            const processing = Boolean(item?.processing);
            const metadata = item?.metadata && typeof item.metadata === 'object'
                ? item.metadata
                : {};
            const posterCandidate = normalizedType === 'video'
                ? resolveWorkbenchMediaUrl(
                    metadata.thumbnail_path
                    ?? metadata.thumbnail
                    ?? metadata.poster_path
                    ?? metadata.poster
                    ?? ''
                )
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

function isWorkbenchAudioMediaItem(item) {
    const typeToken = String(item?.type ?? '').toLowerCase();
    const trackKind = String(item?.metadata?.track_kind ?? '').toLowerCase();

    return typeToken.includes('audio') || trackKind === 'audio';
}

function isWorkbenchVisualMediaItem(item) {
    const typeToken = String(item?.type ?? '').toLowerCase();
    const trackKind = String(item?.metadata?.track_kind ?? '').toLowerCase();

    return typeToken.includes('video')
        || typeToken.includes('image')
        || typeToken.includes('photo')
        || trackKind === 'video';
}

function buildWorkbenchAudioSessionPayload(payload, sourceMedia = null) {
    const media = (Array.isArray(sourceMedia) ? sourceMedia : (Array.isArray(payload?.media) ? payload.media : []))
        .filter((item) => {
            return isWorkbenchAudioMediaItem(item);
        })
        .map((item) => {
            const startedAt = String(
                item?.metadata?.started_at
                ?? item?.available_at
                ?? item?.created_at
                ?? ''
            ).trim();
            const startedMs = Date.parse(startedAt);
            const timestampToken = Number.isFinite(startedMs)
                ? new Date(startedMs).toISOString().replace(/\.\d{3}Z$/, 'Z').replace(/:/g, '-')
                : '';
            const peerRole = String(item?.peer_role ?? '').trim().toLowerCase();
            const callSessionId = Number(item?.call_session_id ?? payload?.current_call_session?.id ?? 0);
            const resolvedPath = resolveWorkbenchMediaUrl(item?.path ?? '');

            return {
                ...item,
                type: 'audio',
                path: resolvedPath,
                srcUrl: resolvedPath,
                metadata: {
                    ...(item?.metadata && typeof item.metadata === 'object' ? item.metadata : {}),
                    recording_role: peerRole && callSessionId && timestampToken
                        ? `${peerRole}-${callSessionId}-${timestampToken}`
                        : '',
                },
            };
        });

    return {
        ...payload,
        media,
    };
}

function workbenchMediaForCallSession(payload, callSessionId) {
    const sessionId = Number(callSessionId ?? 0);

    return (Array.isArray(payload?.media) ? payload.media : [])
        .filter((item) => Number(item?.call_session_id ?? 0) === sessionId);
}

function workbenchTimelineStatusForCallSession(session) {
    const status = String(session?.status ?? '').toLowerCase();
    const outcome = String(session?.outcome ?? '').toLowerCase();

    if (status === 'active' || status === 'answered') {
        return 'active';
    }

    if (outcome.includes('decline') || outcome.includes('reject') || outcome.includes('miss')) {
        return 'cancelled';
    }

    if (status === 'ended' || status === 'completed' || outcome.includes('ended')) {
        return 'completed';
    }

    return 'default';
}

function workbenchCallSessionTimelineItems(payload) {
    return (Array.isArray(payload?.call_history) ? payload.call_history : [])
        .filter((session) => Number(session?.id ?? 0) > 0)
        .map((session) => {
            const sessionId = Number(session.id);
            const media = workbenchMediaForCallSession(payload, sessionId);
            const audioCount = media.filter(isWorkbenchAudioMediaItem).length;
            const visualCount = media.filter(isWorkbenchVisualMediaItem).length;

            return {
                id: `call-session-${sessionId}`,
                title: `Call Session #${sessionId}`,
                subtitle: session.outcome ? formatStatusLabel(session.outcome) : '',
                description: '',
                timestamp: session.started_at ?? session.created_at ?? null,
                status: workbenchTimelineStatusForCallSession(session),
                contentKey: `${sessionId}:${audioCount}:${visualCount}:${media.map((item) => `${item.id}:${item.path ?? ''}:${item.available_at ?? ''}:${item.processing ? 1 : 0}`).join('|')}`,
                hasCustomContent: true,
                session,
                media,
            };
        });
}

function normalizePersistedChatSender(payload) {
    const sender = payload?.sender && typeof payload.sender === 'object'
        ? payload.sender
        : {};
    const senderId = Number(sender?.user_id ?? 0);
    const fallbackUser = appState.bootstrap?.user ?? {};
    const currentUserId = Number(fallbackUser?.id ?? 0);
    const isOperatorSender = senderId > 0 && senderId === currentUserId;

    return {
        id: senderId || currentUserId,
        role: isOperatorSender ? 'operator' : 'caller',
        name: String(sender?.display_name ?? (isOperatorSender ? (fallbackUser?.name ?? 'Operator') : 'Caller')),
        avatar: String(
            isOperatorSender
                ? (fallbackUser?.avatar ?? '')
                : (appState.runtime.operatorIncomingCallItem?.caller_avatar ?? '')
        ),
    };
}

function mediaRecorderSupport() {
    return typeof window !== 'undefined'
        && typeof window.MediaRecorder === 'function'
        && typeof window.MediaStream === 'function';
}

function resolveRecorderSpec(kind) {
    const MediaRecorderCtor = window.MediaRecorder;
    const supported = typeof MediaRecorderCtor?.isTypeSupported === 'function'
        ? MediaRecorderCtor.isTypeSupported.bind(MediaRecorderCtor)
        : () => false;

    if (kind === 'video') {
        const candidates = [
            { mimeType: 'video/webm;codecs=vp8', extension: 'webm' },
            { mimeType: 'video/webm', extension: 'webm' },
        ];

        return candidates.find((item) => supported(item.mimeType)) ?? candidates.at(-1);
    }

    const candidates = [
        { mimeType: 'audio/webm;codecs=opus', extension: 'weba' },
        { mimeType: 'audio/webm', extension: 'weba' },
    ];

    return candidates.find((item) => supported(item.mimeType)) ?? candidates.at(-1);
}

function buildCaptureSegmentKey(prefix) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

const OPERATOR_MEDIA_QUEUE_CONSUMER_POLL_MS = 500;

function createOperatorMediaTransportAdapter() {
    const chunkTransport = createRealtimeOperatorMediaChunkTransport({
        mode: OPERATOR_MEDIA_CHUNK_TRANSPORT,
    });
    const bootstrapTransport = createOperatorMediaBootstrapTransport();
    const batchTransport = createOperatorMediaBatchChunkTransport();
    const finalizer = createOperatorMediaFinalizer();

    return {
        enabled: OPERATOR_MEDIA_CONSUMER_ENABLED,
        transportMode: OPERATOR_MEDIA_CHUNK_TRANSPORT,
        pollMs: OPERATOR_MEDIA_QUEUE_CONSUMER_POLL_MS,
        publishChunk: chunkTransport.publishChunk,
        publishBootstrapChunk: bootstrapTransport.publishChunk,
        flushChunks: batchTransport.flushChunks,
        finalizeRecord: finalizer.finalizeRecord,
        destroyCallSession(callSessionId) {
            chunkTransport.destroy?.(callSessionId);
        },
    };
}

function operatorMediaManagersRuntime() {
    if (!appState.runtime.operatorMediaManagers) {
        appState.runtime.operatorMediaTransportAdapter = createOperatorMediaTransportAdapter();
        appState.runtime.operatorMediaManagers = createOperatorMediaManagers(appState.runtime.operatorMediaTransportAdapter);

        appState.runtime.operatorMediaManagers.setHooks({
            debug: debugMediaCapture,
        });
    }

    return appState.runtime.operatorMediaManagers;
}

function operatorMediaTransportRuntime() {
    if (!appState.runtime.operatorMediaTransportAdapter) {
        operatorMediaManagersRuntime();
    }

    return appState.runtime.operatorMediaTransportAdapter;
}

function installOperatorMediaConsoleApi() {
    if (typeof window === 'undefined') {
        return;
    }

    window.hotlineOperatorMedia = {
        enableConsumer() {
            const runtime = operatorMediaManagersRuntime();
            runtime.setConsumerEnabled(true);
            return runtime.getStatus();
        },
        disableConsumer() {
            const runtime = operatorMediaManagersRuntime();
            runtime.setConsumerEnabled(false);
            return runtime.getStatus();
        },
        startConsumer() {
            const runtime = operatorMediaManagersRuntime();
            runtime.start();
            return runtime.getStatus();
        },
        stopConsumer() {
            const runtime = operatorMediaManagersRuntime();
            runtime.stop();
            return runtime.getStatus();
        },
        scanConsumer() {
            const runtime = operatorMediaManagersRuntime();
            void runtime.scanConsumers();
            return runtime.getStatus();
        },
        status() {
            return operatorMediaManagersRuntime().getStatus();
        },
        items() {
            return operatorMediaManagersRuntime().getItems();
        },
        clear() {
            operatorMediaManagersRuntime().clear();
            return operatorMediaManagersRuntime().getStatus();
        },
    };
}

function createOperatorCallCaptureManager({
    callSessionId,
    incidentId,
    caller,
    operator,
    onRecorderCreated,
    onRecorderPrimed,
    onMediaUpdated,
}) {
    if (!callSessionId || !incidentId || !mediaRecorderSupport()) {
        return null;
    }

    const state = {
        finalized: false,
        finalizePromise: null,
        shuttingDown: false,
        captureActive: false,
        captureReadyAt: null,
        localAudio: null,
        remoteAudio: null,
        remoteVideo: null,
        remoteAudioSyncPromise: Promise.resolve(),
        remoteVideoSyncPromise: Promise.resolve(),
        chunkFailureNotified: false,
    };
    const mediaManagers = operatorMediaManagersRuntime();
    mediaManagers.setHooks({
        debug: debugMediaCapture,
    });

    const createProcessingAsset = async ({ type, peerUserId, peerRole, peerLabel, trackKind, extension, mimeType, segmentKey, startedAt }) => {
        const response = await fetchJson(`/api/operator/call-sessions/${callSessionId}/media`, {
            method: 'post',
            data: {
                type,
                peer_user_id: peerUserId,
                peer_role: peerRole,
                peer_label: peerLabel,
                track_kind: trackKind,
                extension,
                mime_type: mimeType,
                segment_key: segmentKey,
                started_at: startedAt,
            },
        });

        return response?.media ?? null;
    };

    const startRecorderRuntime = (runtime) => {
        if (!runtime || runtime.recorderStarted) {
            return false;
        }

        return Boolean(runtime.producer?.start());
    };

    const createRecorderRuntime = async ({ key, mediaType, trackKind, sourceStream, peerUserId, peerRole, peerLabel }) => {
        if (!(sourceStream instanceof MediaStream)) {
            return null;
        }

        const track = trackKind === 'video'
            ? sourceStream.getVideoTracks().at(0)
            : sourceStream.getAudioTracks().at(0);

        if (!track) {
            return null;
        }

        const clonedTrack = track.clone();
        clonedTrack.enabled = track.enabled;
        const clonedTracks = [clonedTrack];

        if (trackKind === 'video') {
            const sourceAudioTrack = sourceStream.getAudioTracks().at(0) ?? null;

            if (sourceAudioTrack) {
                const clonedAudioTrack = sourceAudioTrack.clone();
                clonedAudioTrack.enabled = sourceAudioTrack.enabled;
                clonedTracks.push(clonedAudioTrack);
            }
        }

        const recordingStream = new MediaStream(clonedTracks);
        const spec = resolveRecorderSpec(trackKind);
        const startedAt = new Date().toISOString();
        const media = await createProcessingAsset({
            type: mediaType,
            peerUserId,
            peerRole,
            peerLabel,
            trackKind,
            extension: spec.extension,
            mimeType: spec.mimeType,
            segmentKey: buildCaptureSegmentKey(key),
            startedAt,
        });

        if (!media?.id) {
            clonedTrack.stop();
            return null;
        }

        const recorder = spec?.mimeType
            ? new MediaRecorder(recordingStream, { mimeType: spec.mimeType })
            : new MediaRecorder(recordingStream);

        const runtime = {
            key,
            mediaId: Number(media.id),
            extension: spec.extension,
            mimeType: spec.mimeType || track?.getSettings?.().mimeType || '',
            startedAt,
            timesliceMs: 1000,
            recorder,
            clonedTrack,
            clonedTracks,
            captureReadyAt: state.captureReadyAt,
            mediaType,
            peerUserId,
            peerRole,
            peerLabel,
            trackKind,
            segmentKey: String(media?.metadata?.segment_key ?? ''),
            sourceTrack: track,
        };

        const mediaRecord = {
            media_id: runtime.mediaId,
            call_session_id: Number(callSessionId),
            incident_id: Number(incidentId),
            key,
            media_type: mediaType,
            track_kind: trackKind,
            peer_user_id: Number(peerUserId ?? 0),
            peer_role: String(peerRole ?? ''),
            peer_label: String(peerLabel ?? ''),
            extension: runtime.extension,
            mime_type: runtime.mimeType,
            segment_key: runtime.segmentKey,
            started_at: startedAt,
            ended_at: null,
            duration_seconds: 0,
            status: 'open',
            updated_at: new Date().toISOString(),
        };

        const producer = await mediaManagers.producerManager.create(recorder, mediaRecord, {
            state,
            callSessionId,
            incidentId,
            onRecorderPrimed,
            showToast,
            captureReadyAt: runtime.captureReadyAt,
            timesliceMs: runtime.timesliceMs,
            sourceTrack: runtime.sourceTrack,
            clonedTrack: runtime.clonedTrack,
            clonedTracks: runtime.clonedTracks,
        });
        producer.attachHandle(runtime);

        debugMediaCapture('recorder-created', {
            debugSource: 'Producer',
            callSessionId,
            mediaId: runtime.mediaId,
            key,
            mediaType,
            trackKind,
            segmentKey: runtime.segmentKey,
            sourceTrackId: track.id,
            clonedTrackId: clonedTrack.id,
            clonedTrackCount: clonedTracks.length,
            sourceTrackReadyState: track.readyState,
            sourceTrackMuted: Boolean(track.muted),
        });
        if (typeof onRecorderCreated === 'function') {
            onRecorderCreated({
                key,
                mediaId: runtime.mediaId,
                mediaType,
                trackKind,
                segmentKey: runtime.segmentKey,
            });
        }
        if (typeof onMediaUpdated === 'function') {
            onMediaUpdated(media);
        }
        track.addEventListener('mute', () => {
            debugMediaCapture('source-track-mute', {
                callSessionId,
                mediaId: runtime.mediaId,
                key,
                mediaType,
                trackKind,
                trackId: track.id,
            });
        });
        track.addEventListener('unmute', () => {
            debugMediaCapture('source-track-unmute', {
                callSessionId,
                mediaId: runtime.mediaId,
                key,
                mediaType,
                trackKind,
                trackId: track.id,
            });
        });
        track.addEventListener('ended', () => {
            debugMediaCapture('source-track-ended', {
                callSessionId,
                mediaId: runtime.mediaId,
                key,
                mediaType,
                trackKind,
                trackId: track.id,
            });
        }, { once: true });

        if (state.captureActive && !startRecorderRuntime(runtime)) {
            mediaManagers.producerManager.remove(runtime.mediaId);
            return null;
        }
        return runtime;
    };

    const stopRecorderRuntime = async (runtime) => {
        if (!runtime || runtime.stopPromise) {
            return runtime?.stopPromise ?? null;
        }

        return runtime.producer?.close() ?? Promise.resolve();
    };

    return {
        async ensureLocalAudio(stream) {
            if (state.shuttingDown || state.localAudio || !(stream instanceof MediaStream)) {
                return;
            }

            state.localAudio = await createRecorderRuntime({
                key: 'operator-audio',
                mediaType: 'audio_peer',
                trackKind: 'audio',
                sourceStream: stream,
                peerUserId: Number(operator?.id ?? 0),
                peerRole: 'operator',
                peerLabel: operator?.name ?? 'Operator',
            });
        },
        activateCapture(captureReadyAt) {
            const readyAtMs = captureReadyAt ? new Date(captureReadyAt).getTime() : Date.now();
            state.captureActive = true;
            state.captureReadyAt = readyAtMs;

            [state.localAudio, state.remoteAudio, state.remoteVideo].forEach((runtime) => {
                if (!runtime) {
                    return;
                }

                runtime.captureReadyAt = readyAtMs;
                runtime.producer?.setCaptureReadyAt(readyAtMs);
                startRecorderRuntime(runtime);
            });
        },
        setOfficialEndedAt(endedAt) {
            const endedAtMs = endedAt ? new Date(endedAt).getTime() : Date.now();

            [state.localAudio, state.remoteAudio, state.remoteVideo].forEach((runtime) => {
                if (!runtime) {
                    return;
                }

                runtime.producer?.setStopRequestedAt(endedAtMs);
            });
        },
        syncOperatorAudioMute(nextMuted) {
            const runtime = state.localAudio;

            if (!runtime?.clonedTrack) {
                return;
            }

            runtime.clonedTrack.enabled = !Boolean(nextMuted);
            debugMediaCapture('operator-audio-recorder-mute-sync', {
                callSessionId,
                mediaId: runtime.mediaId,
                key: runtime.key,
                nextMuted: Boolean(nextMuted),
                sourceTrackEnabled: Boolean(runtime.sourceTrack?.enabled),
                sourceTrackMuted: Boolean(runtime.sourceTrack?.muted),
                clonedTrackEnabled: Boolean(runtime.clonedTrack?.enabled),
                clonedTrackMuted: Boolean(runtime.clonedTrack?.muted),
            });
        },
        markOperatorAudioUnmuted() {
            const runtime = state.localAudio;

            if (!runtime) {
                return;
            }

            runtime.producer?.markUnmuted(Date.now());
            debugMediaCapture('operator-audio-unmuted', {
                callSessionId,
                mediaId: runtime.mediaId,
                key: runtime.key,
                sourceTrackEnabled: Boolean(runtime.sourceTrack?.enabled),
                sourceTrackMuted: Boolean(runtime.sourceTrack?.muted),
                sourceTrackReadyState: runtime.sourceTrack?.readyState ?? '',
                clonedTrackEnabled: Boolean(runtime.clonedTrack?.enabled),
                clonedTrackMuted: Boolean(runtime.clonedTrack?.muted),
                clonedTrackReadyState: runtime.clonedTrack?.readyState ?? '',
            });
        },
        async ensureRemoteAudio(stream) {
            if (state.shuttingDown || !(stream instanceof MediaStream)) {
                debugMediaCapture('caller-audio-sync-skip', {
                    callSessionId,
                    hasStream: stream instanceof MediaStream,
                    shuttingDown: state.shuttingDown,
                });
                return;
            }

            state.remoteAudioSyncPromise = state.remoteAudioSyncPromise
                .catch(() => {})
                .then(async () => {
                    if (state.shuttingDown || !(stream instanceof MediaStream)) {
                        return;
                    }

                    const nextTrack = stream.getAudioTracks().at(0) ?? null;
                    const currentTrackId = state.remoteAudio?.sourceTrack?.id ?? '';

                    debugMediaCapture('caller-audio-sync', {
                        callSessionId,
                        hasStream: true,
                        nextTrackId: nextTrack?.id ?? '',
                        nextTrackReadyState: nextTrack?.readyState ?? '',
                        nextTrackMuted: Boolean(nextTrack?.muted),
                        nextTrackEnabled: Boolean(nextTrack?.enabled),
                        currentRecorderMediaId: state.remoteAudio?.mediaId ?? null,
                        currentRecorderTrackId: currentTrackId,
                        currentRecorderStopping: Boolean(state.remoteAudio?.stopping),
                        currentRecorderFinalizing: Boolean(state.remoteAudio?.finalizing),
                    });

                    if (!nextTrack) {
                        return;
                    }

                    if (nextTrack.readyState !== 'live' || nextTrack.muted) {
                        debugMediaCapture('caller-audio-sync-track-not-ready', {
                            callSessionId,
                            nextTrackId: nextTrack.id,
                            nextTrackReadyState: nextTrack.readyState,
                            nextTrackMuted: Boolean(nextTrack.muted),
                            currentRecorderMediaId: state.remoteAudio?.mediaId ?? null,
                            currentRecorderTrackId: state.remoteAudio?.sourceTrack?.id ?? '',
                        });

                        if (state.remoteAudio?.sourceTrack?.readyState !== 'live') {
                            const staleRuntime = state.remoteAudio;
                            await stopRecorderRuntime(staleRuntime);
                            if (state.remoteAudio === staleRuntime) {
                                state.remoteAudio = null;
                            }
                        }

                        return;
                    }

                    if (state.remoteAudio?.sourceTrack?.id === nextTrack.id) {
                        debugMediaCapture('caller-audio-sync-recorder-reuse', {
                            callSessionId,
                            mediaId: state.remoteAudio?.mediaId ?? null,
                            currentRecorderTrackId: state.remoteAudio?.sourceTrack?.id ?? '',
                        });
                        return;
                    }

                    const previousRuntime = state.remoteAudio;

                    debugMediaCapture('caller-audio-sync-recorder-refresh', {
                        callSessionId,
                        currentRecorderMediaId: previousRuntime?.mediaId ?? null,
                        currentRecorderTrackId: previousRuntime?.sourceTrack?.id ?? '',
                        nextTrackId: nextTrack.id,
                    });

                    if (previousRuntime) {
                        await stopRecorderRuntime(previousRuntime);
                        if (state.remoteAudio === previousRuntime) {
                            state.remoteAudio = null;
                        }
                    }

                    if (state.shuttingDown) {
                        return;
                    }

                    if (state.remoteAudio?.sourceTrack?.id === nextTrack.id) {
                        debugMediaCapture('caller-audio-sync-recorder-reuse', {
                            callSessionId,
                            mediaId: state.remoteAudio?.mediaId ?? null,
                            currentRecorderTrackId: state.remoteAudio?.sourceTrack?.id ?? '',
                        });
                        return;
                    }

                    const nextRuntime = await createRecorderRuntime({
                        key: 'citizen-audio',
                        mediaType: 'audio_peer',
                        trackKind: 'audio',
                        sourceStream: stream,
                        peerUserId: Number(caller?.id ?? 0),
                        peerRole: 'citizen',
                        peerLabel: caller?.name ?? 'Citizen',
                    });

                    if (!nextRuntime) {
                        return;
                    }

                    if (state.remoteAudio?.sourceTrack?.id === nextRuntime.sourceTrack?.id) {
                        await stopRecorderRuntime(nextRuntime);
                        return;
                    }

                    state.remoteAudio = nextRuntime;

                    debugMediaCapture('caller-audio-sync-recorder-ready', {
                        callSessionId,
                        mediaId: state.remoteAudio?.mediaId ?? null,
                        nextTrackId: nextTrack.id,
                        clonedTrackId: state.remoteAudio?.clonedTrack?.id ?? '',
                    });
                });

            return state.remoteAudioSyncPromise;
        },
        async syncRemoteVideo(enabled, stream) {
            if (state.shuttingDown) {
                debugMediaCapture('caller-video-sync-skip-shutdown', {
                    callSessionId,
                    enabled: Boolean(enabled),
                });
                return;
            }

            state.remoteVideoSyncPromise = state.remoteVideoSyncPromise
                .catch(() => {})
                .then(async () => {
                    const nextTrack = stream instanceof MediaStream ? stream.getVideoTracks().at(0) : null;
                    const nextAudioTrack = stream instanceof MediaStream ? stream.getAudioTracks().at(0) : null;
                    const currentTrackId = state.remoteVideo?.sourceTrack?.id ?? '';

                    debugMediaCapture('caller-video-sync', {
                        callSessionId,
                        enabled: Boolean(enabled),
                        hasStream: stream instanceof MediaStream,
                        nextTrackId: nextTrack?.id ?? '',
                        nextTrackReadyState: nextTrack?.readyState ?? '',
                        nextTrackMuted: Boolean(nextTrack?.muted),
                        nextTrackEnabled: Boolean(nextTrack?.enabled),
                        nextAudioTrackId: nextAudioTrack?.id ?? '',
                        nextAudioTrackReadyState: nextAudioTrack?.readyState ?? '',
                        nextAudioTrackMuted: Boolean(nextAudioTrack?.muted),
                        nextAudioTrackEnabled: Boolean(nextAudioTrack?.enabled),
                        currentRecorderMediaId: state.remoteVideo?.mediaId ?? null,
                        currentRecorderTrackId: currentTrackId,
                        currentRecorderStopping: Boolean(state.remoteVideo?.stopping),
                        currentRecorderFinalizing: Boolean(state.remoteVideo?.finalizing),
                    });

                    if (enabled) {
                        if (state.remoteVideo?.sourceTrack?.id === (nextTrack?.id ?? '')) {
                            debugMediaCapture('caller-video-sync-recorder-reuse', {
                                callSessionId,
                                mediaId: state.remoteVideo?.mediaId ?? null,
                                currentRecorderTrackId: state.remoteVideo?.sourceTrack?.id ?? '',
                            });
                            return;
                        }

                        debugMediaCapture('caller-video-sync-recorder-refresh', {
                            callSessionId,
                            currentRecorderMediaId: state.remoteVideo?.mediaId ?? null,
                            currentRecorderTrackId: currentTrackId,
                            nextTrackId: nextTrack?.id ?? '',
                        });

                        const previousRuntime = state.remoteVideo;
                        if (previousRuntime) {
                            await stopRecorderRuntime(previousRuntime);
                            if (state.remoteVideo === previousRuntime) {
                                state.remoteVideo = null;
                            }
                        }

                        if (state.shuttingDown || !nextTrack) {
                            return;
                        }

                        if (state.remoteVideo?.sourceTrack?.id === nextTrack.id) {
                            debugMediaCapture('caller-video-sync-recorder-reuse', {
                                callSessionId,
                                mediaId: state.remoteVideo?.mediaId ?? null,
                                currentRecorderTrackId: state.remoteVideo?.sourceTrack?.id ?? '',
                            });
                            return;
                        }

                        const nextRuntime = await createRecorderRuntime({
                            key: 'citizen-cam',
                            mediaType: 'citizen_video',
                            trackKind: 'video',
                            sourceStream: stream,
                            peerUserId: Number(caller?.id ?? 0),
                            peerRole: 'citizen',
                            peerLabel: caller?.name ?? 'Citizen',
                        });

                        if (!nextRuntime) {
                            return;
                        }

                        if (state.remoteVideo?.sourceTrack?.id === nextRuntime.sourceTrack?.id) {
                            await stopRecorderRuntime(nextRuntime);
                            return;
                        }

                        state.remoteVideo = nextRuntime;

                        debugMediaCapture('caller-video-sync-recorder-ready', {
                            callSessionId,
                            mediaId: state.remoteVideo?.mediaId ?? null,
                            nextTrackId: nextTrack?.id ?? '',
                            clonedTrackId: state.remoteVideo?.clonedTrack?.id ?? '',
                            clonedTrackCount: state.remoteVideo?.clonedTracks?.length ?? 0,
                        });
                        return;
                    }

                    if (!state.remoteVideo) {
                        return;
                    }

                    if (state.remoteVideo.stopping || state.remoteVideo.finalizing) {
                        debugMediaCapture('caller-video-sync-skip-shutdown', {
                            callSessionId,
                            enabled: false,
                            mediaId: state.remoteVideo?.mediaId ?? null,
                        });
                        return;
                    }

                    debugMediaCapture('caller-video-sync-recorder-stop', {
                        callSessionId,
                        mediaId: state.remoteVideo?.mediaId ?? null,
                        currentRecorderTrackId: state.remoteVideo?.sourceTrack?.id ?? '',
                    });
                    const runtimeToStop = state.remoteVideo;
                    await stopRecorderRuntime(runtimeToStop);
                    if (state.remoteVideo === runtimeToStop) {
                        state.remoteVideo = null;
                        debugMediaCapture('caller-video-sync-recorder-cleared', {
                            callSessionId,
                        });
                    }
                });

            return state.remoteVideoSyncPromise;
        },
        async finalizeAll() {
            if (state.finalizePromise) {
                return state.finalizePromise;
            }

            state.finalizePromise = (async () => {
                if (state.finalized) {
                    return;
                }

                state.shuttingDown = true;
                await mediaManagers.producerManager.close();
                state.finalized = true;
            })();

            return state.finalizePromise;
        },
        destroy() {
            void this.finalizeAll();
        },
    };
}

async function loadSharedWorkbenchLookups() {
    if (appState.runtime.operatorWorkbenchLookups) {
        return appState.runtime.operatorWorkbenchLookups;
    }

    const bootstrapLookups = appState.bootstrap?.surface_payload ?? {};

    if (
        Array.isArray(bootstrapLookups.incident_type_categories)
        && Array.isArray(bootstrapLookups.incident_type_catalog)
        && Array.isArray(bootstrapLookups.resource_types)
        && Array.isArray(bootstrapLookups.team_categories)
        && Array.isArray(bootstrapLookups.teams)
    ) {
        const lookups = {
            incidentCategories: bootstrapLookups.incident_type_categories,
            incidentTypes: bootstrapLookups.incident_type_catalog,
            resourceTypes: bootstrapLookups.resource_types,
            teamCategories: bootstrapLookups.team_categories,
            teams: bootstrapLookups.teams,
        };

        appState.runtime.operatorWorkbenchLookups = lookups;
        return lookups;
    }

    throw new Error('Operator workbench lookups are missing from bootstrap surface_payload.');
}

async function ensureOperatorWorkbenchHelpers() {
    await ensureHelperUi();

    if (
        appState.helper.incidentTypesHelper
        && appState.helper.incidentAssignmentsHelper
        && appState.helper.createAudioCallSession
        && appState.helper.createAudioGraph
        && appState.helper.createMediaStrip
    ) {
        return appState.helper;
    }

    const [
        incidentTypesHelper,
        incidentAssignmentsHelper,
        createAudioCallSession,
        createAudioGraph,
        createMediaStrip,
    ] = await Promise.all([
        appState.helper.uiLoader.get('incident.types'),
        appState.helper.uiLoader.get('incident.teams.assignments'),
        appState.helper.uiLoader.get('ui.audio.callSession'),
        appState.helper.uiLoader.get('ui.audio.audiograph'),
        appState.helper.uiLoader.get('ui.media.strip'),
    ]);

    Object.assign(appState.helper, {
        incidentTypesHelper,
        incidentAssignmentsHelper,
        createAudioCallSession,
        createAudioGraph,
        createMediaStrip,
    });

    return appState.helper;
}

function workbenchCallSessionsMarkup(callHistory) {
    if (!Array.isArray(callHistory) || callHistory.length === 0) {
        return '<div class="operator-workbench-empty">No call sessions recorded yet.</div>';
    }

    return callHistory.map((session) => `
        <article class="operator-workbench-session-card">
            <div class="operator-workbench-session-row">
                <strong>${escapeHtml(formatDateTime(session.started_at))}</strong>
                <span>${escapeHtml(formatStatusLabel(session.status ?? 'unknown'))}</span>
            </div>
            <div class="operator-workbench-session-row muted">
                <span>Ended: ${escapeHtml(formatDateTime(session.ended_at))}</span>
                <span>Outcome: ${escapeHtml(formatStatusLabel(session.outcome ?? 'pending'))}</span>
            </div>
        </article>
    `).join('');
}

function formatWorkbenchDateTimeCompact(value) {
    if (!value) {
        return 'Unknown';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return 'Unknown';
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(date);
}

function renderWorkbenchMediaColumnMarkup(isActive, callState) {
    if (isActive) {
        return `
            <div class="operator-workbench-card operator-workbench-audio-card">
                <div class="operator-workbench-card-head">
                    <strong>Audio Activity</strong>
                </div>
                <div class="operator-workbench-audio-host" data-workbench-audio data-workbench-audio-state="${callState}"></div>
            </div>
            <div class="operator-workbench-card">
                <div class="operator-workbench-card-head">
                    <strong>Video Stream</strong>
                </div>
                <div class="operator-workbench-video-preview is-active" data-workbench-video-preview>Video placeholder</div>
                <div class="operator-workbench-media-strip-host" data-workbench-media-strip></div>
            </div>
        `;
    }

    return `
        <div class="operator-workbench-card operator-workbench-call-timeline-card">
            <div class="operator-workbench-card-head">
                <strong>Call Sessions</strong>
            </div>
            <div class="operator-workbench-call-timeline-host" data-workbench-call-session-timeline></div>
        </div>
    `;
}

function workbenchCallerLocation(payload = {}) {
    return normalizeCallerLocationPayload(payload);
}

function formatWorkbenchCoordinates(location) {
    const nextLocation = normalizeCallerLocationPayload(location);

    if (!nextLocation) {
        return '';
    }

    return `${nextLocation.latitude.toFixed(7)}, ${nextLocation.longitude.toFixed(7)}`;
}

function formatWorkbenchHeading(location) {
    const heading = Number(location?.heading ?? NaN);

    if (!Number.isFinite(heading)) {
        return 'Facing unavailable';
    }

    const normalized = ((heading % 360) + 360) % 360;
    const directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    const direction = directions[Math.round(normalized / 45) % 8];

    return `Facing ${direction} (${Math.round(normalized)}°)`;
}

function formatWorkbenchElevation(location) {
    const altitude = Number(location?.altitude ?? NaN);

    if (!Number.isFinite(altitude)) {
        return 'Elevation unavailable';
    }

    const accuracy = Number(location?.altitude_accuracy ?? NaN);
    const suffix = Number.isFinite(accuracy)
        ? `, ±${Math.round(accuracy)}m`
        : '';

    return `Elevation ${Math.round(altitude)}m${suffix}`;
}

function normalizeWorkbenchAddressPart(value) {
    return String(value ?? '').trim();
}

function workbenchCallerAddressValue(payload = {}) {
    return {
        road: normalizeWorkbenchAddressPart(payload.location_road),
        neighborhood: normalizeWorkbenchAddressPart(payload.location_suburb),
        barangay: normalizeWorkbenchAddressPart(payload.location_barangay),
        town: '',
        city: normalizeWorkbenchAddressPart(payload.location_citymunicipality),
        state: '',
        country: normalizeWorkbenchAddressPart(payload.location_country) || 'Philippines',
    };
}

function workbenchCallerAddressParts(payload = {}) {
    const address = workbenchCallerAddressValue(payload);

    return [
        address.road,
        address.neighborhood,
        address.barangay ? `Barangay ${address.barangay}` : '',
        address.city || address.town,
        address.country,
    ].filter(Boolean);
}

function workbenchCallerAddressSummary(payload = {}) {
    const explicitLocation = normalizeWorkbenchAddressPart(payload.location);

    if (explicitLocation) {
        return explicitLocation;
    }

    return workbenchCallerAddressParts(payload).join(', ');
}

function renderWorkbenchCallerAddressMarkup(payload = {}) {
    const rows = [
        ['Road / Landmark', payload.location_road],
        ['Neighborhood', payload.location_suburb],
        ['Barangay', payload.location_barangay],
        ['City / Municipality', payload.location_citymunicipality],
        ['Country', payload.location_country],
    ]
        .map(([label, value]) => [label, normalizeWorkbenchAddressPart(value)])
        .filter(([, value]) => value);

    if (!rows.length) {
        return '<p class="operator-workbench-address-empty">No caller address has been recorded.</p>';
    }

    return `
        <dl class="operator-workbench-address-list">
            ${rows.map(([label, value]) => `
                <div>
                    <dt>${escapeHtml(label)}</dt>
                    <dd>${escapeHtml(value)}</dd>
                </div>
            `).join('')}
        </dl>
    `;
}

function renderWorkbenchCallerLocationMarkup(payload = {}) {
    const location = workbenchCallerLocation(payload);
    const locationText = workbenchCallerAddressSummary(payload);
    const addressMarkup = renderWorkbenchCallerAddressMarkup(payload);

    if (!location) {
        return {
            text: locationText || 'Location pending',
            address: addressMarkup,
            placeholder: '<span>Map placeholder</span>',
        };
    }

    const accuracy = Number.isFinite(Number(location.accuracy))
        ? `${Math.round(Number(location.accuracy))}m accuracy`
        : 'Accuracy unavailable';
    const capturedAt = location.captured_at
        ? `Updated ${formatDateTime(location.captured_at)}`
        : 'Live caller coordinates';

    return {
        text: locationText || 'Coordinates available',
        address: addressMarkup,
        placeholder: `
            <div class="operator-workbench-location-map-frame" data-workbench-location-mini-map data-workbench-location-open-map role="button" tabindex="0" aria-label="Open caller location map"></div>
            <div class="operator-workbench-location-coordinates">
                <strong>${escapeHtml(formatWorkbenchCoordinates(location))}</strong>
                <span>${escapeHtml(accuracy)}</span>
                <span>${escapeHtml(formatWorkbenchHeading(location))}</span>
                <span>${escapeHtml(formatWorkbenchElevation(location))}</span>
                <small>${escapeHtml(capturedAt)}</small>
            </div>
        `,
    };
}

function updateWorkbenchCallerLocationView(overlay, payload = {}) {
    if (!overlay) {
        return;
    }

    const locationMarkup = renderWorkbenchCallerLocationMarkup(payload);
    const textNode = overlay.querySelector('[data-workbench-location-text]');
    const addressNode = overlay.querySelector('[data-workbench-location-address]');
    const mapNode = overlay.querySelector('[data-workbench-location-map]');

    if (textNode) {
        textNode.textContent = locationMarkup.text;
    }

    if (addressNode) {
        addressNode.innerHTML = locationMarkup.address;
    }

    if (mapNode) {
        mapNode.innerHTML = locationMarkup.placeholder;
    }

    void mountOrUpdateWorkbenchLocationMap(overlay, payload);
}

function workbenchCallerAddressPayloadFromValue(value = {}) {
    const road = normalizeWorkbenchAddressPart(value.road);
    const neighborhood = normalizeWorkbenchAddressPart(value.neighborhood);
    const barangay = normalizeWorkbenchAddressPart(value.barangay);
    const city = normalizeWorkbenchAddressPart(value.city) || normalizeWorkbenchAddressPart(value.town);
    const country = normalizeWorkbenchAddressPart(value.country);
    const summary = [
        road,
        neighborhood,
        barangay ? `Barangay ${barangay}` : '',
        city,
        country,
    ].filter(Boolean).join(', ');

    return {
        location: summary || null,
        location_road: road || null,
        location_suburb: neighborhood || null,
        location_barangay: barangay || null,
        location_citymunicipality: city || null,
        location_country: country || null,
    };
}

function workbenchEditIconMarkup() {
    if (typeof appState.helper.createIcon === 'function') {
        try {
            return appState.helper.createIcon('actions.edit', { size: 16, ariaLabel: 'Edit' }).outerHTML ?? '';
        } catch (_error) {
            return '';
        }
    }

    return '';
}

async function ensureWorkbenchAddressHelpers() {
    await ensureHelperUi();

    const [createFieldGroup, fieldGroupPresets] = await Promise.all([
        appState.helper.uiLoader.get('ui.field.group'),
        appState.helper.uiLoader.get('ui.field.group.presets'),
    ]);

    return {
        createActionModal: appState.helper.createActionModal,
        createFieldGroup,
        fieldGroupPresets,
    };
}

function updateWorkbenchCallerIdentityView(overlay, payload = {}) {
    const callerNameInput = overlay?.querySelector?.('[data-workbench-actual-caller-name]');
    const callerRelationshipHost = overlay?.querySelector?.('[data-workbench-caller-relationship]');
    const callerRelationshipSelect = callerRelationshipHost?.__operatorCallerRelationshipSelect ?? null;

    if (callerNameInput) {
        callerNameInput.value = String(payload.actual_caller_name ?? '');
    }

    callerRelationshipSelect?.setValue?.(workbenchCallerRelationship(payload), { emit: false });
}

function applyWorkbenchIntakePayload(overlay, payload, incident = null) {
    if (incident && typeof incident === 'object') {
        Object.assign(payload, incident);
    }

    if (appState.runtime.operatorWorkbench?.payload) {
        appState.runtime.operatorWorkbench.payload = payload;
    }

    syncOperatorIncidentRails(currentOperatorRoot(), payload);
    updateWorkbenchCallerIdentityView(overlay, payload);
    updateWorkbenchCallerLocationView(overlay, payload);
}

async function openWorkbenchCallerAddressModal(overlay, payload = {}) {
    const helper = await ensureWorkbenchAddressHelpers();

    if (typeof helper.createActionModal !== 'function' || typeof helper.createFieldGroup !== 'function') {
        showToast('Address editor is unavailable right now.', 'warn');
        return;
    }

    const content = document.createElement('div');
    content.className = 'operator-workbench-address-modal';

    const intro = document.createElement('p');
    intro.className = 'operator-workbench-address-modal-note';
    intro.textContent = 'Review and correct the caller address when the reported location needs operator cleanup.';

    const groupHost = document.createElement('div');
    groupHost.className = 'operator-workbench-address-field-group';
    content.append(intro, groupHost);

    let addressGroup = null;
    let modal = null;

    const addressPreset = helper.fieldGroupPresets?.address?.({
        label: 'Caller Address',
        fields: {
            neighborhood: { label: 'Neighborhood / Sitio' },
            city: { label: 'City / Municipality' },
            state: { label: 'Province / State' },
        },
        extraFields: [
            { key: 'road', label: 'Road / Landmark', type: 'text' },
        ],
    }) ?? {
        label: 'Caller Address',
        preset: 'address',
    };

    modal = helper.createActionModal({
        title: 'Edit Caller Address',
        ariaLabel: 'Edit caller address',
        size: 'md',
        content,
        closeOnBackdrop: false,
        actions: [
            {
                id: 'cancel',
                label: 'Cancel',
                variant: 'default',
            },
            {
                id: 'save',
                label: 'Save Address',
                variant: 'primary',
                autoFocus: true,
                busyMessage: 'Saving caller address...',
                closeOnClick: false,
                async onClick() {
                    const validation = addressGroup?.validate?.();

                    if (validation && validation.status === false) {
                        showToast('Complete the required address fields before saving.', 'warn');
                        return false;
                    }

                    const addressPayload = workbenchCallerAddressPayloadFromValue(addressGroup?.getValue?.() ?? {});

                    try {
                        const response = await fetchJson(`/api/operator/incidents/${payload.id}/citizen-address`, {
                            method: 'post',
                            data: addressPayload,
                        });

                        if (response?.incident) {
                            applyWorkbenchIntakePayload(overlay, payload, response.incident);
                            publishOperatorIncidentUpdate({
                                incident_id: Number(payload.id ?? 0),
                                caller_id: Number(payload.caller_id ?? 0),
                                scope: 'address',
                                patch: addressPayload,
                            });
                        }

                        await modal?.close({ reason: 'submit' });
                        showToast('Caller address updated.', 'success');
                    } catch (error) {
                        showToast(error?.response?.data?.message ?? 'Unable to save caller address.', 'warn');
                    }

                    return false;
                },
            },
        ],
        onClose() {
            addressGroup?.destroy?.();
            addressGroup = null;
        },
    });

    addressGroup = helper.createFieldGroup(groupHost, {
        name: 'caller_address',
        ...addressPreset,
        repeatable: false,
        required: false,
        chrome: false,
        value: workbenchCallerAddressValue(payload),
    });

    modal.open();
}

function bindWorkbenchCallerAddressEditor(overlay, payload = {}) {
    const button = overlay?.querySelector?.('[data-edit-workbench-caller-address]');

    if (!button) {
        return null;
    }

    const handler = () => {
        void openWorkbenchCallerAddressModal(overlay, payload).catch((error) => {
            showToast(error?.response?.data?.message ?? 'Unable to open caller address editor.', 'warn');
        });
    };

    button.addEventListener('click', handler);

    return {
        destroy() {
            button.removeEventListener('click', handler);
        },
    };
}

async function openWorkbenchInitialIntakeModal(overlay, payload = {}) {
    const helper = await ensureWorkbenchAddressHelpers();

    if (
        typeof helper.createActionModal !== 'function'
        || typeof helper.createFieldGroup !== 'function'
        || typeof appState.helper.createSelect !== 'function'
    ) {
        showToast('Caller intake prompt is unavailable right now.', 'warn');
        return;
    }

    const content = document.createElement('div');
    content.className = 'operator-workbench-intake-modal';
    content.innerHTML = `
        <p class="operator-workbench-intake-note">Confirm these details with the caller before continuing the incident workup.</p>
        <label class="operator-workbench-intake-field">
            <span>Actual Caller Name</span>
            <input class="ui-input" type="text" data-intake-caller-name placeholder="Actual caller name">
        </label>
        <div class="operator-workbench-intake-field">
            <span>Actual Caller Relationship</span>
            <div data-intake-caller-relationship></div>
        </div>
        <div class="operator-workbench-intake-address" data-intake-caller-address></div>
    `;

    const callerNameInput = content.querySelector('[data-intake-caller-name]');
    const relationshipHost = content.querySelector('[data-intake-caller-relationship]');
    const addressHost = content.querySelector('[data-intake-caller-address]');
    callerNameInput.value = String(payload.actual_caller_name ?? payload.caller?.name ?? '').trim();

    const relationshipSelect = appState.helper.createSelect(relationshipHost, workbenchRelationshipOptions(), {
        ariaLabel: 'Actual Caller Relationship',
        placeholder: 'Select relationship',
        searchable: false,
        clearable: false,
        selected: [workbenchCallerRelationship(payload)],
    });

    const addressPreset = helper.fieldGroupPresets?.address?.({
        label: 'Caller Address',
        fields: {
            neighborhood: { label: 'Neighborhood / Sitio' },
            city: { label: 'City / Municipality' },
            state: { label: 'Province / State' },
        },
        extraFields: [
            { key: 'road', label: 'Road / Landmark', type: 'text' },
        ],
    }) ?? {
        label: 'Caller Address',
        preset: 'address',
    };

    let addressGroup = null;
    let modal = null;

    modal = helper.createActionModal({
        title: 'Initial Caller Intake',
        ariaLabel: 'Initial caller intake',
        size: 'md',
        content,
        closeOnBackdrop: false,
        closeOnEscape: false,
        actions: [
            {
                id: 'skip',
                label: 'Skip for now',
                variant: 'default',
            },
            {
                id: 'save',
                label: 'Save Intake',
                variant: 'primary',
                autoFocus: true,
                busyMessage: 'Saving caller intake...',
                closeOnClick: false,
                async onClick() {
                    const actualCallerName = String(callerNameInput?.value ?? '').trim();

                    if (!actualCallerName) {
                        showToast('Actual caller name is required.', 'warn');
                        callerNameInput?.focus?.();
                        return false;
                    }

                    const relationship = String(relationshipSelect?.getValue?.() ?? 'Self').trim() || 'Self';
                    const addressPayload = workbenchCallerAddressPayloadFromValue(addressGroup?.getValue?.() ?? {});

                    try {
                        const intakePayload = {
                            actual_caller_name: actualCallerName,
                            actual_caller_relationship: relationship,
                            ...addressPayload,
                        };
                        const intakeResponse = await fetchJson(`/api/operator/incidents/${payload.id}/intake`, {
                            method: 'post',
                            data: intakePayload,
                        });

                        applyWorkbenchIntakePayload(overlay, payload, intakeResponse?.incident);
                        publishOperatorIncidentUpdate({
                            incident_id: Number(payload.id ?? 0),
                            caller_id: Number(payload.caller_id ?? 0),
                            scope: 'intake',
                            patch: intakePayload,
                        });

                        await modal?.close({ reason: 'submit' });
                        showToast('Initial caller intake saved.', 'success');
                    } catch (error) {
                        showToast(error?.response?.data?.message ?? 'Unable to save caller intake.', 'warn');
                    }

                    return false;
                },
            },
        ],
        onClose() {
            relationshipSelect?.destroy?.();
            addressGroup?.destroy?.();
            addressGroup = null;
        },
    });

    addressGroup = helper.createFieldGroup(addressHost, {
        name: 'caller_address',
        ...addressPreset,
        repeatable: false,
        required: false,
        chrome: false,
        value: workbenchCallerAddressValue(payload),
    });

    modal.open();
}

function maybeOpenWorkbenchInitialIntake(overlay, payload = {}, options = {}) {
    if (!options.initialIntake) {
        return null;
    }

    const timer = window.setTimeout(() => {
        void openWorkbenchInitialIntakeModal(overlay, payload).catch((error) => {
            console.warn('Unable to open initial caller intake modal.', error);
            showToast('Unable to open initial caller intake prompt.', 'warn');
        });
    }, 0);

    return {
        destroy() {
            window.clearTimeout(timer);
        },
    };
}

function renderWorkbenchLocationWindowMeta(location = {}) {
    const normalized = workbenchCallerLocation(location);

    if (!normalized) {
        return '';
    }

    const accuracy = Number.isFinite(Number(normalized.accuracy))
        ? `${Math.round(Number(normalized.accuracy))}m accuracy`
        : 'Accuracy unavailable';
    const capturedAt = normalized.captured_at
        ? `Updated ${formatDateTime(normalized.captured_at)}`
        : 'Live caller coordinates';

    return `
        <strong>${escapeHtml(formatWorkbenchCoordinates(normalized))}</strong>
        <span>${escapeHtml(accuracy)}</span>
        <span>${escapeHtml(formatWorkbenchHeading(normalized))}</span>
        <span>${escapeHtml(formatWorkbenchElevation(normalized))}</span>
        <small>${escapeHtml(capturedAt)}</small>
    `;
}

function destroyWorkbenchLocationMap(instance = null) {
    const current = appState.runtime.operatorWorkbenchLocationMap ?? null;

    if (instance && current !== instance) {
        instance.destroy?.();
        return;
    }

    current?.destroy?.();
    appState.runtime.operatorWorkbenchLocationMap = null;
}

function destroyWorkbenchLocationWindow() {
    const current = appState.runtime.operatorWorkbenchLocationWindow ?? null;
    const manager = appState.runtime.operatorWorkbenchLocationWindowManager ?? null;

    appState.runtime.operatorWorkbenchLocationWindow = null;
    appState.runtime.operatorWorkbenchLocationWindowManager = null;

    current?.observer?.disconnect?.();
    current?.map?.destroy?.();

    if (current?.window && !current.closing) {
        void current.window.close({ reason: 'workbench-destroy' });
    }

    manager?.destroy?.();
}

async function ensureWorkbenchLocationWindowManager() {
    if (appState.runtime.operatorWorkbenchLocationWindowManager) {
        return appState.runtime.operatorWorkbenchLocationWindowManager;
    }

    await ensureHelperUi();

    if (typeof appState.helper.createWindowManager !== 'function') {
        appState.helper.createWindowManager = await appState.helper.uiLoader.get('ui.window');
    }

    if (typeof appState.helper.createWindowManager !== 'function') {
        return null;
    }

    const manager = appState.helper.createWindowManager({
        container: document.body,
        showTaskbar: false,
        className: 'operator-location-window-manager',
    });

    appState.runtime.operatorWorkbenchLocationWindowManager = manager;

    return manager;
}

function updateWorkbenchLocationWindow(payload = {}) {
    const current = appState.runtime.operatorWorkbenchLocationWindow ?? null;
    const location = workbenchCallerLocation(payload);

    if (!current || !location) {
        return;
    }

    current.map?.setLocation?.(location);
    current.map?.appendTrackPoint?.(location);

    if (current.meta) {
        current.meta.innerHTML = renderWorkbenchLocationWindowMeta(location);
    }

    requestAnimationFrame(() => current.map?.resize?.());
}

async function loadWorkbenchCallerLocationTrack(incidentId) {
    const nextIncidentId = Number(incidentId ?? 0);

    if (!nextIncidentId) {
        return [];
    }

    const response = await fetchJson(`/api/operator/incidents/${nextIncidentId}/citizen-locations?limit=500`);

    return Array.isArray(response?.items) ? response.items : [];
}

async function mountWorkbenchLocationWindowMap(entry, location) {
    if (!entry?.mapHost || !location) {
        return;
    }

    const map = createWorkbenchLocationMap({
        container: entry.mapHost,
        controls: true,
        interactive: true,
        zoom: 17,
    });

    entry.map = map;

    let mounted = false;

    try {
        mounted = await map.init(location);
    } catch (error) {
        console.warn('Unable to mount operator caller location window map.', error);
    }

    if (appState.runtime.operatorWorkbenchLocationWindow !== entry) {
        map.destroy?.();
        return;
    }

    if (!mounted) {
        entry.mapHost.dataset.mapUnavailable = '1';
        return;
    }

    if (entry.trackLoaded !== true && entry.incidentId) {
        entry.trackLoaded = true;
        try {
            const track = await loadWorkbenchCallerLocationTrack(entry.incidentId);
            if (appState.runtime.operatorWorkbenchLocationWindow === entry) {
                map.setTrack?.(track);
            }
        } catch (error) {
            console.warn('Unable to load caller location track.', error);
        }
    }

    map.setLocation(location);
    map.appendTrackPoint?.(location);
    if (typeof ResizeObserver === 'function') {
        entry.observer = new ResizeObserver(() => {
            requestAnimationFrame(() => map.resize?.());
        });
        entry.observer.observe(entry.mapHost);
    }
    requestAnimationFrame(() => map.resize?.());
}

async function openWorkbenchLocationWindow(payload = {}) {
    const location = workbenchCallerLocation(payload);

    if (!location) {
        return;
    }

    const existing = appState.runtime.operatorWorkbenchLocationWindow ?? null;

    if (existing?.window) {
        existing.window.focus?.();
        updateWorkbenchLocationWindow(payload);
        return;
    }

    const manager = await ensureWorkbenchLocationWindowManager();

    if (!manager) {
        return;
    }

    const content = document.createElement('div');
    content.className = 'operator-location-window-body';
    content.setAttribute('data-ui-window-fill', 'true');
    content.innerHTML = `
        <div class="operator-location-window-map" data-caller-location-window-map aria-label="Caller location map"></div>
        <div class="operator-location-window-meta" data-caller-location-window-meta>
            ${renderWorkbenchLocationWindowMeta(location)}
        </div>
    `;

    const entry = {
        manager,
        map: null,
        observer: null,
        window: null,
        incidentId: Number(payload.id ?? 0),
        trackLoaded: false,
        mapHost: content.querySelector('[data-caller-location-window-map]'),
        meta: content.querySelector('[data-caller-location-window-meta]'),
        closing: false,
    };

    const resizeMap = () => requestAnimationFrame(() => entry.map?.resize?.());

    entry.window = manager.createWindow({
        id: `operator-caller-location-${payload.id ?? 'current'}`,
        title: 'Caller Location',
        content,
        width: 720,
        height: 520,
        minWidth: 420,
        minHeight: 320,
        draggable: true,
        resizable: true,
        minimizable: false,
        maximizable: true,
        closable: true,
        className: 'operator-location-window',
        onOpen: () => {
            void mountWorkbenchLocationWindowMap(entry, location);
        },
        onResize: resizeMap,
        onStateChange: resizeMap,
        onClose: () => {
            entry.closing = true;
            const closedWindow = entry.window;
            if (appState.runtime.operatorWorkbenchLocationWindow === entry) {
                appState.runtime.operatorWorkbenchLocationWindow = null;
            }
            entry.observer?.disconnect?.();
            entry.map?.destroy?.();
            entry.map = null;
            queueMicrotask(() => closedWindow?.destroy?.());
        },
    });

    appState.runtime.operatorWorkbenchLocationWindow = entry;
}

function bindWorkbenchLocationMapLauncher(container, payload = {}) {
    if (!container || container.dataset.openMapBound === '1') {
        return;
    }

    container.dataset.openMapBound = '1';

    const open = (event) => {
        event.preventDefault();
        void openWorkbenchLocationWindow(payload);
    };

    container.addEventListener('click', open);
    container.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        open(event);
    });
}

async function mountOrUpdateWorkbenchLocationMap(overlay, payload = {}) {
    const location = workbenchCallerLocation(payload);
    const container = overlay?.querySelector('[data-workbench-location-mini-map]');

    if (!container || !location) {
        destroyWorkbenchLocationMap();
        destroyWorkbenchLocationWindow();
        return null;
    }

    const current = appState.runtime.operatorWorkbenchLocationMap ?? null;
    bindWorkbenchLocationMapLauncher(container, payload);
    updateWorkbenchLocationWindow(payload);

    if (current?.container === container) {
        current.setLocation(location);
        requestAnimationFrame(() => current.resize?.());
        return current;
    }

    destroyWorkbenchLocationMap();

    const requestId = Number(appState.runtime.operatorWorkbenchLocationMapRequestId ?? 0) + 1;
    appState.runtime.operatorWorkbenchLocationMapRequestId = requestId;

    const nextMap = createWorkbenchLocationMap({ container });
    appState.runtime.operatorWorkbenchLocationMap = nextMap;

    let mounted = false;

    try {
        mounted = await nextMap.init(location);
    } catch (error) {
        console.warn('Unable to mount operator caller location mini map.', error);
    }

    if (appState.runtime.operatorWorkbenchLocationMapRequestId !== requestId || appState.runtime.operatorWorkbenchLocationMap !== nextMap) {
        nextMap.destroy?.();
        return null;
    }

    if (!mounted) {
        container.dataset.mapUnavailable = '1';
        destroyWorkbenchLocationMap(nextMap);
        return null;
    }

    nextMap.setLocation(location);
    requestAnimationFrame(() => nextMap.resize?.());
    return nextMap;
}

function renderWorkbench(payload, stateOverride = null) {
    const callState = workbenchCallState(payload, stateOverride);
    const isActive = callState === 'active';
    const callerName = escapeHtml(workbenchCallerName(payload));
    const callerMobile = escapeHtml(workbenchCallerMobile(payload));
    const duration = formatWorkbenchDuration(payload.called_at, payload.updated_at);
    const calledAt = formatWorkbenchDateTimeCompact(payload.called_at);
    const endedAt = formatWorkbenchDateTimeCompact(payload.updated_at);
    const locationMarkup = renderWorkbenchCallerLocationMarkup(payload);

    return `
        <div class="overlay-backdrop operator-workbench-backdrop" data-workbench-overlay>
            <section class="overlay-panel operator-workbench-modal" data-workbench-state="${callState}">
                <div class="operator-workbench-navbar-host" data-workbench-navbar></div>
                <div class="operator-workbench-body">
                    <section class="operator-workbench-column operator-workbench-report-column">
                        <div class="operator-workbench-card operator-workbench-report-id">
                            <span class="operator-workbench-card-label">Incident Number</span>
                            <strong>${escapeHtml(padIncidentId(payload.id ?? payload.display_id ?? '0'))}</strong>
                        </div>
                        <div class="operator-workbench-card operator-workbench-report-stats">
                            <article>
                                <span>Datetime Called</span>
                                <strong>${escapeHtml(calledAt)}</strong>
                            </article>
                            <article class="operator-workbench-report-duration">
                                <span>Duration</span>
                                <strong>${escapeHtml(duration)}</strong>
                            </article>
                            <article>
                                <span>Datetime Ended</span>
                                <strong>${escapeHtml(endedAt)}</strong>
                            </article>
                        </div>
                        <div class="operator-workbench-card operator-workbench-location-card">
                            <div class="operator-workbench-card-head">
                                <strong>Caller Location</strong>
                                <button class="operator-workbench-icon-button" type="button" data-edit-workbench-caller-address aria-label="Edit caller address" title="Edit caller address">
                                    ${workbenchEditIconMarkup()}
                                </button>
                            </div>
                            <p class="operator-workbench-location-text" data-workbench-location-text>${escapeHtml(locationMarkup.text)}</p>
                            <div class="operator-workbench-address" data-workbench-location-address>${locationMarkup.address}</div>
                            <div class="operator-workbench-map-placeholder" data-workbench-location-map>${locationMarkup.placeholder}</div>
                        </div>
                    </section>
                    <section class="operator-workbench-column operator-workbench-incident-column">
                        <div class="operator-workbench-helper-host" data-workbench-incident-types></div>
                    </section>
                    <section class="operator-workbench-column operator-workbench-dispatch-column">
                        <div class="operator-workbench-helper-host" data-workbench-team-assignments></div>
                    </section>
                    <section class="operator-workbench-column operator-workbench-media-column">
                        ${renderWorkbenchMediaColumnMarkup(isActive, callState)}
                    </section>
                    <section class="operator-workbench-column operator-workbench-chat-column">
                        <div class="operator-workbench-card operator-workbench-chat-card">
                            <div class="operator-workbench-card-head">
                                <strong>Caller Chat</strong>
                                <span>${callerMobile}</span>
                            </div>
                            <div class="operator-workbench-chat-thread" data-workbench-chat-thread></div>
                            <div class="operator-workbench-chat-composer${isActive ? '' : ' is-hidden'}" data-workbench-chat-composer-wrap>
                                <div data-workbench-chat-upload-queue></div>
                                <div data-workbench-chat-composer></div>
                            </div>
                        </div>
                    </section>
                </div>
            </section>
        </div>
    `;
}

function workbenchRelationshipOptions() {
    return (appState.bootstrap?.lookups?.caller_relationships ?? [])
        .map((label) => String(label ?? '').trim())
        .filter(Boolean)
        .map((label) => ({ value: label, label }));
}

function workbenchCallerRelationship(payload) {
    return String(payload?.actual_caller_relationship ?? 'Self').trim() || 'Self';
}

function buildWorkbenchNavbarContent(payload) {
    const wrapper = document.createElement('div');
    wrapper.className = 'operator-workbench-navbar-meta';
    wrapper.innerHTML = `
        <label class="operator-workbench-navbar-field">
            <small>Caller Name</small>
            <input class="ui-input operator-workbench-navbar-input" type="text" value="${escapeHtml(payload.actual_caller_name ?? '')}" placeholder="Caller name" data-workbench-actual-caller-name>
        </label>
        <div class="operator-workbench-navbar-field">
            <small>Caller Relationship</small>
            <div data-workbench-caller-relationship></div>
        </div>
    `;
    return wrapper;
}

function workbenchNavbarIcon(name) {
    const icons = {
        close: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7.8 6.4a1 1 0 0 1 1.4 0L12 9.2l2.8-2.8a1 1 0 1 1 1.4 1.4L13.4 10.6l2.8 2.8a1 1 0 0 1-1.4 1.4L12 12l-2.8 2.8a1 1 0 1 1-1.4-1.4l2.8-2.8-2.8-2.8a1 1 0 0 1 0-1.4Z" fill="currentColor"></path></svg>',
        defer: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Zm1 2.5a1 1 0 1 0-2 0V12c0 .27.11.52.3.71l2.4 2.4a1 1 0 0 0 1.4-1.42L13 11.58V8.5Z" fill="currentColor"></path></svg>',
        discard: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h6a1 1 0 0 1 1 1v1h4a1 1 0 1 1 0 2h-1l-.8 12.1A2 2 0 0 1 16.2 21H7.8a2 2 0 0 1-2-1.9L5 7H4a1 1 0 0 1 0-2h4V4a1 1 0 0 1 1-1Zm1 2h4v0h-4v0Zm-2.99 2 .8 12h8.38l.8-12H7.01ZM10 9a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0v-6a1 1 0 0 1 1-1Zm4 0a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0v-6a1 1 0 0 1 1-1Z" fill="currentColor"></path></svg>',
        endCall: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6.7 9.4c3.4-2.1 7.2-2.1 10.6 0 .8.5 1.2 1.4.9 2.3l-.7 2.2c-.2.6-.9 1-1.5.8l-2.7-.7a1.2 1.2 0 0 1-.9-1.2v-1.3a8.5 8.5 0 0 0-1 0v1.3c0 .55-.37 1.04-.9 1.18l-2.7.72c-.6.17-1.3-.18-1.5-.8l-.7-2.2c-.3-.9.1-1.8.9-2.3Zm11.6 7.9a1 1 0 0 1-1.4 0L12 12.4l-4.9 4.9a1 1 0 0 1-1.4-1.4l4.9-4.9-2.4-2.4a1 1 0 0 1 1.4-1.4L12 9.6l2.4-2.4a1 1 0 0 1 1.4 1.4L13.4 11l4.9 4.9a1 1 0 0 1 0 1.4Z" fill="currentColor"></path></svg>',
        resolved: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.5 16.6a1 1 0 0 1-.7-.3l-3.1-3.1a1 1 0 1 1 1.4-1.4l2.4 2.4 7.4-7.4a1 1 0 0 1 1.4 1.4l-8.1 8.1a1 1 0 0 1-.7.3Z" fill="currentColor"></path></svg>',
        transfer: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14.3 4.3a1 1 0 0 1 1.4 0l4 4a1 1 0 0 1 0 1.4l-4 4a1 1 0 1 1-1.4-1.4L16.6 10H5a1 1 0 1 1 0-2h11.6l-2.3-2.3a1 1 0 0 1 0-1.4ZM9.7 10.3a1 1 0 0 1 0 1.4L7.4 14H19a1 1 0 1 1 0 2H7.4l2.3 2.3a1 1 0 0 1-1.4 1.4l-4-4a1 1 0 0 1 0-1.4l4-4a1 1 0 0 1 1.4 0Z" fill="currentColor"></path></svg>',
    };

    return icons[name] ?? '';
}

function workbenchInactiveNavbarActions(payload) {
    const currentStatus = String(payload?.status ?? '').trim().toLowerCase();
    const canTransitionStatus = currentStatus === 'active' || currentStatus === 'deferred';
    const actions = [];

    if (canTransitionStatus) {
        actions.push({ id: 'transfer', label: 'Transfer', tone: 'transfer', icon: workbenchNavbarIcon('transfer') });
        actions.push({ id: 'discard', label: 'Discard', targetStatus: 'Discarded', tone: 'discarded', icon: workbenchNavbarIcon('discard') });

        if (currentStatus !== 'deferred') {
            actions.push({ id: 'defer', label: 'Defer', targetStatus: 'Deferred', tone: 'deferred', icon: workbenchNavbarIcon('defer') });
        }

        actions.push({ id: 'resolved', label: 'Resolved', targetStatus: 'Resolved', tone: 'resolved', icon: workbenchNavbarIcon('resolved') });
    }

    if (currentStatus !== 'active') {
        actions.push({ id: 'close', label: 'Close', tone: 'close', icon: workbenchNavbarIcon('close') });
    }

    return actions;
}

function applyWorkbenchNavbarActionClasses(host, actions) {
    const actionsHost = host?.querySelector?.('.operator-workbench-navbar .ui-navbar-actions');
    const buttons = actionsHost?.querySelectorAll?.('.ui-navbar-action');

    if (!buttons?.length) {
        return;
    }

    actionsHost.querySelectorAll('.operator-workbench-action-divider').forEach((divider) => divider.remove());

    buttons.forEach((button, index) => {
        const action = actions[index] ?? null;

        if (!action?.id) {
            return;
        }

        button.dataset.workbenchAction = String(action.id);
        button.classList.add('operator-workbench-action', `is-${String(action.tone ?? action.id).trim().toLowerCase()}`);

        if (action.id === 'transfer' || (action.id === 'close' && index > 0)) {
            const divider = document.createElement('span');
            divider.className = 'operator-workbench-action-divider';
            divider.setAttribute('aria-hidden', 'true');

            if (action.id === 'transfer') {
                button.after(divider);
            } else {
                button.before(divider);
            }
        }
    });
}

async function mountWorkbenchNavbar(overlay, payload, stateOverride, close) {
    const helper = await ensureHelperUi();
    const host = overlay?.querySelector('[data-workbench-navbar]');

    if (!host || !helper.createNavbar) {
        return null;
    }

    const callState = workbenchCallState(payload, stateOverride);
    const isActive = callState === 'active';
    const actions = isActive
        ? [{ id: 'end_call', label: 'End Call', tone: 'end-call', icon: workbenchNavbarIcon('endCall') }]
        : workbenchInactiveNavbarActions(payload);

    const brandAvatar = workbenchCallerAvatar(payload);
    const brandMedia = brandAvatar
        ? `<img class="operator-workbench-brand-avatar" src="${escapeHtml(brandAvatar)}" alt="${escapeHtml(workbenchCallerName(payload))}">`
        : `<span class="operator-workbench-brand-avatar operator-workbench-brand-avatar-fallback">${escapeHtml(workbenchCallerName(payload).slice(0, 1))}</span>`;

    const contentStart = buildWorkbenchNavbarContent(payload);
    const callerNameInput = contentStart.querySelector('.operator-workbench-navbar-input');
    const callerRelationshipHost = contentStart.querySelector('[data-workbench-caller-relationship]');
    let callerRelationshipSelect = null;
    let callerSaveTimerId = null;
    let callerSaveRequestId = 0;

    const clearCallerSaveTimer = () => {
        if (callerSaveTimerId !== null) {
            window.clearTimeout(callerSaveTimerId);
            callerSaveTimerId = null;
        }
    };

    const syncActualCallerPayload = (incident) => {
        if (!incident || typeof incident !== 'object') {
            return;
        }

        payload.actual_caller_name = String(incident.actual_caller_name ?? payload.actual_caller_name ?? '').trim();
        payload.actual_caller_relationship = String(incident.actual_caller_relationship ?? payload.actual_caller_relationship ?? 'Self').trim() || 'Self';

        if (callerNameInput) {
            callerNameInput.value = payload.actual_caller_name;
        }

        if (callerRelationshipSelect?.update) {
            callerRelationshipSelect.update(workbenchRelationshipOptions(), {
                className: 'operator-workbench-navbar-select',
                ariaLabel: 'Caller Relationship',
                placeholder: 'Select relationship',
                searchable: false,
                clearable: false,
                selected: [workbenchCallerRelationship(payload)],
                onChange: (value) => {
                    payload.actual_caller_relationship = value ? String(value) : 'Self';
                    queueActualCallerSave();
                },
            });
        }
    };

    const queueActualCallerSave = (delayMs = 350) => {
        clearCallerSaveTimer();

        callerSaveTimerId = window.setTimeout(async () => {
            callerSaveTimerId = null;
            const requestId = ++callerSaveRequestId;

            try {
                const response = await fetchJson(`/api/operator/incidents/${payload.id}/actual-citizen`, {
                    method: 'post',
                    data: {
                        actual_citizen_name: String(payload.actual_caller_name ?? '').trim(),
                        actual_citizen_relationship: String(payload.actual_caller_relationship ?? 'Self').trim() || 'Self',
                    },
                });

                if (requestId !== callerSaveRequestId) {
                    return;
                }

                if (response?.incident) {
                    syncActualCallerPayload(response.incident);
                    publishOperatorIncidentUpdate({
                        incident_id: Number(payload.id ?? 0),
                        caller_id: Number(payload.caller_id ?? response?.incident?.caller_id ?? 0),
                        scope: 'actual_caller',
                        patch: {
                            actual_caller_name: String(response?.incident?.actual_caller_name ?? payload.actual_caller_name ?? '').trim(),
                            actual_caller_relationship: String(response?.incident?.actual_caller_relationship ?? payload.actual_caller_relationship ?? 'Self').trim() || 'Self',
                        },
                    });
                }
            } catch (error) {
                if (requestId !== callerSaveRequestId) {
                    return;
                }

                showToast(error?.response?.data?.message ?? 'Unable to autosave caller details.', 'warn');
            }
        }, delayMs);
    };

    const navbar = helper.createNavbar(host, {}, {
        className: 'operator-workbench-navbar',
        brandText: workbenchCallerName(payload),
        brandSubtitle: workbenchCallerMobile(payload),
        brandMedia,
        contentStart: () => contentStart,
        items: [],
        actions,
        onNavigate: () => {},
        onAction: async (action) => {
            if (action?.id === 'close') {
                close();
                return;
            }

            if (action?.id === 'end_call') {
                try {
                    const activeCallSessionId = Number(deriveActiveCallSessionId(payload) ?? 0);
                    const endedAt = new Date().toISOString();
                    let response = null;

                    if (activeCallSessionId) {
                        try {
                            response = await fetchJson(`/api/operator/call-sessions/${activeCallSessionId}/hangup`, {
                                method: 'post',
                            });
                        } catch (error) {
                            if (Number(error?.response?.status ?? 0) !== 409) {
                                throw error;
                            }
                        }
                    }

                    if (activeCallSessionId) {
                        payload = patchIncidentCallSession(payload, activeCallSessionId, {
                            status: response?.call_session?.status ?? 'ended',
                            outcome: response?.call_session?.outcome ?? 'ended_by_operator',
                            ended_at: response?.call_session?.ended_at ?? endedAt,
                            updated_at: response?.call_session?.updated_at ?? endedAt,
                        });
                    }

                    const officialEndedAt = response?.call_session?.ended_at ?? endedAt;
                    appState.runtime.operatorWorkbenchCaptureManager?.setOfficialEndedAt?.(officialEndedAt);
                    void appState.runtime.operatorWorkbenchCaptureManager?.finalizeAll?.();

                    appState.runtime.operatorWorkbenchCallRuntime?.sendHangup?.({
                        reason: 'ended-by-operator',
                        ended_at: officialEndedAt,
                    });

                    await refreshWorkbenchOverlay(payload, null);
                } catch (error) {
                    showToast(error.response?.data?.message ?? 'Unable to end the active call.');
                }
                return;
            }

            if (action?.id === 'transfer') {
                await openOutboundTransferModal(currentOperatorRoot(), payload);
                return;
            }

            const statusMap = {
                discard: 'Discarded',
                defer: 'Deferred',
                resolved: 'Resolved',
            };
            const targetStatus = statusMap[action?.id] ?? null;
            const label = String(action?.label ?? formatStatusLabel(action?.id ?? 'Action'));
            let statusResponse = null;
            const confirmed = await appState.helper.uiConfirm(`Change incident to ${label}?`, {
                title: label,
                variant: action?.id === 'discard' ? 'warning' : 'info',
                confirmText: label,
                cancelText: 'Cancel',
                busyMessage: `${label}...`,
                onConfirm: async () => {
                    if (!targetStatus || !payload?.id) {
                        return true;
                    }

                    statusResponse = await fetchJson(`/api/operator/incidents/${payload.id}/status`, {
                        method: 'post',
                        data: {
                            status: targetStatus,
                        },
                    });

                    return true;
                },
            });

            if (confirmed) {
                if (statusResponse?.incident) {
                    payload = statusResponse.incident;
                    syncOperatorIncidentRails(currentOperatorRoot(), payload);
                    publishOperatorIncidentUpdate({
                        incident_id: Number(payload.id ?? 0),
                        caller_id: Number(payload.caller_id ?? 0),
                        scope: 'status',
                        patch: {
                            status: String(payload.status ?? targetStatus ?? ''),
                            resolved_at: payload.resolved_at ?? null,
                        },
                    });
                }

                close();
            }
        },
    });

    applyWorkbenchNavbarActionClasses(host, actions);

    const handleCallerNameInput = (event) => {
        payload.actual_caller_name = String(event?.target?.value ?? '').trimStart();
        queueActualCallerSave();
    };

    callerNameInput?.addEventListener('input', handleCallerNameInput);

    if (callerRelationshipHost && helper.createSelect) {
        callerRelationshipSelect = helper.createSelect(callerRelationshipHost, workbenchRelationshipOptions(), {
            className: 'operator-workbench-navbar-select',
            ariaLabel: 'Caller Relationship',
            placeholder: 'Select relationship',
            searchable: false,
            clearable: false,
            selected: [workbenchCallerRelationship(payload)],
            onChange: (value) => {
                payload.actual_caller_relationship = value ? String(value) : 'Self';
                queueActualCallerSave();
            },
        });
        callerRelationshipHost.__operatorCallerRelationshipSelect = callerRelationshipSelect;
    }

    return {
        destroy() {
            clearCallerSaveTimer();
            callerNameInput?.removeEventListener('input', handleCallerNameInput);
            if (callerRelationshipHost) {
                callerRelationshipHost.__operatorCallerRelationshipSelect = null;
            }
            callerRelationshipSelect?.destroy?.();
            navbar?.destroy?.();
        },
    };
}

async function mountWorkbenchHelpers(overlay, payload, stateOverride) {
    const helper = await ensureOperatorWorkbenchHelpers();
    const lookups = await loadSharedWorkbenchLookups();
    const instances = [];
    const callState = workbenchCallState(payload, stateOverride);
    const isActive = callState === 'active';
    const canEditIncidentDetails = workbenchIncidentEditable(payload);
    const incidentTypesHost = overlay?.querySelector('[data-workbench-incident-types]');
    const teamAssignmentsHost = overlay?.querySelector('[data-workbench-team-assignments]');
    const audioHost = overlay?.querySelector('[data-workbench-audio]');
    const mediaStripHost = overlay?.querySelector('[data-workbench-media-strip]');
    const callSessionTimelineHost = overlay?.querySelector('[data-workbench-call-session-timeline]');
    const videoPreviewHost = overlay?.querySelector('[data-workbench-video-preview]');
    const chatThreadHost = overlay?.querySelector('[data-workbench-chat-thread]');
    const chatComposerHost = overlay?.querySelector('[data-workbench-chat-composer]');
    const chatUploadQueueHost = overlay?.querySelector('[data-workbench-chat-upload-queue]');
    const locationMap = await mountOrUpdateWorkbenchLocationMap(overlay, payload);
    let callerGraphApi = null;
    let operatorGraphApi = null;
    let audioCallSessionApi = null;
    const incidentTypeCategories = Array.isArray(payload.incident_type_categories) && payload.incident_type_categories.length
        ? payload.incident_type_categories
        : lookups.incidentCategories;
    const incidentTypeCatalog = Array.isArray(payload.incident_type_catalog) && payload.incident_type_catalog.length
        ? payload.incident_type_catalog
        : lookups.incidentTypes;
    const teamCategories = Array.isArray(payload.team_categories) && payload.team_categories.length
        ? payload.team_categories
        : lookups.teamCategories;
    const teams = Array.isArray(payload.teams) && payload.teams.length
        ? payload.teams
        : lookups.teams;
    let incidentTypesApi = null;
    let teamAssignmentsApi = null;
    let mediaStripApi = null;
    let callSessionTimelineApi = null;
    const incidentTypeSaveTimers = new Map();
    const incidentTypeSaveRequestIds = new Map();
    const teamAssignmentSaveTimers = new Map();
    const callerAddressEditor = bindWorkbenchCallerAddressEditor(overlay, payload);
    if (callerAddressEditor) {
        instances.push(callerAddressEditor);
    }

    if (locationMap) {
        instances.push({
            destroy() {
                destroyWorkbenchLocationMap();
                destroyWorkbenchLocationWindow();
            },
        });
    }

    const cloneWorkbenchValue = (value) => {
        if (typeof structuredClone === 'function') {
            return structuredClone(value);
        }

        return JSON.parse(JSON.stringify(value));
    };

    const syncIncidentTypesPayload = (nextList) => {
        const incidentTypes = Array.isArray(nextList)
            ? cloneWorkbenchValue(nextList)
            : [];

        payload.incident_types = incidentTypes.map((item) => ({
            ...item,
            id: item?.incident_type_id ?? item?.id ?? null,
            incident_type_id: item?.incident_type_id ?? item?.id ?? null,
            category_id: item?.category_id ?? item?.incident_type_category_id ?? null,
            category_name: item?.category_name ?? item?.incident_type_category_name ?? '',
            pivot: {
                id: item?.pivot?.id ?? item?.id ?? null,
            },
            detail_entries: Array.isArray(item?.detail_entries) ? item.detail_entries : [],
            resources_needed: Array.isArray(item?.resources_needed) ? item.resources_needed : [],
        }));

        payload.incident_type_details = incidentTypes.flatMap((item) => (
            Array.isArray(item?.detail_entries)
                ? item.detail_entries.map((entry) => ({
                    ...entry,
                    incident_type_id: entry?.incident_type_id ?? item?.incident_type_id ?? item?.id ?? null,
                }))
                : []
        ));

        payload.incident_resources_needed = incidentTypes.flatMap((item) => (
            Array.isArray(item?.resources_needed)
                ? item.resources_needed.map((entry) => ({
                    ...entry,
                    incident_type_id: entry?.incident_type_id ?? item?.incident_type_id ?? item?.id ?? null,
                }))
                : []
        ));
    };

    const buildIncidentTypesData = () => ({
        id: payload.id,
        incident_types: Array.isArray(payload.incident_types) ? payload.incident_types : [],
        detail_entries: Array.isArray(payload.incident_type_details) ? payload.incident_type_details : [],
        resources_needed: Array.isArray(payload.incident_resources_needed) ? payload.incident_resources_needed : [],
    });

    const updateIncidentTypesView = () => {
        incidentTypesApi?.update?.(buildIncidentTypesData(), buildIncidentTypesOptions());
    };

    const mergeIncidentTypeItem = (currentItem, nextItem) => {
        const mergedCurrent = currentItem && typeof currentItem === 'object'
            ? cloneWorkbenchValue(currentItem)
            : {};
        const mergedNext = nextItem && typeof nextItem === 'object'
            ? cloneWorkbenchValue(nextItem)
            : {};

        return {
            ...mergedCurrent,
            ...mergedNext,
            fields: Array.isArray(mergedNext?.fields) && mergedNext.fields.length
                ? cloneWorkbenchValue(mergedNext.fields)
                : cloneWorkbenchValue(mergedCurrent?.fields ?? []),
            resources: Array.isArray(mergedNext?.resources) && mergedNext.resources.length
                ? cloneWorkbenchValue(mergedNext.resources)
                : cloneWorkbenchValue(mergedCurrent?.resources ?? []),
            detail_entries: Array.isArray(mergedNext?.detail_entries)
                ? cloneWorkbenchValue(mergedNext.detail_entries)
                : cloneWorkbenchValue(mergedCurrent?.detail_entries ?? []),
            resources_needed: Array.isArray(mergedNext?.resources_needed)
                ? cloneWorkbenchValue(mergedNext.resources_needed)
                : cloneWorkbenchValue(mergedCurrent?.resources_needed ?? []),
        };
    };

    const upsertIncidentTypeItem = (nextItem) => {
        if (!nextItem || typeof nextItem !== 'object') {
            return;
        }

        const normalizedItem = cloneWorkbenchValue(nextItem);
        const currentIncidentTypes = Array.isArray(payload.incident_types)
            ? payload.incident_types
            : [];
        const nextItemId = normalizedItem?.id ?? normalizedItem?._client_key;
        const nextIncidentTypeId = normalizedItem?.incident_type_id ?? normalizedItem?.id ?? null;
        let replaced = false;

        const nextIncidentTypes = currentIncidentTypes.map((item) => {
            const currentItemId = item?.id ?? item?._client_key;
            const currentIncidentTypeId = item?.incident_type_id ?? item?.id ?? null;

            if (String(currentItemId) === String(nextItemId)) {
                replaced = true;
                return mergeIncidentTypeItem(item, normalizedItem);
            }

            if (String(currentIncidentTypeId ?? '') === String(nextIncidentTypeId ?? '')) {
                replaced = true;
                return mergeIncidentTypeItem(item, normalizedItem);
            }

            return item;
        });

        if (!replaced) {
            nextIncidentTypes.push(normalizedItem);
        }

        syncIncidentTypesPayload(nextIncidentTypes);
    };

    const removeIncidentTypeItem = (incidentTypeData) => {
        const currentIncidentTypes = Array.isArray(payload.incident_types)
            ? payload.incident_types
            : [];
        const itemId = incidentTypeData?.id ?? incidentTypeData?._client_key ?? null;
        const incidentTypeId = incidentTypeData?.incident_type_id ?? incidentTypeData?.id ?? null;

        syncIncidentTypesPayload(currentIncidentTypes.filter((item) => {
            const currentItemId = item?.id ?? item?._client_key ?? null;
            const currentIncidentTypeId = item?.incident_type_id ?? item?.id ?? null;

            if (itemId !== null && String(currentItemId) === String(itemId)) {
                return false;
            }

            if (incidentTypeId !== null && String(currentIncidentTypeId) === String(incidentTypeId)) {
                return false;
            }

            return true;
        }));
    };

    const upsertIncidentTypeDetailEntry = (detail) => {
        if (!detail || typeof detail !== 'object') {
            return;
        }

        const currentIncidentTypes = Array.isArray(payload.incident_types)
            ? cloneWorkbenchValue(payload.incident_types)
            : [];
        const nextIncidentTypeId = detail?.incident_type_id ?? null;
        const nextFieldId = detail?.field_id ?? null;
        const nextFieldKey = String(detail?.field_key ?? '').trim();

        syncIncidentTypesPayload(currentIncidentTypes.map((item) => {
            const currentIncidentTypeId = item?.incident_type_id ?? item?.id ?? null;

            if (String(currentIncidentTypeId ?? '') !== String(nextIncidentTypeId ?? '')) {
                return item;
            }

            const detailEntries = Array.isArray(item?.detail_entries) ? item.detail_entries : [];
            let replaced = false;
            const nextDetailEntries = detailEntries.map((entry) => {
                const currentFieldId = entry?.field_id ?? null;
                const currentFieldKey = String(entry?.field_key ?? '').trim();

                if (
                    (nextFieldId !== null && String(currentFieldId ?? '') === String(nextFieldId))
                    || (nextFieldKey !== '' && currentFieldKey === nextFieldKey)
                ) {
                    replaced = true;
                    return cloneWorkbenchValue(detail);
                }

                return entry;
            });

            if (!replaced) {
                nextDetailEntries.push(cloneWorkbenchValue(detail));
            }

            return {
                ...item,
                detail_entries: nextDetailEntries,
            };
        }));
    };

    const removeIncidentTypeDetailEntry = (incidentTypeId, fieldKey, fieldId = null) => {
        const normalizedFieldKey = String(fieldKey ?? '').trim();
        const currentIncidentTypes = Array.isArray(payload.incident_types)
            ? cloneWorkbenchValue(payload.incident_types)
            : [];

        syncIncidentTypesPayload(currentIncidentTypes.map((item) => {
            const currentIncidentTypeId = item?.incident_type_id ?? item?.id ?? null;

            if (String(currentIncidentTypeId ?? '') !== String(incidentTypeId ?? '')) {
                return item;
            }

            return {
                ...item,
                detail_entries: (Array.isArray(item?.detail_entries) ? item.detail_entries : []).filter((entry) => {
                    if (fieldId !== null && String(entry?.field_id ?? '') === String(fieldId)) {
                        return false;
                    }

                    if (normalizedFieldKey !== '' && String(entry?.field_key ?? '').trim() === normalizedFieldKey) {
                        return false;
                    }

                    return true;
                }),
            };
        }));
    };

    const upsertIncidentTypeResourceEntry = (resource) => {
        if (!resource || typeof resource !== 'object') {
            return;
        }

        const currentIncidentTypes = Array.isArray(payload.incident_types)
            ? cloneWorkbenchValue(payload.incident_types)
            : [];
        const nextIncidentTypeId = resource?.incident_type_id ?? null;
        const nextResourceTypeId = resource?.resource_type_id ?? null;

        syncIncidentTypesPayload(currentIncidentTypes.map((item) => {
            const currentIncidentTypeId = item?.incident_type_id ?? item?.id ?? null;

            if (String(currentIncidentTypeId ?? '') !== String(nextIncidentTypeId ?? '')) {
                return item;
            }

            const resourcesNeeded = Array.isArray(item?.resources_needed) ? item.resources_needed : [];
            let replaced = false;
            const nextResourcesNeeded = resourcesNeeded.map((entry) => {
                if (String(entry?.resource_type_id ?? '') === String(nextResourceTypeId ?? '')) {
                    replaced = true;
                    return cloneWorkbenchValue(resource);
                }

                return entry;
            });

            if (!replaced) {
                nextResourcesNeeded.push(cloneWorkbenchValue(resource));
            }

            return {
                ...item,
                resources_needed: nextResourcesNeeded,
            };
        }));
    };

    const removeIncidentTypeResourceEntry = (incidentTypeId, resourceTypeId) => {
        const currentIncidentTypes = Array.isArray(payload.incident_types)
            ? cloneWorkbenchValue(payload.incident_types)
            : [];

        syncIncidentTypesPayload(currentIncidentTypes.map((item) => {
            const currentIncidentTypeId = item?.incident_type_id ?? item?.id ?? null;

            if (String(currentIncidentTypeId ?? '') !== String(incidentTypeId ?? '')) {
                return item;
            }

            return {
                ...item,
                resources_needed: (Array.isArray(item?.resources_needed) ? item.resources_needed : []).filter((entry) => (
                    String(entry?.resource_type_id ?? '') !== String(resourceTypeId ?? '')
                )),
            };
        }));
    };

    const syncTeamAssignmentsPayload = (nextList) => {
        payload.team_assignments = Array.isArray(nextList)
            ? cloneWorkbenchValue(nextList)
            : [];
    };

    const updateTeamAssignmentsView = () => {
        teamAssignmentsApi?.update?.({
            team_assignments: Array.isArray(payload.team_assignments) ? payload.team_assignments : [],
        }, buildTeamAssignmentsOptions());
    };

    const upsertTeamAssignment = (assignment) => {
        if (!assignment || typeof assignment !== 'object') {
            return;
        }

        const nextAssignment = cloneWorkbenchValue(assignment);
        const currentAssignments = Array.isArray(payload.team_assignments)
            ? payload.team_assignments
            : [];
        const nextAssignmentId = nextAssignment?.id ?? nextAssignment?._client_key;
        const nextIncidentId = nextAssignment?.incident_id ?? payload.id ?? null;
        const nextTeamId = nextAssignment?.team_id ?? nextAssignment?.team?.id ?? null;
        let replaced = false;

        const nextAssignments = currentAssignments.map((item) => {
            const currentAssignmentId = item?.id ?? item?._client_key;
            const currentIncidentId = item?.incident_id ?? payload.id ?? null;
            const currentTeamId = item?.team_id ?? item?.team?.id ?? null;

            if (String(currentAssignmentId) === String(nextAssignmentId)) {
                replaced = true;
                return nextAssignment;
            }

            // Helper adds an optimistic unsaved item first. When the server returns
            // the canonical saved assignment, reconcile by incident/team identity.
            if (
                item?.id == null
                && nextAssignment?.id != null
                && String(currentIncidentId ?? '') === String(nextIncidentId ?? '')
                && String(currentTeamId ?? '') === String(nextTeamId ?? '')
            ) {
                replaced = true;
                return nextAssignment;
            }

            return item;
        });

        if (!replaced) {
            nextAssignments.push(nextAssignment);
        }

        syncTeamAssignmentsPayload(nextAssignments);
        updateTeamAssignmentsView();
        syncOperatorActiveIncidentAssignments(currentOperatorRoot(), payload.id, payload.team_assignments);
    };

    const removeTeamAssignment = (assignmentId) => {
        const currentAssignments = Array.isArray(payload.team_assignments)
            ? payload.team_assignments
            : [];

        syncTeamAssignmentsPayload(currentAssignments.filter((item) => (
            String(item?.id ?? item?._client_key) !== String(assignmentId)
        )));
        updateTeamAssignmentsView();
        syncOperatorActiveIncidentAssignments(currentOperatorRoot(), payload.id, payload.team_assignments);
    };

    const clearTeamAssignmentSaveTimers = () => {
        teamAssignmentSaveTimers.forEach((timeoutId) => window.clearTimeout(timeoutId));
        teamAssignmentSaveTimers.clear();
    };

    const clearIncidentTypeSaveTimers = () => {
        incidentTypeSaveTimers.forEach((timeoutId) => window.clearTimeout(timeoutId));
        incidentTypeSaveTimers.clear();
    };

    const invalidateIncidentTypeSaveKeys = (matcher) => {
        incidentTypeSaveRequestIds.forEach((currentRequestId, key) => {
            if (matcher(key)) {
                incidentTypeSaveRequestIds.set(key, Number(currentRequestId ?? 0) + 1);
            }
        });
    };

    const clearIncidentTypeSaveTimersFor = (incidentTypeId) => {
        const normalizedIncidentTypeId = String(incidentTypeId ?? '');

        incidentTypeSaveTimers.forEach((timeoutId, key) => {
            if (key.startsWith(`incident-type:${normalizedIncidentTypeId}:`)) {
                window.clearTimeout(timeoutId);
                incidentTypeSaveTimers.delete(key);
            }
        });

        invalidateIncidentTypeSaveKeys((key) => key.startsWith(`incident-type:${normalizedIncidentTypeId}:`));
    };

    const queueIncidentTypeSave = (saveKey, task, delayMs = 350) => {
        const normalizedKey = String(saveKey ?? '');

        if (!normalizedKey) {
            return;
        }

        if (incidentTypeSaveTimers.has(normalizedKey)) {
            window.clearTimeout(incidentTypeSaveTimers.get(normalizedKey));
        }

        const timeoutId = window.setTimeout(async () => {
            incidentTypeSaveTimers.delete(normalizedKey);
            const requestId = Number(incidentTypeSaveRequestIds.get(normalizedKey) ?? 0) + 1;
            incidentTypeSaveRequestIds.set(normalizedKey, requestId);

            const isCurrent = () => Number(incidentTypeSaveRequestIds.get(normalizedKey) ?? 0) === requestId;

            try {
                await task(isCurrent);
            } catch (error) {
                if (!isCurrent()) {
                    return;
                }

                console.warn('Unable to autosave incident type update.', error);
                showToast(error?.response?.data?.message ?? 'Unable to autosave incident type changes.', 'warn');
            }
        }, delayMs);

        incidentTypeSaveTimers.set(normalizedKey, timeoutId);
    };

    const queueTeamAssignmentSave = (key, task, delayMs = 350) => {
        const timerKey = String(key ?? '');

        if (teamAssignmentSaveTimers.has(timerKey)) {
            window.clearTimeout(teamAssignmentSaveTimers.get(timerKey));
        }

        const timeoutId = window.setTimeout(async () => {
            teamAssignmentSaveTimers.delete(timerKey);

            try {
                await task();
            } catch (error) {
                console.warn('Unable to autosave team assignment update.', error);
                showToast(error?.response?.data?.message ?? 'Unable to autosave dispatch changes.', 'warn');
            }
        }, delayMs);

        teamAssignmentSaveTimers.set(timerKey, timeoutId);
    };

    const refreshTeamAssignments = async () => {
        const nextPayload = await fetchJson(`/api/operator/incidents/${payload.id}`);

        if (!nextPayload || typeof nextPayload !== 'object') {
            return;
        }

        payload.team_assignments = Array.isArray(nextPayload.team_assignments)
            ? nextPayload.team_assignments
            : [];

        updateTeamAssignmentsView();
        syncOperatorActiveIncidentAssignments(currentOperatorRoot(), payload.id, payload.team_assignments);
    };

    const publishTeamAssignmentsUpdate = () => {
        publishOperatorIncidentUpdate({
            incident_id: Number(payload.id ?? 0),
            caller_id: Number(payload.caller_id ?? 0),
            scope: 'team_assignments',
            patch: {
                team_assignments: cloneWorkbenchValue(payload.team_assignments ?? []),
            },
        });
    };

    const buildTeamAssignmentResourcePayload = (assignmentId, resourceTypeId, quantityAllocated) => {
        const assignment = (Array.isArray(payload.team_assignments) ? payload.team_assignments : [])
            .find((item) => Number(item?.id ?? 0) === Number(assignmentId));
        const quantities = new Map();

        (Array.isArray(assignment?.allocated_resources) ? assignment.allocated_resources : []).forEach((item) => {
            const nextResourceTypeId = Number(item?.resource_type_id ?? 0);
            const nextQuantity = Number(item?.quantity_allocated ?? 0);

            if (nextResourceTypeId > 0 && nextQuantity > 0) {
                quantities.set(nextResourceTypeId, nextQuantity);
            }
        });

        if (Number(resourceTypeId) > 0) {
            const nextQuantity = Number(quantityAllocated ?? 0);

            if (nextQuantity > 0) {
                quantities.set(Number(resourceTypeId), nextQuantity);
            } else {
                quantities.delete(Number(resourceTypeId));
            }
        }

        return Array.from(quantities.entries()).map(([nextResourceTypeId, nextQuantity]) => ({
            resource_type_id: nextResourceTypeId,
            quantity_allocated: nextQuantity,
        }));
    };

    const buildTeamAssignmentsOptions = () => ({
        editable: canEditIncidentDetails,
        headerText: 'Dispatch',
        categories: teamCategories,
        teams,
        noticeAlreadyExist: () => {},
        incident_id: payload.id ?? 0,
        operator_id: appState.bootstrap?.user?.id ?? 0,
        className: 'operator-workbench-assignment-helper',
        showModalMessage(message) {
            showToast(String(message ?? ''), 'warn');
        },
        onItemChange(nextItem, meta) {
            if (!nextItem || meta?.reason === 'remove') {
                return;
            }

            const currentAssignments = Array.isArray(payload.team_assignments)
                ? payload.team_assignments
                : [];
            const nextAssignmentId = nextItem?.id ?? nextItem?._client_key;
            const nextAssignments = currentAssignments.map((item) => {
                const currentAssignmentId = item?.id ?? item?._client_key;

                return String(currentAssignmentId) === String(nextAssignmentId)
                    ? cloneWorkbenchValue(nextItem)
                    : item;
            });

            syncTeamAssignmentsPayload(nextAssignments);
        },
        onChange(nextList) {
            syncTeamAssignmentsPayload(nextList);
        },
        allowContactEditAfterDispatch: true,
        confirmDelete: async () => appState.helper.uiConfirm('Remove this team assignment?', {
            title: 'Remove Assignment',
            variant: 'warning',
            confirmText: 'Remove Assignment',
            confirmVariant: 'danger',
            cancelText: 'Keep Assignment',
            busyMessage: 'Removing assignment...',
        }),
        onAssignTeam: async (assignment) => {
            const response = await fetchJson(`/api/operator/incidents/${payload.id}/team-assignments`, {
                method: 'post',
                data: {
                    team_id: assignment?.team_id,
                    contact_person: assignment?.contact_person ?? null,
                    resources: [],
                },
            });

            if (response?.assignment) {
                upsertTeamAssignment(response.assignment);
                publishTeamAssignmentsUpdate();
                return;
            }

            await refreshTeamAssignments();
            publishTeamAssignmentsUpdate();
        },
        onDelete: async (assignmentId) => {
            await fetchJson(`/api/operator/team-assignments/${assignmentId}`, {
                method: 'delete',
            });
            removeTeamAssignment(assignmentId);
            publishTeamAssignmentsUpdate();
        },
        requestCancelReason: async (fromStatus, meta = {}) => {
            if (typeof appState.helper.createReasonFormModal !== 'function') {
                return null;
            }

            return await new Promise((resolve) => {
                let settled = false;
                let submittedReason = null;

                const finish = (value) => {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    resolve(value);
                };

                const modal = appState.helper.createReasonFormModal({
                    title: 'Cancel Assignment',
                    message: `Provide a reason before cancelling this assignment from ${formatStatusLabel(fromStatus)}.`,
                    submitLabel: 'Continue',
                    busyMessage: 'Saving reason...',
                    reasonOptions: Array.isArray(meta?.reasonOptions) ? meta.reasonOptions : [],
                    reasonLabel: 'Cancellation Reason',
                    detailsLabel: 'Details',
                    detailsPlaceholder: 'Add details when needed.',
                    detailsRequiredFor: ['other'],
                    detailsRequiredMessage: 'Details are required when the reason is Other.',
                    initialValues: {
                        reasonCode: '',
                        reasonDetails: '',
                    },
                    async onSubmit(values) {
                        submittedReason = {
                            reasonCode: String(values?.reasonCode ?? '').trim(),
                            reasonNote: String(values?.reasonDetails ?? '').trim(),
                        };
                        return true;
                    },
                    onClose(meta) {
                        if (meta?.reason === 'submit') {
                            finish(submittedReason);
                            return;
                        }

                        finish(null);
                    },
                });

                modal.open();
            });
        },
        confirmCancel: async () => true,
        onCancel: async (assignmentId, _fromStatus, reasonCode, reasonNote) => {
            const response = await fetchJson(`/api/operator/team-assignments/${assignmentId}`, {
                method: 'post',
                data: {
                    status: 'Cancelled',
                    cancel_reason_code: reasonCode,
                    cancel_reason_note: String(reasonNote ?? '').trim() || null,
                },
            });

            if (response?.assignment) {
                upsertTeamAssignment(response.assignment);
                publishTeamAssignmentsUpdate();
                return;
            }

            await refreshTeamAssignments();
            publishTeamAssignmentsUpdate();
        },
        confirmStatus: async (nextStatus) => {
            const label = formatStatusLabel(String(nextStatus ?? '').replace(/_/g, ' '));

            return appState.helper.uiConfirm(`Mark this assignment as ${label}?`, {
                title: label,
                variant: 'info',
                confirmText: `Mark ${label}`,
                cancelText: 'Cancel',
                busyMessage: `${label}...`,
            });
        },
        onStatusNext: async (assignmentId, nextStatus) => {
            const response = await fetchJson(`/api/operator/team-assignments/${assignmentId}`, {
                method: 'post',
                data: {
                    status: mapTeamAssignmentStatusToApi(nextStatus),
                },
            });

            if (response?.assignment) {
                upsertTeamAssignment(response.assignment);
                publishTeamAssignmentsUpdate();
                return;
            }

            await refreshTeamAssignments();
            publishTeamAssignmentsUpdate();
        },
        onAllocateChange: (assignmentId, resourceTypeId, quantityAllocated) => {
            queueTeamAssignmentSave(`allocation:${assignmentId}`, async () => {
                const response = await fetchJson(`/api/operator/team-assignments/${assignmentId}`, {
                    method: 'post',
                    data: {
                        resources: buildTeamAssignmentResourcePayload(assignmentId, resourceTypeId, quantityAllocated),
                    },
                });

                if (response?.assignment) {
                    upsertTeamAssignment(response.assignment);
                    publishTeamAssignmentsUpdate();
                    return;
                }

                await refreshTeamAssignments();
                publishTeamAssignmentsUpdate();
            });
        },
        onContactChange: (assignmentId, contactPerson) => {
            queueTeamAssignmentSave(`contact:${assignmentId}`, async () => {
                const response = await fetchJson(`/api/operator/team-assignments/${assignmentId}`, {
                    method: 'post',
                    data: {
                        contact_person: String(contactPerson ?? '').trim() || null,
                    },
                });

                if (response?.assignment) {
                    upsertTeamAssignment(response.assignment);
                    publishTeamAssignmentsUpdate();
                    return;
                }

                await refreshTeamAssignments();
                publishTeamAssignmentsUpdate();
            });
        },
        onNoteAdd: async (assignmentId, note) => {
            const response = await fetchJson(`/api/operator/team-assignments/${assignmentId}/notes`, {
                method: 'post',
                data: {
                    note: String(note ?? '').trim(),
                },
            });

            if (response?.assignment) {
                upsertTeamAssignment(response.assignment);
                publishTeamAssignmentsUpdate();
                return;
            }

            await refreshTeamAssignments();
            publishTeamAssignmentsUpdate();
        },
    });

    const mediaStripOptions = () => ({
        className: 'operator-workbench-media-strip',
        layout: 'scroll',
        showViewerAudiograph: true,
        viewerAudiographStyle: currentAudioGraphStyle(),
        showViewerFooter: false,
    });

    const audioCallSessionOptions = () => ({
        className: 'operator-workbench-audio-session',
        autoplay: false,
        showMute: true,
        audiographStyle: currentAudioGraphStyle(),
    });

    const renderMediaStrip = () => {
        if (!mediaStripHost) {
            return;
        }

        if (!helper.createMediaStrip) {
            mediaStripApi?.destroy?.();
            mediaStripApi = null;
            return;
        }

        const items = normalizeWorkbenchMediaStripItems(payload.media);
        const options = mediaStripOptions();

        if (items.length === 0) {
            mediaStripApi?.destroy?.();
            mediaStripApi = null;
            mediaStripHost.replaceChildren();
            return;
        }

        if (mediaStripApi?.update) {
            mediaStripApi.update(items, options);
            return;
        }

        mediaStripHost.replaceChildren();
        mediaStripApi = helper.createMediaStrip(mediaStripHost, items, options);
    };

    const renderAudioCallSession = () => {
        if (isActive || !audioHost || !helper.createAudioCallSession) {
            return;
        }

        const nextPayload = buildWorkbenchAudioSessionPayload(payload);
        const options = audioCallSessionOptions();

        if (audioCallSessionApi?.update) {
            void audioCallSessionApi.update(nextPayload, options);
            return;
        }

        audioHost.replaceChildren();
        audioCallSessionApi = helper.createAudioCallSession(audioHost, nextPayload, options);
    };

    const mountCallSessionTimelineContent = (host, item) => {
        let audioApi = null;
        let stripApi = null;

        const render = (nextItem) => {
            const session = nextItem?.session ?? {};
            const sessionMedia = Array.isArray(nextItem?.media) ? nextItem.media : [];
            const audioMedia = sessionMedia.filter(isWorkbenchAudioMediaItem);
            const visualMedia = sessionMedia.filter(isWorkbenchVisualMediaItem);

            if (!host.querySelector('[data-call-session-audio]')) {
                host.innerHTML = `
                    <article class="operator-workbench-call-session-card">
                        <div class="operator-workbench-call-session-audio" data-call-session-audio></div>
                        <div class="operator-workbench-call-session-strip" data-call-session-strip></div>
                    </article>
                `;
            }

            const audioTarget = host.querySelector('[data-call-session-audio]');
            const stripTarget = host.querySelector('[data-call-session-strip]');

            if (audioTarget && helper.createAudioCallSession) {
                if (audioMedia.length === 0) {
                    audioApi?.destroy?.();
                    audioApi = null;
                    audioTarget.innerHTML = '<p class="operator-workbench-call-session-empty">No audio recording available yet.</p>';
                } else {
                    const audioPayload = buildWorkbenchAudioSessionPayload(payload, audioMedia);
                    const options = audioCallSessionOptions();

                    if (audioApi?.update) {
                        void audioApi.update(audioPayload, options);
                    } else {
                        audioTarget.replaceChildren();
                        audioApi = helper.createAudioCallSession(audioTarget, audioPayload, options);
                    }
                }
            }

            if (stripTarget && helper.createMediaStrip) {
                const items = normalizeWorkbenchMediaStripItems(visualMedia);

                if (items.length === 0) {
                    stripApi?.destroy?.();
                    stripApi = null;
                    stripTarget.replaceChildren();
                } else {
                    const options = mediaStripOptions();

                    if (stripApi?.update) {
                        stripApi.update(items, options);
                    } else {
                        stripTarget.replaceChildren();
                        stripApi = helper.createMediaStrip(stripTarget, items, options);
                    }
                }
            }
        };

        render(item);

        return {
            update(nextItem) {
                render(nextItem);
            },
            destroy() {
                audioApi?.destroy?.();
                stripApi?.destroy?.();
                audioApi = null;
                stripApi = null;
            },
        };
    };

    const renderCallSessionTimeline = () => {
        if (isActive || !callSessionTimelineHost) {
            return;
        }

        const items = workbenchCallSessionTimelineItems(payload);

        if (!helper.createTimeline) {
            callSessionTimelineApi?.destroy?.();
            callSessionTimelineApi = null;
            callSessionTimelineHost.innerHTML = items.length
                ? workbenchCallSessionsMarkup(payload.call_history)
                : '<div class="operator-workbench-empty">No call sessions recorded yet.</div>';
            return;
        }

        const options = {
            ariaLabel: 'Incident call sessions timeline',
            className: 'operator-workbench-call-timeline',
            density: 'compact',
            emptyText: 'No call sessions recorded yet.',
            groupByDate: true,
            mountItemContent: mountCallSessionTimelineContent,
        };

        if (callSessionTimelineApi?.update) {
            callSessionTimelineApi.update(items, options);
            return;
        }

        callSessionTimelineHost.replaceChildren();
        callSessionTimelineApi = helper.createTimeline(callSessionTimelineHost, items, options);
    };

    const syncWorkbenchMediaViews = (changedMedia = null) => {
        const changedItems = Array.isArray(changedMedia)
            ? changedMedia
            : (changedMedia && typeof changedMedia === 'object' ? [changedMedia] : []);

        if (!isActive) {
            renderCallSessionTimeline();
            return;
        }

        if (changedItems.length === 0) {
            renderMediaStrip();
            renderAudioCallSession();
            return;
        }

        if (changedItems.some(isWorkbenchVisualMediaItem)) {
            renderMediaStrip();
        }

        if (changedItems.some(isWorkbenchAudioMediaItem)) {
            renderAudioCallSession();
        }
    };

    appState.runtime.operatorWorkbench = {
        payload,
        applyMediaEvent(nextMedia) {
            if (!nextMedia || typeof nextMedia !== 'object') {
                return;
            }

            payload.media = mergeIncidentMediaItems(payload.media, nextMedia);
            this.payload = payload;
            syncWorkbenchMediaViews(nextMedia);
        },
        syncMediaViews: syncWorkbenchMediaViews,
    };

    const buildIncidentTypesOptions = () => ({
        editable: canEditIncidentDetails,
        headerText: 'Incident Types',
        categories: incidentTypeCategories,
        incidentTypes: incidentTypeCatalog,
        lookups: {
            resourceTypes: lookups.resourceTypes,
        },
        confirmRemoveIncidentType: async (incidentTypeData) => appState.helper.uiConfirm(
            `Remove ${String(incidentTypeData?.name ?? 'this incident type')} from the incident?`,
            {
                title: 'Remove Incident Type',
                variant: 'warning',
                confirmText: 'Remove Incident',
                confirmVariant: 'danger',
                cancelText: 'Keep Incident',
                busyMessage: 'Removing incident type...',
            },
        ),
        onItemChange(nextItem, meta) {
            if (!nextItem) {
                return;
            }

            const incidentTypeId = Number(nextItem?.incident_type_id ?? nextItem?.id ?? 0);

            if (meta?.reason === 'remove') {
                const previousItem = cloneWorkbenchValue(nextItem);

                clearIncidentTypeSaveTimersFor(incidentTypeId);
                removeIncidentTypeItem(nextItem);

                fetchJson(`/api/operator/incidents/${payload.id}/incident-types/${incidentTypeId}`, {
                    method: 'delete',
                }).then(() => {
                    publishOperatorIncidentUpdate({
                        incident_id: Number(payload.id ?? 0),
                        caller_id: Number(payload.caller_id ?? 0),
                        scope: 'incident_types',
                        patch: {
                            incident_types: cloneWorkbenchValue(payload.incident_types ?? []),
                            incident_type_details: cloneWorkbenchValue(payload.incident_type_details ?? []),
                            incident_resources_needed: cloneWorkbenchValue(payload.incident_resources_needed ?? []),
                        },
                    });
                }).catch((error) => {
                    upsertIncidentTypeItem(previousItem);
                    updateIncidentTypesView();
                    console.warn('Unable to remove incident type.', error);
                    showToast(error?.response?.data?.message ?? 'Unable to remove incident type.', 'warn');
                });
                return;
            }

            upsertIncidentTypeItem(nextItem);

            if (meta?.reason === 'add') {
                fetchJson(`/api/operator/incidents/${payload.id}/incident-types/${incidentTypeId}`, {
                    method: 'post',
                }).then((response) => {
                    if (response?.incident_type) {
                        upsertIncidentTypeItem(response.incident_type);
                        updateIncidentTypesView();
                        publishOperatorIncidentUpdate({
                            incident_id: Number(payload.id ?? 0),
                            caller_id: Number(payload.caller_id ?? 0),
                            scope: 'incident_types',
                            patch: {
                                incident_types: cloneWorkbenchValue(payload.incident_types ?? []),
                                incident_type_details: cloneWorkbenchValue(payload.incident_type_details ?? []),
                                incident_resources_needed: cloneWorkbenchValue(payload.incident_resources_needed ?? []),
                            },
                        });
                    }
                }).catch((error) => {
                    console.warn('Unable to attach incident type.', error);
                    showToast(error?.response?.data?.message ?? 'Unable to add incident type.', 'warn');
                });
                return;
            }

            if (meta?.reason === 'field') {
                const fieldKey = String(meta?.fieldKey ?? '').trim();
                const detailEntry = (Array.isArray(nextItem?.detail_entries) ? nextItem.detail_entries : [])
                    .find((entry) => String(entry?.field_key ?? '').trim() === fieldKey);

                queueIncidentTypeSave(`incident-type:${incidentTypeId}:field:${fieldKey}`, async (isCurrent) => {
                    const response = await fetchJson(`/api/operator/incidents/${payload.id}/incident-types/${incidentTypeId}/details`, {
                        method: 'post',
                        data: {
                            field_id: detailEntry?.field_id ?? null,
                            field_key: fieldKey,
                            field_value: detailEntry?.field_value ?? meta?.value ?? '',
                        },
                    });

                    if (!isCurrent()) {
                        return;
                    }

                    if (response?.detail) {
                        upsertIncidentTypeDetailEntry(response.detail);
                    } else {
                        removeIncidentTypeDetailEntry(incidentTypeId, response?.field_key ?? fieldKey, response?.field_id ?? null);
                    }

                    publishOperatorIncidentUpdate({
                        incident_id: Number(payload.id ?? 0),
                        caller_id: Number(payload.caller_id ?? 0),
                        scope: 'incident_types',
                        patch: {
                            incident_types: cloneWorkbenchValue(payload.incident_types ?? []),
                            incident_type_details: cloneWorkbenchValue(payload.incident_type_details ?? []),
                            incident_resources_needed: cloneWorkbenchValue(payload.incident_resources_needed ?? []),
                        },
                    });
                });

                return;
            }

            if (meta?.reason === 'resource') {
                const resourceEntry = (Array.isArray(nextItem?.resources_needed) ? nextItem.resources_needed : [])
                    .find((entry) => Number(entry?.resource_type_id ?? 0) === Number(meta?.resourceTypeId ?? 0));
                const resourceTypeId = Number(resourceEntry?.resource_type_id ?? meta?.resourceTypeId ?? 0);

                queueIncidentTypeSave(`incident-type:${incidentTypeId}:resource:${resourceTypeId}`, async (isCurrent) => {
                    const response = await fetchJson(`/api/operator/incidents/${payload.id}/incident-types/${incidentTypeId}/resources/${resourceTypeId}`, {
                        method: 'post',
                        data: {
                            quantity_needed: resourceEntry?.quantity_needed ?? meta?.quantityNeeded ?? 0,
                            notes: resourceEntry?.notes ?? null,
                        },
                    });

                    if (!isCurrent()) {
                        return;
                    }

                    if (response?.resource) {
                        upsertIncidentTypeResourceEntry(response.resource);
                    } else {
                        removeIncidentTypeResourceEntry(incidentTypeId, response?.resource_type_id ?? resourceTypeId);
                    }

                    publishOperatorIncidentUpdate({
                        incident_id: Number(payload.id ?? 0),
                        caller_id: Number(payload.caller_id ?? 0),
                        scope: 'incident_types',
                        patch: {
                            incident_types: cloneWorkbenchValue(payload.incident_types ?? []),
                            incident_type_details: cloneWorkbenchValue(payload.incident_type_details ?? []),
                            incident_resources_needed: cloneWorkbenchValue(payload.incident_resources_needed ?? []),
                        },
                    });
                });
            }
        },
        onChange(nextList) {
            syncIncidentTypesPayload(nextList);
        },
        removeIncidentType(incidentTypeData) {
            const incidentTypeId = Number(incidentTypeData?.incident_type_id ?? incidentTypeData?.id ?? 0);
            clearIncidentTypeSaveTimersFor(incidentTypeId);
            removeIncidentTypeItem(incidentTypeData);
            updateIncidentTypesView();
        },
        className: 'operator-workbench-incident-helper',
    });

    if (incidentTypesHost && helper.incidentTypesHelper) {
        incidentTypesApi = helper.incidentTypesHelper(
            incidentTypesHost,
            buildIncidentTypesData(),
            buildIncidentTypesOptions(),
        );
        instances.push(incidentTypesApi);
    }

    if (teamAssignmentsHost && helper.incidentAssignmentsHelper) {
        teamAssignmentsApi = helper.incidentAssignmentsHelper(teamAssignmentsHost, {
            team_assignments: payload.team_assignments ?? [],
        }, buildTeamAssignmentsOptions());
        instances.push(teamAssignmentsApi);
    }

    if (audioHost) {
        if (isActive && helper.createAudioGraph) {
            audioHost.innerHTML = `
                <div class="operator-workbench-audiograph-stack">
                    <div class="operator-workbench-audiograph-row" data-workbench-audiograph="caller"></div>
                    <div class="operator-workbench-audiograph-row" data-workbench-audiograph="operator"></div>
                </div>
            `;

            const callerGraphHost = audioHost.querySelector('[data-workbench-audiograph="caller"]');
            const operatorGraphHost = audioHost.querySelector('[data-workbench-audiograph="operator"]');

            if (callerGraphHost) {
                callerGraphApi = helper.createAudioGraph(callerGraphHost, {
                    role: 'caller',
                    roleLabel: workbenchCallerName(payload),
                    muted: false,
                    isPlaying: false,
                    isLive: true,
                    isActive: false,
                    currentMs: 0,
                    durationMs: 0,
                }, {
                    style: currentAudioGraphStyle(),
                    showMute: false,
                    ariaLabel: 'Caller audiograph',
                });
                instances.push(callerGraphApi);
            }

            if (operatorGraphHost) {
                operatorGraphApi = helper.createAudioGraph(operatorGraphHost, {
                    role: 'operator',
                    roleLabel: appState.bootstrap?.user?.name ?? 'Operator',
                    muted: false,
                    isPlaying: false,
                    isLive: true,
                    isActive: false,
                    currentMs: 0,
                    durationMs: 0,
                }, {
                    style: currentAudioGraphStyle(),
                    showMute: false,
                    ariaLabel: 'Operator audiograph',
                });
                instances.push(operatorGraphApi);
            }
        }
    }

    syncWorkbenchMediaViews();
    instances.push({
        destroy() {
            clearIncidentTypeSaveTimers();
            clearTeamAssignmentSaveTimers();
            audioCallSessionApi?.destroy?.();
            audioCallSessionApi = null;
            mediaStripApi?.destroy?.();
            mediaStripApi = null;
            callSessionTimelineApi?.destroy?.();
            callSessionTimelineApi = null;
        },
    });

    if (chatThreadHost && !isActive) {
        instances.push(mountChatThread(chatThreadHost, payload.messages, 'operator', {
            emptyTitle: 'No chat yet',
            emptyText: 'Caller chat remains visible across active and inactive call states.',
        }));
    }

    if (isActive && chatComposerHost) {
        const liveConversation = await mountRealtimeIncidentChat({
            incidentId: Number(payload.id ?? 0),
            messages: payload.messages ?? [],
            viewerRole: 'operator',
            admissionPath: '/api/realtime/admission/operator',
            currentUserId: String(appState.bootstrap?.user?.id ?? ''),
            currentDisplayName: String(appState.bootstrap?.user?.name ?? 'Operator'),
            threadHost: chatThreadHost,
            composerHost: chatComposerHost,
            uploadQueueHost: chatUploadQueueHost,
            emptyTitle: 'No chat yet',
            emptyText: 'Caller chat remains visible across active and inactive call states.',
            composerOptions: {
                placeholder: 'Reply to caller...',
                helperText: '',
                showAttachmentButton: true,
                accept: 'image/*,video/*',
            },
            persistMessage: async (messagePayload) => {
                const response = await fetchJson(`/api/incidents/${payload.id}/messages`, {
                    method: 'post',
                    data: {
                        body: String(messagePayload?.text ?? '').trim(),
                        sender: normalizePersistedChatSender(messagePayload),
                    },
                });
                return response?.item ?? null;
            },
            persistAttachments: async (savedMessage, attachments) => {
                for (const attachment of (Array.isArray(attachments) ? attachments : [])) {
                    if (!(attachment?.file instanceof File) || !savedMessage?.id) {
                        continue;
                    }

                    const formData = new FormData();
                    formData.append('attachment', attachment.file, String(attachment.originalFilename ?? attachment.file.name ?? 'attachment'));
                    formData.append('type', String(attachment.type ?? 'file'));

                    await fetchJson(`/api/incidents/${payload.id}/messages/${savedMessage.id}/attachments`, {
                        method: 'post',
                        data: formData,
                    });
                }
            },
            onMediaEvent(_eventType, eventPayload) {
                const nextMedia = eventPayload?.media && typeof eventPayload.media === 'object'
                    ? eventPayload.media
                    : null;

                if (!nextMedia) {
                    return;
                }

                payload.media = mergeIncidentMediaItems(payload.media, nextMedia);
                syncWorkbenchMediaViews(nextMedia);
            },
        });

        if (liveConversation) {
            appState.runtime.operatorWorkbenchChat = liveConversation;
            instances.push(liveConversation);
        } else {
            if (chatThreadHost) {
                instances.push(mountChatThread(chatThreadHost, payload.messages, 'operator', {
                    emptyTitle: 'No chat yet',
                    emptyText: 'Caller chat remains visible across active and inactive call states.',
                }));
            }

            instances.push(mountChatComposer(chatComposerHost, {
                showAttachmentButton: true,
                helperText: '',
                accept: 'image/*,video/*',
                onSend() {
                    showToast('Live chat is unavailable right now.', 'warn');
                },
                onFilesSelected() {
                    showToast('Attachment transport is unavailable right now.', 'warn');
                },
            }));
        }
    }

    if (isActive && payload?.id && payload?.caller?.id) {
        const activeSessionId = Number(deriveActiveCallSessionId(payload) ?? 0);

        if (activeSessionId) {
            const needsConnectionGate = !payload.call_history?.find((session) => Number(session?.id ?? 0) === activeSessionId)?.answered_at;
            const readiness = {
                peerConnected: false,
                localStream: false,
                remoteStream: false,
                localAudioRecorder: false,
                remoteAudioRecorder: false,
                localAudioPrimed: false,
                remoteAudioPrimed: false,
                completed: false,
                inFlight: false,
            };
            let callRuntime = null;

            const dismissConnectionOverlay = () => {
                appState.runtime.operatorConnectingModalClose?.();
                appState.runtime.operatorConnectingModalClose = null;
                appState.runtime.operatorIncomingCallPhase = null;
                overlay?.querySelector('[data-connecting-overlay]')?.remove();
                overlay?.querySelector('[data-incoming-call-overlay]')?.remove();
            };

            const liftConnectionGate = async () => {
                if (!needsConnectionGate || readiness.completed || readiness.inFlight) {
                    return;
                }

                const missing = [];

                if (!readiness.peerConnected) {
                    missing.push('peerConnected');
                }

                if (!readiness.localStream) {
                    missing.push('localStream');
                }

                if (!readiness.remoteStream) {
                    missing.push('remoteStream');
                }

                if (missing.length > 0) {
                    debugMediaCapture('connection-gate-waiting', {
                        callSessionId: activeSessionId,
                        incidentId: Number(payload.id ?? 0),
                        missing,
                        readiness: { ...readiness },
                    });
                    return;
                }

                readiness.inFlight = true;

                try {
                    const gateLiftedAt = new Date().toISOString();
                    const response = await fetchJson(`/api/operator/call-sessions/${activeSessionId}/ready`, {
                        method: 'post',
                        data: {
                            answered_at: gateLiftedAt,
                        },
                    });
                    const answeredAt = response?.call_session?.answered_at ?? gateLiftedAt;
                    payload = patchIncidentCallSession(payload, activeSessionId, {
                        answered_at: answeredAt,
                    });
                    debugMediaCapture('connection-gate-lift', {
                        callSessionId: activeSessionId,
                        incidentId: Number(payload.id ?? 0),
                        answeredAt,
                        readiness: { ...readiness },
                    });
                    captureManager?.activateCapture?.(answeredAt);
                    callRuntime?.setMediaMuted?.(false);
                    captureManager?.syncOperatorAudioMute?.(false);
                    captureManager?.markOperatorAudioUnmuted?.();
                    dismissConnectionOverlay();
                    publishOperatorCallFlow('caller.call.ready', {
                        incident_id: Number(payload.id ?? 0),
                        call_session_id: activeSessionId,
                        caller_id: Number(payload.caller?.id ?? 0),
                        operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                        answered_at: answeredAt,
                    });
                    readiness.completed = true;
                } catch (error) {
                    console.warn('Unable to mark call session ready.', error);
                    showToast(error.response?.data?.message ?? 'Unable to complete call connection.', 'warn');
                } finally {
                    readiness.inFlight = false;
                }
            };

            const captureManager = createOperatorCallCaptureManager({
                callSessionId: activeSessionId,
                incidentId: Number(payload.id ?? 0),
                caller: payload.caller ?? null,
                operator: appState.bootstrap?.user ?? null,
                onMediaUpdated(nextMedia) {
                    if (!nextMedia || typeof nextMedia !== 'object') {
                        return;
                    }

                    payload.media = mergeIncidentMediaItems(payload.media, nextMedia);
                    syncWorkbenchMediaViews(nextMedia);
                },
                onRecorderCreated({ key, mediaType }) {
                    if (mediaType !== 'audio_peer') {
                        return;
                    }

                    if (key === 'operator-audio') {
                        readiness.localAudioRecorder = true;
                    }

                    if (key === 'citizen-audio') {
                        readiness.remoteAudioRecorder = true;
                    }

                    void liftConnectionGate();
                },
                onRecorderPrimed({ key, mediaType }) {
                    if (mediaType !== 'audio_peer') {
                        return;
                    }

                    if (key === 'operator-audio') {
                        readiness.localAudioPrimed = true;
                    }

                    if (key === 'citizen-audio') {
                        readiness.remoteAudioPrimed = true;
                    }

                    void liftConnectionGate();
                },
            });

            if (captureManager) {
                appState.runtime.operatorWorkbenchCaptureManager = captureManager;
                if (!needsConnectionGate) {
                    const existingAnsweredAt = String(
                        payload.call_history?.find((session) => Number(session?.id ?? 0) === activeSessionId)?.answered_at
                        ?? new Date().toISOString(),
                    );
                    captureManager.activateCapture?.(existingAnsweredAt);
                }
                instances.push(captureManager);
            }

            callRuntime = await mountRealtimeCallSession({
                callSessionId: activeSessionId,
                viewerRole: 'operator',
                admissionPath: '/api/realtime/admission/operator',
                currentUserId: String(appState.bootstrap?.user?.id ?? ''),
                remoteUserId: String(payload.caller.id),
                remoteAudioHost: audioHost ?? overlay,
                remoteVideoHost: videoPreviewHost,
                startMuted: needsConnectionGate,
                onLocalStream(stream) {
                    readiness.localStream = true;
                    if (operatorGraphApi) {
                        operatorGraphApi.update({
                            isLive: true,
                            isActive: true,
                            isPlaying: true,
                        });
                        operatorGraphApi.attachMediaStream?.(stream);
                        operatorGraphApi.resume?.();
                    }
                    void captureManager?.ensureLocalAudio?.(stream);
                    void liftConnectionGate();
                },
                onRemoteStream(stream) {
                    readiness.remoteStream = true;
                    if (callerGraphApi) {
                        callerGraphApi.update({
                            isLive: true,
                            isActive: true,
                            isPlaying: true,
                        });
                        callerGraphApi.attachMediaStream?.(stream);
                        callerGraphApi.resume?.();
                    }
                    void captureManager?.ensureRemoteAudio?.(stream);
                    void liftConnectionGate();
                },
                onRemoteVideoStateChange(enabled, stream) {
                    debugMediaCapture('caller-video-state-change', {
                        callSessionId: activeSessionId,
                        enabled: Boolean(enabled),
                        hasStream: stream instanceof MediaStream,
                        videoTracks: stream instanceof MediaStream
                            ? stream.getVideoTracks().map((track) => ({
                                id: track.id,
                                enabled: Boolean(track.enabled),
                                muted: Boolean(track.muted),
                                readyState: track.readyState,
                            }))
                            : [],
                        audioTracks: stream instanceof MediaStream
                            ? stream.getAudioTracks().map((track) => ({
                                id: track.id,
                                enabled: Boolean(track.enabled),
                                muted: Boolean(track.muted),
                                readyState: track.readyState,
                            }))
                            : [],
                    });
                    void captureManager?.syncRemoteVideo?.(enabled, stream);
                },
                onCallerLocation(locationPayload) {
                    const location = normalizeCallerLocationPayload(locationPayload);

                    if (!location) {
                        return;
                    }

                    const incidentId = Number(payload?.id ?? 0);

                    payload = {
                        ...payload,
                        latitude: location.latitude,
                        longitude: location.longitude,
                        caller_location: location,
                    };

                    if (appState.runtime.operatorWorkbench) {
                        appState.runtime.operatorWorkbench.payload = payload;
                    }

                    updateWorkbenchCallerLocationView(overlay, payload);

                    void persistOperatorCallerLocation(incidentId, location).then((incident) => {
                        if (!incident) {
                            return;
                        }

                        syncOperatorActiveIncident(currentOperatorRoot(), incident);
                        publishOperatorCallerLocationPersisted(incident, location);

                        if (Number(appState.runtime.operatorWorkbench?.payload?.id ?? 0) !== Number(incident.id ?? 0)) {
                            return;
                        }

                        payload = {
                            ...payload,
                            latitude: incident.latitude ?? location.latitude,
                            longitude: incident.longitude ?? location.longitude,
                            caller_location: incident.caller_location ?? location,
                        };
                        appState.runtime.operatorWorkbench.payload = {
                            ...appState.runtime.operatorWorkbench.payload,
                            ...payload,
                        };
                        updateWorkbenchCallerLocationView(overlay, payload);
                    });
                },
                onStateChange(nextState) {
                    readiness.peerConnected = String(nextState ?? '').trim() === 'connected';
                    const active = ['connected', 'connecting'].includes(String(nextState ?? '').trim());

                    callerGraphApi?.update({
                        isActive: active,
                        isPlaying: active,
                    });
                    operatorGraphApi?.update({
                        isActive: active,
                        isPlaying: active,
                    });
                    void liftConnectionGate();
                },
                onDisconnectRequest() {
                    void (async () => {
                        const requestedAt = new Date().toISOString();
                        callRuntime?.sendHangupConfirm?.({
                            requested_at: requestedAt,
                        });

                        try {
                            const endedAt = new Date().toISOString();
                            let response = null;

                            try {
                                response = await fetchJson(`/api/operator/call-sessions/${activeSessionId}/hangup`, {
                                    method: 'post',
                                });
                            } catch (error) {
                                if (Number(error?.response?.status ?? 0) !== 409) {
                                    throw error;
                                }
                            }

                            const officialEndedAt = String(response?.call_session?.ended_at ?? endedAt);
                            captureManager?.setOfficialEndedAt?.(officialEndedAt);
                            void captureManager?.finalizeAll?.();
                            payload = patchIncidentCallSession(payload, activeSessionId, {
                                status: response?.call_session?.status ?? 'ended',
                                outcome: response?.call_session?.outcome ?? 'ended_by_citizen',
                                ended_at: officialEndedAt,
                                updated_at: response?.call_session?.updated_at ?? officialEndedAt,
                            });
                            callRuntime?.sendHangupComplete?.({
                                reason: 'ended-by-caller',
                                ended_at: officialEndedAt,
                            });
                            await refreshWorkbenchOverlay(payload, null);
                        } catch (error) {
                            console.warn('Unable to complete caller-requested hangup.', error);
                        }
                    })();
                },
                onHangup() {
                    void (async () => {
                        const endedAt = String(payload?.meta?.ended_at ?? payload?.ended_at ?? new Date().toISOString());
                        try {
                            dismissConnectionOverlay();
                        } catch (error) {
                            console.warn('Unable to dismiss operator connection overlays during hangup cleanup.', error);
                        }
                        captureManager?.setOfficialEndedAt?.(endedAt);
                        void captureManager?.finalizeAll?.();
                        payload = patchIncidentCallSession(payload, activeSessionId, {
                            status: 'ended',
                            outcome: 'ended_by_citizen',
                            ended_at: endedAt,
                            updated_at: endedAt,
                        });
                        await refreshWorkbenchOverlay(payload, null);
                    })();
                },
            });

            if (callRuntime) {
                appState.runtime.operatorWorkbenchCallRuntime = callRuntime;
                instances.push(callRuntime);
            }
        }
    }

    return instances.filter(Boolean);
}

async function presentWorkbench(root, payload, stateOverride = null, options = {}) {
    const persistState = options.persistState !== false;
    const activeCallSessionId = deriveActiveCallSessionId(payload);

    if (payload?.id) {
        joinOperatorIncidentMediaRoom(payload.id);
    }

    if (persistState && payload?.id) {
        sessionStorage.setItem(OPERATOR_WORKBENCH_KEY, String(payload.id));
    }

    if (payload?.id) {
        joinOperatorIncidentMediaRoom(payload.id);
    }

    if (persistState && activeCallSessionId) {
        sessionStorage.setItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY, String(activeCallSessionId));
    } else {
        sessionStorage.removeItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY);
    }

    publishOperatorDiscoveryPresence();

    appState.runtime.operatorWorkbenchClose?.();
    root.insertAdjacentHTML('beforeend', renderWorkbench(payload, stateOverride));

    const overlay = root.querySelector('[data-workbench-overlay]');
    const overlayInstances = [];
    appState.runtime.operatorWorkbenchOverlay = overlay;
    appState.runtime.operatorWorkbenchRoot = root;
    appState.runtime.operatorWorkbenchInstances = overlayInstances;
    appState.runtime.operatorWorkbenchCallRuntime = null;
    appState.runtime.operatorWorkbenchCaptureManager = null;
    const close = () => {
        clearOperatorWorkbenchCallStatusPoll();
        sessionStorage.removeItem(OPERATOR_WORKBENCH_KEY);
        sessionStorage.removeItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY);
        appState.runtime.operatorWorkbenchCallRuntime = null;
        appState.runtime.operatorWorkbenchCaptureManager = null;
        appState.runtime.operatorWorkbenchChat = null;
        appState.runtime.operatorWorkbench = null;
        appState.runtime.operatorConnectingModalClose?.();
        appState.runtime.operatorConnectingModalClose = null;
        overlayInstances.forEach((instance) => instance?.destroy?.());
        overlay?.remove();
        publishOperatorDiscoveryPresence();
        if (appState.runtime.operatorWorkbenchClose === close) {
            appState.runtime.operatorWorkbenchClose = null;
            appState.runtime.operatorWorkbenchOverlay = null;
            appState.runtime.operatorWorkbenchRoot = null;
            appState.runtime.operatorWorkbenchInstances = [];
            appState.runtime.operatorWorkbenchChat = null;
            appState.runtime.operatorWorkbench = null;
        }
    };
    appState.runtime.operatorWorkbenchClose = close;

    const navbar = await mountWorkbenchNavbar(overlay, payload, stateOverride, close);

    if (navbar) {
        overlayInstances.push(navbar);
    }

    overlayInstances.push(...await mountWorkbenchHelpers(overlay, payload, stateOverride));
    const initialIntake = maybeOpenWorkbenchInitialIntake(overlay, payload, options);

    if (initialIntake) {
        overlayInstances.push(initialIntake);
    }
}

function createOperatorWorkbenchBusyOverlay(root) {
    if (typeof appState.helper.createBusyOverlay !== 'function') {
        return null;
    }

    const target = root && typeof root.querySelector === 'function'
        ? root
        : document.body;

    try {
        return appState.helper.createBusyOverlay(target, {
            text: 'Opening incident...',
            ariaLabel: 'Opening incident workbench',
            visible: true,
            fullscreen: false,
            lockScroll: false,
            className: 'operator-workbench-busy-overlay',
        });
    } catch (error) {
        console.warn('Unable to show operator workbench busy overlay.', error);
        return null;
    }
}

async function openOperatorWorkbench(root, incidentId, options = {}) {
    const busyOverlay = options.busyOverlay === false
        ? null
        : createOperatorWorkbenchBusyOverlay(root);

    try {
        const payload = await fetchJson(`/api/operator/incidents/${incidentId}`);
        await presentWorkbench(root, payload, options.stateOverride ?? null, {
            persistState: options.persistState !== false,
            initialIntake: options.initialIntake === true,
        });
    } finally {
        busyOverlay?.destroy?.();
    }
}

function transferModalMarkup(item) {
    return `
        <div class="overlay-backdrop" data-transfer-request-overlay>
            <section class="overlay-panel">
                <div class="overlay-head">
                    <div>
                        <h2 class="overlay-title">Transfer Request</h2>
                        <p class="hero-copy">Incident ${escapeHtml(item.display_id)} from ${escapeHtml(item.from_operator?.name ?? 'Unknown operator')}</p>
                    </div>
                    <button class="overlay-close" type="button" data-dismiss-transfer="1">Close</button>
                </div>
                <p class="hero-copy">${escapeHtml(item.reason ?? 'No transfer reason provided.')}</p>
                <div class="button-row">
                    <button class="surface-button" type="button" data-accept-transfer="1">Accept</button>
                    <button class="surface-button secondary" type="button" data-reject-transfer="1">Reject</button>
                </div>
                <div class="notice" data-transfer-notice hidden></div>
            </section>
        </div>
    `;
}

function outboundTransferTargetOptions(candidates, selectedId = 0) {
    if (!Array.isArray(candidates) || candidates.length === 0) {
        return '<option value="">No available online operators</option>';
    }

    return candidates.map((operator) => {
        const id = normalizeOperatorPresenceUserId(operator?.id);
        const selected = id === normalizeOperatorPresenceUserId(selectedId) ? ' selected' : '';

        return `<option value="${escapeHtml(id)}"${selected}>${escapeHtml(operator?.name ?? `Operator #${id}`)}</option>`;
    }).join('');
}

function outboundTransferModalMarkup(payload, candidates = []) {
    const displayId = payload?.display_id || `#${padIncidentId(payload?.id)}`;
    const hasTargets = Array.isArray(candidates) && candidates.length > 0;

    return `
        <div class="overlay-backdrop" data-outbound-transfer-overlay>
            <section class="overlay-panel operator-transfer-create-modal">
                <div class="overlay-head">
                    <div>
                        <h2 class="overlay-title">Transfer Incident</h2>
                        <p class="hero-copy">Incident ${escapeHtml(displayId)} can only be sent to online available operators.</p>
                    </div>
                    <button class="overlay-close" type="button" data-dismiss-outbound-transfer="1">Close</button>
                </div>
                <form class="modal-form operator-transfer-create-form" data-outbound-transfer-form>
                    <label class="field">
                        <span class="field-label">Target operator</span>
                        <select class="ui-input" name="to_operator_id" ${hasTargets ? '' : 'disabled'}>
                            ${outboundTransferTargetOptions(candidates)}
                        </select>
                    </label>
                    <label class="field">
                        <span class="field-label">Transfer reason</span>
                        <textarea class="ui-input" name="reason" rows="4" placeholder="Why should this incident be transferred?" required></textarea>
                    </label>
                    <p class="operator-transfer-create-hint" data-outbound-transfer-count>
                        ${hasTargets
                            ? `${escapeHtml(candidates.length)} available online operator${candidates.length === 1 ? '' : 's'} found.`
                            : 'No available online operators are visible in presence right now.'}
                    </p>
                    <div class="notice" data-outbound-transfer-notice hidden></div>
                    <div class="button-row">
                        <button class="surface-button" type="submit" data-submit-outbound-transfer ${hasTargets ? '' : 'disabled'}>Send transfer request</button>
                        <button class="surface-button secondary" type="button" data-dismiss-outbound-transfer="1">Cancel</button>
                    </div>
                </form>
            </section>
        </div>
    `;
}

function refreshOutboundTransferModalTargets() {
    const overlay = document.querySelector('[data-outbound-transfer-overlay]');

    if (!overlay) {
        return;
    }

    const select = overlay.querySelector('select[name="to_operator_id"]');
    const submit = overlay.querySelector('[data-submit-outbound-transfer]');
    const count = overlay.querySelector('[data-outbound-transfer-count]');
    const candidates = operatorAvailableTransferTargets();
    const selectedId = normalizeOperatorPresenceUserId(select?.value);
    const selectedStillAvailable = candidates.some((candidate) => normalizeOperatorPresenceUserId(candidate?.id) === selectedId);
    const nextSelectedId = selectedStillAvailable ? selectedId : normalizeOperatorPresenceUserId(candidates[0]?.id);

    if (select) {
        select.innerHTML = outboundTransferTargetOptions(candidates, nextSelectedId);
        select.disabled = candidates.length === 0;
    }

    if (submit) {
        submit.disabled = candidates.length === 0;
    }

    if (count) {
        count.textContent = candidates.length > 0
            ? `${candidates.length} available online operator${candidates.length === 1 ? '' : 's'} found.`
            : 'No available online operators are visible in presence right now.';
    }
}

async function openOutboundTransferModal(root, payload) {
    const host = root ?? currentOperatorRoot();

    if (!host || !payload?.id) {
        return;
    }

    publishOperatorDiscoveryPresence(true);
    host.querySelector('[data-outbound-transfer-overlay]')?.remove();
    host.insertAdjacentHTML('beforeend', outboundTransferModalMarkup(payload, operatorAvailableTransferTargets()));

    const overlay = host.querySelector('[data-outbound-transfer-overlay]');
    const form = overlay?.querySelector('[data-outbound-transfer-form]');
    const notice = overlay?.querySelector('[data-outbound-transfer-notice]');

    const showNotice = (message, tone = 'error') => {
        if (!notice) {
            return;
        }

        notice.textContent = message;
        notice.hidden = false;
        notice.dataset.tone = tone;
    };
    const close = () => overlay?.remove();

    overlay?.querySelectorAll('[data-dismiss-outbound-transfer]').forEach((button) => {
        button.addEventListener('click', close);
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const formData = new FormData(form);
        const targetId = normalizeOperatorPresenceUserId(formData.get('to_operator_id'));
        const reason = String(formData.get('reason') ?? '').trim();
        const candidates = operatorAvailableTransferTargets();
        const target = candidates.find((candidate) => normalizeOperatorPresenceUserId(candidate?.id) === targetId);

        if (!target) {
            showNotice('Selected operator is no longer available. Choose another target.');
            refreshOutboundTransferModalTargets();
            return;
        }

        if (!reason) {
            showNotice('Transfer reason is required.');
            return;
        }

        const submit = form.querySelector('[data-submit-outbound-transfer]');
        submit.disabled = true;

        try {
            await fetchJson(`/api/operator/incidents/${payload.id}/transfers`, {
                method: 'post',
                data: {
                    to_operator_id: targetId,
                    reason,
                },
            });

            showToast(`Transfer request sent to ${target.name}.`, 'success');
            close();
        } catch (error) {
            showNotice(error.response?.data?.message ?? 'Unable to create transfer request.');
            submit.disabled = false;
            refreshOutboundTransferModalTargets();
        }
    });
}

function nextWorkbenchOverlayMarkup(payload, stateOverride = null) {
    const host = document.createElement('div');
    host.innerHTML = renderWorkbench(payload, stateOverride);
    return host.querySelector('[data-workbench-overlay]');
}

async function refreshWorkbenchOverlay(payload, stateOverride = null, options = {}) {
    const overlay = appState.runtime.operatorWorkbenchOverlay;
    const root = appState.runtime.operatorWorkbenchRoot;

    if (!overlay || !root) {
        return presentWorkbench(root ?? document.getElementById('app'), payload, stateOverride, options);
    }

    const liveMessages = appState.runtime.operatorWorkbenchChat?.getMessages?.();

    if (Array.isArray(liveMessages) && liveMessages.length > 0) {
        payload.messages = liveMessages;
    }

    const persistState = options.persistState !== false;
    const activeCallSessionId = deriveActiveCallSessionId(payload);

    if (persistState && payload?.id) {
        sessionStorage.setItem(OPERATOR_WORKBENCH_KEY, String(payload.id));
    }

    if (persistState && activeCallSessionId) {
        sessionStorage.setItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY, String(activeCallSessionId));
    } else {
        sessionStorage.removeItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY);
    }

    publishOperatorDiscoveryPresence();

    const priorInstances = Array.isArray(appState.runtime.operatorWorkbenchInstances)
        ? appState.runtime.operatorWorkbenchInstances
        : [];
    priorInstances.forEach((instance) => instance?.destroy?.());
    appState.runtime.operatorWorkbenchInstances = [];
    appState.runtime.operatorWorkbenchCallRuntime = null;
    appState.runtime.operatorWorkbenchCaptureManager = null;
    appState.runtime.operatorWorkbench = null;

    const nextOverlay = nextWorkbenchOverlayMarkup(payload, stateOverride);

    if (!nextOverlay) {
        return;
    }

    overlay.innerHTML = nextOverlay.innerHTML;

    const overlayInstances = [];
    const close = () => {
        clearOperatorWorkbenchCallStatusPoll();
        sessionStorage.removeItem(OPERATOR_WORKBENCH_KEY);
        sessionStorage.removeItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY);
        appState.runtime.operatorWorkbenchCallRuntime = null;
        appState.runtime.operatorWorkbenchCaptureManager = null;
        appState.runtime.operatorWorkbenchChat = null;
        appState.runtime.operatorWorkbench = null;
        appState.runtime.operatorConnectingModalClose?.();
        appState.runtime.operatorConnectingModalClose = null;
        overlayInstances.forEach((instance) => instance?.destroy?.());
        overlay?.remove();
        publishOperatorDiscoveryPresence();
        if (appState.runtime.operatorWorkbenchClose === close) {
            appState.runtime.operatorWorkbenchClose = null;
            appState.runtime.operatorWorkbenchOverlay = null;
            appState.runtime.operatorWorkbenchRoot = null;
            appState.runtime.operatorWorkbenchInstances = [];
            appState.runtime.operatorWorkbenchChat = null;
            appState.runtime.operatorWorkbench = null;
        }
    };

    appState.runtime.operatorWorkbenchClose = close;
    appState.runtime.operatorWorkbenchOverlay = overlay;
    appState.runtime.operatorWorkbenchRoot = root;
    appState.runtime.operatorWorkbenchInstances = overlayInstances;

    const navbar = await mountWorkbenchNavbar(overlay, payload, stateOverride, close);

    if (navbar) {
        overlayInstances.push(navbar);
    }

    overlayInstances.push(...await mountWorkbenchHelpers(overlay, payload, stateOverride));
    const initialIntake = maybeOpenWorkbenchInitialIntake(overlay, payload, options);

    if (initialIntake) {
        overlayInstances.push(initialIntake);
    }
}

function removeIncomingCallFromDashboard(item) {
    if (!appState.operatorDashboard || !Array.isArray(appState.operatorDashboard.incoming_calls)) {
        return;
    }

    const targetKind = String(item?.kind ?? '').trim();
    const targetId = String(item?.id ?? '').trim();
    appState.operatorDashboard.incoming_calls = appState.operatorDashboard.incoming_calls.filter((entry) => (
        String(entry?.kind ?? '').trim() !== targetKind
        || String(entry?.id ?? '').trim() !== targetId
    ));
}

function closeIncomingCallModal(root, item = null) {
    const activeIncoming = appState.runtime.operatorIncomingCallItem;

    if (
        item
        && activeIncoming
        && String(activeIncoming.id ?? '') !== String(item.id ?? '')
    ) {
        return;
    }

    if (typeof appState.runtime.operatorIncomingModalClose === 'function') {
        appState.runtime.operatorIncomingModalClose();
        return;
    }

    stopOperatorIncomingRingtone();
    root?.querySelector?.('[data-incoming-call-overlay]')?.remove();
    appState.runtime.operatorIncomingCallItem = null;
    appState.runtime.operatorIncomingCallPhase = null;
    publishOperatorDiscoveryPresence();
}

async function openIncomingCallModal(root, item, phase = 'incoming') {
    stopOperatorIncomingRingtone();
    closeIncomingCallModal(root);
    root.insertAdjacentHTML('beforeend', incomingCallModalMarkup(item, phase));
    appState.runtime.operatorIncomingCallItem = item;
    appState.runtime.operatorIncomingCallPhase = phase;
    publishOperatorDiscoveryPresence();

    const overlay = root.querySelector('[data-incoming-call-overlay]');
    const notice = overlay?.querySelector('[data-incoming-notice]');
    const dismissKey = `${INCOMING_MODAL_DISMISS_PREFIX}${item.kind}.${item.id}`;
    let autoDeclineTimeoutId = null;
    const close = () => {
        if (autoDeclineTimeoutId) {
            window.clearTimeout(autoDeclineTimeoutId);
            autoDeclineTimeoutId = null;
        }

        stopOperatorIncomingRingtone();
        if (String(appState.runtime.operatorIncomingCallItem?.id ?? '') === String(item?.id ?? '')) {
            appState.runtime.operatorIncomingCallItem = null;
            appState.runtime.operatorIncomingCallPhase = null;
        }
        publishOperatorDiscoveryPresence();
        if (appState.runtime.operatorConnectingModalClose === close) {
            appState.runtime.operatorConnectingModalClose = null;
        }
        if (appState.runtime.operatorIncomingModalClose === close) {
            appState.runtime.operatorIncomingModalClose = null;
        }
        overlay?.remove();
    };
    appState.runtime.operatorIncomingModalClose = close;
    const showNotice = (message, tone = 'error') => {
        if (!notice) {
            return;
        }

        notice.hidden = false;
        notice.className = `notice ${tone}`;
        notice.textContent = message;
    };

    if (phase === 'reconnect') {
        speakOperatorPhrase(`${item.caller_name ?? 'Caller'} is trying to reconnect`);
    }

    if (!item?.demo && (phase === 'incoming' || phase === 'reconnect' || phase === 'preparing')) {
        playOperatorIncomingRingtone();
    }

    if (phase === 'connecting') {
        appState.runtime.operatorConnectingModalClose = close;
        return;
    }

    if (phase === 'preparing') {
        return;
    }

    const dismissIncoming = async () => {
        if (item?.demo) {
            sessionStorage.setItem(dismissKey, '1');
            close();
            return;
        }

        try {
            if (item.kind === 'new_call' || (item.kind === 'reconnect' && item.call_attempt_id)) {
                await fetchJson(`/api/operator/call-attempt-operator-attempts/${item.id}/decline`, {
                    method: 'post',
                });
            }

            appState.runtime.operatorDiscoveryClaimed = false;
            sessionStorage.removeItem(dismissKey);
            removeIncomingCallFromDashboard(item);
            publishOperatorDiscoveryPresence();

            if (item.kind === 'reconnect' && item.call_attempt_id) {
                publishOperatorCallFlow('caller.reconnect.declined', {
                    call_attempt_id: Number(item.call_attempt_id ?? 0),
                    call_attempt_operator_attempt_id: Number(item.id),
                    caller_id: Number(item.caller_id ?? 0),
                    incident_id: Number(item.incident_id ?? 0),
                    operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                    message: 'Operator is currently not available. Please try again later.',
                    outcome: 'declined_by_operator',
                    ended_at: new Date().toISOString(),
                });
            } else {
                publishOperatorCallFlow('caller.call.declined', {
                    call_attempt_id: Number(item.call_attempt_id ?? 0),
                    call_attempt_operator_attempt_id: Number(item.id),
                    caller_id: Number(item.caller_id ?? 0),
                    operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                    outcome: 'declined_by_operator',
                    ended_at: new Date().toISOString(),
                });
            }
            close();
        } catch (error) {
            showNotice(error.response?.data?.message ?? 'Unable to dismiss incoming call.');
        }
    };

    overlay?.querySelector('[data-dismiss-incoming]')?.addEventListener('click', () => {
        void dismissIncoming();
    });

    if (!item?.demo && item.kind === 'new_call' && phase === 'incoming') {
        autoDeclineTimeoutId = window.setTimeout(() => {
            autoDeclineTimeoutId = null;

            if (
                String(appState.runtime.operatorIncomingCallItem?.id ?? '') !== String(item.id ?? '')
                || appState.runtime.operatorIncomingCallPhase !== 'incoming'
            ) {
                return;
            }

            void dismissIncoming();
        }, operatorCallTimeoutMs());
    }

    overlay?.addEventListener('click', (event) => {
        if (event.target === overlay) {
            void dismissIncoming();
        }
    });

    overlay?.querySelector('[data-answer-incoming]')?.addEventListener('click', async () => {
        if (item?.demo) {
            showNotice('Temporary incoming-call modal preview.', 'success');
            return;
        }

        try {
            if (item.kind === 'new_call') {
                const response = await fetchJson(`/api/operator/call-attempt-operator-attempts/${item.id}/answer`, {
                    method: 'post',
                });

                publishOperatorCallFlow('caller.call.answered', {
                    call_attempt_id: Number(response.attempt?.id ?? 0),
                    call_attempt_operator_attempt_id: Number(item.id),
                    incident_id: Number(response.incident?.id ?? 0),
                    chat_room: response.incident?.id ? `chat.thread.incident.${response.incident.id}` : '',
                    call_room: response.call_session?.id ? `call.session.${response.call_session.id}` : '',
                    call_session_id: Number(response.call_session?.id ?? 0),
                    caller_id: Number(response.incident?.caller_id ?? item.caller_id ?? 0),
                    operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                    answered_at: response.call_session?.answered_at ?? null,
                    incident: response.incident ?? null,
                    call_session: response.call_session ?? null,
                });

                sessionStorage.removeItem(dismissKey);
                stopOperatorIncomingRingtone();
                appState.runtime.operatorIncomingCallItem = {
                    ...item,
                    incident_id: Number(response.incident?.id ?? 0),
                    call_session_id: Number(response.call_session?.id ?? 0),
                    display_id: String(response.incident?.display_id ?? ''),
                };
                appState.runtime.operatorIncomingCallPhase = 'connecting';
                publishOperatorDiscoveryPresence();
                removeIncomingCallFromDashboard(item);
                syncOperatorActiveIncident(root, response.incident);
                await openIncomingCallModal(root, appState.runtime.operatorIncomingCallItem, 'connecting');
                await openOperatorWorkbench(root, response.incident.id, {
                    initialIntake: true,
                });
                return;
            }

            if (item.call_attempt_id) {
                const response = await fetchJson(`/api/operator/call-attempt-operator-attempts/${item.id}/answer`, {
                    method: 'post',
                });

                publishOperatorCallFlow('caller.reconnect.answered', {
                    call_attempt_id: Number(response.attempt?.id ?? item.call_attempt_id ?? 0),
                    call_attempt_operator_attempt_id: Number(item.id),
                    incident_id: Number(response.incident?.id ?? item.incident_id ?? 0),
                    chat_room: response.incident?.id ? `chat.thread.incident.${response.incident.id}` : '',
                    call_room: response.call_session?.id ? `call.session.${response.call_session.id}` : '',
                    call_session_id: Number(response.call_session?.id ?? 0),
                    caller_id: Number(item.caller_id ?? 0),
                    operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                    answered_at: null,
                    incident: response.incident ?? null,
                    call_session: response.call_session ?? null,
                });

                sessionStorage.removeItem(dismissKey);
                stopOperatorIncomingRingtone();
                appState.runtime.operatorIncomingCallItem = {
                    ...item,
                    incident_id: Number(response.incident?.id ?? item.incident_id ?? 0),
                    call_session_id: Number(response.call_session?.id ?? 0),
                    display_id: String(response.incident?.display_id ?? item.display_id ?? ''),
                };
                appState.runtime.operatorIncomingCallPhase = 'connecting';
                publishOperatorDiscoveryPresence();
                removeIncomingCallFromDashboard(item);
                syncOperatorActiveIncident(root, response.incident);

                await openIncomingCallModal(root, appState.runtime.operatorIncomingCallItem, 'connecting');

                if (
                    Number(item.incident_id ?? 0) > 0
                    && operatorWorkbenchIncidentId() === Number(item.incident_id ?? 0)
                    && appState.runtime.operatorWorkbenchOverlay
                    && response?.incident
                ) {
                    await refreshWorkbenchOverlay(response.incident, 'active');
                } else if (response?.incident?.id) {
                    await openOperatorWorkbench(root, response.incident.id, {
                        stateOverride: 'active',
                    });
                }
                return;
            }

            await fetchJson(`/api/operator/call-sessions/${item.call_session_id}/answer`, {
                method: 'post',
            });

            publishOperatorCallFlow('caller.call.answered', {
                incident_id: Number(item.incident_id ?? 0),
                call_session_id: Number(item.call_session_id ?? 0),
                caller_id: Number(item.caller_id ?? 0),
                operator_id: Number(appState.bootstrap?.user?.id ?? 0),
                answered_at: null,
                call_session: {
                    id: Number(item.call_session_id ?? 0),
                    status: 'in_progress',
                    answered_at: null,
                },
            });

            sessionStorage.removeItem(dismissKey);
            stopOperatorIncomingRingtone();
            appState.runtime.operatorIncomingCallItem = item;
            appState.runtime.operatorIncomingCallPhase = 'connecting';
            publishOperatorDiscoveryPresence();
            removeIncomingCallFromDashboard(item);
            await openIncomingCallModal(root, item, 'connecting');
            if (item.incident_id) {
                await openOperatorWorkbench(root, item.incident_id);
            }
        } catch (error) {
            showNotice(error.response?.data?.message ?? 'Unable to answer incoming call.');
        }
    });
}

async function openTransferRequestModal(root, item) {
    root.querySelector('[data-transfer-request-overlay]')?.remove();
    root.insertAdjacentHTML('beforeend', transferModalMarkup(item));

    const overlay = root.querySelector('[data-transfer-request-overlay]');
    const notice = overlay?.querySelector('[data-transfer-notice]');
    const dismissKey = `${TRANSFER_MODAL_DISMISS_PREFIX}${item.id}`;
    const close = () => {
        overlay?.remove();
    };
    const showNotice = (message, tone = 'error') => {
        if (!notice) {
            return;
        }

        notice.hidden = false;
        notice.className = `notice ${tone}`;
        notice.textContent = message;
    };

    overlay?.querySelector('[data-dismiss-transfer]')?.addEventListener('click', () => {
        sessionStorage.setItem(dismissKey, '1');
        close();
    });

    overlay?.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });

    overlay?.querySelector('[data-accept-transfer]')?.addEventListener('click', async () => {
        try {
            await fetchJson(`/api/operator/transfers/${item.id}/accept`, {
                method: 'post',
            });

            sessionStorage.removeItem(dismissKey);
            close();
            await renderSurface('operator');
            await openOperatorWorkbench(root, item.incident_id);
        } catch (error) {
            showNotice(error.response?.data?.message ?? 'Unable to accept transfer.');
        }
    });

    overlay?.querySelector('[data-reject-transfer]')?.addEventListener('click', async () => {
        try {
            await fetchJson(`/api/operator/transfers/${item.id}/reject`, {
                method: 'post',
            });

            sessionStorage.removeItem(dismissKey);
            close();
            await renderSurface('operator');
        } catch (error) {
            showNotice(error.response?.data?.message ?? 'Unable to reject transfer.');
        }
    });
}

function openConnectingModal(root, item = {}) {
    root.querySelector('[data-connecting-overlay]')?.remove();
    root.insertAdjacentHTML('beforeend', incomingCallModalMarkup(item, 'connecting').replace('data-incoming-call-overlay', 'data-connecting-overlay'));

    const overlay = root.querySelector('[data-connecting-overlay]');
    const close = () => {
        overlay?.remove();
    };

    overlay?.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });
}

function renderOperator(root, bootstrap, dashboard, primerReport) {
    appState.runtime.operatorDashboardMapControls?.destroy?.();
    appState.runtime.operatorDashboardMapControls = null;
    appState.runtime.operatorDashboardMap?.destroy?.();
    appState.runtime.operatorDashboardMap = null;

    const firstPendingTransfer = Array.isArray(dashboard.pending_transfer_requests) ? dashboard.pending_transfer_requests[0] : null;
    const alertToneClass = operatorAlertToneClass(bootstrap?.alert_level);
    const content = `
        <div class="operator-fixed-command" aria-live="polite">
            <div class="operator-alert-clock ${alertToneClass}" data-operator-alert-clock>
                <span class="operator-alert-level" data-operator-alert-level>Alert: ${escapeHtml(String(bootstrap.alert_level ?? 'Normal').toUpperCase())}</span>
                <strong class="operator-live-time" data-live-time>--:--:--</strong>
                <small class="operator-live-date" data-live-date>---</small>
            </div>
        </div>
        <section class="panel-card operator-stage-shell">
            <div class="operator-map-stage">
                <div class="operator-map-canvas" data-operator-map-canvas></div>
                <div data-map-marker-layer></div>
                <aside class="panel-card operator-column operator-floating-rail operator-left-rail">
                    <div class="operator-tab-toolbar">
                        <div data-operator-active-tabs></div>
                    </div>
                </aside>
                <aside class="panel-card operator-column operator-floating-rail operator-right-rail">
                    <div class="operator-tab-toolbar">
                        <div data-operator-tabs></div>
                        ${Array.isArray(dashboard.pending_transfer_requests) && dashboard.pending_transfer_requests.length > 0
                            ? `<button class="surface-button secondary tiny" type="button" data-open-transfer-request="${dashboard.pending_transfer_requests[0].id}">Transfers (${dashboard.pending_transfer_requests.length})</button>`
                            : ''}
                    </div>
                </aside>
                <div data-map-summary></div>
                <aside class="panel-card operator-lanes-card operator-bottom-rail" aria-label="Operator team assignment lanes">
                    <div data-operator-lanes-board></div>
                </aside>
            </div>
        </section>
    `;

    root.innerHTML = sharedShell({
        title: '',
        kicker: 'Operator',
        statusLabel: '',
        content,
        brandHref: '/operator',
        showHero: false,
        shellClass: ['operator-shell-compact', alertToneClass].filter(Boolean).join(' '),
        mainClass: 'operator-main-compact',
        toolbarClass: 'operator-toolbar-compact',
        statusActions: ``,
    });

    appState.runtime.navbarActions = [];
    appState.runtime.navbarOnAction = null;
    appState.runtime.navbarContentEnd = () => {
        const mapControlsHost = document.createElement('div');
        mapControlsHost.className = 'operator-navbar-map-controls';
        mapControlsHost.setAttribute('data-operator-dashboard-map-controls', '');

        return mapControlsHost;
    };
    mountOperatorDashboardMap(root);
    mountSurfaceChrome(root, 'operator', bootstrap);
    wirePrimer(root, primerReport);

    const transferIndex = new Map((dashboard.pending_transfer_requests ?? []).map((item) => [String(item.id), item]));

    root.querySelectorAll('[data-open-workbench]').forEach((button) => {
        button.addEventListener('click', async () => {
            await openOperatorWorkbench(root, button.dataset.openWorkbench);
        });
    });

    root.querySelectorAll('[data-open-transfer-request]').forEach((button) => {
        button.addEventListener('click', async () => {
            const item = transferIndex.get(String(button.dataset.openTransferRequest));

            if (item) {
                await openTransferRequestModal(root, item);
            }
        });
    });

    root.querySelectorAll('[data-map-bubble]').forEach((button) => {
        button.addEventListener('click', async () => {
            await openOperatorWorkbench(root, button.dataset.mapBubble);
        });
    });

    mountOperatorActiveTabs(root, dashboard);
    mountOperatorUtilityTabs(root, dashboard);
    mountOperatorAssignmentBoard(root, dashboard);

    const liveTime = root.querySelector('[data-live-time]');
    const liveDate = root.querySelector('[data-live-date]');
    const updateClock = () => {
        const now = new Date();

        if (liveTime) {
            liveTime.textContent = now.toLocaleTimeString('en-PH', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
            });
        }

        if (liveDate) {
            liveDate.textContent = now.toLocaleDateString('en-PH', {
                weekday: 'short',
                month: 'short',
                day: '2-digit',
                year: 'numeric',
            });
        }
    };

    updateClock();
    appState.runtime.operatorClockTimer = window.setInterval(updateClock, 1000);

    appState.runtime.operatorIncomingCallItem = null;
    appState.runtime.operatorIncomingCallPhase = null;
    installOperatorSessionRestoredPresenceRefresh(root);
    publishOperatorDiscoveryPresence();

    if (firstPendingTransfer && !sessionStorage.getItem(`${TRANSFER_MODAL_DISMISS_PREFIX}${firstPendingTransfer.id}`)) {
        openTransferRequestModal(root, firstPendingTransfer);
    }
}

function incidentStatusToneClass(status) {
    const normalized = String(status ?? '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return normalized ? `is-${normalized}` : 'is-unknown';
}

function isTerminalIncidentStatus(status) {
    return ['discarded', 'resolved'].includes(String(status ?? '').trim().toLowerCase());
}

function incidentElapsedStartTime(item) {
    return item?.called_at ?? item?.created_at ?? null;
}

function incidentElapsedEndTime(item) {
    if (!isTerminalIncidentStatus(item?.status)) {
        return null;
    }

    return item?.resolved_at ?? item?.updated_at ?? null;
}

function incidentElapsedVariant(status) {
    const normalized = String(status ?? '').trim().toLowerCase();

    if (normalized === 'deferred') {
        return 'warn';
    }

    if (normalized === 'resolved') {
        return 'success';
    }

    if (normalized === 'discarded') {
        return 'danger';
    }

    return 'info';
}

function clearOperatorIncidentElapsedTimers(scope = 'all') {
    const timers = appState.runtime.operatorIncidentElapsedTimers;

    if (!(timers instanceof Map)) {
        appState.runtime.operatorIncidentElapsedTimers = new Map();
        return;
    }

    Array.from(timers.entries()).forEach(([key, timer]) => {
        if (scope !== 'all' && !String(key).startsWith(`${scope}:`)) {
            return;
        }

        timer?.destroy?.();
        timers.delete(key);
    });
}

function mountOperatorIncidentElapsedTime(card, item, scope) {
    const host = card.querySelector('[data-incident-elapsed]');
    const createElapsedTime = appState.helper.createElapsedTime;

    if (!host || !createElapsedTime) {
        if (host) {
            host.textContent = formatDateTime(item?.created_at);
        }
        return;
    }

    const startTime = incidentElapsedStartTime(item);
    const endTime = incidentElapsedEndTime(item);
    const running = !endTime && !isTerminalIncidentStatus(item?.status);
    const timerKey = `${scope}:${item?.id ?? ''}`;

    if (!startTime) {
        host.textContent = 'Pending';
        return;
    }

    if (!(appState.runtime.operatorIncidentElapsedTimers instanceof Map)) {
        appState.runtime.operatorIncidentElapsedTimers = new Map();
    }

    appState.runtime.operatorIncidentElapsedTimers.get(timerKey)?.destroy?.();
    appState.runtime.operatorIncidentElapsedTimers.set(timerKey, createElapsedTime(host, {
        startTime,
        endTime,
        running,
        format: 'compact',
        chrome: false,
        size: 'sm',
        variant: incidentElapsedVariant(item?.status),
        ariaLabel: running
            ? `Incident #${item?.display_id ?? item?.id} active duration`
            : `Incident #${item?.display_id ?? item?.id} final duration`,
    }));
}

function operatorActiveIncidentCardElement(item, root) {
    const statusToneClass = incidentStatusToneClass(item.status);
    const card = document.createElement('article');
    card.className = `operator-incident-card ${statusToneClass}`;
    card.dataset.focusMap = String(item.id ?? '');
    card.innerHTML = `
        <div class="operator-incident-meta">
            <div class="operator-incident-card-head">
                <strong>#${escapeHtml(item.display_id)}</strong>
                <span class="operator-incident-status ${statusToneClass}">${escapeHtml(item.status)}</span>
            </div>
            <span>${escapeHtml(item.actual_caller_name ?? 'Unknown caller')}</span>
            <span class="operator-incident-elapsed" data-incident-elapsed></span>
        </div>
    `;
    mountOperatorIncidentElapsedTime(card, item, 'active');
    card.tabIndex = 0;
    card.setAttribute('role', 'button');
    card.setAttribute('aria-label', `Open incident #${item.display_id ?? item.id}`);

    const openWorkbench = async () => {
        focusOperatorStageIncident(root, Number(item.id ?? 0));
        await openOperatorWorkbench(root, item.id);
    };

    card.addEventListener('click', () => {
        void openWorkbench();
    });
    card.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        event.preventDefault();
        void openWorkbench();
    });

    return card;
}

function operatorArchiveIncidentCardElement(item, root) {
    const statusToneClass = incidentStatusToneClass(item.status);
    const card = document.createElement('article');
    card.className = `operator-incident-card operator-archive-card ${statusToneClass}`;
    card.innerHTML = `
        <div class="operator-incident-meta">
            <div class="operator-incident-card-head">
                <strong>#${escapeHtml(item.display_id)}</strong>
                <span class="operator-incident-status ${statusToneClass}">${escapeHtml(item.status)}</span>
            </div>
            <span>${escapeHtml(item.actual_caller_name ?? 'Unknown caller')}</span>
            <span class="operator-incident-elapsed" data-incident-elapsed></span>
        </div>
    `;
    mountOperatorIncidentElapsedTime(card, item, 'archive');
    card.tabIndex = 0;
    card.setAttribute('role', 'button');
    card.setAttribute('aria-label', `Open archived incident #${item.display_id ?? item.id}`);

    const openWorkbench = async () => {
        await openOperatorWorkbench(root, item.id);
    };

    card.addEventListener('click', () => {
        void openWorkbench();
    });
    card.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        event.preventDefault();
        void openWorkbench();
    });

    return card;
}

function renderOperatorStageItems(root, items) {
    const markerLayer = root.querySelector('[data-map-marker-layer]');
    const summary = root.querySelector('[data-map-summary]');
    const stageItems = Array.isArray(items) ? items : [];
    const dashboardMap = appState.runtime.operatorDashboardMap ?? null;
    const mapCanRenderItems = dashboardMap?.hasRenderableItems?.(stageItems) ?? false;

    if (dashboardMap) {
        dashboardMap.setIncidents(stageItems);
    }

    if (markerLayer) {
        markerLayer.hidden = mapCanRenderItems;
        markerLayer.innerHTML = mapCanRenderItems ? '' : stageItems.map((item, index) => `
            <button
                class="operator-map-marker ${incidentStatusToneClass(item.status)} ${index === 0 ? 'is-focused' : ''}"
                style="left:${12 + ((index * 19) % 68)}%;top:${14 + ((index * 23) % 58)}%;"
                type="button"
                data-map-bubble="${item.id}"
            >
                <span>#${escapeHtml(item.display_id)}</span>
            </button>
        `).join('');
        markerLayer.querySelectorAll('[data-map-bubble]').forEach((button) => {
            button.addEventListener('click', async () => {
                await openOperatorWorkbench(root, button.dataset.mapBubble);
            });
        });
    }

    if (!summary) {
        return;
    }

    summary.className = '';
    summary.innerHTML = '';
}

function mountOperatorDashboardMap(root) {
    const container = root.querySelector('[data-operator-map-canvas]');
    const stage = root.querySelector('.operator-map-stage');

    if (!container || appState.runtime.operatorDashboardMap) {
        return;
    }

    const dashboardMap = createDashboardMap({
        container,
        configUrl: '/hotline.json',
        onIncidentClick: async (incidentId) => {
            await openOperatorWorkbench(root, incidentId);
        },
    });

    appState.runtime.operatorDashboardMap = dashboardMap;

    dashboardMap.init()
        .then(() => {
            stage?.classList.toggle('has-map', dashboardMap.isAvailable());
            if (!dashboardMap.isAvailable()) {
                const markerLayer = root.querySelector('[data-map-marker-layer]');
                if (markerLayer) {
                    markerLayer.hidden = false;
                }
            }
            dashboardMap.setIncidents(appState.operatorDashboard?.active_items ?? []);
            mountOperatorDashboardMapControls(root, dashboardMap);
            requestAnimationFrame(() => dashboardMap.resize());
        })
        .catch((error) => {
            console.warn('[operator-map] MapLibre dashboard map unavailable.', error);
            stage?.classList.remove('has-map');
            const markerLayer = root.querySelector('[data-map-marker-layer]');
            if (markerLayer) {
                markerLayer.hidden = false;
            }
        });
}

function mountOperatorDashboardMapControls(root, dashboardMap) {
    const host = root.querySelector('[data-operator-dashboard-map-controls]');
    const map = dashboardMap?.getMap?.() ?? null;

    if (!host || !map || !appState.helper.createMapControls) {
        return;
    }

    appState.runtime.operatorDashboardMapControls?.destroy?.();
    appState.runtime.operatorDashboardMapControls = appState.helper.createMapControls(host, {
        map,
        controls: ['zoom', 'compass', 'pitch', 'fit', 'layers'],
        orientation: 'horizontal',
        placement: 'top-right',
        compact: true,
        ariaLabel: 'Dashboard map controls',
        layers: [
            { id: 'incidents', label: 'Incidents', checked: true },
            ...(dashboardMap.hasTerrainLayer?.() ? [{ id: 'terrain', label: 'Terrain', checked: true }] : []),
            { id: 'poi', label: 'POI', checked: true },
        ],
        onResetNorth: ({ map: controlMap }) => {
            controlMap?.easeTo?.({
                bearing: 0,
                duration: 650,
                essential: true,
            });
        },
        onPitchChange: ({ pitch, map: controlMap }) => {
            controlMap?.easeTo?.({
                pitch,
                duration: 650,
                essential: true,
            });
        },
        onFit: () => {
            dashboardMap.fitIncidents?.({ duration: 700 });
        },
        onLayerToggle: ({ layerId, checked }) => {
            dashboardMap.setLayerGroupVisibility?.(layerId, checked);
        },
    });
}

function mountOperatorActivityLog(panel, root) {
    if (!panel) {
        return;
    }

    const renderList = (items) => {
        renderOperatorActivityTimeline(panel, root, items);
    };

    const cachedItems = appState.operatorDashboard?.activity_items;
    if (Array.isArray(cachedItems)) {
        scheduleOperatorRailRender(() => renderList(cachedItems));
        return;
    }

    if (appState.helper.createSkeleton) {
        trackSurfaceInstance(appState.helper.createSkeleton(panel, { lines: 4 }, {
            variant: 'card',
            className: 'operator-activity-list-skeleton',
        }));
    } else {
        panel.innerHTML = '<p class="surface-empty">Loading operator activity...</p>';
    }

    appState.runtime.operatorActivityItemsLoadPromise = fetchJson('/api/operator/activity')
        .then((response) => {
            const items = Array.isArray(response?.items) ? response.items : [];
            appState.operatorDashboard = {
                ...(appState.operatorDashboard ?? {}),
                activity_items: items,
            };
            renderList(items);

            return items;
        })
        .catch((error) => {
            if (appState.helper.createEmptyState) {
                panel.innerHTML = '';
                trackSurfaceInstance(appState.helper.createEmptyState(panel, {
                    title: 'Unable to load activity',
                    description: error?.response?.data?.message ?? 'Refresh the operator dashboard and try again.',
                }, {
                    chrome: false,
                    className: 'operator-activity-list-empty',
                    ariaLabel: 'Operator activity load error',
                }));
            } else {
                panel.innerHTML = '<p class="surface-empty">Unable to load operator activity.</p>';
            }

            return [];
        });
}

function operatorActivityTimelineStatus(item) {
    const description = String(item?.description ?? '').toLowerCase();
    const kind = String(item?.kind ?? '').toLowerCase();

    if (description.includes('discarded') || description.includes('rejected') || description.includes('cancelled')) {
        return 'cancelled';
    }

    if (description.includes('resolved') || description.includes('completed')) {
        return 'completed';
    }

    if (description.includes('accepted')) {
        return 'accepted';
    }

    if (description.includes('requested')) {
        return 'requested';
    }

    if (kind === 'team_assignment') {
        return 'assigned';
    }

    return 'assigned';
}

function operatorActivityIncidentStatus(item) {
    const description = String(item?.description ?? '').toLowerCase();

    if (description.includes('discarded')) {
        return 'Discarded';
    }

    if (description.includes('deferred')) {
        return 'Deferred';
    }

    if (description.includes('resolved') || description.includes('completed')) {
        return 'Resolved';
    }

    if (description.includes('active')) {
        return 'Active';
    }

    return '';
}

function renderOperatorActivityTimeline(panel, root, items) {
    panel.innerHTML = '';
    panel.style.setProperty('--operator-rail-content-max-height', `${operatorRailContentMaxHeight(panel, 360)}px`);

    const host = document.createElement('div');
    host.className = 'operator-activity-timeline-scroll';
    panel.appendChild(host);

    if (!appState.helper.createTimeline) {
        if (!Array.isArray(items) || items.length === 0) {
            host.innerHTML = '<p class="surface-empty">No operator activity has been recorded yet.</p>';
            return;
        }

        host.innerHTML = `
            <div class="operator-activity-list">${items.map((item) => `
                <button class="operator-activity-item" type="button" data-open-workbench="${item.incident_id}">
                    <strong>${escapeHtml(item.title ?? `Incident #${padIncidentId(item.incident_id)}`)}</strong>
                    <span>${escapeHtml(item.description ?? '')}</span>
                    <small>${escapeHtml(formatDateTime(item.created_at))}</small>
                </button>
            `).join('')}</div>
        `;
        wireWorkbenchTriggers(host, root);
        return;
    }

    const incidentByTimelineId = new Map();
    const timelineItems = (Array.isArray(items) ? items : []).map((item, index) => {
        const incidentId = item?.incident_id;
        const timelineId = `operator-activity-${incidentId ?? 'unknown'}-${index}`;
        const incidentStatus = operatorActivityIncidentStatus(item);
        if (incidentId) {
            incidentByTimelineId.set(timelineId, incidentId);
        }

        return {
            id: timelineId,
            title: incidentId ? `#${padIncidentId(incidentId)}` : (item?.title ?? 'Incident activity'),
            subtitle: incidentStatus,
            description: item?.description ?? '',
            timestamp: item?.created_at ?? null,
            status: operatorActivityTimelineStatus(item),
        };
    });

    trackSurfaceInstance(appState.helper.createTimeline(host, timelineItems, {
        ariaLabel: 'Operator activity timeline',
        className: 'operator-activity-timeline',
        density: 'compact',
        emptyText: 'No operator activity has been recorded yet.',
        groupByDate: true,
        onItemClick: async (item) => {
            const incidentId = incidentByTimelineId.get(item?.id);
            if (incidentId) {
                await openOperatorWorkbench(root, incidentId);
            }
        },
    }));
}

function wireWorkbenchTriggers(scope, root) {
    scope?.querySelectorAll?.('[data-open-workbench]')?.forEach((button) => {
        button.addEventListener('click', async () => {
            await openOperatorWorkbench(root, button.dataset.openWorkbench);
        });
    });
}

function focusOperatorStageIncident(root, incidentId) {
    appState.runtime.operatorDashboardMap?.focusIncident?.(incidentId);
    root.querySelectorAll('[data-map-bubble]').forEach((marker) => {
        marker.classList.toggle('is-focused', Number(marker.dataset.mapBubble) === Number(incidentId));
    });
}

function filterOperatorItems(items, searchTerm, fields) {
    const term = String(searchTerm ?? '').trim().toLowerCase();

    if (!term) {
        return Array.isArray(items) ? items : [];
    }

    return (Array.isArray(items) ? items : []).filter((item) => fields.some((field) => String(item?.[field] ?? '')
        .toLowerCase()
        .includes(term)));
}

function stretchOperatorVirtualList(host, listClassName) {
    if (!host) {
        return;
    }

    host.style.width = '100%';
    host.style.minWidth = '0';
    host.style.boxSizing = 'border-box';

    const list = host.querySelector(`.${listClassName}`);
    const viewport = list?.querySelector('.ui-virtual-list-viewport');
    const layer = list?.querySelector('.ui-virtual-list-layer');

    [list, viewport, layer].forEach((node) => {
        if (!node) {
            return;
        }

        node.style.width = '100%';
        node.style.maxWidth = 'none';
        node.style.minWidth = '0';
        node.style.boxSizing = 'border-box';
    });
}

function operatorRailContentMaxHeight(panel, fallback = 360) {
    const rail = panel?.closest?.('.operator-floating-rail');
    const stage = panel?.closest?.('.operator-map-stage');

    if (!rail || !stage) {
        return fallback;
    }

    const stageHeight = stage.clientHeight;
    const railTop = rail.offsetTop;
    const tablistHeight = rail.querySelector('.ui-tablist')?.offsetHeight ?? 0;
    const bottomRail = stage.querySelector('.operator-bottom-rail');
    const bottomReserve = bottomRail
        ? Math.ceil(bottomRail.offsetHeight + Math.max(12, stageHeight - bottomRail.offsetTop))
        : 0;
    const available = Math.floor(stageHeight - railTop - bottomReserve - tablistHeight - 48);

    return Math.max(140, Number.isFinite(available) ? available : fallback);
}

function scheduleOperatorRailRender(callback) {
    if (typeof window === 'undefined' || typeof window.requestAnimationFrame !== 'function') {
        callback();
        return;
    }

    window.requestAnimationFrame(callback);
}

function mountOperatorActiveList(root, dashboard, scope = root) {
    const panelHost = scope.querySelector('[data-active-items-panel]');
    const searchInput = scope.querySelector('[data-active-search]');

    if (!panelHost || !searchInput) {
        return;
    }

    const renderList = (items) => {
        clearOperatorIncidentElapsedTimers('active');
        const filteredItems = filterOperatorItems(items, searchInput.value, [
            'display_id',
            'actual_caller_name',
            'status',
        ]);

        panelHost.innerHTML = '';

        if (!filteredItems.length) {
            if (appState.helper.createEmptyState) {
                trackSurfaceInstance(appState.helper.createEmptyState(panelHost, {
                    title: 'No active or deferred incidents',
                    description: 'Assigned incidents will appear here when they become active or deferred.',
                }, {
                    chrome: false,
                    className: 'operator-active-list-empty',
                    ariaLabel: 'Active and deferred incidents empty state',
                }));
            } else {
                panelHost.innerHTML = '<p class="surface-empty">No active or deferred incidents are currently assigned.</p>';
            }
            return;
        }

        const rowHeight = 78;
        const toolbarHeight = scope.querySelector('.operator-rail-toolbar')?.offsetHeight ?? 0;
        const maxHeight = Math.max(140, operatorRailContentMaxHeight(scope, 360) - toolbarHeight - 12);
        const hasOverflow = filteredItems.length * rowHeight > maxHeight;

        trackSurfaceInstance(appState.helper.createVirtualList(panelHost, filteredItems, {
            className: `operator-active-virtual-list${hasOverflow ? ' has-overflow' : ''}`,
            chrome: false,
            ariaLabel: 'Active and deferred incidents',
            height: Math.min(maxHeight, Math.max(rowHeight, filteredItems.length * rowHeight)),
            rowHeight,
            overscan: 2,
            renderItem: (item) => operatorActiveIncidentCardElement(item, root),
        }));
        stretchOperatorVirtualList(panelHost, 'operator-active-virtual-list');
    };

    searchInput.addEventListener('input', () => renderList(operatorActiveItems()));

    const cachedItems = appState.operatorDashboard?.active_items;
    if (Array.isArray(cachedItems)) {
        scheduleOperatorRailRender(() => renderList(cachedItems));
        return;
    }

    if (appState.helper.createSkeleton) {
        trackSurfaceInstance(appState.helper.createSkeleton(panelHost, { lines: 4 }, {
            variant: 'card',
            className: 'operator-active-list-skeleton',
        }));
    } else {
        panelHost.innerHTML = '<p class="surface-empty">Loading active incidents...</p>';
    }

    appState.runtime.operatorActiveItemsLoadPromise = fetchJson('/api/operator/incidents?status=Active,Deferred')
        .then((response) => {
            const items = Array.isArray(response?.items) ? response.items : [];
            appState.operatorDashboard = {
                ...(appState.operatorDashboard ?? {}),
                active_items: items,
            };
            renderOperatorStageItems(root, items);
            renderList(items);
            mountOperatorAssignmentBoard(root, appState.operatorDashboard);

            return items;
        })
        .catch((error) => {
            panelHost.innerHTML = '';
            if (appState.helper.createEmptyState) {
                trackSurfaceInstance(appState.helper.createEmptyState(panelHost, {
                    title: 'Unable to load incidents',
                    description: error?.response?.data?.message ?? 'Refresh the operator dashboard and try again.',
                }, {
                    chrome: false,
                    className: 'operator-active-list-empty',
                    ariaLabel: 'Active and deferred incidents load error',
                }));
            } else {
                panelHost.innerHTML = '<p class="surface-empty">Unable to load active or deferred incidents.</p>';
            }

            return [];
        });
}

function mountOperatorActiveTabs(root, dashboard) {
    const tabsHost = root.querySelector('[data-operator-active-tabs]');

    if (!tabsHost || !appState.helper.createTabs) {
        mountOperatorActiveList(root, dashboard);
        return;
    }

    trackSurfaceInstance(appState.helper.createTabs(tabsHost, {
        activeId: 'active',
        ariaLabel: 'Operator active incident tabs',
        tabs: [
            {
                id: 'active',
                label: 'Active + Deferred',
                render: (panel) => {
                    panel.innerHTML = `
                        <div class="operator-rail-toolbar">
                            <input class="operator-search-input" type="search" placeholder="Search incidents..." data-active-search>
                        </div>
                        <div data-active-items-panel></div>
                    `;
                    mountOperatorActiveList(root, dashboard, panel);
                },
            },
        ],
    }));
}

function mountOperatorArchiveList(panel, root) {
    if (!panel) {
        return;
    }

    panel.innerHTML = `
        <div class="operator-rail-toolbar">
            <input class="operator-search-input" type="search" placeholder="Search archived incidents..." data-archive-search>
        </div>
        <div class="operator-archive-list-host" data-archive-items-panel></div>
    `;

    const listHost = panel.querySelector('[data-archive-items-panel]');
    const searchInput = panel.querySelector('[data-archive-search]');

    if (!listHost || !searchInput) {
        return;
    }

    const renderList = (items) => {
        clearOperatorIncidentElapsedTimers('archive');
        const archiveItems = filterOperatorItems(items, searchInput.value, [
            'display_id',
            'actual_caller_name',
            'status',
        ]);

        listHost.innerHTML = '';

        if (!archiveItems.length) {
            if (appState.helper.createEmptyState) {
                trackSurfaceInstance(appState.helper.createEmptyState(listHost, {
                    title: 'No archived incidents',
                    description: 'Resolved and discarded incidents will appear here.',
                }, {
                    chrome: false,
                    className: 'operator-archive-list-empty',
                    ariaLabel: 'Archived incidents empty state',
                }));
            } else {
                listHost.innerHTML = '<p class="surface-empty">Resolved and discarded incidents will appear here.</p>';
            }
            return;
        }

        const rowHeight = 78;
        const toolbarHeight = panel.querySelector('.operator-rail-toolbar')?.offsetHeight ?? 0;
        const maxHeight = Math.max(220, operatorRailContentMaxHeight(panel, 360) - toolbarHeight - 12);
        const hasOverflow = archiveItems.length * rowHeight > maxHeight;

        trackSurfaceInstance(appState.helper.createVirtualList(listHost, archiveItems, {
            className: `operator-archive-virtual-list${hasOverflow ? ' has-overflow' : ''}`,
            chrome: false,
            ariaLabel: 'Archived incidents',
            height: Math.min(maxHeight, Math.max(rowHeight, archiveItems.length * rowHeight)),
            rowHeight,
            overscan: 2,
            renderItem: (item) => operatorArchiveIncidentCardElement(item, root),
        }));
        stretchOperatorVirtualList(listHost, 'operator-archive-virtual-list');
    };

    searchInput.addEventListener('input', () => renderList(appState.operatorDashboard?.archived_items ?? []));

    const cachedItems = appState.operatorDashboard?.archived_items;
    if (Array.isArray(cachedItems)) {
        scheduleOperatorRailRender(() => renderList(cachedItems));
        return;
    }

    if (appState.helper.createSkeleton) {
        trackSurfaceInstance(appState.helper.createSkeleton(listHost, { lines: 4 }, {
            variant: 'card',
            className: 'operator-archive-list-skeleton',
        }));
    } else {
        listHost.innerHTML = '<p class="surface-empty">Loading archived incidents...</p>';
    }

    appState.runtime.operatorArchiveItemsLoadPromise = fetchJson('/api/operator/incidents?status=Resolved,Discarded')
        .then((response) => {
            const items = Array.isArray(response?.items) ? response.items : [];
            appState.operatorDashboard = {
                ...(appState.operatorDashboard ?? {}),
                archived_items: items,
            };
            renderList(items);

            return items;
        })
        .catch((error) => {
            listHost.innerHTML = '';
            if (appState.helper.createEmptyState) {
                trackSurfaceInstance(appState.helper.createEmptyState(listHost, {
                    title: 'Unable to load archive',
                    description: error?.response?.data?.message ?? 'Refresh the operator dashboard and try again.',
                }, {
                    chrome: false,
                    className: 'operator-archive-list-empty',
                    ariaLabel: 'Archived incidents load error',
                }));
            } else {
                listHost.innerHTML = '<p class="surface-empty">Unable to load archived incidents.</p>';
            }

            return [];
        });
}

function mountOperatorUtilityTabs(root, dashboard) {
    const tabsHost = root.querySelector('[data-operator-tabs]');

    if (!tabsHost || !appState.helper.createTabs) {
        return;
    }

    const buildTabs = () => [
            {
                id: 'archive',
                label: 'Archive',
                render: (panel) => {
                    mountOperatorArchiveList(panel, root);
                },
            },
            {
                id: 'activity',
                label: 'Activity Log',
                render: (panel) => {
                    mountOperatorActivityLog(panel, root);
                },
            },
        ];

    const tabs = trackSurfaceInstance(appState.helper.createTabs(tabsHost, {
        activeId: 'archive',
        tabs: buildTabs(),
        ariaLabel: 'Operator utility tabs',
    }));
}

function mountOperatorAssignmentBoard(root, dashboard) {
    const boardHost = root.querySelector('[data-operator-lanes-board]');

    if (!boardHost || !appState.helper.createKanban) {
        return;
    }

    const laneById = new Map();
    const lanes = (Array.isArray(dashboard.team_assignment_lanes)
        ? dashboard.team_assignment_lanes
        : [])
        .map((lane) => ({
            ...lane,
            title: formatStatusLabel(lane?.title ?? lane?.id ?? ''),
            cards: [],
        }));

    lanes.forEach((lane) => {
        laneById.set(String(lane?.id ?? ''), lane);
    });

    operatorActiveItems().forEach((incident) => {
        const incidentId = Number(incident?.id ?? 0);
        const displayId = String(incident?.display_id ?? padIncidentId(incidentId));

        (Array.isArray(incident?.team_assignments) ? incident.team_assignments : []).forEach((assignment) => {
            const laneId = mapTeamAssignmentStatusToApi(assignment?.status);
            const lane = laneById.get(laneId);

            if (!lane) {
                return;
            }

            const contact = String(assignment?.contact_person ?? '').trim();

            lane.cards.push({
                id: String(assignment?.id ?? `${incidentId}-${assignment?.team_id ?? laneId}`),
                incident_id: incidentId,
                assignment_id: assignment?.id ?? null,
                title: assignment?.team?.name ?? 'Unknown team',
                meta: contact ? `#${displayId} • ${contact}` : `#${displayId} • No contact person`,
                status: laneId,
                assigned_at: assignment?.assigned_at ?? null,
                updated_at: assignment?.updated_at ?? null,
                raw: {
                    ...assignment,
                    incident_id: incidentId,
                    incident,
                },
            });
        });
    });

    appState.runtime.operatorAssignmentBoard?.destroy?.();
    boardHost.replaceChildren();

    appState.runtime.operatorAssignmentBoard = trackSurfaceInstance(appState.helper.createKanban(boardHost, lanes, {
        ariaLabel: 'Operator team assignment lanes',
        draggable: false,
        keyboardMoves: false,
        onCardClick(card) {
            if (card?.raw?.incident_id) {
                void openOperatorWorkbench(root, card.raw.incident_id);
            }
        },
    }));
}


export async function renderOperatorSurface(root, bootstrap) {
    clearOperatorIncidentElapsedTimers();
    const primerReport = evaluateDevicePrimer('operator');
    installOperatorMediaConsoleApi();
    operatorMediaManagersRuntime().start();
    const dashboard = await fetchJson('/api/operator/dashboard');
    appState.operatorDashboard = dashboard;
    renderOperator(root, bootstrap, dashboard, primerReport);
    await connectOperatorRealtimeStream(root);
    await (appState.runtime.operatorActiveItemsLoadPromise ?? Promise.resolve([]));

    const retainedIncidentId = Number(sessionStorage.getItem(OPERATOR_WORKBENCH_KEY) ?? 0);
    const retainedIncidentKnown = retainedIncidentId > 0
        && operatorActiveItems().some((item) => Number(item?.id ?? 0) === retainedIncidentId);

    if (!retainedIncidentKnown) {
        sessionStorage.removeItem(OPERATOR_WORKBENCH_KEY);
        sessionStorage.removeItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY);
        clearOperatorWorkbenchCallStatusPoll();
        return;
    }

    if (retainedIncidentId > 0) {
        try {
            await openOperatorWorkbench(root, retainedIncidentId);
        } catch (_error) {
            sessionStorage.removeItem(OPERATOR_WORKBENCH_KEY);
            sessionStorage.removeItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY);
        }
    }
}
