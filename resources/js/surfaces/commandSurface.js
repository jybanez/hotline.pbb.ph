import {
    appState,
    escapeHtml,
    fetchJson,
    formatDateTime,
    formatStatusLabel,
    handleCommandBroadcastEnvelope,
    ensureHelperUi,
    mountSurfaceChrome,
    sharedShell,
    showToast,
    trackSurfaceInstance,
} from './surfaceShared.js';
import { createOperatorPresenceAvatars, normalizeOperatorPresenceItems } from '../features/operatorPresenceAvatars.js';
import { createDashboardMap } from '../maps/dashboardMap.js';
import {
    buildPresenceSubscribePayload,
    buildRoomJoinPayload,
    listPresenceRosterItems,
    parseRealtimeEnvelope,
    reducePresenceRosterEvent,
    RealtimeSocketClient,
} from '../../../../realtime/resources/js/sdk/index.js';

const SITREP_INDEX_URL = '/api/command/sitreps';
const COMMAND_INCIDENTS_URL = '/api/command/incidents';
const COMMAND_BROADCASTS_URL = '/api/command/broadcasts';
const COMMAND_ALERT_LEVEL_URL = '/api/command/alert-level';
const CALL_DISCOVERY_ROOM = 'presence.global.hotline';
const INCIDENT_UPDATE_EVENT = 'hotline.incident.updated';
const COMMAND_REALTIME_RECONNECT_MIN_MS = 1000;
const COMMAND_REALTIME_RECONNECT_MAX_MS = 15000;
const COMMAND_ASSIGNMENT_LANES = [
    { id: 'assigned', title: 'Assigned' },
    { id: 'requested', title: 'Requested' },
    { id: 'accepted', title: 'Accepted' },
    { id: 'en_route', title: 'En Route' },
    { id: 'on_scene', title: 'On Scene' },
    { id: 'completed', title: 'Completed' },
    { id: 'cancelled', title: 'Cancelled' },
];
const COMMAND_INCIDENT_STATUS_FILTER_OPTIONS = [
    { value: 'active', label: 'Active' },
    { value: 'deferred', label: 'Deferred' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'discarded', label: 'Discarded' },
];
const COMMAND_ALERT_LEVEL_OPTIONS = [
    { value: 'Normal', label: 'Normal' },
    { value: 'Elevated', label: 'Elevated' },
    { value: 'Critical', label: 'Critical' },
];

let sitrepGridInstance = null;
let commandIncidentsGridInstance = null;
let commandTabsInstance = null;
let commandSplitterInstance = null;
let sitrepPreviewModal = null;
let sitrepPreviewIframeHost = null;
let sitrepPreviewDownloadMenu = null;
let sitrepPreviewCurrentRow = null;
let createModalFactory = null;
let createIframeHostFactory = null;
let createDropdownFactory = null;
let isGeneratingSitrep = false;
let latestSitreps = [];
let latestIncidents = [];
let commandMapResizeObserver = null;
let commandOperatorPresenceAvatars = null;
let commandAssignmentBoardInstance = null;
let commandIncidentStatusFilters = [];
let commandIncidentStatusSelectInstance = null;
let commandWorkbenchRenderFrame = null;

export async function renderCommandSurface(root, bootstrap) {
    appState.runtime.navbarItems = [];
    appState.runtime.navbarActiveId = 'command';
    appState.runtime.navbarActions = [{
        id: 'change_alert_level',
        label: 'Alert',
        icon: appState.helper.createIcon?.('status.warning', {
            size: 18,
            ariaLabel: 'Alert',
        })?.outerHTML,
    }, {
        id: 'broadcast_message',
        label: 'Broadcast',
        icon: appState.helper.createIcon?.('comms.message', {
            size: 18,
            ariaLabel: 'Broadcast',
        })?.outerHTML,
    }];
    appState.runtime.navbarProfileMenuItems = [];
    appState.runtime.navbarOnAction = (action) => {
        if (action?.id === 'change_alert_level') {
            void openCommandAlertLevelModal(root);
            return;
        }

        if (action?.id === 'broadcast_message') {
            void openCommandBroadcastModal(root);
        }
    };
    appState.runtime.navbarOnActionMenuSelect = null;
    appState.runtime.navbarContentStart = () => {
        const mapControlsHost = document.createElement('div');
        mapControlsHost.className = 'command-navbar-map-controls';
        mapControlsHost.setAttribute('data-command-map-controls', '');

        return mapControlsHost;
    };
    appState.runtime.navbarContentCenter = null;
    appState.runtime.navbarContentEnd = null;

    const alertToneClass = commandAlertToneClass(bootstrap?.alert_level);

    root.innerHTML = sharedShell({
        title: 'PBB Hotline Command',
        kicker: 'Command',
        statusLabel: '',
        brandHref: '/command',
        showHero: false,
        shellClass: 'command-shell',
        mainClass: 'command-main',
        content: `
            <div class="command-fixed-command" aria-live="polite">
                <div class="command-alert-clock ${alertToneClass}" data-command-alert-clock>
                    <span class="command-alert-level" data-command-alert-level>Alert: ${escapeHtml(String(bootstrap?.alert_level ?? 'Normal').toUpperCase())}</span>
                    <strong class="command-live-time" data-command-live-time>--:--:--</strong>
                    <small class="command-live-date" data-command-live-date>---</small>
                </div>
            </div>
            <section class="command-workspace" aria-label="Command workspace">
                <div class="command-splitter-host" data-command-splitter>
                    <section class="command-map-pane panel-card" aria-label="Command incident map">
                        <div class="command-map-canvas" data-command-map-canvas></div>
                        <div class="command-map-empty" data-command-map-empty>Loading command map...</div>
                        <div class="command-map-operator-presence" data-command-operator-presence aria-label="Online operator presence"></div>
                    </section>
                    <section class="command-command-pane panel-card" aria-label="Command details">
                        <div data-command-tabs></div>
                    </section>
                </div>
                <section class="panel-card command-lanes-card" aria-label="Command team assignment lanes">
                    <div data-command-lanes-board></div>
                </section>
            </section>
        `,
    });

    mountSurfaceChrome(root, 'command', bootstrap);
    mountCommandAlertClock(root, bootstrap);
    await mountCommandWorkspace(root);
    void connectCommandRealtimeStream(root);
    await Promise.all([
        loadSitreps(root),
        loadCommandIncidents(root),
    ]);
}

function commandAlertToneClass(alertLevel) {
    const normalized = String(alertLevel ?? '').trim().toLowerCase();

    if (normalized === 'elevated') {
        return 'is-alert-elevated';
    }

    if (normalized === 'critical') {
        return 'is-alert-critical';
    }

    return '';
}

function setCommandAlertLevel(root, alertLevel) {
    const label = root?.querySelector('[data-command-alert-level]');
    const clock = root?.querySelector('[data-command-alert-clock]');
    const shell = root?.querySelector('.command-shell');
    const toneClass = commandAlertToneClass(alertLevel);

    if (label) {
        label.textContent = `Alert: ${String(alertLevel ?? 'Normal').toUpperCase()}`;
    }

    if (clock) {
        clock.classList.remove('is-alert-elevated', 'is-alert-critical');

        if (toneClass) {
            clock.classList.add(toneClass);
        }
    }

    if (shell) {
        shell.classList.remove('is-alert-elevated', 'is-alert-critical');

        if (toneClass) {
            shell.classList.add(toneClass);
        }
    }

    renderCurrentSnapshot(root);
}

function applyCommandAlertLevel(root, alertLevel) {
    appState.bootstrap = {
        ...(appState.bootstrap ?? {}),
        alert_level: alertLevel,
        settings: {
            ...(appState.bootstrap?.settings ?? {}),
            alert_level: alertLevel,
        },
    };

    setCommandAlertLevel(root, alertLevel);
}

function mountCommandAlertClock(root, bootstrap) {
    if (appState.runtime.commandClockTimer) {
        window.clearInterval(appState.runtime.commandClockTimer);
        appState.runtime.commandClockTimer = null;
    }

    const updateClock = () => {
        const liveTime = root.querySelector('[data-command-live-time]');
        const liveDate = root.querySelector('[data-command-live-date]');
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

    setCommandAlertLevel(root, bootstrap?.alert_level);
    updateClock();
    appState.runtime.commandClockTimer = window.setInterval(updateClock, 1000);
}

function commandRealtimeReconnectRuntime() {
    if (!appState.runtime.commandRealtimeReconnect) {
        appState.runtime.commandRealtimeReconnect = {
            attempts: 0,
            connecting: false,
            timerId: null,
        };
    }

    return appState.runtime.commandRealtimeReconnect;
}

function clearCommandRealtimeReconnectTimer() {
    const runtime = commandRealtimeReconnectRuntime();

    if (runtime.timerId) {
        window.clearTimeout(runtime.timerId);
        runtime.timerId = null;
    }

    appState.runtime.commandRealtimeSignal?.setReconnectRuntime?.(runtime);
}

function scheduleCommandRealtimeReconnect(root) {
    if (!appState.bootstrap?.authenticated || appState.activeSurface !== 'command') {
        return;
    }

    const runtime = commandRealtimeReconnectRuntime();

    if (runtime.timerId || runtime.connecting) {
        return;
    }

    runtime.attempts = Math.min(runtime.attempts + 1, 8);

    const baseDelay = Math.min(
        COMMAND_REALTIME_RECONNECT_MAX_MS,
        COMMAND_REALTIME_RECONNECT_MIN_MS * (2 ** Math.max(0, runtime.attempts - 1)),
    );
    const jitter = Math.floor(Math.random() * 350);

    runtime.timerId = window.setTimeout(() => {
        runtime.timerId = null;
        appState.runtime.commandRealtimeSignal?.setReconnectRuntime?.(runtime);
        void connectCommandRealtimeStream(root, { reconnect: true });
    }, baseDelay + jitter);
    appState.runtime.commandRealtimeSignal?.setReconnectRuntime?.(runtime);
}

function commandPresenceRuntime() {
    if (!appState.runtime.commandPresence) {
        appState.runtime.commandPresence = {
            roster: {},
            subscribed: false,
        };
    }

    return appState.runtime.commandPresence;
}

function mergeCommandPresenceRosterEvent(roster, payload) {
    const nextRoster = reducePresenceRosterEvent(roster, payload);
    const subject = payload?.subject && typeof payload.subject === 'object' ? payload.subject : {};
    const rosterKey = String(subject.session_id || subject.user_id || '').trim();
    const meta = payload?.meta && typeof payload.meta === 'object' ? payload.meta : null;
    const state = String(payload?.state ?? '').trim().toLowerCase();

    if (rosterKey && meta && state !== 'offline' && nextRoster[rosterKey]) {
        nextRoster[rosterKey] = {
            ...nextRoster[rosterKey],
            meta,
        };
    }

    return nextRoster;
}

function mergeCommandPresenceRosterSnapshot(roster, entries) {
    return (Array.isArray(entries) ? entries : []).reduce(
        (nextRoster, entry) => mergeCommandPresenceRosterEvent(nextRoster, entry),
        roster,
    );
}

function commandPresenceRosterItems() {
    return listPresenceRosterItems(commandPresenceRuntime().roster);
}

function normalizeCommandIncidentLookupKey(value) {
    const numeric = Number(value ?? 0);

    if (!Number.isFinite(numeric) || numeric <= 0) {
        return '';
    }

    return String(Math.trunc(numeric));
}

function commandWorkbenchIncidentDetails() {
    const details = new Map();

    commandPresenceRosterItems().forEach((entry) => {
        const meta = entry?.meta && typeof entry.meta === 'object' ? entry.meta : {};
        const incidentKeys = [
            meta.incident_id,
            meta.active_incident_id,
            meta.workbench_incident_id,
            meta.incident_display_id,
            entry?.incidentId,
        ]
            .map((value) => normalizeCommandIncidentLookupKey(value))
            .filter(Boolean);

        if (!incidentKeys.length) {
            return;
        }

        const operatorName = String(
            meta.operator_name
            ?? entry?.name
            ?? entry?.displayName
            ?? (meta.operator_id ? `Operator #${meta.operator_id}` : 'Operator'),
        ).trim();
        const label = operatorName || 'Operator';

        Array.from(new Set(incidentKeys)).forEach((incidentKey) => {
            const current = details.get(incidentKey) ?? [];
            current.push(label);
            details.set(incidentKey, current);
        });
    });

    return details;
}

function commandIncidentsWithWorkbenchState(items = latestIncidents) {
    const workbenchDetails = commandWorkbenchIncidentDetails();

    return (Array.isArray(items) ? items : []).map((incident) => {
        const incidentKeys = [
            incident?.id,
            incident?.display_id,
        ]
            .map((value) => normalizeCommandIncidentLookupKey(value))
            .filter(Boolean);
        const operators = Array.from(new Set(
            incidentKeys.flatMap((incidentKey) => workbenchDetails.get(incidentKey) ?? []),
        ));

        return {
            ...incident,
            workbench_active: operators.length > 0,
            workbench_operator_names: operators,
            workbench_label: operators.length
                ? `${operators.length} on workbench`
                : '',
        };
    });
}

function refreshCommandWorkbenchIndicators(root) {
    appState.runtime.commandDashboardMap?.setIncidents?.(commandIncidentsWithWorkbenchState());

    if (commandWorkbenchRenderFrame) {
        window.cancelAnimationFrame(commandWorkbenchRenderFrame);
    }

    commandWorkbenchRenderFrame = window.requestAnimationFrame(() => {
        commandWorkbenchRenderFrame = null;
        renderCommandIncidents(root);
    });
}

function mountCommandOperatorPresence(root) {
    const host = root.querySelector('[data-command-operator-presence]');

    if (!host) {
        return;
    }

    commandOperatorPresenceAvatars?.destroy?.();
    commandOperatorPresenceAvatars = createOperatorPresenceAvatars(host, {
        items: commandPresenceRosterItems(),
        currentUserId: appState.bootstrap?.user?.id,
        emptyText: 'No online operators visible in presence.',
    });
    trackSurfaceInstance(commandOperatorPresenceAvatars);
}

function refreshCommandOperatorPresence(root) {
    if (!root.querySelector('[data-command-operator-presence]')) {
        return;
    }

    if (!commandOperatorPresenceAvatars) {
        mountCommandOperatorPresence(root);
        return;
    }

    commandOperatorPresenceAvatars.update(commandPresenceRosterItems());
}

function resetCommandPresence() {
    const runtime = appState.runtime.commandPresence;

    if (runtime) {
        runtime.roster = {};
        runtime.subscribed = false;
    }

    if (commandWorkbenchRenderFrame) {
        window.cancelAnimationFrame(commandWorkbenchRenderFrame);
        commandWorkbenchRenderFrame = null;
    }

    commandOperatorPresenceAvatars?.destroy?.();
    commandOperatorPresenceAvatars = null;
}

function resetCommandPresenceSubscription() {
    const runtime = appState.runtime.commandPresence;

    if (runtime) {
        runtime.subscribed = false;
    }
}

function subscribeCommandPresence(client) {
    const runtime = commandPresenceRuntime();

    if (runtime.subscribed || !client?.isOpen?.()) {
        return;
    }

    runtime.subscribed = true;
    client.sendRequest('presence.subscribe', CALL_DISCOVERY_ROOM, buildPresenceSubscribePayload(CALL_DISCOVERY_ROOM));
}

function syncCommandPresenceRosterSnapshot(root, envelope) {
    if (envelope?.phase !== 'ack' || envelope?.type !== 'presence.subscribe') {
        return;
    }

    const runtime = commandPresenceRuntime();
    runtime.roster = mergeCommandPresenceRosterSnapshot(runtime.roster, envelope?.payload?.roster);
    refreshCommandOperatorPresence(root);
    refreshCommandWorkbenchIndicators(root);
}

function syncCommandPresenceRoster(root, envelope) {
    if (envelope?.phase !== 'event' || envelope?.type !== 'presence.state.event') {
        return;
    }

    const runtime = commandPresenceRuntime();
    runtime.roster = mergeCommandPresenceRosterEvent(runtime.roster, envelope.payload);
    refreshCommandOperatorPresence(root);
    refreshCommandWorkbenchIndicators(root);
}

function commandIncidentIsOpenForAssignment(incident) {
    const status = String(incident?.status ?? '').trim().toLowerCase();

    return status === 'active' || status === 'deferred';
}

function mapCommandTeamAssignmentStatus(status) {
    const normalized = String(status ?? '')
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_');

    return ({
        assigned: 'assigned',
        requested: 'requested',
        accepted: 'accepted',
        en_route: 'en_route',
        enroute: 'en_route',
        on_scene: 'on_scene',
        onscene: 'on_scene',
        completed: 'completed',
        cancelled: 'cancelled',
        canceled: 'cancelled',
    })[normalized] ?? 'assigned';
}

function refreshCommandIncidentViews(root) {
    appState.runtime.commandDashboardMap?.setIncidents?.(commandIncidentsWithWorkbenchState());
    renderCommandIncidents(root);
    renderCurrentSnapshot(root);
    mountCommandAssignmentBoard(root);
}

function applyCommandIncidentPatch(root, payload = {}) {
    const incidentId = Number(payload?.incident_id ?? payload?.incident?.id ?? payload?.id ?? 0);

    if (!incidentId) {
        return;
    }

    const patch = payload?.patch && typeof payload.patch === 'object'
        ? payload.patch
        : (
            payload?.incident && typeof payload.incident === 'object'
                ? payload.incident
                : {}
        );

    let matched = false;
    latestIncidents = latestIncidents.map((incident) => {
        if (Number(incident?.id ?? 0) !== incidentId) {
            return incident;
        }

        matched = true;

        const nextIncident = {
            ...incident,
            ...patch,
            id: incident.id,
        };

        if (
            Object.prototype.hasOwnProperty.call(patch, 'status')
            && !Object.prototype.hasOwnProperty.call(patch, 'status_label')
        ) {
            nextIncident.status_label = formatStatusLabel(patch.status);
        }

        return nextIncident;
    });

    if (!matched && payload?.incident && typeof payload.incident === 'object') {
        latestIncidents = [
            ...latestIncidents,
            payload.incident,
        ];
    }

    refreshCommandIncidentViews(root);
}

async function connectCommandRealtimeStream(root, options = {}) {
    if (!appState.bootstrap?.authenticated || appState.activeSurface !== 'command') {
        return;
    }

    if (appState.runtime.commandRealtimeStream?.client) {
        setCommandAlertLevel(root, appState.bootstrap?.alert_level);
        return;
    }

    const reconnectRuntime = commandRealtimeReconnectRuntime();

    if (reconnectRuntime.connecting) {
        return;
    }

    reconnectRuntime.connecting = true;
    appState.runtime.commandRealtimeSignal?.setReconnectRuntime?.(reconnectRuntime);

    try {
        const admission = await fetchJson('/api/realtime/admission/command', {
            method: 'post',
            data: {
                context_type: 'surface_runtime',
                context_id: 0,
            },
        });

        const rooms = Array.from(new Set([
            ...(Array.isArray(admission?.rooms) ? admission.rooms.filter(Boolean) : []),
            CALL_DISCOVERY_ROOM,
        ]));

        if (!admission?.token || !admission?.websocket_url || rooms.length === 0) {
            reconnectRuntime.connecting = false;
            scheduleCommandRealtimeReconnect(root);
            return;
        }

        let streamRef = null;
        resetCommandPresenceSubscription();

        const client = new RealtimeSocketClient({
            websocketUrl: admission.websocket_url,
            token: admission.token,
            requestPrefix: 'command_surface',
            onOpen() {
                reconnectRuntime.connecting = false;
                reconnectRuntime.attempts = 0;
                clearCommandRealtimeReconnectTimer();
                setCommandAlertLevel(root, appState.bootstrap?.alert_level);
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

                if (appState.runtime.commandRealtimeStream?.client === client) {
                    appState.runtime.commandRealtimeStream.client = null;
                }

                resetCommandPresenceSubscription();
                scheduleCommandRealtimeReconnect(root);
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
                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'room.join.request') {
                    const joinedRoom = String(envelope?.room ?? envelope?.payload?.room ?? '').trim();

                    if (joinedRoom === CALL_DISCOVERY_ROOM) {
                        subscribeCommandPresence(client);
                    }

                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'presence.subscribe') {
                    syncCommandPresenceRosterSnapshot(root, envelope);
                    return;
                }

                if (envelope?.phase === 'event' && envelope?.type === 'presence.state.event') {
                    syncCommandPresenceRoster(root, envelope);
                    return;
                }

                if (envelope?.phase === 'event' && envelope?.type === INCIDENT_UPDATE_EVENT) {
                    applyCommandIncidentPatch(root, envelope.payload ?? {});
                    return;
                }

                if (handleCommandBroadcastEnvelope(envelope)) {
                    return;
                }

                if (envelope?.phase !== 'event' || envelope?.type !== 'hotline.alert_level.changed') {
                    return;
                }

                const nextAlertLevel = String(envelope?.payload?.alert_level ?? '').trim();

                if (!nextAlertLevel || String(appState.bootstrap?.alert_level ?? '').trim() === nextAlertLevel) {
                    return;
                }

                applyCommandAlertLevel(root, nextAlertLevel);
                showToast(`Alert level changed to ${nextAlertLevel}.`, 'info');
            },
        });

        streamRef = {
            client,
            destroyed: false,
            destroy() {
                this.destroyed = true;
                clearCommandRealtimeReconnectTimer();
                resetCommandPresence();
                client.close();
            },
        };

        appState.runtime.commandRealtimeStream = streamRef;
        trackSurfaceInstance(streamRef);
        appState.runtime.commandRealtimeSignal?.setReconnectRuntime?.(reconnectRuntime);
        appState.runtime.commandRealtimeSignal?.bindClient?.(client);
        client.connect();
    } catch (error) {
        reconnectRuntime.connecting = false;
        appState.runtime.commandRealtimeStream = null;
        appState.runtime.commandRealtimeSignal?.setReconnectRuntime?.(reconnectRuntime);

        if (!options.reconnect) {
            console.warn('Command Realtime surface stream unavailable.', error);
        }

        scheduleCommandRealtimeReconnect(root);
    }
}

async function mountCommandWorkspace(root) {
    const splitterHost = root.querySelector('[data-command-splitter]');
    const mapPane = root.querySelector('.command-map-pane');
    const commandPane = root.querySelector('.command-command-pane');

    mountCommandTabs(root);
    mountCommandOperatorPresence(root);
    mountCommandAssignmentBoard(root);

    if (splitterHost && mapPane && commandPane) {
        try {
            const createSplitter = await appState.helper.uiLoader?.get?.('ui.splitter');

            if (createSplitter) {
                commandSplitterInstance?.destroy?.();
                splitterHost.classList.add('has-helper-splitter');
                commandSplitterInstance = createSplitter(splitterHost, {
                    className: 'command-main-splitter',
                    orientation: 'horizontal',
                    initialRatio: 0.58,
                    minRatio: 0.38,
                    maxRatio: 0.72,
                    paneA: mapPane,
                    paneB: commandPane,
                    onResize: () => {
                        appState.runtime.commandDashboardMap?.resize?.();
                    },
                });
                trackSurfaceInstance(commandSplitterInstance);
            }
        } catch (_) {
            splitterHost.classList.add('is-fallback-layout');
        }
    }

    mountCommandDashboardMap(root);
}

function mountCommandTabs(root) {
    const tabsHost = root.querySelector('[data-command-tabs]');

    if (!tabsHost || !appState.helper.createTabs) {
        return;
    }

    commandTabsInstance?.destroy?.();
    commandTabsInstance = appState.helper.createTabs(tabsHost, {
        activeId: 'current',
        ariaLabel: 'Command workspace tabs',
        onChange(tab) {
            if (tab?.id === 'incidents') {
                renderCommandIncidents(root);
            }
        },
        tabs: [
            {
                id: 'current',
                label: 'Current',
                render(panel) {
                    panel.innerHTML = '<div data-command-current-panel><p class="surface-empty">Loading current situation...</p></div>';
                    renderCurrentSnapshot(root, panel.querySelector('[data-command-current-panel]'));
                },
            },
            {
                id: 'snapshots',
                label: 'Snapshots',
                render(panel) {
                    panel.innerHTML = `
                        <section class="command-sitrep-board" aria-labelledby="command-sitrep-title">
                            <div class="command-sitrep-header">
                                <div>
                                    <p class="ui-eyebrow">Command Situation Reports</p>
                                    <h1 id="command-sitrep-title">Generated SITREPs</h1>
                                    <p class="command-sitrep-copy">Review generated situation reports and open previews from one command surface.</p>
                                </div>
                            </div>
                            <div class="command-sitrep-grid-host" data-command-sitreps-grid>
                                <p class="surface-empty">Loading generated SITREPs...</p>
                            </div>
                        </section>
                    `;
                    renderSitrepsGrid(panel.querySelector('[data-command-sitreps-grid]'), latestSitreps, root);
                },
            },
            {
                id: 'incidents',
                label: 'Incidents',
                render(panel) {
                    panel.innerHTML = `
                        <section class="command-incidents-board" aria-labelledby="command-incidents-title">
                            <div class="command-sitrep-header">
                                <div>
                                    <p class="ui-eyebrow">Incident Register</p>
                                    <h1 id="command-incidents-title">Incidents</h1>
                                    <p class="command-sitrep-copy">Open work stays first. Select an incident to focus it on the map.</p>
                                </div>
                            </div>
                            <div class="command-incidents-grid-host" data-command-incidents-grid>
                                <p class="surface-empty">Loading incidents...</p>
                            </div>
                        </section>
                    `;
                    renderCommandIncidents(root);
                },
            },
        ],
    });
    trackSurfaceInstance(commandTabsInstance);
}

function mountCommandDashboardMap(root) {
    const container = root.querySelector('[data-command-map-canvas]');

    if (!container) {
        return;
    }

    commandMapResizeObserver?.disconnect?.();
    commandMapResizeObserver = null;
    appState.runtime.commandDashboardMapControls?.destroy?.();
    appState.runtime.commandDashboardMapControls = null;
    appState.runtime.commandDashboardMap?.destroy?.();
    const dashboardMap = createDashboardMap({
        container,
        configUrl: '/hotline.json',
        onIncidentClick: (incidentId) => {
            commandTabsInstance?.setActive?.('incidents');
            focusIncidentRow(root, incidentId);
        },
    });

    appState.runtime.commandDashboardMap = dashboardMap;
    dashboardMap.init()
        .then(() => {
            root.querySelector('[data-command-map-empty]')?.remove();
            dashboardMap.setIncidents(commandIncidentsWithWorkbenchState());
            mountCommandDashboardMapControls(root, dashboardMap);
            requestAnimationFrame(() => dashboardMap.resize());
            window.setTimeout(() => dashboardMap.resize(), 120);
        })
        .catch(() => {
            const empty = root.querySelector('[data-command-map-empty]');
            if (empty) {
                empty.textContent = 'Command map is unavailable.';
            }
        });

    if (typeof ResizeObserver !== 'undefined') {
        commandMapResizeObserver = new ResizeObserver(() => {
            dashboardMap.resize();
        });
        commandMapResizeObserver.observe(container);
    }
}

function mountCommandDashboardMapControls(root, dashboardMap) {
    const host = root.querySelector('[data-command-map-controls]');
    const map = dashboardMap?.getMap?.() ?? null;

    if (!host || !map || !appState.helper.createMapControls) {
        return;
    }

    appState.runtime.commandDashboardMapControls?.destroy?.();
    appState.runtime.commandDashboardMapControls = appState.helper.createMapControls(host, {
        map,
        controls: ['zoom', 'compass', 'pitch', 'fit', 'layers'],
        orientation: 'horizontal',
        placement: 'top-center',
        compact: true,
        ariaLabel: 'Command map controls',
        layers: [
            ...(dashboardMap.hasBoundaryLayer?.() ? [{ id: 'boundary', label: 'Boundary', checked: true }] : []),
            { id: 'incidents', label: 'Incidents', checked: true },
            ...(dashboardMap.hasTerrainLayer?.() ? [{ id: 'terrain', label: 'Terrain', checked: true }] : []),
            { id: 'poi', label: 'POI', checked: true },
        ],
        onFit: () => {
            dashboardMap.fitIncidents?.();
        },
        onLayerToggle: ({ layerId, checked }) => {
            dashboardMap.setLayerGroupVisibility?.(layerId, checked);
        },
    });
}

function focusIncidentRow(root, incidentId) {
    const row = root.querySelector(`[data-command-incident-row="${CSS.escape(String(incidentId))}"]`);

    if (!row) {
        return;
    }

    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    row.classList.add('is-focused');
    window.setTimeout(() => row.classList.remove('is-focused'), 1500);
}

async function generateCurrentDaySitrep(root) {
    if (isGeneratingSitrep) {
        return;
    }

    isGeneratingSitrep = true;
    const period = currentManilaDayPeriod();

    try {
        const payload = await fetchJson(SITREP_INDEX_URL, {
            method: 'post',
            data: {
                title: `Daily SITREP - ${period.label}`,
                coverage_area: 'All coverage areas',
                period_started_at: period.startedAt,
                period_ended_at: period.endedAt,
                status: 'draft',
                visibility: 'private',
            },
        });

        showToast(`Generated ${formatSitrepNumber(payload?.sitrep?.sequence_number ?? payload?.sitrep?.id)}.`, 'success');
        await loadSitreps(root);
    } catch (error) {
        showToast('Unable to generate current-day SITREP.', 'error');
        throw error;
    } finally {
        isGeneratingSitrep = false;
    }
}

async function openCommandBroadcastModal(root) {
    await ensureHelperUi();

    if (!appState.helper.createFormModal) {
        showToast('Broadcast form is unavailable.', 'error');
        return;
    }

    const modal = appState.helper.createFormModal({
        title: 'Broadcast Message',
        submitLabel: 'Broadcast',
        busyMessage: 'Broadcasting message...',
        initialValues: {
            title: '',
            tone: 'info',
            target_callers: true,
            target_operators: true,
            message: '',
        },
        rows: [
            [
                {
                    type: 'input',
                    name: 'title',
                    label: 'Title',
                    placeholder: 'Optional short heading',
                    maxlength: 120,
                },
            ],
            [
                {
                    type: 'checkbox',
                    name: 'target_callers',
                    label: 'Citizens',
                },
                {
                    type: 'checkbox',
                    name: 'target_operators',
                    label: 'Operators',
                },
            ],
            [
                {
                    type: 'select',
                    name: 'tone',
                    label: 'Priority',
                    required: true,
                    options: [
                        { value: 'info', label: 'Announcement' },
                        { value: 'warning', label: 'Instruction' },
                        { value: 'urgent', label: 'Urgent Instruction' },
                    ],
                },
            ],
            [
                {
                    type: 'textarea',
                    name: 'message',
                    label: 'Message',
                    required: true,
                    placeholder: 'Write the announcement or instruction to send to everyone online.',
                    maxlength: 2000,
                },
            ],
        ],
        async onSubmit(values, context) {
            try {
                const targetRoles = [];

                if (values?.target_callers) {
                    targetRoles.push('citizen');
                }

                if (values?.target_operators) {
                    targetRoles.push('operator');
                }

                if (!targetRoles.length) {
                    context?.setErrors?.({
                        target_callers: 'Select at least one target.',
                        target_operators: 'Select at least one target.',
                    });
                    return false;
                }

                const response = await fetchJson(COMMAND_BROADCASTS_URL, {
                    method: 'post',
                    data: {
                        title: String(values?.title ?? '').trim() || null,
                        tone: String(values?.tone ?? 'info').trim() || 'info',
                        message: String(values?.message ?? '').trim(),
                        audience: 'global',
                        target_roles: targetRoles,
                    },
                });
                const realtimeStatus = String(response?.realtime?.status ?? '').trim();

                if (['accepted', 'pending'].includes(realtimeStatus)) {
                    showToast('Broadcast sent to online users.', realtimeStatus === 'accepted' ? 'success' : 'warn');
                } else {
                    showToast('Broadcast saved, but live delivery was not confirmed.', 'warn');
                }

                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                showToast(error.response?.data?.message ?? 'Unable to send broadcast.', 'error');
                return false;
            }
        },
    });

    trackSurfaceInstance(modal);
    await modal.open();
}

async function openCommandAlertLevelModal(root) {
    await ensureHelperUi();

    if (!appState.helper.createFormModal) {
        showToast('Alert level form is unavailable.', 'error');
        return;
    }

    const currentAlertLevel = normalizeCommandAlertLevel(appState.bootstrap?.alert_level);
    const modal = appState.helper.createFormModal({
        title: 'Change Alert Level',
        submitLabel: 'Update Alert',
        busyMessage: 'Updating alert level...',
        initialValues: {
            alert_level: currentAlertLevel,
        },
        rows: [
            [
                {
                    type: 'select',
                    name: 'alert_level',
                    label: 'Alert Level',
                    required: true,
                    options: COMMAND_ALERT_LEVEL_OPTIONS,
                },
            ],
        ],
        async onSubmit(values, context) {
            const nextAlertLevel = normalizeCommandAlertLevel(values?.alert_level);

            if (nextAlertLevel === currentAlertLevel) {
                showToast(`Alert level is already ${nextAlertLevel}.`, 'info');
                return true;
            }

            const confirmed = await confirmCommandAlertLevelChange(currentAlertLevel, nextAlertLevel);

            if (!confirmed) {
                return false;
            }

            try {
                const response = await fetchJson(COMMAND_ALERT_LEVEL_URL, {
                    method: 'post',
                    data: {
                        alert_level: nextAlertLevel,
                    },
                });
                const updatedAlertLevel = normalizeCommandAlertLevel(response?.alert_level ?? nextAlertLevel);

                applyCommandAlertLevel(root, updatedAlertLevel);
                showToast(`Alert level changed to ${updatedAlertLevel}.`, response?.changed === false ? 'info' : 'success');

                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                showToast(error.response?.data?.message ?? 'Unable to update alert level.', 'error');
                return false;
            }
        },
    });

    trackSurfaceInstance(modal);
    await modal.open();
}

async function confirmCommandAlertLevelChange(currentAlertLevel, nextAlertLevel) {
    if (!appState.helper.uiConfirm) {
        return true;
    }

    if (nextAlertLevel === 'Critical') {
        return appState.helper.uiConfirm('Set command alert level to Critical?', {
            title: 'Confirm Critical Alert',
            description: 'All connected command, operator, and citizen surfaces will receive the alert-level update.',
            confirmText: 'Set Critical',
            cancelText: 'Cancel',
            variant: 'warning',
        });
    }

    if (currentAlertLevel === 'Critical') {
        return appState.helper.uiConfirm(`Lower alert level from Critical to ${nextAlertLevel}?`, {
            title: 'Confirm Alert Downgrade',
            description: 'All connected command, operator, and citizen surfaces will receive the alert-level update.',
            confirmText: `Set ${nextAlertLevel}`,
            cancelText: 'Cancel',
            variant: 'warning',
        });
    }

    return true;
}

function normalizeCommandAlertLevel(value) {
    const normalized = String(value ?? '').trim().toLowerCase();
    const match = COMMAND_ALERT_LEVEL_OPTIONS.find((option) => option.value.toLowerCase() === normalized);

    return match?.value ?? 'Normal';
}

async function loadSitreps(root) {
    const host = root.querySelector('[data-command-sitreps-grid]');

    if (host) {
        host.innerHTML = '<p class="surface-empty">Loading generated SITREPs...</p>';
    }

    try {
        const payload = await fetchJson(SITREP_INDEX_URL);
        latestSitreps = Array.isArray(payload.items) ? payload.items : [];
        renderSitrepsGrid(host, latestSitreps, root);
        renderCurrentSnapshot(root);
    } catch (error) {
        if (host) {
            host.innerHTML = '<p class="surface-empty">Unable to load generated SITREPs.</p>';
        }
        showToast('Unable to load generated SITREPs.', 'error');
        throw error;
    }
}

async function loadCommandIncidents(root) {
    const host = root.querySelector('[data-command-incidents-grid]');

    if (host) {
        host.innerHTML = '<p class="surface-empty">Loading incidents...</p>';
    }

    try {
        const payload = await fetchJson(COMMAND_INCIDENTS_URL);
        latestIncidents = Array.isArray(payload.items) ? payload.items : [];
        refreshCommandIncidentViews(root);
    } catch (error) {
        if (host) {
            host.innerHTML = '<p class="surface-empty">Unable to load incidents.</p>';
        }
        showToast('Unable to load command incidents.', 'error');
        throw error;
    }
}

function mountCommandAssignmentBoard(root) {
    const boardHost = root.querySelector('[data-command-lanes-board]');

    if (!boardHost) {
        return;
    }

    commandAssignmentBoardInstance?.destroy?.();
    commandAssignmentBoardInstance = null;

    if (!appState.helper.createKanban) {
        boardHost.innerHTML = '<p class="surface-empty">Team assignment board is unavailable.</p>';
        return;
    }

    const laneById = new Map();
    const lanes = COMMAND_ASSIGNMENT_LANES.map((lane) => ({
        ...lane,
        title: formatStatusLabel(lane.title ?? lane.id),
        cards: [],
    }));

    lanes.forEach((lane) => {
        laneById.set(String(lane.id), lane);
    });

    latestIncidents
        .filter(commandIncidentIsOpenForAssignment)
        .forEach((incident) => {
            const incidentId = Number(incident?.id ?? 0);
            const displayId = String(incident?.display_id ?? String(incidentId).padStart(6, '0'));

            (Array.isArray(incident?.team_assignments) ? incident.team_assignments : []).forEach((assignment) => {
                const laneId = mapCommandTeamAssignmentStatus(assignment?.status);
                const lane = laneById.get(laneId);

                if (!lane) {
                    return;
                }

                const contact = String(assignment?.contact_person ?? '').trim();
                const teamName = String(assignment?.team?.name ?? assignment?.team_name ?? 'Unknown team').trim();

                lane.cards.push({
                    id: String(assignment?.id ?? `${incidentId}-${assignment?.team_id ?? laneId}`),
                    incident_id: incidentId,
                    assignment_id: assignment?.id ?? null,
                    title: teamName || 'Unknown team',
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

    boardHost.replaceChildren();
    commandAssignmentBoardInstance = appState.helper.createKanban(boardHost, lanes, {
        ariaLabel: 'Command team assignment lanes',
        draggable: false,
        keyboardMoves: false,
        showEmptyPlaceholder: false,
        onCardClick(card) {
            if (!card?.raw?.incident_id) {
                return;
            }

            appState.runtime.commandDashboardMap?.focusIncident?.(card.raw.incident_id);
            commandTabsInstance?.setActive?.('incidents');
            requestAnimationFrame(() => {
                focusIncidentRow(root, card.raw.incident_id);
            });
        },
    });
    trackSurfaceInstance(commandAssignmentBoardInstance);
}

function renderCurrentSnapshot(root, targetHost = null) {
    const host = targetHost ?? root.querySelector('[data-command-current-panel]');

    if (!host) {
        return;
    }

    const latest = latestSitreps[0] ?? null;
    const period = currentManilaDayPeriod();
    const activeCount = latestIncidents.filter((incident) => ['Active', 'Deferred'].includes(incident.status)).length;
    const closedCount = latestIncidents.filter((incident) => ['Resolved', 'Discarded'].includes(incident.status)).length;
    const assignmentCount = latestIncidents.reduce((total, incident) => total + (Array.isArray(incident.team_assignments) ? incident.team_assignments.length : 0), 0);
    const locatedCount = latestIncidents.filter(hasIncidentCoordinates).length;
    const summary = latest?.summary ?? {};
    const liveAlertLevel = appState.bootstrap?.alert_level ?? latest?.alert_level ?? 'Normal';
    const alertTone = formatAlertTone(liveAlertLevel);

    host.innerHTML = `
        <section class="command-current-summary">
            <div class="command-current-hero is-alert-${alertTone}">
                <div>
                    <p class="ui-eyebrow">Current Snapshot</p>
                    <h2>${escapeHtml(latest?.title ?? `Daily SITREP - ${period.label}`)}</h2>
                    <p>${escapeHtml(summary?.headline ?? summary?.primary_concern ?? 'Generate a SITREP snapshot to capture the current operational picture.')}</p>
                </div>
                <button class="surface-button secondary tiny command-generate-sitrep-button" type="button" data-command-generate-current title="Generate today's SITREP manually">Generate Today</button>
            </div>
            <div class="command-current-cards">
                ${renderCurrentCard('Latest SITREP', latest ? formatSitrepNumber(latest.sequence_number ?? latest.id) : 'None', latest ? `Record ${formatSitrepRecordNumber(latest.id)} · ${formatDateTime(latest.generated_at)}` : 'No generated snapshot yet')}
                ${renderCurrentCard('Open Incidents', activeCount, 'Active and deferred incidents')}
                ${renderCurrentCard('Closed Incidents', closedCount, 'Resolved and discarded incidents')}
                ${renderCurrentCard('Assignments', assignmentCount, 'Team assignment records')}
                ${renderCurrentCard('Mapped Incidents', `${locatedCount}/${latestIncidents.length}`, 'Incidents with coordinates')}
                ${renderCurrentCard('Alert Level', formatStatusLabel(liveAlertLevel), 'Current command alert')}
            </div>
            <section class="command-current-detail">
                <h3>Situation Readout</h3>
                <p><strong>Operational posture:</strong> ${escapeHtml(summary?.operational_posture ?? 'Pending generated assessment.')}</p>
                <p><strong>Primary concern:</strong> ${escapeHtml(summary?.primary_concern ?? 'No generated primary concern yet.')}</p>
                <p><strong>Hotspot:</strong> ${escapeHtml(summary?.hotspot ?? 'No hotspot identified yet.')}</p>
            </section>
        </section>
    `;

    host.querySelector('[data-command-generate-current]')?.addEventListener('click', () => {
        void generateCurrentDaySitrep(root);
    });
    refreshCommandOperatorPresence(root);
}

function renderCurrentCard(label, value, detail) {
    return `
        <article class="command-current-card">
            <span>${escapeHtml(label)}</span>
            <strong>${escapeHtml(String(value ?? ''))}</strong>
            <small>${escapeHtml(detail)}</small>
        </article>
    `;
}

function hasIncidentCoordinates(incident) {
    return Number.isFinite(Number(incident?.latitude)) && Number.isFinite(Number(incident?.longitude));
}

function renderSitrepsGrid(host, items, root = null) {
    sitrepGridInstance?.destroy?.();
    sitrepGridInstance = null;

    if (!host) {
        return;
    }

    if (!appState.helper.createGrid) {
        host.innerHTML = renderFallbackList(items);
        return;
    }

    host.innerHTML = '';

    const homeSitrepId = findHomeSitrepId(items);
    const rows = items.map((item) => {
        const workflowStatus = normalizeSitrepWorkflowStatus(item.status);
        const visibility = normalizeSitrepVisibility(item.visibility, workflowStatus);
        const sitrepLabel = formatSitrepNumber(item.sequence_number ?? item.id);
        const periodLabel = formatPeriod(item.period_started_at, item.period_ended_at);
        const coverageLabel = item.coverage_area ?? 'All coverage areas';
        const titleLabel = item.title ?? 'Untitled SITREP';
        const generatedLabel = item.generated_at ? `Generated ${formatDateTime(item.generated_at)}` : 'Generated time unavailable';

        return {
            ...item,
            report: `${sitrepLabel} ${titleLabel} ${coverageLabel}`,
            sitrep_label: sitrepLabel,
            period_label: periodLabel,
            report_title: sitrepLabel,
            report_meta: `${titleLabel} · ${coverageLabel}`,
            report_submeta: `${periodLabel} · ${generatedLabel}`,
            status_key: workflowStatus,
            status_label: sitrepWorkflowStatusLabel(workflowStatus),
            visibility_key: visibility,
            visibility_label: sitrepVisibilityLabel(visibility),
            is_home_snapshot: String(item.id) === String(homeSitrepId),
            alert_tone: formatAlertTone(item.alert_level ?? 'Normal'),
            search_summary: [
                sitrepLabel,
                periodLabel,
                titleLabel,
                coverageLabel,
                sitrepWorkflowStatusLabel(workflowStatus),
                sitrepVisibilityLabel(visibility),
                String(item.id) === String(homeSitrepId) ? 'Home' : '',
            ].filter(Boolean).join(' '),
        };
    });

    sitrepGridInstance = appState.helper.createGrid(host, rows, {
        chrome: true,
        className: 'command-sitrep-grid',
        rowKey: 'id',
        selectable: 'none',
        enableSearch: true,
        enableSort: true,
        enablePagination: false,
        enableColumnResize: true,
        searchPlaceholder: 'Search SITREP title, coverage, or status',
        emptyText: 'No generated SITREPs yet.',
        minColumnWidth: 100,
        columnWidths: {
            report: 330,
            status_label: 110,
            visibility_label: 135,
        },
        toolbarEnd: root ? () => createCommandSitrepRefreshButton(root) : null,
        columns: [
            {
                key: 'report',
                label: 'Report',
                width: 330,
                sortable: true,
                renderCell: ({ row }) => createSitrepReportCell(row),
            },
            {
                key: 'status_label',
                label: 'Workflow',
                width: 110,
                sortable: true,
                renderCell: ({ row }) => createSitrepStatusCell(row),
            },
            {
                key: 'visibility_label',
                label: 'Visibility',
                width: 135,
                sortable: true,
                renderCell: ({ row }) => createSitrepVisibilityCell(row),
            },
        ],
        onRowClick: (row) => {
            void openSitrepPreviewModal(row, root).catch((error) => {
                showToast('Unable to open SITREP preview.', 'error');
                throw error;
            });
        },
    });

    trackSurfaceInstance(sitrepGridInstance);
}

function createCommandSitrepRefreshButton(root) {
    const refresh = document.createElement('button');
    refresh.type = 'button';
    refresh.className = 'surface-button secondary tiny command-grid-refresh command-icon-button';
    refresh.setAttribute('aria-label', 'Refresh SITREPs');
    refresh.title = 'Refresh SITREPs';
    refresh.innerHTML = appState.helper.createIcon?.('actions.refresh', {
        size: 15,
        ariaLabel: 'Refresh SITREPs',
    })?.outerHTML ?? 'Refresh';
    refresh.addEventListener('click', () => {
        void loadSitreps(root);
    });

    return refresh;
}

function renderCommandIncidents(root) {
    const host = root.querySelector('[data-command-incidents-grid]');

    if (!host) {
        return;
    }

    clearCommandIncidentElapsedTimers();
    commandIncidentStatusSelectInstance?.destroy?.();
    commandIncidentStatusSelectInstance = null;
    commandIncidentsGridInstance?.destroy?.();
    commandIncidentsGridInstance = null;

    const rows = buildCommandIncidentRows(commandIncidentsWithWorkbenchState());
    const filteredRows = filterCommandIncidentRows(rows);

    if (!appState.helper.createGrid) {
        host.innerHTML = `
            ${renderCommandIncidentFilterFallback(root)}
            ${renderIncidentFallbackList(filteredRows)}
        `;
        wireCommandIncidentFilterFallback(root);
        return;
    }

    host.innerHTML = '';
    commandIncidentsGridInstance = appState.helper.createGrid(host, filteredRows, {
        chrome: true,
        className: 'command-incidents-grid',
        rowKey: 'id',
        selectable: 'none',
        enableSearch: true,
        enableSort: true,
        enablePagination: false,
        enableColumnResize: false,
        searchPlaceholder: 'Search incidents, caller, location, or status',
        filters: {
            status: commandIncidentStatusFilters.length ? commandIncidentStatusFilters.join(',') : 'all',
        },
        emptyText: createCommandIncidentEmptyText(),
        minColumnWidth: 100,
        columnWidths: {
            incident_summary: 340,
            duration_label: 138,
        },
        toolbarStart: () => createCommandIncidentFilterBar(root, rows),
        toolbarEnd: () => createCommandIncidentRefreshButton(root),
        columns: [
            {
                key: 'incident_summary',
                label: 'Incident',
                width: 340,
                sortable: true,
                renderCell: ({ row }) => createIncidentSummaryCell(row),
            },
            {
                key: 'duration_label',
                label: 'Duration',
                width: 138,
                sortable: true,
                renderCell: ({ row }) => createIncidentDurationCell(row),
            },
        ],
        onRowClick: (row) => {
            appState.runtime.commandDashboardMap?.focusIncident?.(row.id);
            focusIncidentRow(root, row.id);
        },
    });

    trackSurfaceInstance(commandIncidentsGridInstance);
}

function buildCommandIncidentRows(items) {
    return [...items].map((incident) => {
        const assignmentCount = Array.isArray(incident.team_assignments) ? incident.team_assignments.length : 0;
        const citizenLabel = incident.citizen_name ?? incident.actual_citizen_name ?? 'Unknown citizen';
        const displayId = incident.display_id ?? String(incident.id).padStart(6, '0');
        const locationLabel = incident.location_label ?? 'Location unavailable';
        const statusLabel = incident.status_label ?? formatStatusLabel(incident.status);
        const statusKey = normalizeCommandIncidentStatus(incident.status ?? statusLabel);
        const typeLabel = Array.isArray(incident.incident_types) && incident.incident_types.length
            ? incident.incident_types.map((type) => type.name).join(', ')
            : 'No incident types';
        const workbenchOperatorNames = Array.isArray(incident.workbench_operator_names)
            ? incident.workbench_operator_names
            : [];

        return {
            ...incident,
            id_label: `#${displayId}`,
            status_key: statusKey,
            status_label: statusLabel,
            status_sort: commandIncidentStatusSortRank(statusKey),
            citizen_label: citizenLabel,
            location_label: locationLabel,
            updated_label: formatCommandIncidentUpdatedAt(incident.updated_at),
            updated_sort: Date.parse(String(incident.updated_at ?? '')) || 0,
            duration_start_at: commandIncidentElapsedStartTime(incident),
            duration_end_at: commandIncidentElapsedEndTime(incident, statusKey),
            duration_running: commandIncidentElapsedIsRunning(statusKey),
            duration_label: [
                statusLabel,
                commandIncidentElapsedStartTime(incident),
                commandIncidentElapsedEndTime(incident, statusKey),
            ].filter(Boolean).join(' '),
            workbench_active: incident.workbench_active === true,
            workbench_operator_names: workbenchOperatorNames,
            workbench_label: workbenchOperatorNames.length
                ? `${workbenchOperatorNames.length} on workbench`
                : '',
            assignment_count: assignmentCount,
            assignment_label: `${assignmentCount} assignment${assignmentCount === 1 ? '' : 's'}`,
            type_label: typeLabel,
            incident_summary: [
                `#${displayId}`,
                citizenLabel,
                locationLabel,
                typeLabel,
                statusLabel,
                workbenchOperatorNames.join(' '),
                `${assignmentCount} assignment${assignmentCount === 1 ? '' : 's'}`,
                formatCommandIncidentUpdatedAt(incident.updated_at),
            ].join(' '),
        };
    }).sort((a, b) => (
        a.status_sort - b.status_sort
        || b.updated_sort - a.updated_sort
        || Number(b.id ?? 0) - Number(a.id ?? 0)
    ));
}

function filterCommandIncidentRows(rows) {
    if (!commandIncidentStatusFilters.length) {
        return rows;
    }

    const selectedStatuses = new Set(commandIncidentStatusFilters);

    return rows.filter((row) => selectedStatuses.has(row.status_key));
}

function normalizeCommandIncidentStatusFilters(values) {
    const allowed = new Set(COMMAND_INCIDENT_STATUS_FILTER_OPTIONS.map((option) => option.value));
    const list = Array.isArray(values) ? values : [values];

    return list
        .map((value) => normalizeCommandIncidentStatus(value))
        .filter((value, index, filtered) => allowed.has(value) && filtered.indexOf(value) === index);
}

function createCommandIncidentEmptyText() {
    if (!commandIncidentStatusFilters.length) {
        return 'No incidents available.';
    }

    const statusLabel = commandIncidentStatusFilters
        .map((status) => formatStatusLabel(status))
        .join(', ');

    return `No ${statusLabel} incidents available.`;
}

function normalizeCommandIncidentStatus(value) {
    return String(value ?? 'unknown').trim().toLowerCase().replaceAll(' ', '_').replaceAll('-', '_') || 'unknown';
}

function commandIncidentElapsedStartTime(item) {
    return item?.called_at ?? item?.created_at ?? null;
}

function commandIncidentElapsedEndTime(item, statusKey = normalizeCommandIncidentStatus(item?.status)) {
    if (commandIncidentElapsedIsRunning(statusKey)) {
        return null;
    }

    return item?.resolved_at ?? item?.updated_at ?? null;
}

function commandIncidentElapsedIsRunning(statusKey) {
    return ['active', 'deferred'].includes(normalizeCommandIncidentStatus(statusKey));
}

function commandIncidentElapsedVariant(statusKey) {
    switch (normalizeCommandIncidentStatus(statusKey)) {
        case 'deferred':
            return 'warn';
        case 'resolved':
            return 'success';
        case 'discarded':
            return 'danger';
        case 'active':
            return 'info';
        default:
            return 'neutral';
    }
}

function clearCommandIncidentElapsedTimers() {
    if (!(appState.runtime.commandIncidentElapsedTimers instanceof Map)) {
        appState.runtime.commandIncidentElapsedTimers = new Map();
        return;
    }

    appState.runtime.commandIncidentElapsedTimers.forEach((timer) => {
        timer?.destroy?.();
    });
    appState.runtime.commandIncidentElapsedTimers.clear();
}

function commandIncidentStatusSortRank(status) {
    switch (status) {
        case 'active':
            return 0;
        case 'deferred':
            return 1;
        case 'resolved':
            return 2;
        case 'discarded':
            return 3;
        default:
            return 4;
    }
}

function createCommandIncidentFilterBar(root, rows) {
    const counts = commandIncidentStatusCounts(rows);
    const wrap = document.createElement('div');
    wrap.className = 'command-incident-filter-bar';

    const selectHost = document.createElement('div');
    selectHost.className = 'command-incident-status-select';
    wrap.appendChild(selectHost);

    commandIncidentStatusSelectInstance = appState.helper.createSelect(selectHost, COMMAND_INCIDENT_STATUS_FILTER_OPTIONS.map((option) => ({
        value: option.value,
        label: `${option.label} ${counts[option.value] ?? 0}`,
    })), {
        ariaLabel: 'Incident status filters',
        className: 'command-incident-status-select-control',
        placeholder: `All statuses ${rows.length}`,
        multiple: true,
        searchable: false,
        closeOnSelect: false,
        clearable: true,
        selected: commandIncidentStatusFilters,
        onChange: (values) => {
            commandIncidentStatusFilters = normalizeCommandIncidentStatusFilters(values);
            renderCommandIncidents(root);
        },
    });
    trackSurfaceInstance(commandIncidentStatusSelectInstance);

    return wrap;
}

function createCommandIncidentRefreshButton(root) {
    const refresh = document.createElement('button');
    refresh.type = 'button';
    refresh.className = 'surface-button secondary tiny command-incident-refresh command-icon-button';
    refresh.setAttribute('aria-label', 'Refresh incidents');
    refresh.title = 'Refresh incidents';
    refresh.innerHTML = appState.helper.createIcon?.('actions.refresh', {
        size: 15,
        ariaLabel: 'Refresh incidents',
    })?.outerHTML ?? 'Refresh';
    refresh.addEventListener('click', () => {
        void loadCommandIncidents(root);
    });

    return refresh;
}

function commandIncidentStatusCounts(rows) {
    return rows.reduce((counts, row) => {
        counts[row.status_key] = (counts[row.status_key] ?? 0) + 1;
        return counts;
    }, {});
}

function formatCommandIncidentUpdatedAt(value) {
    if (!value) {
        return 'Pending';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return String(value);
    }

    return parsed.toLocaleString('en-PH', {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function renderCommandIncidentFilterFallback(root) {
    const rows = buildCommandIncidentRows(commandIncidentsWithWorkbenchState());
    const counts = commandIncidentStatusCounts(rows);

    return `
        <div class="command-incident-filter-bar">
            <label class="command-incident-native-select">
                <select multiple aria-label="Incident status filters" data-command-incident-status-filter>
                    ${COMMAND_INCIDENT_STATUS_FILTER_OPTIONS.map((option) => `
                        <option
                            value="${escapeHtml(option.value)}"
                            ${commandIncidentStatusFilters.includes(option.value) ? 'selected' : ''}
                        >
                            ${escapeHtml(`${option.label} ${counts[option.value] ?? 0}`)}
                        </option>
                    `).join('')}
                </select>
            </label>
            <button class="surface-button secondary tiny command-incident-refresh command-icon-button" type="button" aria-label="Refresh incidents" title="Refresh incidents" data-command-refresh-incidents>
                ${appState.helper.createIcon?.('actions.refresh', { size: 15, ariaLabel: 'Refresh incidents' })?.outerHTML ?? 'Refresh'}
            </button>
        </div>
    `;
}

function wireCommandIncidentFilterFallback(root) {
    root.querySelector('[data-command-incident-status-filter]')?.addEventListener('change', (event) => {
        const select = event.currentTarget;
        commandIncidentStatusFilters = normalizeCommandIncidentStatusFilters(
            Array.from(select?.selectedOptions ?? []).map((option) => option.value)
        );
        renderCommandIncidents(root);
    });
    root.querySelector('[data-command-refresh-incidents]')?.addEventListener('click', () => {
        void loadCommandIncidents(root);
    });
}

function createIncidentSummaryCell(row) {
    const wrap = document.createElement('div');
    wrap.className = `command-incident-summary is-${row.status_key}${row.workbench_active ? ' has-workbench' : ''}`;

    const topLine = document.createElement('div');
    topLine.className = 'command-incident-summary-top';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'command-incident-link';
    button.dataset.commandIncidentRow = String(row.id);
    button.textContent = row.id_label;
    button.addEventListener('click', () => {
        appState.runtime.commandDashboardMap?.focusIncident?.(row.id);
    });

    topLine.appendChild(button);

    if (row.workbench_active) {
        const badge = document.createElement('span');
        badge.className = 'command-incident-workbench-badge';
        badge.textContent = row.workbench_label || 'On workbench';
        badge.title = row.workbench_operator_names?.length
            ? `Open with ${row.workbench_operator_names.join(', ')}`
            : 'Open in operator workbench';
        topLine.appendChild(badge);
    }

    const primaryMeta = document.createElement('span');
    primaryMeta.className = 'command-incident-summary-primary';
    primaryMeta.textContent = `${row.citizen_label} · ${row.location_label}`;

    const secondaryMeta = document.createElement('span');
    secondaryMeta.className = 'command-incident-summary-secondary';
    secondaryMeta.textContent = `${row.type_label} · ${row.assignment_label}`;

    wrap.append(topLine, primaryMeta, secondaryMeta);

    return wrap;
}

function createIncidentDurationCell(row) {
    const wrap = document.createElement('div');
    wrap.className = `command-incident-duration is-${row.status_key}`;

    const timerHost = document.createElement('span');
    timerHost.className = 'command-incident-duration-value';

    const chip = document.createElement('span');
    chip.className = `command-chip command-incident-duration-status is-${String(row.status ?? 'unknown').toLowerCase()}`;
    chip.textContent = row.status_label;

    wrap.append(timerHost, chip);
    mountCommandIncidentElapsedTime(timerHost, row);

    return wrap;
}

function mountCommandIncidentElapsedTime(host, row) {
    const createElapsedTime = appState.helper.createElapsedTime;

    if (!host || !createElapsedTime) {
        if (host) {
            host.textContent = formatCommandIncidentFallbackDuration(row);
        }
        return;
    }

    if (!row.duration_start_at) {
        host.textContent = 'Pending';
        return;
    }

    if (!(appState.runtime.commandIncidentElapsedTimers instanceof Map)) {
        appState.runtime.commandIncidentElapsedTimers = new Map();
    }

    const timerKey = `incident:${row.id}`;
    appState.runtime.commandIncidentElapsedTimers.get(timerKey)?.destroy?.();
    appState.runtime.commandIncidentElapsedTimers.set(timerKey, createElapsedTime(host, {
        startTime: row.duration_start_at,
        endTime: row.duration_end_at,
        running: row.duration_running,
        format: 'compact',
        chrome: false,
        size: 'sm',
        variant: commandIncidentElapsedVariant(row.status_key),
        ariaLabel: row.duration_running
            ? `${row.id_label} running duration`
            : `${row.id_label} final duration`,
    }));
}

function formatCommandIncidentFallbackDuration(row) {
    const start = Date.parse(String(row.duration_start_at ?? ''));
    const end = row.duration_running ? Date.now() : Date.parse(String(row.duration_end_at ?? ''));

    if (!Number.isFinite(start) || !Number.isFinite(end)) {
        return 'Pending';
    }

    const totalSeconds = Math.max(0, Math.floor((end - start) / 1000));
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const parts = [
        days,
        hours,
        minutes,
        seconds,
    ].map((value) => String(value).padStart(2, '0'));
    const first = parts.findIndex((value) => value !== '00');

    return first === -1 ? parts[3] : parts.slice(first).join(':');
}

function createStackedCell(titleText, subtitleText, extraClass = '') {
    const wrap = document.createElement('div');
    wrap.className = ['command-grid-stacked-cell', extraClass].filter(Boolean).join(' ');

    const title = document.createElement('span');
    title.className = 'command-grid-stacked-title';
    title.textContent = titleText;

    const subtitle = document.createElement('span');
    subtitle.className = 'command-grid-stacked-subtitle';
    subtitle.textContent = subtitleText;

    wrap.append(title, subtitle);

    return wrap;
}

function createSitrepReportCell(row) {
    const wrap = document.createElement('div');
    wrap.className = ['command-sitrep-report-cell', `is-alert-${row.alert_tone}`].filter(Boolean).join(' ');

    const title = document.createElement('span');
    title.className = 'command-sitrep-report-main';
    title.textContent = row.report_title;

    const meta = document.createElement('span');
    meta.className = 'command-sitrep-report-meta';
    meta.textContent = row.report_meta;

    const submeta = document.createElement('span');
    submeta.className = 'command-sitrep-report-submeta';
    submeta.textContent = row.report_submeta;

    wrap.append(title, meta, submeta);

    return wrap;
}

function createSitrepStatusCell(row) {
    const wrap = document.createElement('div');
    wrap.className = 'command-sitrep-chip-cell';

    const status = document.createElement('span');
    status.className = `command-chip is-${row.status_key}`;
    status.textContent = row.status_label;

    wrap.append(status);

    return wrap;
}

function createSitrepVisibilityCell(row) {
    const wrap = document.createElement('div');
    wrap.className = 'command-sitrep-chip-cell';

    const visibility = document.createElement('span');
    visibility.className = `command-chip is-${row.visibility_key}`;
    visibility.textContent = row.visibility_label;

    wrap.append(visibility);

    if (row.is_home_snapshot) {
        const home = document.createElement('span');
        home.className = 'command-chip is-home';
        home.textContent = 'Home';
        wrap.append(home);
    }

    return wrap;
}

async function openSitrepPreviewModal(row, root = null) {
    if (!row?.preview_url) {
        showToast('SITREP preview is not available.', 'error');
        return;
    }

    const [createModal, createIframeHost, createDropdown] = await resolvePreviewHelpers();
    const normalizedRow = normalizeSitrepPreviewRow(row);

    sitrepPreviewCurrentRow = normalizedRow;

    sitrepPreviewIframeHost?.destroy?.();
    sitrepPreviewIframeHost = createIframeHost({
        src: cacheBustSitrepPreviewUrl(normalizedRow.preview_url),
        title: `Preview ${normalizedRow.sitrep_label}`,
        loadingText: `Loading ${normalizedRow.sitrep_label} preview...`,
        errorTitle: 'Unable to load SITREP preview',
        errorMessage: 'Check the preview route or try refreshing the command surface.',
        sandbox: 'allow-scripts allow-same-origin allow-forms allow-popups allow-downloads allow-modals',
        className: 'command-sitrep-preview-iframe',
    });

    sitrepPreviewModal?.destroy?.();
    sitrepPreviewDownloadMenu?.destroy?.();
    sitrepPreviewDownloadMenu = null;
    sitrepPreviewModal = createModal({
        title: `SITREP Preview ${normalizedRow.sitrep_label}`,
        ownerTitle: normalizedRow.title ?? 'Generated situation report',
        size: 'xl',
        position: 'top',
        draggable: true,
        closeOnBackdrop: true,
        closeOnEscape: true,
        className: 'command-sitrep-preview-modal',
        headerActions: createSitrepPreviewHeaderActions(normalizedRow, createDropdown, root),
        content: sitrepPreviewIframeHost.root,
        onClose() {
            sitrepPreviewDownloadMenu?.destroy?.();
            sitrepPreviewDownloadMenu = null;
            sitrepPreviewIframeHost?.destroy?.();
            sitrepPreviewIframeHost = null;
            sitrepPreviewModal?.destroy?.();
            sitrepPreviewModal = null;
            sitrepPreviewCurrentRow = null;
        },
    });

    trackSurfaceInstance(sitrepPreviewModal);
    trackSurfaceInstance(sitrepPreviewIframeHost);
    sitrepPreviewModal.open();
}

async function resolvePreviewHelpers() {
    if (createModalFactory && createIframeHostFactory && createDropdownFactory) {
        return [createModalFactory, createIframeHostFactory, createDropdownFactory];
    }

    const loader = appState.helper.uiLoader;

    if (!loader?.get) {
        throw new Error('Helper UI loader is not available.');
    }

    [createModalFactory, createIframeHostFactory, createDropdownFactory] = await Promise.all([
        loader.get('ui.modal'),
        loader.get('ui.iframe.host'),
        loader.get('ui.dropdown'),
    ]);

    return [createModalFactory, createIframeHostFactory, createDropdownFactory];
}

function createSitrepPreviewHeaderActions(row, createDropdown, root = null) {
    const wrap = document.createElement('div');
    wrap.className = 'command-sitrep-preview-actions';

    const workflowStatus = normalizeSitrepWorkflowStatus(row.status);
    const visibility = normalizeSitrepVisibility(row.visibility, workflowStatus);

    if (workflowStatus === 'draft') {
        wrap.append(createSitrepStateActionButton({
            label: 'Publish',
            title: 'Publish SITREP',
            onClick: () => updateSitrepState(row, root, {
                status: 'published',
            }, {
                confirmTitle: 'Publish SITREP?',
                confirmMessage: `${row.sitrep_label} will become the official command SITREP. It will remain private until visibility is set public.`,
                confirmText: 'Publish',
                successMessage: `${row.sitrep_label} published.`,
            }),
        }));
    }

    if (workflowStatus === 'published' && visibility === 'private') {
        wrap.append(createSitrepStateActionButton({
            label: 'Set Public',
            title: 'Set SITREP public',
            onClick: () => updateSitrepState(row, root, {
                visibility: 'public',
            }, {
                confirmTitle: 'Set SITREP Public?',
                confirmMessage: `${row.sitrep_label} will be eligible for the home page when it is the latest official public SITREP.`,
                confirmText: 'Set Public',
                successMessage: `${row.sitrep_label} is now public.`,
            }),
        }));
    }

    if (workflowStatus === 'published' && visibility === 'public') {
        wrap.append(createSitrepStateActionButton({
            label: 'Set Private',
            title: 'Set SITREP private',
            onClick: () => updateSitrepState(row, root, {
                visibility: 'private',
            }, {
                confirmTitle: 'Set SITREP Private?',
                confirmMessage: `${row.sitrep_label} will no longer be shown on the public home page or public archive.`,
                confirmText: 'Set Private',
                successMessage: `${row.sitrep_label} is now private.`,
            }),
        }));
    }

    const printButton = document.createElement('button');
    printButton.type = 'button';
    printButton.className = 'surface-button secondary tiny command-preview-action';
    printButton.innerHTML = `${appState.helper.createIcon?.('actions.export', {
        size: 15,
        ariaLabel: 'Print',
    })?.outerHTML ?? ''}<span>Print</span>`;
    printButton.addEventListener('click', () => {
        printSitrepPreview(row);
    });

    const downloadButton = document.createElement('button');
    downloadButton.type = 'button';
    downloadButton.className = 'surface-button secondary tiny command-preview-action';
    downloadButton.innerHTML = `${appState.helper.createIcon?.('actions.download', {
        size: 15,
        ariaLabel: 'Download',
    })?.outerHTML ?? ''}<span>Download</span>`;

    const urls = getSitrepDownloadUrls(row);
    sitrepPreviewDownloadMenu = createDropdown(downloadButton, [
        { id: 'pdf', label: 'As PDF', icon: appState.helper.createIcon?.('actions.download', { size: 14 })?.outerHTML },
        { id: 'json', label: 'As JSON', icon: appState.helper.createIcon?.('actions.export', { size: 14 })?.outerHTML },
        { id: 'zip', label: 'As ZIP', icon: appState.helper.createIcon?.('actions.download', { size: 14 })?.outerHTML },
    ], {
        align: 'right',
        ariaLabel: `Download ${row.sitrep_label}`,
        className: 'command-sitrep-download-menu',
        onSelect(item) {
            const url = urls[item.id];

            if (!url) {
                showToast('Download is not available.', 'error');
                return;
            }

            window.location.assign(url);
        },
    });

    wrap.append(printButton, downloadButton);

    return wrap;
}

function createSitrepStateActionButton({ label, title, onClick }) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'surface-button secondary tiny command-preview-action command-sitrep-state-action';
    button.title = title;
    button.innerHTML = `<span>${label}</span>`;
    button.addEventListener('click', () => {
        void onClick();
    });

    return button;
}

async function updateSitrepState(row, root, payload, options = {}) {
    const confirmed = await confirmSitrepStateChange(options);

    if (!confirmed) {
        return;
    }

    sitrepPreviewModal?.setBusy?.(true, {
        message: options.busyMessage ?? 'Updating SITREP...',
    });

    try {
        const response = await fetchJson(`/api/command/sitreps/${encodeURIComponent(row.id)}`, {
            method: 'patch',
            data: payload,
        });
        const updated = normalizeSitrepPreviewRow(response?.sitrep ?? row);

        sitrepPreviewCurrentRow = updated;
        refreshSitrepPreviewModal(updated, root);
        showToast(options.successMessage ?? `${updated.sitrep_label} updated.`, 'success');

        if (root) {
            await loadSitreps(root);
        }
    } catch (error) {
        showToast(error.response?.data?.message ?? 'Unable to update SITREP.', 'error');
    } finally {
        sitrepPreviewModal?.setBusy?.(false);
    }
}

async function confirmSitrepStateChange(options = {}) {
    if (!appState.helper.uiConfirm) {
        return true;
    }

    return appState.helper.uiConfirm(options.confirmTitle ?? 'Update SITREP?', {
        title: options.confirmTitle ?? 'Update SITREP',
        description: options.confirmMessage ?? 'This changes the command SITREP state.',
        confirmText: options.confirmText ?? 'Update',
        cancelText: 'Cancel',
        variant: 'warning',
    });
}

function normalizeSitrepPreviewRow(row) {
    return {
        ...row,
        sitrep_label: row.sitrep_label ?? formatSitrepNumber(row.sequence_number ?? row.id),
    };
}

function refreshSitrepPreviewModal(row, root = null) {
    sitrepPreviewDownloadMenu?.destroy?.();
    sitrepPreviewDownloadMenu = null;

    if (!sitrepPreviewModal || !sitrepPreviewIframeHost) {
        void openSitrepPreviewModal(row, root);
        return;
    }

    sitrepPreviewModal.setTitle?.(`SITREP Preview ${row.sitrep_label}`);
    sitrepPreviewModal.update?.({
        ownerTitle: row.title ?? 'Generated situation report',
        headerActions: createSitrepPreviewHeaderActions(row, createDropdownFactory, root),
    });
    sitrepPreviewIframeHost.update?.({
        src: cacheBustSitrepPreviewUrl(row.preview_url),
        title: `Preview ${row.sitrep_label}`,
        loadingText: `Loading ${row.sitrep_label} preview...`,
    });
}

function cacheBustSitrepPreviewUrl(url) {
    const source = String(url ?? '').trim();

    if (!source) {
        return source;
    }

    try {
        const parsed = new URL(source, window.location.origin);
        parsed.searchParams.set('_', String(Date.now()));

        return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    } catch {
        const joiner = source.includes('?') ? '&' : '?';

        return `${source}${joiner}_=${Date.now()}`;
    }
}

function printSitrepPreview(row) {
    const currentRow = sitrepPreviewCurrentRow ?? row;
    const frame = sitrepPreviewIframeHost?.root?.querySelector?.('iframe');

    if (!frame?.contentWindow) {
        window.open(currentRow.preview_url, '_blank', 'noopener,noreferrer');
        return;
    }

    frame.contentWindow.focus();
    frame.contentWindow.print();
}

function getSitrepDownloadUrls(row) {
    if (row?.download_urls && typeof row.download_urls === 'object') {
        return row.download_urls;
    }

    return {
        pdf: `/command/sitreps/${encodeURIComponent(row.id)}/download/pdf`,
        json: `/command/sitreps/${encodeURIComponent(row.id)}/download/json`,
        zip: `/command/sitreps/${encodeURIComponent(row.id)}/download/zip`,
    };
}

function renderFallbackList(items) {
    if (!items.length) {
        return '<p class="surface-empty">No generated SITREPs yet.</p>';
    }

    return `
        <div class="command-sitrep-fallback-list">
            ${items.map((item) => `
                <article class="command-sitrep-fallback-card">
                    <strong>${escapeHtml(item.title ?? 'Untitled SITREP')}</strong>
                    <span>${escapeHtml(formatSitrepNumber(item.sequence_number ?? item.id))} · ${escapeHtml(item.coverage_area ?? 'All coverage areas')}</span>
                    <small>${escapeHtml(formatPeriod(item.period_started_at, item.period_ended_at))}</small>
                    <a class="surface-button secondary tiny" href="${escapeHtml(item.preview_url)}">Preview</a>
                </article>
            `).join('')}
        </div>
    `;
}

function renderIncidentFallbackList(items) {
    if (!items.length) {
        return '<p class="surface-empty">No incidents available.</p>';
    }

    return `
        <div class="command-sitrep-fallback-list">
            ${items.map((item) => `
                <article class="command-sitrep-fallback-card">
                    <strong>#${escapeHtml(item.display_id ?? item.id)}</strong>
                    <span>${escapeHtml(item.citizen_name ?? 'Unknown citizen')} · ${escapeHtml(item.status_label ?? item.status ?? '')}</span>
                    <small>${escapeHtml(item.location_label ?? 'Location unavailable')}</small>
                </article>
            `).join('')}
        </div>
    `;
}

function formatSitrepRecordNumber(value) {
    const numeric = Number(value);

    if (!Number.isFinite(numeric)) {
        return `#${value ?? ''}`.trim();
    }

    return `#${String(numeric).padStart(6, '0')}`;
}

function formatSitrepNumber(value) {
    const numeric = Number(value);

    if (!Number.isFinite(numeric)) {
        return `SITREP ${value ?? ''}`.trim();
    }

    return `SITREP #${String(numeric).padStart(4, '0')}`;
}

function formatAlertTone(value) {
    const normalized = String(value ?? '').trim();

    if (normalized === 'Critical') {
        return 'critical';
    }

    if (normalized === 'Elevated') {
        return 'elevated';
    }

    return 'normal';
}

function normalizeSitrepWorkflowStatus(value) {
    return String(value ?? '').trim().toLowerCase() === 'published' ? 'published' : 'draft';
}

function normalizeSitrepVisibility(value, workflowStatus = 'draft') {
    if (workflowStatus === 'draft') {
        return 'private';
    }

    return String(value ?? '').trim().toLowerCase() === 'public' ? 'public' : 'private';
}

function sitrepWorkflowStatusLabel(value) {
    return value === 'published' ? 'Official' : 'Draft';
}

function sitrepVisibilityLabel(value) {
    return value === 'public' ? 'Public' : 'Private';
}

function findHomeSitrepId(items) {
    const home = items.find((item) => {
        const workflowStatus = normalizeSitrepWorkflowStatus(item.status);

        return workflowStatus === 'published'
            && normalizeSitrepVisibility(item.visibility, workflowStatus) === 'public';
    });

    return home?.id ?? null;
}

function formatPeriod(startedAt, endedAt) {
    return `${formatDateTime(startedAt)} - ${formatDateTime(endedAt)}`;
}

function currentManilaDayPeriod() {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(new Date())
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );
    const day = `${parts.year}-${parts.month}-${parts.day}`;

    return {
        label: day,
        startedAt: `${day}T00:00:00+08:00`,
        endedAt: `${day}T23:59:59+08:00`,
    };
}
