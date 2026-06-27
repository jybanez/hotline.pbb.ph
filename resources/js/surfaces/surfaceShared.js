import '../bootstrap.js';
import {
    bindMediaElementStream,
    buildCallSignalPayload,
    createThreadAttachment,
    createRealtimeConferenceState,
    ensureConferencePeerConnection,
    ensureConferenceRemoteStream,
    formatAttachmentFileSize,
    buildChatPublishPayload,
    buildRoomJoinPayload,
    getAttachmentMimeType,
    inferAttachmentKind,
    normalizeRealtimeSdp,
    normalizeChatMessageEvent,
    parseRealtimeEnvelope,
    parseRealtimeSignalJson,
    RealtimeSocketClient,
    reduceAttachmentChunkStore,
    resolveAttachmentUrlFromStore,
    shouldPreviewAttachmentFile,
    transferAttachmentInChunks,
    validateDraftAttachments,
} from '../vendor/pbb-realtime-sdk/index.js';

const OPERATOR_WORKBENCH_KEY = 'hotline.operator.active_incident_id';
const OPERATOR_WORKBENCH_CALL_SESSION_KEY = 'hotline.operator.active_call_session_id';
const INCOMING_MODAL_DISMISS_PREFIX = 'hotline.operator.dismissed_incoming.';
const TRANSFER_MODAL_DISMISS_PREFIX = 'hotline.operator.dismissed_transfer.';
const DEVICE_PRIMER_DISMISS_PREFIX = 'hotline.device.primer.dismissed.';
const SESSION_ACTIVITY_STALE_MS = 30 * 1000;
const SESSION_KEEPALIVE_MIN_INTERVAL_MS = 15 * 1000;
const SESSION_WATCH_INTERVAL_MS = 5 * 1000;
const CALL_SESSION_HEARTBEAT_MS = 2000;
const CALL_SESSION_KEEPALIVE_MS = 60 * 1000;
const HELPER_VENDOR_REV = 'eee1354';
const realtimeCallSessionRegistry = new Map();

function isDebugFlagEnabled(storageKey, globalKey) {
    if (typeof window === 'undefined') {
        return false;
    }

    if (window[globalKey] === true) {
        return true;
    }

    try {
        const stored = window.localStorage?.getItem(storageKey) ?? window.sessionStorage?.getItem(storageKey);
        return ['1', 'true', 'yes', 'on'].includes(String(stored ?? '').trim().toLowerCase());
    } catch {
        return false;
    }
}

const appState = {
    bootstrap: null,
    operatorDashboard: null,
    activeSurface: null,
    runtime: {
        mounted: [],
        operatorAlertClock: null,
        commandAlertClock: null,
        surfaceRefreshTimer: null,
        sessionWatcherTimer: null,
        sessionActivityBound: false,
        lastServerTouchAt: null,
        lastServerTouchClientAt: null,
        lastActivityAt: null,
        lastKeepaliveAt: null,
        keepaliveInFlight: false,
        reauthPrompting: false,
        callerPendingState: null,
        callerRealtimeStream: null,
        operatorRealtimeStream: null,
        operatorIncomingRingtone: null,
        operatorDiscoveryClaimed: false,
        operatorIncomingCallItem: null,
        operatorIncomingCallPhase: null,
        operatorIncomingModalClose: null,
        operatorPrimerAutoOpened: false,
        operatorIncidentElapsedTimers: new Map(),
        commandIncidentElapsedTimers: new Map(),
        receivedCommandBroadcastIds: new Set(),
        callerSpeechPrimed: false,
        callerSpeechPrimerCleanup: null,
        callerPrimerAutoOpened: false,
        navbarItems: [],
        navbarActiveId: null,
        navbarActions: [],
        navbarOnAction: null,
        navbarProfileMenuItems: [],
        navbarOnActionMenuSelect: null,
        navbarContentStart: null,
        navbarContentCenter: null,
        navbarContentEnd: null,
        navbarStatusContent: null,
        navbarStatusContentLabel: null,
    },
    helper: {
        ready: false,
        uiLoader: null,
        createNavbar: null,
        createToastStack: null,
        createBusyOverlay: null,
        createIcon: null,
        createActionModal: null,
        createFormModal: null,
        createReasonFormModal: null,
        createLoginFormModal: null,
        createReauthFormModal: null,
        createAccountFormModal: null,
        createChangePasswordFormModal: null,
        createDevicePrimerModal: null,
        createBottomDrawer: null,
        createChatThread: null,
        createChatComposer: null,
        createChatUploadQueue: null,
        createTabs: null,
        createKanban: null,
        createPasswordField: null,
        createPropertyEditor: null,
        createSkeleton: null,
        createEmptyState: null,
        createTreeGrid: null,
        createVirtualList: null,
        createElapsedTime: null,
        uiAlert: null,
        uiConfirm: null,
        navbar: null,
        toast: null,
        loginModal: null,
        reauthModal: null,
        accountModal: null,
        changePasswordModal: null,
        primerModal: null,
        interceptorsInstalled: false,
        reauthOpening: false,
    },
};

function logCallFlow(surface, step, detail = {}) {
    if (typeof console === 'undefined' || typeof console.info !== 'function') {
        return;
    }

    const safeDetail = detail && typeof detail === 'object' && !Array.isArray(detail)
        ? detail
        : {};

    console.info('[hotline.call.flow]', {
        timestamp: new Date().toISOString(),
        surface: String(surface ?? '').trim() || 'unknown',
        step: String(step ?? '').trim() || 'unknown',
        ...safeDetail,
    });
}

function logSessionKeepaliveDecision(step, detail = {}) {
    if (!isDebugFlagEnabled('hotlineSessionDebug', 'HOTLINE_SESSION_DEBUG') || typeof console === 'undefined' || typeof console.info !== 'function') {
        return;
    }

    const safeDetail = detail && typeof detail === 'object' && !Array.isArray(detail)
        ? detail
        : {};

    console.info('[hotline.session.keepalive]', {
        timestamp: new Date().toISOString(),
        surface: appState.activeSurface ?? appState.bootstrap?.surface ?? 'unknown',
        role: appState.bootstrap?.user?.role ?? null,
        step: String(step ?? '').trim() || 'unknown',
        ...safeDetail,
    });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function formatStatusLabel(value) {
    return String(value ?? '')
        .replaceAll('_', ' ')
        .replaceAll('-', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatDateTime(value) {
    if (!value) {
        return 'Pending';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return String(value);
    }

    return parsed.toLocaleString('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function availabilityPillClass(status) {
    if (status === 'green') {
        return 'pill green';
    }

    if (status === 'yellow') {
        return 'pill yellow';
    }

    return 'pill red';
}

function roleHome(role) {
    switch (role) {
        case 'citizen':
        case 'caller':
            return '/citizen';
        case 'operator':
            return '/operator';
        case 'admin':
            return '/admin';
        case 'command':
            return '/command';
        default:
            return '/';
    }
}

async function fetchJson(url, options = {}) {
    const response = await window.axios({
        url,
        method: options.method ?? 'get',
        data: options.data,
        headers: {
            Accept: 'application/json',
            ...(options.headers ?? {}),
        },
    });

    const payload = response.data;
    const requestUrl = String(url ?? '');
    const nextCsrfToken = payload?.csrf_token ?? payload?.data?.csrf_token ?? null;
    const touchedAt = payload?.touched_at ?? payload?.data?.touched_at ?? null;

    if (nextCsrfToken) {
        setCsrfToken(nextCsrfToken);
    }

    if (
        requestUrl.startsWith('/api/')
        && !requestUrl.includes('/api/logout')
        && (appState.bootstrap?.authenticated || Boolean(payload?.user))
    ) {
        touchSessionServerClock(touchedAt);
    }

    return payload;
}

function setCsrfToken(nextToken) {
    const csrfToken = String(nextToken ?? '').trim();

    if (!csrfToken) {
        return;
    }

    const meta = document.querySelector('meta[name="csrf-token"]');

    if (meta) {
        meta.setAttribute('content', csrfToken);
    }

    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;

    appState.bootstrap = {
        ...(appState.bootstrap ?? {}),
        csrf_token: csrfToken,
    };
}

function sessionLifetimeMinutes() {
    return Math.max(1, Number(appState.bootstrap?.session_lifetime_minutes ?? 15) || 15);
}

function touchSessionServerClock(value = null) {
    const parsed = value ? Date.parse(String(value)) : Number.NaN;

    appState.runtime.lastServerTouchAt = Number.isFinite(parsed) ? parsed : Date.now();
    appState.runtime.lastServerTouchClientAt = Date.now();
    appState.runtime.reauthPrompting = false;
}

function touchSessionActivityClock() {
    appState.runtime.lastActivityAt = Date.now();
}

function clearClientSessionState() {
    appState.bootstrap = {
        ...(appState.bootstrap ?? {}),
        authenticated: false,
        user: null,
    };
    appState.runtime.lastServerTouchAt = null;
    appState.runtime.lastServerTouchClientAt = null;
    appState.runtime.lastActivityAt = null;
    appState.runtime.lastKeepaliveAt = null;
    appState.runtime.keepaliveInFlight = false;
    appState.runtime.reauthPrompting = false;
    clearCallerPendingState();
    sessionStorage.removeItem(OPERATOR_WORKBENCH_KEY);
    sessionStorage.removeItem(OPERATOR_WORKBENCH_CALL_SESSION_KEY);
    ensureSessionWatcher();
}

function syncBootstrapSessionState(bootstrap) {
    setCsrfToken(bootstrap?.csrf_token);
    ensureSessionActivityTracking();

    if (bootstrap?.authenticated) {
        touchSessionServerClock(bootstrap?.session_touched_at);
        ensureSessionWatcher();
        return;
    }

    clearClientSessionState();
}

function ensureSessionActivityTracking() {
    if (appState.runtime.sessionActivityBound) {
        return;
    }

    appState.runtime.sessionActivityBound = true;

    let lastRecordedAt = 0;
    const recordActivity = () => {
        const now = Date.now();

        if ((now - lastRecordedAt) < 1000) {
            return;
        }

        lastRecordedAt = now;
        touchSessionActivityClock();
    };

    ['pointerdown', 'click', 'keydown', 'scroll', 'touchstart'].forEach((eventName) => {
        window.addEventListener(eventName, recordActivity, { passive: true });
    });

    window.addEventListener('focus', recordActivity);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            recordActivity();
            void checkSessionExpiryWatcher();
        }
    });
}

function ensureSessionWatcher() {
    if (appState.runtime.sessionWatcherTimer) {
        window.clearInterval(appState.runtime.sessionWatcherTimer);
        appState.runtime.sessionWatcherTimer = null;
    }

    if (!appState.bootstrap?.authenticated) {
        return;
    }

    appState.runtime.sessionWatcherTimer = window.setInterval(() => {
        void checkSessionExpiryWatcher();
    }, SESSION_WATCH_INTERVAL_MS);
}

function sessionKeepaliveWindowMs() {
    const lifetimeMs = sessionLifetimeMinutes() * 60 * 1000;

    return Math.min(2 * 60 * 1000, Math.max(15 * 1000, Math.floor(lifetimeMs * 0.2)));
}

function isCriticalSession() {
    const role = String(appState.bootstrap?.user?.role ?? '').trim();

    return Boolean(
        appState.bootstrap?.authenticated
        && ['citizen', 'caller', 'operator', 'command'].includes(role),
    );
}

function isCallerSession() {
    return Boolean(
        appState.bootstrap?.authenticated
        && ['citizen', 'caller'].includes(appState.bootstrap?.user?.role),
    );
}

function criticalSessionSurface() {
    const surface = String(appState.activeSurface ?? appState.bootstrap?.surface ?? '').trim();
    const role = String(appState.bootstrap?.user?.role ?? '').trim();

    if (['citizen', 'caller', 'operator', 'command'].includes(surface)) {
        return surface;
    }

    if (['citizen', 'caller', 'operator', 'command'].includes(role)) {
        return role;
    }

    return null;
}

function wasRecentlyActive() {
    const lastActivityAt = Number(appState.runtime.lastActivityAt ?? 0);

    if (!lastActivityAt) {
        return false;
    }

    return (Date.now() - lastActivityAt) <= SESSION_ACTIVITY_STALE_MS;
}

function shouldAttemptSessionKeepalive(remainingMs) {
    if (!appState.bootstrap?.authenticated || appState.helper.reauthOpening || appState.runtime.keepaliveInFlight) {
        logSessionKeepaliveDecision('skip-unavailable', { remainingMs });
        return false;
    }

    if (!isCriticalSession() && (document.visibilityState !== 'visible' || !document.hasFocus())) {
        logSessionKeepaliveDecision('skip-background-non-critical', { remainingMs });
        return false;
    }

    if (remainingMs > sessionKeepaliveWindowMs()) {
        logSessionKeepaliveDecision('skip-before-window', {
            remainingMs,
            keepaliveWindowMs: sessionKeepaliveWindowMs(),
        });
        return false;
    }

    const lastKeepaliveAt = Number(appState.runtime.lastKeepaliveAt ?? 0);

    const allowed = !lastKeepaliveAt || ((Date.now() - lastKeepaliveAt) >= SESSION_KEEPALIVE_MIN_INTERVAL_MS);

    logSessionKeepaliveDecision(allowed ? 'allow' : 'skip-min-interval', {
        remainingMs,
        keepaliveWindowMs: sessionKeepaliveWindowMs(),
        lastKeepaliveAgeMs: lastKeepaliveAt ? Date.now() - lastKeepaliveAt : null,
    });

    return allowed;
}

async function pingSessionKeepalive() {
    if (appState.runtime.keepaliveInFlight) {
        return false;
    }

    appState.runtime.keepaliveInFlight = true;
    appState.runtime.lastKeepaliveAt = Date.now();

    try {
        const criticalSurface = criticalSessionSurface();
        const pingUrl = criticalSurface
            ? `/api/session/ping?surface=${encodeURIComponent(criticalSurface)}`
            : '/api/session/ping';
        logSessionKeepaliveDecision('ping-start', { pingUrl });
        const payload = await fetchJson(pingUrl);
        const pingData = payload?.data ?? payload;

        setCsrfToken(pingData?.csrf_token ?? payload?.csrf_token ?? null);
        touchSessionServerClock(pingData?.touched_at ?? payload?.touched_at ?? null);

        logSessionKeepaliveDecision('ping-success', {
            pingUrl,
            lifetimeMinutes: sessionLifetimeMinutes(),
        });
        return true;
    } catch (error) {
        const status = error?.response?.status;
        logSessionKeepaliveDecision('ping-error', {
            status,
            message: String(error?.message ?? ''),
        });

        if (status === 401 || status === 419) {
            if (isCriticalSession() && await restoreCriticalSession()) {
                return true;
            }

            appState.runtime.reauthPrompting = true;
            await openReauthModal();
        }

        return false;
    } finally {
        appState.runtime.keepaliveInFlight = false;
    }
}

async function rerenderActiveSurface(bootstrap = null, options = {}) {
    const { renderSurface } = await import('./renderSurface.js');

    await renderSurface(appState.activeSurface ?? 'public', {
        bootstrap,
        ...options,
    });
}

function refreshActiveSurfaceSessionChrome(bootstrap) {
    const root = document.getElementById('app');
    const surface = appState.activeSurface ?? bootstrap?.surface ?? 'public';

    if (root && surface) {
        mountSurfaceChrome(root, surface, bootstrap);
    }

    window.dispatchEvent(new CustomEvent('hotline:session-restored', {
        detail: {
            bootstrap,
            surface,
        },
    }));
}

async function handleReauthCancel() {
    clearClientSessionState();
    window.location.assign('/');
}

async function restoreCriticalSession() {
    if (!isCriticalSession()) {
        return false;
    }

    const expectedRole = String(appState.bootstrap?.user?.role ?? '').trim();

    try {
        const bootstrap = await refreshBootstrap(criticalSessionSurface() ?? appState.activeSurface ?? expectedRole);

        if (bootstrap?.authenticated && String(bootstrap?.user?.role ?? '').trim() === expectedRole) {
            appState.runtime.reauthPrompting = false;
            appState.helper.reauthOpening = false;
            touchSessionServerClock(bootstrap?.session_touched_at);
            return true;
        }
    } catch (_error) {
        // Fall through to CSRF-only recovery below when restoration is not possible.
    }

    appState.runtime.reauthPrompting = false;
    appState.helper.reauthOpening = false;

    try {
        await refreshCsrfToken();
    } catch (_error) {
        // Keep the critical surface open even if CSRF refresh is unavailable.
    }

    return false;
}

async function checkSessionExpiryWatcher() {
    if (!appState.bootstrap?.authenticated || appState.helper.reauthOpening || appState.runtime.reauthPrompting) {
        logSessionKeepaliveDecision('watcher-skip-unavailable');
        return;
    }

    const lastTouchAt = Number(appState.runtime.lastServerTouchAt ?? 0);
    const lastTouchClientAt = Number(appState.runtime.lastServerTouchClientAt ?? 0);

    if (!lastTouchAt || !lastTouchClientAt) {
        logSessionKeepaliveDecision('watcher-skip-missing-clock', {
            lastTouchAt,
            lastTouchClientAt,
        });
        return;
    }

    const lifetimeMs = sessionLifetimeMinutes() * 60 * 1000;
    const remainingMs = lifetimeMs - (Date.now() - lastTouchClientAt);
    logSessionKeepaliveDecision('watcher-check', {
        lifetimeMinutes: sessionLifetimeMinutes(),
        lifetimeMs,
        remainingMs,
        keepaliveWindowMs: sessionKeepaliveWindowMs(),
        critical: isCriticalSession(),
        visible: document.visibilityState,
        focused: document.hasFocus(),
    });

    if (remainingMs > 0) {
        if (shouldAttemptSessionKeepalive(remainingMs) && (wasRecentlyActive() || isCriticalSession())) {
            await pingSessionKeepalive();
        }

        return;
    }

    if (wasRecentlyActive() || isCriticalSession()) {
        const refreshed = await pingSessionKeepalive();

        if (refreshed) {
            return;
        }
    }

    if (isCriticalSession() && await restoreCriticalSession()) {
        return;
    }

    appState.runtime.reauthPrompting = true;
    await openReauthModal();
}

function applySessionPayload(payload) {
    setCsrfToken(payload?.csrf_token);

    appState.bootstrap = {
        ...(appState.bootstrap ?? {}),
        authenticated: Object.prototype.hasOwnProperty.call(payload ?? {}, 'authenticated')
            ? Boolean(payload?.authenticated)
            : Boolean(payload?.user),
        user: payload?.user ?? null,
        alert_level: payload?.alert_level ?? appState.bootstrap?.alert_level,
        alert_level_description: payload?.alert_level_description ?? appState.bootstrap?.alert_level_description,
        settings: payload?.settings ?? appState.bootstrap?.settings ?? {},
        session_lifetime_minutes: Number(payload?.session_lifetime_minutes ?? appState.bootstrap?.session_lifetime_minutes ?? 0) || appState.bootstrap?.session_lifetime_minutes,
        surface: payload?.surface ?? appState.bootstrap?.surface ?? appState.activeSurface ?? null,
    };

    if (payload?.user) {
        touchSessionServerClock(payload?.session_touched_at);
        touchSessionActivityClock();
        ensureSessionActivityTracking();
        ensureSessionWatcher();
        return;
    }

    clearClientSessionState();
}

async function refreshBootstrap(surface = appState.activeSurface ?? 'public') {
    const bootstrap = await fetchJson(`/api/bootstrap?surface=${encodeURIComponent(surface)}`);

    appState.bootstrap = bootstrap;
    syncBootstrapSessionState(bootstrap);

    return bootstrap;
}

async function refreshCsrfToken() {
    try {
        const payload = await fetchJson('/api/csrf-token');

        return payload?.csrf_token ?? payload?.data?.csrf_token ?? null;
    } catch (error) {
        return null;
    }
}

function currentCsrfToken() {
    return String(
        appState.bootstrap?.csrf_token
        ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        ?? '',
    ).trim();
}

function csrfRequestHeaders(token = currentCsrfToken()) {
    const csrfToken = String(token ?? '').trim();

    if (!csrfToken) {
        return {};
    }

    return {
        'X-CSRF-TOKEN': csrfToken,
    };
}

async function ensureHelperUi() {
    if (appState.helper.ready) {
        return appState.helper;
    }

    const helperLoaderImport = new Function(`return import("/vendor/helpers.pbb.ph/js/ui/ui.loader.js?v=${HELPER_VENDOR_REV}")`);
    const { createUiLoader, DEFAULT_COMPONENT_REGISTRY } = await helperLoaderImport();
    const uiLoader = createUiLoader(DEFAULT_COMPONENT_REGISTRY, {
        preferBundles: true,
        bundles: {
            ui: {
                prefixes: ['ui.', 'incident.'],
                js: `../../dist/helpers.ui.bundle.min.js?v=${HELPER_VENDOR_REV}`,
                css: [`../../dist/helpers.ui.bundle.min.css?v=${HELPER_VENDOR_REV}`],
            },
        },
    });
    const [
        createNavbar,
        createToastStack,
        createBusyOverlay,
        iconModule,
        createActionModal,
        createFormModal,
        createReasonFormModal,
        createLoginFormModal,
        createReauthFormModal,
        createAccountFormModal,
        createChangePasswordFormModal,
        createDevicePrimerModal,
        createBottomDrawer,
        createChatThread,
        createChatComposer,
        createChatUploadQueue,
        createSelect,
        createTabs,
        createKanban,
        createPasswordField,
        createPropertyEditor,
        createSkeleton,
        createEmptyState,
        createGrid,
        createTreeGrid,
        createVirtualList,
        createTimeline,
        createElapsedTime,
        createClock,
        createMapControls,
        createSignalStrength,
        uiAlert,
        uiConfirm,
    ] = await Promise.all([
        uiLoader.get('ui.navbar'),
        uiLoader.get('ui.toast'),
        uiLoader.get('ui.busy.overlay'),
        uiLoader.get('ui.icons'),
        uiLoader.get('ui.action.modal'),
        uiLoader.get('ui.form.modal'),
        uiLoader.get('ui.form.modal.reason'),
        uiLoader.get('ui.form.modal.login'),
        uiLoader.get('ui.form.modal.reauth'),
        uiLoader.get('ui.form.modal.account'),
        uiLoader.get('ui.form.modal.change.password'),
        uiLoader.get('ui.device.primer.modal'),
        uiLoader.get('ui.drawer'),
        uiLoader.get('ui.chat.thread'),
        uiLoader.get('ui.chat.composer'),
        uiLoader.get('ui.chat.upload.queue'),
        uiLoader.get('ui.select'),
        uiLoader.get('ui.tabs'),
        uiLoader.get('ui.kanban'),
        uiLoader.get('ui.password'),
        uiLoader.get('ui.property.editor'),
        uiLoader.get('ui.skeleton'),
        uiLoader.get('ui.empty.state'),
        uiLoader.get('ui.grid'),
        uiLoader.get('ui.tree.grid'),
        uiLoader.get('ui.virtual.list'),
        uiLoader.get('ui.timeline'),
        uiLoader.get('ui.elapsed.time'),
        uiLoader.get('ui.clock'),
        uiLoader.get('ui.map.controls'),
        uiLoader.get('ui.signal.strength'),
        uiLoader.get('ui.dialog.alert'),
        uiLoader.get('ui.dialog.confirm'),
    ]);
    const createIcon = typeof iconModule === 'function'
        ? iconModule
        : iconModule?.createIcon ?? null;

    Object.assign(appState.helper, {
        ready: true,
        uiLoader,
        createNavbar,
        createToastStack,
        createBusyOverlay,
        createIcon,
        createActionModal,
        createFormModal,
        createReasonFormModal,
        createLoginFormModal,
        createReauthFormModal,
        createAccountFormModal,
        createChangePasswordFormModal,
        createDevicePrimerModal,
        createBottomDrawer,
        createChatThread,
        createChatComposer,
        createChatUploadQueue,
        createSelect,
        createTabs,
        createKanban,
        createPasswordField,
        createPropertyEditor,
        createSkeleton,
        createEmptyState,
        createGrid,
        createTreeGrid,
        createVirtualList,
        createTimeline,
        createElapsedTime,
        createClock,
        createMapControls,
        createSignalStrength,
        uiAlert,
        uiConfirm,
        toast: appState.helper.toast ?? createToastStack({
            position: 'top-right',
            defaultDuration: 3200,
            max: 4,
            speakTypes: ['error', 'warn', 'info'],
        }),
    });

    installAxiosReauthInterceptor();

    return appState.helper;
}

async function confirmDeleteAction(message, options = {}) {
    await ensureHelperUi();

    let completed = false;
    const confirmed = await appState.helper.uiConfirm(message, {
        title: options.title ?? 'Delete Record',
        variant: options.variant ?? 'warning',
        description: options.description ?? '',
        confirmText: options.confirmText ?? 'Delete',
        confirmVariant: options.confirmVariant ?? 'danger',
        cancelText: options.cancelText ?? 'Cancel',
        confirmBusyMessage: options.confirmBusyMessage ?? options.busyMessage ?? 'Deleting...',
        errorText: options.errorText ?? 'Unable to delete the record. Please try again.',
        onConfirm: async () => {
            if (typeof options.onConfirm === 'function') {
                await options.onConfirm();
            }

            completed = true;
            return true;
        },
    });

    return Boolean(confirmed && completed);
}

function formatBlockedDeleteMessage(payload, fallback = 'Delete blocked.') {
    const baseMessage = String(payload?.message ?? fallback).trim() || fallback;
    const references = Array.isArray(payload?.references) ? payload.references : [];

    if (!references.length) {
        return baseMessage;
    }

    const referenceSummary = references
        .slice(0, 4)
        .map((reference) => {
            const label = String(reference?.label ?? reference?.table ?? 'References').trim() || 'References';
            const count = Number(reference?.count ?? 0);

            return count > 0 ? `${label} (${count})` : label;
        })
        .join(', ');

    const extraCount = references.length - Math.min(references.length, 4);
    const extraSuffix = extraCount > 0 ? `, +${extraCount} more` : '';

    return `${baseMessage} Still referenced by: ${referenceSummary}${extraSuffix}.`;
}

function installAxiosReauthInterceptor() {
    if (appState.helper.interceptorsInstalled) {
        return;
    }

    window.axios.interceptors.response.use(
        (response) => response,
        async (error) => {
            const status = error?.response?.status;
            const url = String(error?.config?.url ?? '');
            const canRetryCsrf = (
                status === 419
                && error?.config
                && !error.config._hotlineCsrfRetried
                && !url.includes('/api/csrf-token')
                && !url.includes('/api/login')
                && !url.includes('/api/reauth')
            );

            if (canRetryCsrf) {
                const nextToken = await refreshCsrfToken();

                if (nextToken) {
                    const retryConfig = {
                        ...error.config,
                        _hotlineCsrfRetried: true,
                        headers: {
                            ...(error.config.headers ?? {}),
                            ...csrfRequestHeaders(nextToken),
                        },
                    };

                    return window.axios(retryConfig);
                }
            }

            if (
                (status === 401 || status === 419)
                && appState.bootstrap?.authenticated
                && error?.config
                && !url.includes('/api/login')
                && !url.includes('/api/reauth')
                && !url.includes('/api/logout')
            ) {
                console.warn('[hotline.session] opening reauth after authenticated request failed.', {
                    status,
                    url,
                    retriedCsrf: Boolean(error?.config?._hotlineCsrfRetried),
                });

                if (
                    isCriticalSession()
                    && !error.config._hotlineSessionRestoredRetried
                    && await restoreCriticalSession()
                ) {
                    const retryConfig = {
                        ...error.config,
                        _hotlineSessionRestoredRetried: true,
                        headers: {
                            ...(error.config.headers ?? {}),
                            ...csrfRequestHeaders(currentCsrfToken()),
                        },
                    };

                    return window.axios(retryConfig);
                }

                await openReauthModal();
            }

            return Promise.reject(error);
        },
    );

    appState.helper.interceptorsInstalled = true;
}

function showToast(message, tone = 'error', options = {}) {
    const toast = appState.helper.toast;

    if (!toast) {
        return;
    }

    if (tone === 'success') {
        toast.success(message, options);
        return;
    }

    if (tone === 'warn' || tone === 'warning') {
        toast.warn(message, options);
        return;
    }

    if (tone === 'info') {
        toast.info(message, options);
        return;
    }

    toast.error(message, options);
}

function applyModalErrors(context, error) {
    const payload = error?.response?.data ?? {};

    if (typeof context?.applyApiErrors === 'function') {
        context.applyApiErrors(payload);
        return;
    }

    const errors = payload.errors ?? {};

    if (typeof context?.setErrors === 'function' && errors && typeof errors === 'object') {
        const normalizedErrors = Object.fromEntries(
            Object.entries(errors).map(([key, value]) => [
                key,
                Array.isArray(value) ? String(value[0] ?? '') : String(value ?? ''),
            ]),
        );

        context.setErrors(normalizedErrors);
    }

    if (typeof context?.setFormError === 'function' && payload.message) {
        context.setFormError(payload.message);
        return;
    }

    if (typeof context?.setFormError === 'function' && error?.message) {
        context.setFormError(error.message);
    }
}

function resetSurfaceRuntime(nextSurface = null) {
    appState.runtime.mounted.forEach((instance) => {
        instance?.destroy?.();
    });
    appState.runtime.mounted = [];
    if (appState.runtime.operatorIncidentElapsedTimers instanceof Map) {
        appState.runtime.operatorIncidentElapsedTimers.forEach((instance) => {
            instance?.destroy?.();
        });
        appState.runtime.operatorIncidentElapsedTimers.clear();
    }
    if (appState.runtime.commandIncidentElapsedTimers instanceof Map) {
        appState.runtime.commandIncidentElapsedTimers.forEach((instance) => {
            instance?.destroy?.();
        });
        appState.runtime.commandIncidentElapsedTimers.clear();
    }
    if (!(appState.runtime.receivedCommandBroadcastIds instanceof Set)) {
        appState.runtime.receivedCommandBroadcastIds = new Set();
    }
    appState.runtime.operatorDashboardMapControls?.destroy?.();
    appState.runtime.operatorDashboardMapControls = null;
    appState.runtime.navbarItems = [];
    appState.runtime.navbarActiveId = null;
    appState.runtime.navbarActions = [];
    appState.runtime.navbarOnAction = null;
    appState.runtime.navbarProfileMenuItems = [];
    appState.runtime.navbarOnActionMenuSelect = null;
    appState.runtime.navbarContentStart = null;
    appState.runtime.navbarContentCenter = null;
    appState.runtime.navbarContentEnd = null;
    appState.runtime.navbarStatusContent = null;
    appState.runtime.navbarStatusContentLabel = null;
    appState.runtime.callerRealtimeSignal = null;
    appState.runtime.callerRealtimeSignalSnapshot = null;
    appState.runtime.callerSignalHelpDrawer = null;
    appState.runtime.operatorRealtimeSignal = null;
    appState.runtime.commandRealtimeSignal = null;

    appState.runtime.operatorAlertClock?.destroy?.();
    appState.runtime.operatorAlertClock = null;
    appState.runtime.commandAlertClock?.destroy?.();
    appState.runtime.commandAlertClock = null;

    if (appState.runtime.surfaceRefreshTimer) {
        window.clearTimeout(appState.runtime.surfaceRefreshTimer);
        appState.runtime.surfaceRefreshTimer = null;
    }

    if (appState.runtime.sessionWatcherTimer) {
        window.clearInterval(appState.runtime.sessionWatcherTimer);
        appState.runtime.sessionWatcherTimer = null;
    }

    if (!['citizen', 'caller'].includes(nextSurface) && appState.runtime.callerRealtimeStream) {
        appState.runtime.callerRealtimeStream.destroy?.();
        appState.runtime.callerRealtimeStream = null;
    }

    if (!['citizen', 'caller'].includes(nextSurface) && appState.runtime.callerCameraStream instanceof MediaStream) {
        appState.runtime.callerCameraStream.getTracks().forEach((track) => {
            try {
                track.stop();
            } catch {
                // Ignore device teardown failures.
            }
        });
        appState.runtime.callerCameraStream = null;
        appState.runtime.callerCameraDevices = [];
        appState.runtime.callerCameraDeviceId = null;
    }

    if (nextSurface !== 'operator' && appState.runtime.operatorRealtimeStream) {
        appState.runtime.operatorRealtimeStream.destroy?.();
        appState.runtime.operatorRealtimeStream = null;
    }

    if (appState.runtime.operatorIncomingRingtone instanceof Audio) {
        try {
            appState.runtime.operatorIncomingRingtone.pause();
            appState.runtime.operatorIncomingRingtone.currentTime = 0;
        } catch {
            // Ignore audio teardown failures.
        }
    }
    appState.runtime.operatorIncomingRingtone = null;
    appState.runtime.operatorDiscoveryClaimed = false;
    appState.runtime.operatorIncomingCallItem = null;

    if (!['citizen', 'caller'].includes(nextSurface) && typeof appState.runtime.callerSpeechPrimerCleanup === 'function') {
        appState.runtime.callerSpeechPrimerCleanup();
        appState.runtime.callerSpeechPrimerCleanup = null;
    }

    if (appState.runtime.callerLiveModalPollTimer) {
        window.clearInterval(appState.runtime.callerLiveModalPollTimer);
        appState.runtime.callerLiveModalPollTimer = null;
    }

    appState.runtime.callerLiveModal = null;
}

function trackSurfaceInstance(instance) {
    if (!instance || typeof instance.destroy !== 'function') {
        return instance;
    }

    appState.runtime.mounted.push(instance);

    return instance;
}

function getCallerPendingState() {
    return appState.runtime.callerPendingState;
}

function setCallerPendingState(payload) {
    appState.runtime.callerPendingState = payload;
}

function clearCallerPendingState() {
    appState.runtime.callerPendingState = null;
}

function dedupeNavItems(items) {
    const seen = new Set();

    return items.filter((item) => {
        const key = String(item?.id ?? '');

        if (!key || seen.has(key)) {
            return false;
        }

        seen.add(key);
        return true;
    });
}

function createProfileMenuItems() {
    return [
        { id: 'account', label: 'Manage Account' },
        { id: 'password', label: 'Change Password' },
        { id: 'logout', label: 'Logout', danger: true },
    ];
}

function createIconMarkup(name, options = {}) {
    if (!appState.helper.createIcon) {
        return '';
    }

    try {
        return appState.helper.createIcon(name, options).outerHTML ?? '';
    } catch (error) {
        return '';
    }
}

function mountSurfaceChrome(root, surface, bootstrap) {
    const navHost = root.querySelector('[data-helper-navbar]');

    if (!navHost || !appState.helper.createNavbar) {
        return;
    }

    appState.helper.navbar?.destroy?.();

    const currentRoleHome = bootstrap.authenticated ? roleHome(bootstrap.user?.role) : '/';
    const items = dedupeNavItems(appState.runtime.navbarItems ?? []);
    const activeId = appState.runtime.navbarActiveId ?? (surface === 'public' ? 'public' : (bootstrap.user?.role ?? surface));

    const actions = bootstrap.authenticated
        ? [
            ...(Array.isArray(appState.runtime.navbarActions) ? appState.runtime.navbarActions : []),
            {
                id: 'profile',
                label: bootstrap.user?.name ?? 'Account',
                icon: createIconMarkup('people.user', { size: 18, ariaLabel: 'Account' }),
                menuItems: [
                    ...(Array.isArray(appState.runtime.navbarProfileMenuItems) ? appState.runtime.navbarProfileMenuItems : []),
                    ...createProfileMenuItems(),
                ],
            },
        ]
        : [{
            id: 'login',
            label: 'Login',
            icon: createIconMarkup('people.user', { size: 18, ariaLabel: 'Login' }),
        }];

    appState.helper.navbar = appState.helper.createNavbar(navHost, {}, {
        brandText: 'PBB Hotline Beta',
        brandSubtitle: bootstrap?.app?.version ? `v${bootstrap.app.version}` : 'hotline.pbb.ph',
        activeId,
        items,
        actions,
        contentStart: appState.runtime.navbarContentStart ?? null,
        contentCenter: appState.runtime.navbarContentCenter ?? null,
        contentEnd: appState.runtime.navbarContentEnd ?? null,
        statusContent: appState.runtime.navbarStatusContent ?? null,
        statusContentLabel: appState.runtime.navbarStatusContentLabel ?? 'Status',
        sticky: true,
        onNavigate: (item) => {
            const target = item?.id === 'brand'
                ? (navHost.dataset.brandHref || currentRoleHome)
                : item?.href;

            if (target) {
                window.location.assign(target);
            }
        },
        onAction: (action) => {
            if (action?.id === 'login') {
                void openLoginModal();
                return;
            }

            if (typeof appState.runtime.navbarOnAction === 'function') {
                appState.runtime.navbarOnAction(action);
            }
        },
        onActionMenuSelect: (action, item) => {
            if (typeof appState.runtime.navbarOnActionMenuSelect === 'function'
                && appState.runtime.navbarOnActionMenuSelect(action, item) === true) {
                return;
            }

            if (item?.id === 'account') {
                void openAccountModal();
                return;
            }

            if (item?.id === 'password') {
                void openChangePasswordModal();
                return;
            }

            if (item?.id === 'logout') {
                void logoutCurrentUser();
            }
        },
    });
}

async function logoutCurrentUser() {
    try {
        const response = await fetchJson('/api/logout', { method: 'post' });

        setCsrfToken(response?.csrf_token);
        clearClientSessionState();
        showToast('Signed out.', 'success');
        window.location.assign('/');
    } catch (error) {
        showToast(error.response?.data?.message ?? 'Unable to sign out.');
    }
}

async function openLoginModal() {
    const helper = await ensureHelperUi();

    helper.loginModal?.destroy?.();
    helper.loginModal = helper.createLoginFormModal({
        title: 'PBB Hotline Login',
        message: 'Use your active Hotline account to continue.',
        identifierKind: 'email',
        closeOnBackdrop: true,
        closeOnEscape: true,
        async onSubmit(values, context) {
            try {
                const activeCsrfToken = currentCsrfToken();

                if (!activeCsrfToken) {
                    if (typeof context?.setFormError === 'function') {
                        context.setFormError('Unable to resolve the CSRF token. Please reload the page and try again.');
                    }

                    return false;
                }

                const response = await fetchJson('/api/login', {
                    method: 'post',
                    data: values,
                    headers: csrfRequestHeaders(activeCsrfToken),
                });

                if (!response?.user) {
                    throw new Error('Authenticated session was not available after login.');
                }

                applySessionPayload(response);
                const target = String(response?.redirect_to ?? roleHome(response.user.role) ?? '/').trim() || '/';

                showToast('Signed in successfully.', 'success');

                if (window.location.pathname !== target) {
                    window.location.assign(target);
                } else {
                    await rerenderActiveSurface(appState.bootstrap);
                }

                return true;
            } catch (error) {
                applyModalErrors(context, error);
                return false;
            }
        },
        onClose() {
            helper.loginModal = null;
        },
    });

    return helper.loginModal.open();
}

async function openReauthModal() {
    const helper = await ensureHelperUi();

    if (helper.reauthOpening || !appState.bootstrap?.authenticated) {
        return;
    }

    helper.reauthModal?.destroy?.();
    helper.reauthModal = helper.createReauthFormModal({
        title: 'Session Expired',
        message: 'Your session has expired. To continue, please enter your password again.',
        submitLabel: 'Login',
        busyMessage: 'Signing in...',
        cancelLabel: 'Cancel',
        closeOnBackdrop: false,
        closeOnEscape: false,
        identifierValue: appState.bootstrap?.user?.email ?? '',
        async onSubmit(values, context) {
            try {
                const refreshedCsrfToken = await refreshCsrfToken();
                const activeCsrfToken = String(refreshedCsrfToken ?? currentCsrfToken()).trim();

                if (!activeCsrfToken) {
                    if (typeof context?.setFormError === 'function') {
                        context.setFormError('Unable to resolve the CSRF token. Please reload the page and try again.');
                    }

                    return false;
                }

                const response = await fetchJson('/api/reauth', {
                    method: 'post',
                    data: {
                        email: appState.bootstrap?.user?.email ?? values?.email ?? '',
                        password: String(values?.password ?? ''),
                    },
                    headers: csrfRequestHeaders(activeCsrfToken),
                });
                applySessionPayload(response);
                const bootstrap = appState.bootstrap;

                if (!bootstrap?.authenticated || !bootstrap?.user) {
                    throw new Error('Authenticated session was not available after re-authentication.');
                }

                showToast('Session restored.', 'success');
                appState.runtime.reauthPrompting = false;
                helper.reauthOpening = false;

                if (isCallerSession()) {
                    await rerenderActiveSurface(bootstrap, { preserveState: true });
                } else {
                    refreshActiveSurfaceSessionChrome(bootstrap);
                }

                return true;
            } catch (error) {
                const emailErrors = error?.response?.data?.errors?.email;

                if (Array.isArray(emailErrors) && emailErrors.length > 0 && typeof context?.setErrors === 'function') {
                    context.setErrors({
                        password: String(emailErrors[0] ?? 'Unable to restore session.'),
                    });
                    return false;
                }

                applyModalErrors(context, error);
                return false;
            }
        },
        onClose(meta = {}) {
            helper.reauthOpening = false;
            helper.reauthModal = null;
            appState.runtime.reauthPrompting = false;

            if (meta.reason === 'cancel') {
                void handleReauthCancel();
            }
        },
    });

    helper.reauthOpening = true;
    await helper.reauthModal.open();
}

async function openAccountModal() {
    const helper = await ensureHelperUi();
    const user = await fetchJson('/api/user');
    const currentAvatarUrl = user.avatar ?? '';

    helper.accountModal?.destroy?.();
    helper.accountModal = helper.createAccountFormModal({
        title: 'Account',
        initialValues: {
            name: user.name ?? '',
            email: user.email ?? '',
            mobile: user.mobile ?? '',
        },
        avatar: {
            previewUrl: currentAvatarUrl,
            label: 'Profile Photo',
            emptyText: 'Choose a profile photo.',
            previewText: 'Current profile photo',
            selectLabel: 'Choose photo',
            changeLabel: 'Change photo',
        },
        extraRows: [
            [{
                type: 'input',
                input: 'tel',
                name: 'mobile',
                label: 'Mobile',
                placeholder: '+63 917 123 4567',
                required: true,
            }],
        ],
        extraActionsPlacement: 'start',
        extraActions: [{
            id: 'change-password',
            label: 'Change Password',
            onClick() {
                void openChangePasswordModal();
                return false;
            },
        }],
        async onSubmit(values, context) {
            try {
                let payload;

                if (values.avatar instanceof File) {
                    payload = new FormData();
                    payload.append('name', String(values.name ?? ''));
                    payload.append('email', String(values.email ?? ''));
                    payload.append('mobile', String(values.mobile ?? ''));
                    payload.append('avatar', values.avatar);
                } else {
                    payload = {
                        name: values.name,
                        email: values.email,
                        mobile: values.mobile,
                    };
                }

                const response = await fetchJson('/api/user', {
                    method: 'post',
                    data: payload,
                });

                appState.bootstrap = {
                    ...(appState.bootstrap ?? {}),
                    authenticated: true,
                    user: response,
                };

                showToast('Account details updated.', 'success');
                void rerenderActiveSurface();
                return true;
            } catch (error) {
                applyModalErrors(context, error);
                return false;
            }
        },
    });

    await helper.accountModal.open();
}

async function openChangePasswordModal() {
    const helper = await ensureHelperUi();

    helper.changePasswordModal?.destroy?.();
    helper.changePasswordModal = helper.createChangePasswordFormModal({
        title: 'Change Password',
        async onSubmit(values, context) {
            try {
                await fetchJson('/api/user/password', {
                    method: 'post',
                    data: values,
                });

                showToast('Password updated.', 'success');
                return true;
            } catch (error) {
                applyModalErrors(context, error);
                return false;
            }
        },
    });

    await helper.changePasswordModal.open();
}

function buildDevicePrimerPayload(report) {
    return {
        checks: report.items.map((item) => ({
            id: item.key,
            kind: item.key,
            label: item.label,
            description: item.key === 'speechSynthesis' && ['citizen', 'caller'].includes(report.surface)
                ? 'Needed for spoken alert updates. Some browsers require a tap before voice alerts can play.'
                : item.severity === 'blocking'
                    ? 'Required for baseline hotline operation.'
                    : 'Optional in Phase 1 but recommended when available.',
            required: ['citizen', 'caller'].includes(report.surface) ? true : item.severity === 'blocking',
        })),
    };
}

function primeCallerPlaybackGesture() {
    if (
        typeof window === 'undefined'
        || (!window.AudioContext && !window.webkitAudioContext)
    ) {
        return;
    }

    try {
        const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
        const context = new AudioContextCtor();
        const resumeResult = typeof context.resume === 'function' ? context.resume() : null;

        Promise.resolve(resumeResult)
            .catch(() => {})
            .finally(() => {
                Promise.resolve(context.close?.()).catch(() => {});
            });
    } catch {
        // Ignore unlock failures; Helper retry will still run its own check afterward.
    }
}

function primeCallerSpeechGesture() {
    if (
        typeof window === 'undefined'
        || !('speechSynthesis' in window)
        || typeof window.SpeechSynthesisUtterance !== 'function'
    ) {
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
        // Ignore unlock failures; live alert speech can still attempt later.
    }
}

async function openDevicePrimerModal(surface, report, forceOpen = false) {
    const helper = await ensureHelperUi();
    const dismissKey = `${DEVICE_PRIMER_DISMISS_PREFIX}${surface}`;
    const existingPrimerOpen = helper.primerModal?.getState?.().open === true;

    if (forceOpen) {
        sessionStorage.removeItem(dismissKey);
    }

    if (existingPrimerOpen && helper.primerModalSurface === surface) {
        return;
    }

    helper.primerModalReplacing = true;
    helper.primerModal?.destroy?.();
    helper.primerModalReplacing = false;
    helper.primerModalSurface = surface;
    helper.primerModal = helper.createDevicePrimerModal(buildDevicePrimerPayload(report), {
        title: 'Device Check',
        blockUntilReady: false,
        showSummary: false,
        autoCloseOnReady: false,
        mode: ['citizen', 'caller'].includes(surface) ? 'compact' : 'cards',
        closeOnBackdrop: true,
        closeOnEscape: true,
        onRetry(check) {
            if (!['citizen', 'caller'].includes(surface)) {
                return;
            }

            const kind = String(check?.kind ?? '').toLowerCase();

            if (kind === 'audioplayback') {
                primeCallerPlaybackGesture();
            }

            if (kind === 'speechsynthesis') {
                primeCallerSpeechGesture();
            }
        },
        onCheckComplete(check, state) {
            if (
                !['citizen', 'caller'].includes(surface)
                || String(check?.kind ?? '').toLowerCase() !== 'speechsynthesis'
                || appState.runtime.callerSpeechPrimed
            ) {
                return;
            }

            const checks = Array.isArray(state?.checks) ? state.checks : [];
            helper.primerModal?.getPrimer?.()?.update({
                checks: checks.map((item) => {
                    if (String(item?.kind ?? '').toLowerCase() !== 'speechsynthesis') {
                        return item;
                    }

                    return {
                        ...item,
                        status: 'failed',
                        detailText: 'Tap Retry to prime spoken alert playback.',
                        canRetry: true,
                    };
                }),
            }, {
                autoRun: false,
            });
        },
        onComplete(state) {
            syncDevicePrimerReport(surface, report, state);

            const checks = Array.isArray(state?.checks) ? state.checks : [];
            const allChecksReady = checks.length > 0
                && checks.every((check) => String(check?.status ?? '').toLowerCase() === 'ready');
            const callerSpeechReady = !['citizen', 'caller'].includes(surface) || appState.runtime.callerSpeechPrimed;

            if (state?.allComplete && allChecksReady && callerSpeechReady) {
                helper.primerModal?.close?.({ reason: 'device-primer-ready' });
            }
        },
        onClose() {
            if (helper.primerModalSurface === surface) {
                helper.primerModalSurface = null;
            }

            if (!helper.primerModalReplacing) {
                sessionStorage.setItem(dismissKey, '1');
            }
        },
    });

    await helper.primerModal.open();
}

function syncDevicePrimerReport(surface, report, state) {
    if (!report || !state || !Array.isArray(state.checks)) {
        return report;
    }

    const statusById = new Map(state.checks.map((check) => [
        String(check?.id ?? ''),
        String(check?.status ?? '').toLowerCase(),
    ]));
    const items = report.items.map((item) => ({
        ...item,
        ok: statusById.get(item.key) === 'ready',
    }));
    const blockingFailed = items.filter((item) => item.severity === 'blocking' && !item.ok);
    const warnings = items.filter((item) => item.severity === 'warning' && !item.ok);
    const nextReport = {
        ...report,
        items,
        blockingFailed,
        warnings,
        status: blockingFailed.length > 0 ? 'blocked' : (warnings.length > 0 ? 'warning' : 'ready'),
    };

    if (['citizen', 'caller'].includes(surface)) {
        appState.runtime.callerPrimerReport = nextReport;
        refreshCallerPrimerStatusButton(nextReport);
    }

    return nextReport;
}

function refreshCallerPrimerStatusButton(report) {
    const root = appState.runtime.callerRoot;
    const currentButton = root?.querySelector?.('[data-open-primer]');

    if (!currentButton || !report) {
        return;
    }

    const template = document.createElement('template');
    template.innerHTML = primerStatusButton(report).trim();

    const nextButton = template.content.firstElementChild;

    if (!nextButton) {
        return;
    }

    nextButton.addEventListener('click', () => {
        void openDevicePrimerModal(report.surface, report, true);
    });
    currentButton.replaceWith(nextButton);
}

function card(title, body, className = '') {
    return `
        <section class="panel-card ${className}">
            <h3>${title}</h3>
            ${body}
        </section>
    `;
}

function sharedShell({
    title,
    kicker,
    statusLabel,
    content,
    brandHref,
    statusActions = '',
    showHero = true,
    shellClass = '',
    mainClass = '',
    toolbarClass = '',
}) {
    const toolbarContent = `${statusLabel ? `<span class="ui-badge surface-status-badge">${escapeHtml(statusLabel)}</span>` : ''}${statusActions}`;

    return `
        <div class="surface-shell ${shellClass}">
            <header class="surface-chrome">
                <div class="helper-nav-host" data-helper-navbar data-brand-href="${brandHref}"></div>
                ${toolbarContent ? `<div class="surface-toolbar ui-inline ${toolbarClass}">${toolbarContent}</div>` : ''}
            </header>
            <main class="surface-main ${mainClass}">
                ${showHero ? `
                <section class="hero-card ui-panel ${kicker.toLowerCase()}-surface">
                    <p class="ui-eyebrow hero-kicker">${kicker}</p>
                    <h1 class="ui-title hero-title">${title}</h1>
                    ${content}
                </section>
                ` : content}
            </main>
        </div>
    `;
}

function evaluateDevicePrimer(surface) {
    const hasMediaDevices = typeof navigator !== 'undefined' && !!navigator.mediaDevices?.enumerateDevices;
    const hasGetUserMedia = typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia;
    const hasAudioPlayback = typeof window !== 'undefined' && typeof window.Audio !== 'undefined';
    const hasSpeechSynthesis = typeof window !== 'undefined' && 'speechSynthesis' in window;
    const callerSpeechReady = hasSpeechSynthesis && appState.runtime.callerSpeechPrimed;

    const checks = {
        caller: [
            { key: 'microphone', label: 'Microphone', severity: 'blocking', ok: hasGetUserMedia },
            { key: 'audioPlayback', label: 'Audio Playback', severity: 'blocking', ok: hasAudioPlayback },
            { key: 'speechSynthesis', label: 'Speech Synthesis', severity: 'warning', ok: callerSpeechReady },
            { key: 'geolocation', label: 'Geolocation', severity: 'warning', ok: typeof navigator !== 'undefined' && 'geolocation' in navigator },
            { key: 'camera', label: 'Camera', severity: 'warning', ok: hasGetUserMedia },
            { key: 'mediaDevices', label: 'Media Devices', severity: 'warning', ok: hasMediaDevices },
        ],
        operator: [
            { key: 'microphone', label: 'Microphone', severity: 'blocking', ok: hasGetUserMedia },
            { key: 'camera', label: 'Camera Device', severity: 'blocking', ok: hasGetUserMedia },
            { key: 'audioPlayback', label: 'Audio Playback', severity: 'blocking', ok: hasAudioPlayback },
            { key: 'mediaDevices', label: 'Media Devices', severity: 'blocking', ok: hasMediaDevices },
            { key: 'speechSynthesis', label: 'Speech Synthesis', severity: 'warning', ok: hasSpeechSynthesis },
        ],
    };

    const items = checks[surface] ?? [];
    const blockingFailed = items.filter((item) => item.severity === 'blocking' && !item.ok);
    const warnings = items.filter((item) => item.severity === 'warning' && !item.ok);

    return {
        surface,
        items,
        blockingFailed,
        warnings,
        status: blockingFailed.length > 0 ? 'blocked' : (warnings.length > 0 ? 'warning' : 'ready'),
    };
}

function primerStatusButton(report) {
    if (!report) {
        return '';
    }

    const label = report.status === 'blocked'
        ? 'Device Primer: blocked'
        : report.status === 'warning'
            ? 'Device Primer: warning'
            : 'Device Primer: ready';

    const icon = createIconMarkup('media.microphone', {
        size: 16,
        ariaLabel: 'Device Primer',
    });

    return `<button class="ui-button ui-button-ghost helper-primer-button is-${report.status}" type="button" data-open-primer="1">${icon}<span>${label}</span></button>`;
}

function wirePrimer(root, report) {
    if (!report) {
        return report;
    }

    const openButton = root.querySelector('[data-open-primer]');
    const dismissKey = `${DEVICE_PRIMER_DISMISS_PREFIX}${report.surface}`;

    openButton?.addEventListener('click', () => {
        void openDevicePrimerModal(report.surface, report, true);
    });

    const callerNeedsSpeechPrime = ['citizen', 'caller'].includes(report.surface)
        && report.warnings.some((item) => item.key === 'speechSynthesis');

    if (report.surface === 'operator' && !appState.runtime.operatorPrimerAutoOpened) {
        appState.runtime.operatorPrimerAutoOpened = true;
        void openDevicePrimerModal(report.surface, report, true);
    } else if (callerNeedsSpeechPrime && !appState.runtime.callerPrimerAutoOpened) {
        appState.runtime.callerPrimerAutoOpened = true;
        void openDevicePrimerModal(report.surface, report, true);
    } else if (report.status !== 'ready' && !sessionStorage.getItem(dismissKey)) {
        void openDevicePrimerModal(report.surface, report);
    }

    return report;
}

function renderInfoList(items, emptyText) {
    if (!Array.isArray(items) || items.length === 0) {
        return `<p class="surface-empty">${escapeHtml(emptyText)}</p>`;
    }

    return `<ul class="surface-list">${items.join('')}</ul>`;
}

function renderMessages(messages) {
    if (!Array.isArray(messages) || messages.length === 0) {
        return '<p class="surface-empty">No incident messages have been recorded yet.</p>';
    }

    return `
        <div class="stack-list">
            ${messages.map((message) => `
                <article class="timeline-card">
                    <div class="timeline-head">
                        <strong>${escapeHtml(message.sender_name ?? message.sender_role ?? 'Unknown sender')}</strong>
                        <span class="timeline-meta">${formatStatusLabel(message.sender_role ?? 'message')} · ${formatDateTime(message.created_at)}</span>
                    </div>
                    <p class="timeline-body">${escapeHtml(message.body ?? '(No message body)')}</p>
                    ${
                        Array.isArray(message.attachments) && message.attachments.length > 0
                            ? `<ul class="surface-list compact">${message.attachments.map((attachment) => `
                                <li>${escapeHtml(attachment.original_filename)} · ${escapeHtml(attachment.type)}</li>
                            `).join('')}</ul>`
                            : ''
                    }
                </article>
            `).join('')}
        </div>
    `;
}

function renderMedia(media, emptyText = 'No media is available for this incident yet.', includeProcessingHint = false) {
    if (!Array.isArray(media) || media.length === 0) {
        return `
            <p class="surface-empty">${escapeHtml(emptyText)}</p>
            ${includeProcessingHint ? '<p class="hero-copy">If a call has already ended, post-call media may still be processing.</p>' : ''}
        `;
    }

    return `
        <div class="stack-list">
            ${media.map((item) => `
                <article class="timeline-card">
                    <div class="timeline-head">
                        <strong>${escapeHtml(formatStatusLabel(item.type))}</strong>
                        <span class="timeline-meta">${escapeHtml(item.peer_label ?? item.peer_role ?? 'Unknown peer')} · ${formatDateTime(item.available_at ?? item.created_at)}</span>
                    </div>
                    ${
                        item.processing
                            ? '<p class="timeline-body">processing media...</p>'
                            : `<p class="timeline-body">Path: ${escapeHtml(item.path)}</p>`
                    }
                    <p class="hero-copy">Duration: ${escapeHtml(item.duration_seconds ?? 'n/a')} seconds</p>
                </article>
            `).join('')}
        </div>
    `;
}

function mergeIncidentMediaItems(media, nextItem) {
    const normalizedList = Array.isArray(media) ? [...media] : [];

    if (!nextItem || typeof nextItem !== 'object') {
        return normalizedList;
    }

    const nextId = String(nextItem.id ?? '').trim();

    if (!nextId) {
        return normalizedList;
    }

    const existingIndex = normalizedList.findIndex((item) => String(item?.id ?? '').trim() === nextId);

    if (existingIndex >= 0) {
        normalizedList.splice(existingIndex, 1, {
            ...(normalizedList[existingIndex] ?? {}),
            ...nextItem,
        });
    } else {
        normalizedList.push(nextItem);
    }

    normalizedList.sort((left, right) => {
        const leftAt = Date.parse(String(left?.created_at ?? '')) || 0;
        const rightAt = Date.parse(String(right?.created_at ?? '')) || 0;

        if (leftAt !== rightAt) {
            return leftAt - rightAt;
        }

        return Number(left?.id ?? 0) - Number(right?.id ?? 0);
    });

    return normalizedList;
}

function renderTransfers(items) {
    if (!Array.isArray(items) || items.length === 0) {
        return '<p class="surface-empty">No transfer history is recorded yet.</p>';
    }

    return `
        <div class="stack-list">
            ${items.map((transfer) => `
                <article class="timeline-card">
                    <div class="timeline-head">
                        <strong>${escapeHtml(transfer.from_operator?.name ?? 'Unknown')} to ${escapeHtml(transfer.to_operator?.name ?? 'Unknown')}</strong>
                        <span class="timeline-meta">${escapeHtml(formatStatusLabel(transfer.status))} · ${formatDateTime(transfer.requested_at)}</span>
                    </div>
                    <p class="timeline-body">${escapeHtml(transfer.reason ?? 'No transfer reason recorded.')}</p>
                </article>
            `).join('')}
        </div>
    `;
}

function renderAssignments(items) {
    if (!Array.isArray(items) || items.length === 0) {
        return '<p class="surface-empty">No teams are assigned yet.</p>';
    }

    return `
        <div class="stack-list">
            ${items.map((assignment) => `
                <article class="timeline-card">
                    <div class="timeline-head">
                        <strong>${escapeHtml(assignment.team?.name ?? 'Unknown team')}</strong>
                        <span class="timeline-meta">${escapeHtml(assignment.status)} · ${formatDateTime(assignment.assigned_at)}</span>
                    </div>
                    <p class="timeline-body">Contact: ${escapeHtml(assignment.contact_person ?? 'Not provided')}</p>
                    ${
                        Array.isArray(assignment.allocated_resources) && assignment.allocated_resources.length > 0
                            ? `<ul class="surface-list compact">${assignment.allocated_resources.map((resource) => `
                                <li>${escapeHtml(resource.resource_type?.name ?? 'Resource')} × ${escapeHtml(resource.quantity_allocated)}</li>
                            `).join('')}</ul>`
                            : '<p class="hero-copy">No specific resources allocated.</p>'
                    }
                    <div class="button-row compact">
                        <button class="surface-button secondary tiny" type="button" data-assignment-status="${assignment.id}:Accepted">Accept</button>
                        <button class="surface-button secondary tiny" type="button" data-assignment-status="${assignment.id}:En-route">En-route</button>
                        <button class="surface-button secondary tiny" type="button" data-assignment-status="${assignment.id}:On-Scene">On-scene</button>
                        <button class="surface-button secondary tiny" type="button" data-assignment-status="${assignment.id}:Completed">Complete</button>
                        <button class="surface-button secondary tiny" type="button" data-assignment-status="${assignment.id}:Cancelled">Cancel</button>
                        <button class="surface-button secondary tiny" type="button" data-delete-assignment="${assignment.id}">Delete</button>
                    </div>
                </article>
            `).join('')}
        </div>
    `;
}

function renderStatChips(items) {
    if (!Array.isArray(items) || items.length === 0) {
        return '';
    }

    return `
        <div class="chip-row">
            ${items.map((item) => `<span class="pill blue">${escapeHtml(item.label)}: ${escapeHtml(item.value)}</span>`).join('')}
        </div>
    `;
}

function padIncidentId(value) {
    const digits = String(value ?? '').trim();

    if (!digits) {
        return '000000';
    }

    return digits.padStart(6, '0');
}

function formatIncidentStatusHeading(status) {
    if (['Active', 'Deferred'].includes(status)) {
        return 'Active Incident';
    }

    if (status === 'Resolved') {
        return 'Resolved Incident';
    }

    if (status === 'Discarded') {
        return 'Discarded Incident';
    }

    return 'Incident';
}

function latestCallSession(payload) {
    if (!Array.isArray(payload?.call_history) || payload.call_history.length === 0) {
        return null;
    }

    return [...payload.call_history]
        .sort((left, right) => new Date(left.created_at ?? 0).getTime() - new Date(right.created_at ?? 0).getTime())
        .at(-1) ?? null;
}

function mapAttachmentKind(type, mimeType = '') {
    const normalizedType = String(type ?? '').toLowerCase();
    const normalizedMime = String(mimeType ?? '').toLowerCase();

    if (normalizedType.includes('image') || normalizedMime.startsWith('image/')) {
        return 'image';
    }

    if (normalizedType.includes('video') || normalizedMime.startsWith('video/')) {
        return 'video';
    }

    if (normalizedType.includes('audio') || normalizedMime.startsWith('audio/')) {
        return 'audio';
    }

    return 'file';
}

function publicViewerRoleAliases(viewerRole) {
    return ['citizen', 'caller'].includes(viewerRole)
        ? ['citizen', 'caller']
        : [viewerRole];
}

function isPublicViewerRole(viewerRole) {
    return ['citizen', 'caller'].includes(viewerRole);
}

function normalizeChatMessages(messages, viewerRole) {
    const viewerRoleAliases = publicViewerRoleAliases(viewerRole);

    return (Array.isArray(messages) ? messages : []).map((message) => {
        const alreadyNormalized = Object.prototype.hasOwnProperty.call(message ?? {}, 'direction')
            || Object.prototype.hasOwnProperty.call(message ?? {}, 'senderName')
            || Object.prototype.hasOwnProperty.call(message ?? {}, 'text')
            || Object.prototype.hasOwnProperty.call(message ?? {}, 'timestamp');

        if (alreadyNormalized) {
            return {
                id: String(message.id),
                direction: String(message.direction ?? 'incoming'),
                senderName: message.senderName ?? formatStatusLabel(message.senderRole ?? message.sender_role ?? 'Unknown'),
                senderSubtitle: message.senderSubtitle ?? formatStatusLabel(message.senderRole ?? message.sender_role ?? 'message'),
                text: message.text ?? message.body ?? '',
                timestamp: message.timestamp ?? formatDateTime(message.created_at),
                state: message.state,
                attachments: normalizeChatMessageAttachments(message.attachments),
            };
        }

        return {
            id: String(message.id),
            direction: viewerRoleAliases.includes(message.sender_role)
                ? 'outgoing'
                : (message.type === 'system' ? 'system' : 'incoming'),
            senderName: message.sender_name ?? formatStatusLabel(message.sender_role ?? 'Unknown'),
            senderSubtitle: formatStatusLabel(message.sender_role ?? 'message'),
            text: message.body ?? '',
            timestamp: formatDateTime(message.created_at),
            state: viewerRoleAliases.includes(message.sender_role) ? 'sent' : undefined,
            attachments: normalizeChatMessageAttachments(message.attachments),
        };
    });
}

function normalizeChatMessageAttachments(attachments) {
    return (Array.isArray(attachments) ? attachments : []).map((attachment) => {
        const storedPath = attachment.stored_path ?? attachment.url ?? '';
        const thumbnailPath = attachment.thumbnail_path
            ?? attachment.preview_url
            ?? attachment.previewUrl
            ?? storedPath;

        return {
            id: String(attachment.id),
            kind: attachment.kind ?? mapAttachmentKind(attachment.type, attachment.mime_type ?? attachment.mimeType),
            name: attachment.name ?? attachment.original_filename,
            url: normalizeAttachmentStoredUrl(storedPath),
            previewUrl: normalizeAttachmentStoredUrl(thumbnailPath),
            posterUrl: normalizeAttachmentStoredUrl(attachment.poster_url ?? attachment.posterUrl ?? thumbnailPath),
            mimeType: attachment.mimeType ?? attachment.mime_type,
        };
    });
}

function normalizeAttachmentStoredUrl(value) {
    const raw = String(value ?? '').trim();

    if (!raw) {
        return '';
    }

    if (/^https?:\/\//i.test(raw) || raw.startsWith('data:') || raw.startsWith('/')) {
        return raw;
    }

    return `/storage/${raw.replace(/^\/+/, '')}`;
}

function formatAttachmentPolicyHelperText(policy = {}) {
    const parts = [];
    const count = Number(policy.maxAttachmentCount ?? 0) || 0;
    const perFile = Number(policy.maxAttachmentBytes ?? 0) || 0;
    const total = Number(policy.maxTotalBytesPerMessage ?? 0) || 0;

    if (count > 0) {
        parts.push(`Up to ${count} attachment${count === 1 ? '' : 's'}`);
    }

    if (perFile > 0) {
        parts.push(`${formatAttachmentFileSize(perFile)} each`);
    }

    if (total > 0) {
        parts.push(`${formatAttachmentFileSize(total)} total`);
    }

    if (parts.length === 0) {
        return '';
    }

    return parts.join(' · ');
}

function dataUrlToFile(dataUrl, filename, mimeType = '') {
    const match = String(dataUrl ?? '').match(/^data:([^;,]+)?;base64,(.+)$/);

    if (!match) {
        return null;
    }

    const resolvedMimeType = String(mimeType || match[1] || 'application/octet-stream');
    const base64 = String(match[2] || '');
    const binary = window.atob(base64);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return new File([bytes], String(filename || 'attachment'), {
        type: resolvedMimeType,
    });
}

function mountChatThread(host, messages, viewerRole, options = {}) {
    if (!host || !appState.helper.createChatThread) {
        return null;
    }

    return appState.helper.createChatThread(host, {
        messages: normalizeChatMessages(messages, viewerRole),
    }, {
        emptyTitle: 'No messages yet',
        emptyText: 'Conversation history will appear here once a live call message is recorded.',
        showStates: viewerRole !== 'operator' ? false : true,
        showMessageMenuTrigger: false,
        ...options,
    });
}

function mountChatComposer(host, options = {}) {
    if (!host || !appState.helper.createChatComposer) {
        return null;
    }

    return appState.helper.createChatComposer(host, { value: '' }, {
        helperText: '',
        ...options,
    });
}

function extractRealtimeEnvelopeUserId(envelope) {
    return String(
        envelope?.payload?.sender?.user_id
        ?? envelope?.payload?.sender_user_id
        ?? envelope?.meta?.user_id
        ?? envelope?.meta?.sender_user_id
        ?? envelope?.meta?.actor_user_id
        ?? envelope?.meta?.user?.id
        ?? '',
    ).trim();
}

function buildDefaultRtcConfiguration() {
    return {
        iceServers: [],
    };
}

function attachHiddenRemoteAudio(host, className = '') {
    if (!(host instanceof Element)) {
        return null;
    }

    const audio = document.createElement('audio');
    audio.autoplay = true;
    audio.playsInline = true;
    audio.className = ['realtime-remote-audio', className].filter(Boolean).join(' ');
    audio.hidden = true;
    host.appendChild(audio);
    return audio;
}

function resetRemoteVideoHost(host) {
    if (!(host instanceof Element)) {
        return;
    }

    host.classList.add('is-idle');
    host.innerHTML = '<span>Video placeholder</span>';
}

function attachRemoteVideo(host, stream, className = '', onDebug = null) {
    if (!(host instanceof Element)) {
        return null;
    }

    host.classList.remove('is-idle');
    host.replaceChildren();

    const video = document.createElement('video');
    video.autoplay = true;
    video.playsInline = true;
    video.muted = true;
    video.className = ['realtime-remote-video', className].filter(Boolean).join(' ');
    bindMediaElementStream(video, stream, { muted: true });
    host.appendChild(video);

    const emitPreviewMetrics = (event) => {
        if (typeof onDebug !== 'function') {
            return;
        }

        const hostRect = typeof host.getBoundingClientRect === 'function'
            ? host.getBoundingClientRect()
            : null;
        const computedStyle = typeof window !== 'undefined' && typeof window.getComputedStyle === 'function'
            ? window.getComputedStyle(host)
            : null;

        onDebug(event, {
            hostClassName: host.className,
            hostChildCount: host.childElementCount,
            hostRect: hostRect
                ? {
                    width: hostRect.width,
                    height: hostRect.height,
                    top: hostRect.top,
                    left: hostRect.left,
                }
                : null,
            hostDisplay: computedStyle?.display ?? '',
            hostVisibility: computedStyle?.visibility ?? '',
            hostOpacity: computedStyle?.opacity ?? '',
            hostHidden: host.hidden,
            videoClassName: video.className,
            videoReadyState: video.readyState,
            videoPaused: video.paused,
            videoEnded: video.ended,
            videoMuted: video.muted,
            videoWidth: video.videoWidth,
            videoHeight: video.videoHeight,
            streamTrackIds: stream instanceof MediaStream
                ? stream.getVideoTracks().map((track) => track.id)
                : [],
        });
    };

    emitPreviewMetrics('remote-video-element-attached');
    video.addEventListener('loadedmetadata', () => emitPreviewMetrics('remote-video-loadedmetadata'));
    video.addEventListener('playing', () => emitPreviewMetrics('remote-video-playing'));
    video.addEventListener('resize', () => emitPreviewMetrics('remote-video-resize'));

    if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(() => {
            emitPreviewMetrics('remote-video-animation-frame');
        });
    }

    return video;
}

async function mountRealtimeCallSession(options = {}) {
    const callSessionId = Number(options.callSessionId ?? 0);
    const viewerRole = String(options.viewerRole ?? '').trim();
    const admissionPath = String(options.admissionPath ?? '').trim();
    const currentUserId = String(options.currentUserId ?? '').trim();
    const remoteUserId = String(options.remoteUserId ?? '').trim();
    const remoteAudioHost = options.remoteAudioHost ?? null;
    const incomingStreamHost = options.incomingStreamHost ?? remoteAudioHost;
    const remoteVideoHost = options.remoteVideoHost ?? null;

    if (
        !callSessionId
        || !viewerRole
        || !admissionPath
        || !currentUserId
        || !remoteUserId
        || !navigator.mediaDevices?.getUserMedia
        || typeof RTCPeerConnection !== 'function'
    ) {
        logCallFlow(viewerRole || 'unknown', 'call-runtime-skip-missing-prerequisite', {
            callSessionId,
            hasAdmissionPath: Boolean(admissionPath),
            hasCurrentUserId: Boolean(currentUserId),
            hasRemoteUserId: Boolean(remoteUserId),
            hasMediaDevices: Boolean(navigator.mediaDevices?.getUserMedia),
            hasRtcPeerConnection: typeof RTCPeerConnection === 'function',
        });
        return null;
    }

    const registryKey = [
        viewerRole,
        callSessionId,
        currentUserId,
        remoteUserId,
    ].join(':');
    const existingRegistryEntry = realtimeCallSessionRegistry.get(registryKey);

    if (existingRegistryEntry?.promise) {
        logCallFlow(viewerRole, 'call-session-runtime-reuse', {
            callSessionId,
            currentUserId,
            remoteUserId,
            hasRuntime: Boolean(existingRegistryEntry.runtime),
        });
        return existingRegistryEntry.promise;
    }

    let resolveRegistryRuntime = null;
    const registryPromise = new Promise((resolve) => {
        resolveRegistryRuntime = resolve;
    });

    realtimeCallSessionRegistry.set(registryKey, {
        promise: registryPromise,
        runtime: null,
    });

    const finishRegistryRuntime = (runtime) => {
        const currentEntry = realtimeCallSessionRegistry.get(registryKey);

        if (currentEntry) {
            currentEntry.runtime = runtime;
            currentEntry.promise = Promise.resolve(runtime);
        }

        resolveRegistryRuntime?.(runtime);
        return runtime;
    };

    const clearRegistryRuntime = () => {
        const currentEntry = realtimeCallSessionRegistry.get(registryKey);

        if (currentEntry) {
            realtimeCallSessionRegistry.delete(registryKey);
        }

        resolveRegistryRuntime?.(null);
        return null;
    };

    const conferenceState = createRealtimeConferenceState();
    const state = {
        active: true,
        joinedRoom: false,
        sentReady: false,
        startedOffer: false,
        localStream: null,
        localStreamPromise: null,
        localVideoStream: options.localVideoStream instanceof MediaStream ? options.localVideoStream : null,
        remoteAudioEl: null,
        remoteVideoEl: null,
        client: null,
        callRoom: '',
        mediaMuted: Boolean(options.startMuted),
        heartbeatTimerId: null,
        sessionKeepaliveTimerId: null,
    };

    const debugMedia = (event, detail = {}) => {
        if (!isDebugFlagEnabled('hotlineCallDebug', 'HOTLINE_CALL_DEBUG') || typeof console === 'undefined') {
            return;
        }

        console.info('[hotline.call.debug]', {
            timestamp: new Date().toISOString(),
            callSessionId,
            viewerRole,
            event,
            ...detail,
        });
    };

    const attachPendingIceCandidates = async (peerConnection, senderUserId) => {
        const pending = conferenceState.pendingIceCandidatesByUser?.[senderUserId] ?? [];

        if (!pending.length) {
            return;
        }

        delete conferenceState.pendingIceCandidatesByUser[senderUserId];

        for (const candidate of pending) {
            try {
                await peerConnection.addIceCandidate(candidate);
            } catch (error) {
                console.warn('Realtime call session ICE candidate attach failed.', error);
            }
        }
    };

    const ensureLocalStream = async () => {
        if (state.localStream instanceof MediaStream) {
            return state.localStream;
        }

        if (state.localStreamPromise) {
            return state.localStreamPromise;
        }

        state.localStreamPromise = navigator.mediaDevices.getUserMedia({
            audio: true,
            video: false,
        }).then((stream) => {
            state.localStream = stream;
            return stream;
        }).finally(() => {
            state.localStreamPromise = null;
        });

        state.localStream = await state.localStreamPromise;
        logCallFlow(viewerRole, 'call-session-local-media-success', {
            callSessionId,
            audioTrackCount: state.localStream.getAudioTracks().length,
        });

        debugMedia('local-stream-acquired', {
            audioTracks: state.localStream.getAudioTracks().map((track) => ({
                id: track.id,
                enabled: Boolean(track.enabled),
                readyState: track.readyState,
                muted: Boolean(track.muted),
            })),
        });

        if (typeof options.onLocalStream === 'function') {
            options.onLocalStream(state.localStream);
        }

        state.localStream.getAudioTracks().forEach((track) => {
            track.enabled = !state.mediaMuted;
        });

        return state.localStream;
    };

    const syncMediaMuteState = () => {
        const localTrackStates = state.localStream instanceof MediaStream
            ? state.localStream.getAudioTracks().map((track) => ({
                id: track.id,
                enabled: Boolean(track.enabled),
                muted: Boolean(track.muted),
                readyState: track.readyState,
            }))
            : [];

        if (state.localStream instanceof MediaStream) {
            state.localStream.getAudioTracks().forEach((track) => {
                track.enabled = !state.mediaMuted;
            });
        }

        if (state.remoteAudioEl instanceof HTMLMediaElement) {
            state.remoteAudioEl.muted = state.mediaMuted;
        }

        debugMedia('local-media-mute-sync', {
            mediaMuted: state.mediaMuted,
            audioTracksBefore: localTrackStates,
            audioTracksAfter: state.localStream instanceof MediaStream
                ? state.localStream.getAudioTracks().map((track) => ({
                    id: track.id,
                    enabled: Boolean(track.enabled),
                    muted: Boolean(track.muted),
                    readyState: track.readyState,
                }))
                : [],
        });
    };

    const syncRemoteVideoStream = (stream) => {
        if (!(remoteVideoHost instanceof Element)) {
            if (typeof options.onRemoteVideoStateChange === 'function') {
                const nextStream = stream instanceof MediaStream ? stream : null;
                const hasVideo = nextStream instanceof MediaStream
                    && nextStream.getVideoTracks().some((track) => track.readyState === 'live' && !track.muted);
                debugMedia('remote-video-sync', {
                    hasVideo,
                    hasHost: false,
                    videoTracks: nextStream instanceof MediaStream
                        ? nextStream.getVideoTracks().map((track) => ({
                            id: track.id,
                            enabled: Boolean(track.enabled),
                            muted: Boolean(track.muted),
                            readyState: track.readyState,
                        }))
                        : [],
                    audioTracks: nextStream instanceof MediaStream
                        ? nextStream.getAudioTracks().map((track) => ({
                            id: track.id,
                            enabled: Boolean(track.enabled),
                            muted: Boolean(track.muted),
                            readyState: track.readyState,
                        }))
                        : [],
                });
                options.onRemoteVideoStateChange(hasVideo, nextStream);
            }
            return;
        }

        const hasVideo = stream instanceof MediaStream
            && stream.getVideoTracks().some((track) => track.readyState === 'live' && !track.muted);

        debugMedia('remote-video-sync', {
            hasVideo,
            hasHost: true,
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

        if (typeof options.onRemoteVideoStateChange === 'function') {
            options.onRemoteVideoStateChange(hasVideo, hasVideo ? stream : null);
        }

        if (!hasVideo) {
            if (state.remoteVideoEl instanceof HTMLMediaElement) {
                state.remoteVideoEl.srcObject = null;
                state.remoteVideoEl.remove();
                state.remoteVideoEl = null;
            }

            resetRemoteVideoHost(remoteVideoHost);
            return;
        }

        state.remoteVideoEl = attachRemoteVideo(
            remoteVideoHost,
            stream,
            `${viewerRole}-call-remote-video`,
            (event, detail) => {
                debugMedia(event, detail);
            },
        );
    };

    const ensurePeerConnection = async (targetUserId) => {
        const remoteId = String(targetUserId ?? '').trim();

        if (!remoteId) {
            return null;
        }

        return ensureConferencePeerConnection(conferenceState.peerConnections, remoteId, () => {
            const peerConnection = new RTCPeerConnection(buildDefaultRtcConfiguration());

            peerConnection.onicecandidate = (event) => {
                if (!event.candidate || !state.client?.isOpen?.() || !state.joinedRoom || !state.callRoom) {
                    return;
                }

                state.client.sendRequest(
                    'call.signal.publish',
                    state.callRoom,
                    buildCallSignalPayload('ice-candidate', {
                        targetUserId: remoteId,
                        candidate: event.candidate.toJSON ? event.candidate.toJSON() : event.candidate,
                    }),
                );
            };

            peerConnection.ontrack = (event) => {
                const remoteStream = ensureConferenceRemoteStream(conferenceState.remoteStreams, remoteId, () => new MediaStream());
                const stream = remoteStream ?? new MediaStream();

                (event.streams?.[0]?.getTracks?.() ?? [event.track]).forEach((track) => {
                    const alreadyPresent = stream.getTracks().some((item) => item.id === track.id);

                    if (!alreadyPresent) {
                        stream.addTrack(track);
                    }

                    debugMedia('remote-track-added', {
                        remoteId,
                        kind: track.kind,
                        trackId: track.id,
                        readyState: track.readyState,
                        muted: Boolean(track.muted),
                    });
                });

                if (!state.remoteAudioEl) {
                    state.remoteAudioEl = attachHiddenRemoteAudio(incomingStreamHost, `${viewerRole}-call-remote-audio`);
                }

                bindMediaElementStream(state.remoteAudioEl, stream, { muted: state.mediaMuted });
                syncMediaMuteState();
                syncRemoteVideoStream(stream);

                (event.streams?.[0]?.getTracks?.() ?? [event.track]).forEach((track) => {
                    track.addEventListener('mute', () => {
                        debugMedia('remote-track-mute', {
                            remoteId,
                            kind: track.kind,
                            trackId: track.id,
                        });
                        window.setTimeout(() => {
                            syncRemoteVideoStream(stream);
                            if (typeof options.onRemoteStream === 'function') {
                                options.onRemoteStream(stream);
                            }
                        }, 0);
                    });
                    track.addEventListener('unmute', () => {
                        debugMedia('remote-track-unmute', {
                            remoteId,
                            kind: track.kind,
                            trackId: track.id,
                        });
                        window.setTimeout(() => {
                            syncRemoteVideoStream(stream);
                            if (typeof options.onRemoteStream === 'function') {
                                options.onRemoteStream(stream);
                            }
                        }, 0);
                    });
                    track.addEventListener('ended', () => {
                        debugMedia('remote-track-ended', {
                            remoteId,
                            kind: track.kind,
                            trackId: track.id,
                        });
                        window.setTimeout(() => {
                            syncRemoteVideoStream(stream);
                            if (typeof options.onRemoteStream === 'function') {
                                options.onRemoteStream(stream);
                            }
                        }, 0);
                    }, { once: true });
                });

                if (typeof options.onRemoteStream === 'function') {
                    options.onRemoteStream(stream);
                }
            };

            peerConnection.onconnectionstatechange = () => {
                const nextState = String(peerConnection.connectionState ?? '').trim();

                if (typeof options.onStateChange === 'function') {
                    options.onStateChange(nextState);
                }
            };

            return peerConnection;
        });
    };

    const applyLocalVideoTrack = async (peerConnection) => {
        if (!peerConnection) {
            return;
        }

        const nextTrack = state.localVideoStream instanceof MediaStream
            ? (state.localVideoStream.getVideoTracks().at(0) ?? null)
            : null;
        const videoTransceiver = peerConnection.getTransceivers().find((transceiver) => {
            const senderTrackKind = transceiver?.sender?.track?.kind ?? '';
            const receiverTrackKind = transceiver?.receiver?.track?.kind ?? '';

            return senderTrackKind === 'video' || receiverTrackKind === 'video';
        }) ?? null;
        const existingSender = videoTransceiver?.sender
            ?? peerConnection.getSenders().find((sender) => sender.track?.kind === 'video')
            ?? null;
        const setVideoDirection = () => {
            if (!videoTransceiver) {
                return;
            }

            const currentDirection = String(videoTransceiver.direction ?? '').trim().toLowerCase();
            const nextDirection = nextTrack ? 'sendrecv' : 'recvonly';

            if (currentDirection && currentDirection !== nextDirection) {
                videoTransceiver.direction = nextDirection;
            }
        };

        debugMedia('local-video-apply', {
            targetUserId: remoteUserId,
            nextTrackId: nextTrack?.id ?? '',
            nextTrackReadyState: nextTrack?.readyState ?? '',
            nextTrackMuted: Boolean(nextTrack?.muted),
            existingSenderTrackId: existingSender?.track?.id ?? '',
            existingSenderTrackReadyState: existingSender?.track?.readyState ?? '',
            videoTransceiverMid: videoTransceiver?.mid ?? '',
            videoTransceiverDirection: videoTransceiver?.direction ?? '',
            videoReceiverTrackId: videoTransceiver?.receiver?.track?.id ?? '',
        });

        if (existingSender && nextTrack) {
            setVideoDirection();
            if (existingSender.track?.id !== nextTrack.id) {
                await existingSender.replaceTrack(nextTrack);
                debugMedia('local-video-apply-replaced', {
                    targetUserId: remoteUserId,
                    nextTrackId: nextTrack.id,
                });
            }
            return;
        }

        if (existingSender && !nextTrack) {
            setVideoDirection();
            await existingSender.replaceTrack(null);
            debugMedia('local-video-apply-cleared', {
                targetUserId: remoteUserId,
                previousTrackId: existingSender.track?.id ?? '',
            });
            return;
        }

        if (existingSender && !existingSender.track && nextTrack) {
            setVideoDirection();
            await existingSender.replaceTrack(nextTrack);
            debugMedia('local-video-apply-dormant-replaced', {
                targetUserId: remoteUserId,
                nextTrackId: nextTrack.id,
            });
            return;
        }

        if (!existingSender && nextTrack) {
            peerConnection.addTrack(nextTrack, state.localVideoStream);
            debugMedia('local-video-apply-added', {
                targetUserId: remoteUserId,
                nextTrackId: nextTrack.id,
            });
        }
    };

    const ensurePeerConnectionWithLocalAudio = async (targetUserId) => {
        const peerConnection = await ensurePeerConnection(targetUserId);

        if (!peerConnection) {
            return null;
        }

        try {
            const stream = await ensureLocalStream();

            stream.getAudioTracks().forEach((track) => {
                const alreadyAdded = peerConnection.getSenders().some((sender) => sender.track?.id === track.id);

                if (!alreadyAdded) {
                    peerConnection.addTrack(track, stream);
                }
            });
        } catch (error) {
            console.warn('Realtime call session local media acquisition failed.', error);
            showToast('Unable to access the microphone for the live call.', 'error');
        }

        return peerConnection;
    };

    const sendReadySignal = () => {
        if (!state.client?.isOpen?.() || !state.joinedRoom || state.sentReady || !state.callRoom) {
            logCallFlow(viewerRole, 'call-session-ready-signal-skip', {
                callSessionId,
                callRoom: state.callRoom,
                clientOpen: Boolean(state.client?.isOpen?.()),
                joinedRoom: Boolean(state.joinedRoom),
                sentReady: Boolean(state.sentReady),
            });
            return;
        }

        state.sentReady = true;
        logCallFlow(viewerRole, 'call-session-ready-signal-send', {
            callSessionId,
            callRoom: state.callRoom,
            targetUserId: remoteUserId,
        });
        state.client.sendRequest(
            'call.signal.publish',
            state.callRoom,
            buildCallSignalPayload('ready', {
                targetUserId: remoteUserId,
                meta: {
                    role: viewerRole,
                },
            }),
        );
    };

    const sendCallSignal = (signalType, {
        meta = {},
        ...extraPayload
    } = {}) => {
        if (!state.client?.isOpen?.() || !state.joinedRoom || !state.callRoom) {
            return;
        }

        state.client.sendRequest(
            'call.signal.publish',
            state.callRoom,
            buildCallSignalPayload(signalType, {
                targetUserId: remoteUserId,
                meta,
                ...extraPayload,
            }),
        );
    };

    const sendHangupSignal = (meta = {}) => {
        sendCallSignal('hangup', { meta });
    };

    const stopCallHeartbeat = () => {
        if (!state.heartbeatTimerId) {
            return;
        }

        window.clearInterval(state.heartbeatTimerId);
        state.heartbeatTimerId = null;
    };

    const sendHeartbeatSignal = () => {
        sendCallSignal('heartbeat', {
            meta: {
                role: viewerRole,
                sent_at: new Date().toISOString(),
            },
        });
    };

    const startCallHeartbeat = () => {
        if (state.heartbeatTimerId) {
            return;
        }

        sendHeartbeatSignal();
        state.heartbeatTimerId = window.setInterval(sendHeartbeatSignal, CALL_SESSION_HEARTBEAT_MS);
    };

    const stopCallSessionKeepalive = () => {
        if (!state.sessionKeepaliveTimerId) {
            return;
        }

        window.clearInterval(state.sessionKeepaliveTimerId);
        state.sessionKeepaliveTimerId = null;
    };

    const sendCallSessionKeepalive = () => {
        if (!appState.bootstrap?.authenticated || appState.runtime.keepaliveInFlight) {
            return;
        }

        void pingSessionKeepalive();
    };

    const startCallSessionKeepalive = () => {
        if (state.sessionKeepaliveTimerId) {
            return;
        }

        sendCallSessionKeepalive();
        state.sessionKeepaliveTimerId = window.setInterval(sendCallSessionKeepalive, CALL_SESSION_KEEPALIVE_MS);
    };

    const resetRemotePeerConnection = (targetUserId) => {
        const remoteId = String(targetUserId ?? '').trim();

        if (!remoteId) {
            return;
        }

        const peerConnection = conferenceState.peerConnections?.[remoteId] ?? null;

        if (peerConnection) {
            try {
                peerConnection.onicecandidate = null;
                peerConnection.ontrack = null;
                peerConnection.onconnectionstatechange = null;
                peerConnection.close();
            } catch {
                // Ignore stale peer teardown failures.
            }
        }

        delete conferenceState.peerConnections[remoteId];
        delete conferenceState.pendingIceCandidatesByUser[remoteId];
        delete conferenceState.remoteStreams[remoteId];
    };

    const createAndSendOffer = async ({ force = false, resetPeer = true } = {}) => {
        if (!isPublicViewerRole(viewerRole)) {
            return;
        }

        debugMedia('offer-begin', {
            targetUserId: remoteUserId,
            force: Boolean(force),
            resetPeer: Boolean(resetPeer),
            startedOffer: state.startedOffer,
            hasLocalVideo: state.localVideoStream instanceof MediaStream
                && state.localVideoStream.getVideoTracks().length > 0,
            localVideoTracks: state.localVideoStream instanceof MediaStream
                ? state.localVideoStream.getVideoTracks().map((track) => ({
                    id: track.id,
                    readyState: track.readyState,
                    muted: Boolean(track.muted),
                    enabled: Boolean(track.enabled),
                }))
                : [],
        });

        if (force) {
            if (resetPeer) {
                debugMedia('offer-reset-peer', {
                    targetUserId: remoteUserId,
                });
                resetRemotePeerConnection(remoteUserId);
            }
            state.startedOffer = false;
        }

        if (state.startedOffer) {
            debugMedia('offer-skip-inflight', {
                targetUserId: remoteUserId,
            });
            return;
        }

        state.startedOffer = true;

        try {
            const peerConnection = await ensurePeerConnectionWithLocalAudio(remoteUserId);

            if (!peerConnection) {
                state.startedOffer = false;
                return;
            }

            await applyLocalVideoTrack(peerConnection);

            const offer = await peerConnection.createOffer({
                offerToReceiveAudio: true,
                offerToReceiveVideo: true,
            });
            await peerConnection.setLocalDescription(offer);

            debugMedia('offer-created', {
                targetUserId: remoteUserId,
                type: peerConnection.localDescription?.type ?? offer.type ?? 'offer',
                sdpLength: String(peerConnection.localDescription?.sdp ?? offer.sdp ?? '').length,
                senderTracks: peerConnection.getSenders().map((sender) => ({
                    kind: sender.track?.kind ?? '',
                    trackId: sender.track?.id ?? '',
                    readyState: sender.track?.readyState ?? '',
                })),
            });

            if (!state.client?.isOpen?.() || !state.callRoom) {
                state.startedOffer = false;
                return;
            }

            state.client.sendRequest(
                'call.signal.publish',
                state.callRoom,
                buildCallSignalPayload('offer', {
                    targetUserId: remoteUserId,
                    sdp: normalizeRealtimeSdp(peerConnection.localDescription?.sdp ?? offer.sdp ?? ''),
                    meta: {
                        reason: 'video-stream-updated',
                        type: peerConnection.localDescription?.type ?? offer.type ?? 'offer',
                    },
                }),
            );
            debugMedia('offer-sent', {
                targetUserId: remoteUserId,
                callRoom: state.callRoom,
            });
        } catch (error) {
            state.startedOffer = false;
            console.warn('Realtime call session offer failed.', error);
            showToast('Unable to start the live audio session.', 'warn');
        }
    };

    const updateLocalVideoStream = async (nextStream) => {
        const previousVideoTrack = state.localVideoStream instanceof MediaStream
            ? (state.localVideoStream.getVideoTracks().at(0) ?? null)
            : null;
        const nextVideoTrack = nextStream instanceof MediaStream
            ? (nextStream.getVideoTracks().at(0) ?? null)
            : null;
        const videoChanged = (previousVideoTrack?.id ?? '') !== (nextVideoTrack?.id ?? '');

        debugMedia('local-video-update', {
            previousTrackId: previousVideoTrack?.id ?? '',
            previousTrackReadyState: previousVideoTrack?.readyState ?? '',
            nextTrackId: nextVideoTrack?.id ?? '',
            nextTrackReadyState: nextVideoTrack?.readyState ?? '',
            nextTrackMuted: Boolean(nextVideoTrack?.muted),
            nextTrackEnabled: Boolean(nextVideoTrack?.enabled),
            videoChanged,
        });

        state.localVideoStream = nextStream instanceof MediaStream ? nextStream : null;

        for (const peerConnection of Object.values(conferenceState.peerConnections ?? {})) {
            await applyLocalVideoTrack(peerConnection);
        }

        if (isPublicViewerRole(viewerRole) && state.joinedRoom && state.client?.isOpen?.() && state.callRoom) {
            state.client.sendRequest(
                'call.signal.publish',
                state.callRoom,
                buildCallSignalPayload('video-state', {
                    targetUserId: remoteUserId,
                    enabled: Boolean(nextVideoTrack),
                }),
            );
        }

        if (isPublicViewerRole(viewerRole) && state.joinedRoom && videoChanged) {
            await createAndSendOffer({ force: true, resetPeer: false });
        }
    };

    const handleSignalEvent = async (envelope) => {
        const payload = envelope?.payload && typeof envelope.payload === 'object'
            ? envelope.payload
            : {};
        const senderUserId = extractRealtimeEnvelopeUserId(envelope);

        if (!payload || !senderUserId || senderUserId === currentUserId) {
            return;
        }

        const targetUserId = String(payload?.target_user_id ?? '').trim();

        if (targetUserId && targetUserId !== currentUserId) {
            return;
        }

        const signalType = String(payload?.signal_type ?? '').trim();
        const signalMeta = parseRealtimeSignalJson(payload?.meta_json) ?? {};

        debugMedia('signal-received', {
            senderUserId,
            signalType,
            targetUserId,
            meta: signalMeta,
        });
        logCallFlow(viewerRole, 'call-session-signal-received', {
            callSessionId,
            callRoom: state.callRoom,
            senderUserId,
            signalType,
            targetUserId,
        });

        if (!signalType) {
            return;
        }

        if (signalType === 'ready') {
            if (isPublicViewerRole(viewerRole)) {
                await createAndSendOffer({ force: true, resetPeer: false });
            } else if (viewerRole === 'operator') {
                debugMedia('ready-echo', {
                    targetUserId: senderUserId,
                });
                sendCallSignal('ready', {
                    meta: {
                        role: viewerRole,
                        echo: true,
                    },
                });
            }
            return;
        }

        if (signalType === 'hangup') {
            if (typeof options.onHangup === 'function') {
                options.onHangup({
                    ...payload,
                    meta: signalMeta,
                });
            }
            return;
        }

        if (signalType === 'disconnect-request') {
            if (typeof options.onDisconnectRequest === 'function') {
                options.onDisconnectRequest({
                    ...payload,
                    meta: signalMeta,
                });
            }
            return;
        }

        if (signalType === 'hangup-confirm') {
            if (typeof options.onHangupConfirm === 'function') {
                options.onHangupConfirm({
                    ...payload,
                    meta: signalMeta,
                });
            }
            return;
        }

        if (signalType === 'hangup-complete') {
            if (typeof options.onHangupComplete === 'function') {
                options.onHangupComplete({
                    ...payload,
                    meta: signalMeta,
                });
            }
            return;
        }

        if (signalType === 'heartbeat') {
            if (typeof options.onRemoteHeartbeat === 'function') {
                options.onRemoteHeartbeat({
                    ...payload,
                    meta: signalMeta,
                    senderUserId,
                });
            }
            return;
        }

        if (signalType === 'browser-offline') {
            if (typeof options.onRemoteBrowserOffline === 'function') {
                options.onRemoteBrowserOffline({
                    ...payload,
                    meta: signalMeta,
                });
            }
            return;
        }

        if (signalType === 'browser-online') {
            if (typeof options.onRemoteBrowserOnline === 'function') {
                options.onRemoteBrowserOnline({
                    ...payload,
                    meta: signalMeta,
                });
            }
            return;
        }

        if (signalType === 'video-state') {
            debugMedia('signal-video-state', {
                senderUserId,
                enabled: Boolean(payload?.enabled),
            });
            if (viewerRole === 'operator' && !payload?.enabled) {
                syncRemoteVideoStream(null);
            }
            return;
        }

        if (signalType === 'caller-location') {
            if (typeof options.onCallerLocation === 'function') {
                options.onCallerLocation({
                    ...payload,
                    meta: signalMeta,
                    senderUserId,
                });
            }
            return;
        }

        const peerConnection = await ensurePeerConnectionWithLocalAudio(senderUserId);

        if (!peerConnection) {
            return;
        }

        await applyLocalVideoTrack(peerConnection);

        if (signalType === 'offer') {
            try {
                await peerConnection.setRemoteDescription({
                    type: 'offer',
                    sdp: normalizeRealtimeSdp(payload?.sdp ?? ''),
                });
                await attachPendingIceCandidates(peerConnection, senderUserId);

                const answer = await peerConnection.createAnswer();
                await peerConnection.setLocalDescription(answer);

                if (!state.client?.isOpen?.() || !state.callRoom) {
                    return;
                }

                state.client.sendRequest(
                    'call.signal.publish',
                    state.callRoom,
                    buildCallSignalPayload('answer', {
                        targetUserId: senderUserId,
                        sdp: normalizeRealtimeSdp(peerConnection.localDescription?.sdp ?? answer.sdp ?? ''),
                        meta: {
                            type: peerConnection.localDescription?.type ?? answer.type ?? 'answer',
                        },
                    }),
                );
            } catch (error) {
                console.warn('Realtime call session offer handling failed.', error);
            }
            return;
        }

        if (signalType === 'answer') {
            try {
                await peerConnection.setRemoteDescription({
                    type: 'answer',
                    sdp: normalizeRealtimeSdp(payload?.sdp ?? ''),
                });
                await attachPendingIceCandidates(peerConnection, senderUserId);
                state.startedOffer = false;
            } catch (error) {
                console.warn('Realtime call session answer handling failed.', error);
            }
            return;
        }

        if (signalType === 'ice-candidate') {
            const candidate = parseRealtimeSignalJson(payload?.candidate_json);

            if (!candidate) {
                return;
            }

            if (!peerConnection.remoteDescription) {
                conferenceState.pendingIceCandidatesByUser[senderUserId] = [
                    ...(conferenceState.pendingIceCandidatesByUser[senderUserId] ?? []),
                    candidate,
                ];
                return;
            }

            try {
                await peerConnection.addIceCandidate(candidate);
            } catch (error) {
                console.warn('Realtime call session ICE handling failed.', error);
            }
        }
    };

    try {
        logCallFlow(viewerRole, 'call-session-admission-request', {
            callSessionId,
            admissionPath,
            currentUserId,
            remoteUserId,
        });
        const admission = await fetchJson(admissionPath, {
            method: 'post',
            data: {
                context_type: 'call_session',
                context_id: callSessionId,
            },
        });

        const callRoom = String(admission?.call_room ?? admission?.session?.call_room ?? '').trim();

        if (!admission?.token || !admission?.websocket_url || !callRoom) {
            logCallFlow(viewerRole, 'call-session-admission-invalid', {
                callSessionId,
                hasToken: Boolean(admission?.token),
                hasWebsocketUrl: Boolean(admission?.websocket_url),
                callRoom,
            });
            return clearRegistryRuntime();
        }

        state.callRoom = callRoom;
        logCallFlow(viewerRole, 'call-session-admission-success', {
            callSessionId,
            callRoom,
            websocketUrl: admission.websocket_url,
        });
        state.remoteAudioEl = attachHiddenRemoteAudio(remoteAudioHost, `${viewerRole}-call-remote-audio`);
        resetRemoteVideoHost(remoteVideoHost);

        state.client = new RealtimeSocketClient({
            websocketUrl: admission.websocket_url,
            token: admission.token,
            requestPrefix: `${viewerRole}_call_session_${callSessionId}`,
            onMessage(raw) {
                if (!state.active) {
                    return;
                }

                let envelope;

                try {
                    envelope = parseRealtimeEnvelope(raw);
                } catch {
                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'session.auth.request') {
                    logCallFlow(viewerRole, 'call-session-auth-ack', {
                        callSessionId,
                        callRoom,
                    });
                    state.client?.sendRequest('room.join.request', callRoom, buildRoomJoinPayload());
                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'room.join.request') {
                    state.joinedRoom = String(envelope?.room ?? '') === callRoom;
                    logCallFlow(viewerRole, 'call-session-room-join-ack', {
                        callSessionId,
                        callRoom,
                        ackRoom: String(envelope?.room ?? ''),
                        joinedRoom: Boolean(state.joinedRoom),
                    });

                    if (state.joinedRoom) {
                        sendReadySignal();
                        startCallHeartbeat();
                        startCallSessionKeepalive();
                    }
                    return;
                }

                if (
                    envelope?.phase === 'event'
                    && envelope?.type === 'call.signal.event'
                    && String(envelope?.room ?? '') === callRoom
                ) {
                    void handleSignalEvent(envelope);
                }
            },
        });

        state.client.connect();
        logCallFlow(viewerRole, 'call-session-websocket-connect-start', {
            callSessionId,
            callRoom,
        });

        void ensureLocalStream().catch((error) => {
            logCallFlow(viewerRole, 'call-session-local-media-error', {
                callSessionId,
                message: String(error?.message ?? error ?? ''),
            });
            console.warn('Realtime call session local media acquisition failed.', error);
            showToast('Unable to access the microphone for the live call.', 'error');
        });
    } catch (error) {
        logCallFlow(viewerRole, 'call-session-runtime-error', {
            callSessionId,
            message: String(error?.message ?? error ?? ''),
            status: Number(error?.response?.status ?? 0) || null,
        });
        console.warn('Realtime call session is unavailable.', error);
        try {
            options.onUnavailable?.(error);
        } catch (callbackError) {
            console.warn('Realtime call session unavailable callback failed.', callbackError);
        }
        showToast('Live audio is unavailable right now.', 'warn');
        return clearRegistryRuntime();
    }

    const runtimeApi = {
        destroy() {
            state.active = false;
            stopCallHeartbeat();
            stopCallSessionKeepalive();

            Object.values(conferenceState.peerConnections ?? {}).forEach((peerConnection) => {
                try {
                    peerConnection.onicecandidate = null;
                    peerConnection.ontrack = null;
                    peerConnection.onconnectionstatechange = null;
                    peerConnection.close();
                } catch {
                    // Ignore peer-connection teardown failures.
                }
            });

            Object.values(conferenceState.remoteStreams ?? {}).forEach((stream) => {
                stream?.getTracks?.().forEach((track) => {
                    try {
                        track.stop();
                    } catch {
                        // Ignore remote track teardown failures.
                    }
                });
            });

            if (state.localStream instanceof MediaStream) {
                state.localStream.getTracks().forEach((track) => {
                    try {
                        track.stop();
                    } catch {
                        // Ignore local track teardown failures.
                    }
                });
            }

            if (state.remoteAudioEl instanceof HTMLMediaElement) {
                state.remoteAudioEl.srcObject = null;
                state.remoteAudioEl.remove();
            }

            if (state.remoteVideoEl instanceof HTMLMediaElement) {
                state.remoteVideoEl.srcObject = null;
                state.remoteVideoEl.remove();
                state.remoteVideoEl = null;
            }

            resetRemoteVideoHost(remoteVideoHost);

            state.client?.close?.();
            const currentEntry = realtimeCallSessionRegistry.get(registryKey);

            if (currentEntry?.runtime === runtimeApi) {
                realtimeCallSessionRegistry.delete(registryKey);
            }
        },
        sendHangup(meta = {}) {
            sendHangupSignal(meta);
        },
        sendDisconnectRequest(meta = {}) {
            sendCallSignal('disconnect-request', { meta });
        },
        sendHangupConfirm(meta = {}) {
            sendCallSignal('hangup-confirm', { meta });
        },
        sendHangupComplete(meta = {}) {
            sendCallSignal('hangup-complete', { meta });
        },
        sendSignal(signalType, payload = {}) {
            sendCallSignal(signalType, payload && typeof payload === 'object' ? payload : {});
        },
        setMediaMuted(nextMuted) {
            state.mediaMuted = Boolean(nextMuted);
            syncMediaMuteState();
        },
        updateLocalVideoStream,
        getState() {
            return {
                joinedRoom: state.joinedRoom,
                callRoom: state.callRoom,
                hasLocalAudio: state.localStream instanceof MediaStream,
                hasLocalVideo: state.localVideoStream instanceof MediaStream
                    && state.localVideoStream.getVideoTracks().length > 0,
                mediaMuted: state.mediaMuted,
            };
        },
    };

    return finishRegistryRuntime(runtimeApi);
}

async function mountRealtimeIncidentChat(options = {}) {
    const incidentId = Number(options.incidentId ?? 0);
    const viewerRole = String(options.viewerRole ?? '').trim();
    const admissionPath = String(options.admissionPath ?? '').trim();
    const currentUserId = String(options.currentUserId ?? '').trim();
    const currentDisplayName = String(options.currentDisplayName ?? '').trim() || formatStatusLabel(viewerRole || 'user');
    const threadHost = options.threadHost ?? null;
    const composerHost = options.composerHost ?? null;
    const uploadQueueHost = options.uploadQueueHost ?? null;
    const roomName = incidentId > 0
        ? `chat.thread.incident.${incidentId}`
        : '';

    if (!incidentId || !viewerRole || !admissionPath || !roomName || !threadHost) {
        return null;
    }

    await ensureHelperUi();

    let messageList = normalizeChatMessages(options.messages ?? [], viewerRole);
    let receivedAttachments = {};
    let uploadItems = [];
    let uploadHydration = null;
    const threadApi = mountChatThread(threadHost, options.messages ?? [], viewerRole, {
        emptyTitle: options.emptyTitle ?? 'No messages yet',
        emptyText: options.emptyText ?? 'Live chat will appear here once the call is connected.',
        onAttachmentOpen(_message, attachment) {
            const kind = String(attachment?.kind ?? '').toLowerCase();

            if (kind === 'image' || kind === 'video') {
                return;
            }

            const url = resolveAttachmentUrl(attachment, 'url');

            if (url) {
                window.open(url, '_blank', 'noopener');
                return;
            }

            showToast('Attachment is still being reassembled.', 'warn');
        },
        onAttachmentDownload(_message, attachment) {
            const url = resolveAttachmentUrl(attachment, 'url');

            if (!url) {
                showToast('Attachment is not ready to download yet.', 'warn');
                return;
            }

            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = String(attachment?.name ?? 'attachment');
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
        },
        ...(options.threadOptions ?? {}),
    });

    const composerBaseOptions = {
        showAttachmentButton: false,
        helperText: 'Loading attachment policy...',
        placeholder: options.composerPlaceholder ?? 'Type a message...',
        ...(options.composerOptions ?? {}),
    };

    let composerApi = null;
    let uploadQueueApi = null;
    let joinedRoom = false;
    let active = true;
    let requestErrorTimer = null;
    const pendingPublishIds = new Set();
    const persistedRealtimeMessages = new Map();
    const persistedRealtimeAttachmentMessageIds = new Set();
    const attachmentPersistenceTimers = new Map();
    const onMediaEvent = typeof options.onMediaEvent === 'function'
        ? options.onMediaEvent
        : null;
    const attachmentPolicy = {
        maxAttachmentCount: Number(options.attachmentPolicy?.max_attachment_count ?? options.attachmentPolicy?.maxAttachmentCount ?? 0) || 0,
        maxAttachmentBytes: Number(options.attachmentPolicy?.max_attachment_bytes ?? options.attachmentPolicy?.maxAttachmentBytes ?? 0) || 0,
        maxTotalBytesPerMessage: Number(options.attachmentPolicy?.max_total_bytes_per_message ?? options.attachmentPolicy?.maxTotalBytesPerMessage ?? 0) || 0,
    };

    const syncThread = () => {
        threadApi?.update?.({
            messages: messageList,
        });
    };

    const clearAttachmentPersistenceTimer = (realtimeMessageId) => {
        const timer = attachmentPersistenceTimers.get(realtimeMessageId);

        if (timer) {
            window.clearTimeout(timer);
            attachmentPersistenceTimers.delete(realtimeMessageId);
        }
    };

    const buildPersistableAttachments = (rawAttachments = []) => (Array.isArray(rawAttachments) ? rawAttachments : [])
        .map((attachment, index) => {
            const url = resolveAttachmentUrl(attachment, 'url');
            const file = url.startsWith('data:')
                ? dataUrlToFile(
                    url,
                    String(attachment?.name ?? `attachment-${index + 1}`),
                    String(attachment?.mime_type ?? ''),
                )
                : null;

            return {
                attachment,
                index,
                ready: Boolean(file),
                file,
                type: String(attachment?.kind ?? 'file'),
                originalFilename: String(attachment?.name ?? `attachment-${index + 1}`),
                mimeType: String(attachment?.mime_type ?? file?.type ?? ''),
            };
        });

    const persistRealtimeAttachments = async (savedMessage, payload, realtimeMessageId, attempt = 0) => {
        if (
            !savedMessage?.id
            || typeof options.persistAttachments !== 'function'
            || persistedRealtimeAttachmentMessageIds.has(realtimeMessageId)
        ) {
            return;
        }

        const attachments = buildPersistableAttachments(payload?.attachments ?? []);

        if (attachments.length === 0) {
            persistedRealtimeAttachmentMessageIds.add(realtimeMessageId);
            return;
        }

        const pendingAttachment = attachments.find((item) => !item.ready);

        if (pendingAttachment) {
            if (attempt >= 20) {
                console.warn('Realtime incident chat attachment persistence timed out.', pendingAttachment);
                return;
            }

            clearAttachmentPersistenceTimer(realtimeMessageId);
            const retryTimer = window.setTimeout(() => {
                attachmentPersistenceTimers.delete(realtimeMessageId);
                void persistRealtimeAttachments(savedMessage, payload, realtimeMessageId, attempt + 1);
            }, 300);

            attachmentPersistenceTimers.set(realtimeMessageId, retryTimer);
            return;
        }

        clearAttachmentPersistenceTimer(realtimeMessageId);

        try {
            await options.persistAttachments(savedMessage, attachments.map((item) => ({
                file: item.file,
                type: item.type,
                originalFilename: item.originalFilename,
                mimeType: item.mimeType,
            })));
            persistedRealtimeAttachmentMessageIds.add(realtimeMessageId);
        } catch (error) {
            console.warn('Realtime incident chat attachment persistence failed.', error);
            showToast('Unable to save the chat attachments.', 'warn');
        }
    };

    const persistRealtimeMessage = async (payload) => {
        if (typeof options.persistMessage !== 'function') {
            return;
        }

        const realtimeMessageId = String(payload?.message_id ?? '').trim();

        if (!realtimeMessageId) {
            return;
        }

        try {
            let savedMessage = persistedRealtimeMessages.get(realtimeMessageId);

            if (!savedMessage) {
                savedMessage = await options.persistMessage(payload);
                persistedRealtimeMessages.set(realtimeMessageId, savedMessage ?? null);
            }

            await persistRealtimeAttachments(savedMessage, payload, realtimeMessageId);
        } catch (error) {
            console.warn('Realtime incident chat persistence failed.', error);
            showToast('Unable to save the chat message.', 'warn');
        }
    };

    const syncUploadQueue = () => {
        uploadQueueApi?.setItems?.(uploadItems);
    };

    const releaseComposerBusy = () => {
        composerApi?.setBusy?.(false);
    };

    const revokeItemPreview = (item) => {
        const previewUrl = String(item?.previewUrl ?? '').trim();

        if (previewUrl.startsWith('blob:')) {
            URL.revokeObjectURL(previewUrl);
        }
    };

    const clearUploads = () => {
        uploadItems.forEach(revokeItemPreview);
        uploadItems = [];
        syncUploadQueue();
    };

    const removeUploadItem = (itemId) => {
        if (!itemId) {
            return;
        }

        const removed = uploadItems.find((item) => String(item?.id ?? '') === String(itemId));

        if (removed) {
            revokeItemPreview(removed);
        }

        uploadItems = uploadItems.filter((item) => String(item?.id ?? '') !== String(itemId));
        syncUploadQueue();
    };

    const updateUploadItem = (itemId, updates = {}) => {
        uploadItems = uploadItems.map((item) => (
            String(item?.id ?? '') === String(itemId)
                ? { ...item, ...updates }
                : item
        ));
        syncUploadQueue();
    };

    const resolveAttachmentUrl = (attachment, field) => {
        const direct = normalizeAttachmentStoredUrl(attachment?.[field] ?? attachment?.url);

        if (direct) {
            return direct;
        }

        return resolveAttachmentUrlFromStore(receivedAttachments, attachment, field);
    };

    const rehydrateMessageAttachments = (message) => ({
        ...message,
        attachments: (Array.isArray(message?.attachments) ? message.attachments : []).map((attachment) => ({
            ...attachment,
            url: resolveAttachmentUrl(attachment, 'url'),
            previewUrl: resolveAttachmentUrl(attachment, 'preview_url') || resolveAttachmentUrl(attachment, 'previewUrl'),
            posterUrl: resolveAttachmentUrl(attachment, 'poster_url') || resolveAttachmentUrl(attachment, 'posterUrl'),
        })),
    });

    const addDraftFiles = async (files) => {
        const { accepted, rejected } = validateDraftAttachments({
            existingItems: uploadItems,
            files,
            policy: attachmentPolicy,
        });

        rejected.forEach((message) => showToast(message, 'warn'));

        if (!accepted.length) {
            return;
        }

        const hydration = Promise.all(Array.from(accepted).map(async (file, index) => {
            const kind = inferAttachmentKind(file);
            const previewCapable = shouldPreviewAttachmentFile(file);
            const previewUrl = previewCapable ? URL.createObjectURL(file) : '';
            const transportUrl = await readFileAsDataUrl(file);

            return {
                id: `${Date.now()}-${index}-${String(file.name || 'file').replace(/\s+/g, '-')}`,
                transferId: `xfer_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`,
                kind,
                name: String(file.name || 'attachment'),
                sizeLabel: formatAttachmentFileSize(file.size),
                byteSize: Number(file.size) || 0,
                status: 'queued',
                progress: null,
                progressLabel: '',
                previewUrl,
                transportUrl,
                mimeType: String(file.type || getAttachmentMimeType(kind)),
                file,
            };
        }));

        uploadHydration = hydration;
        const nextItems = await hydration;

        if (uploadHydration === hydration) {
            uploadHydration = null;
        }

        uploadItems = uploadItems.concat(nextItems);
        syncUploadQueue();
    };

    let client = null;

    const transferAttachment = async (item) => {
        const attachment = await transferAttachmentInChunks(item, {
            onChunk(chunkPayload) {
                client?.sendRequest?.('sandbox.attachment.chunk.publish', roomName, chunkPayload);
            },
            onProgress(progress, progressLabel) {
                updateUploadItem(item.id, {
                    status: progress >= 100 ? 'uploaded' : 'uploading',
                    progress,
                    progressLabel,
                });
            },
        });

        updateUploadItem(item.id, {
            status: 'uploaded',
            progress: 100,
            progressLabel: 'Delivered to message payload',
        });

        return attachment;
    };

    if (uploadQueueHost && appState.helper.createChatUploadQueue) {
        uploadQueueApi = appState.helper.createChatUploadQueue(uploadQueueHost, { items: uploadItems }, {
            emptyHidden: true,
            onRemove(item) {
                removeUploadItem(String(item?.id ?? ''));
            },
        });
    }

    const attachComposer = () => {
        if (!composerHost) {
            return null;
        }

        return mountChatComposer(composerHost, {
            ...composerBaseOptions,
            async onSend({ text }) {
                const trimmed = String(text ?? '').trim();

                if (uploadHydration) {
                    await uploadHydration;
                }

                if (!joinedRoom || !client?.isOpen?.()) {
                    showToast('Live chat is not connected yet.', 'warn');
                    return;
                }

                composerApi?.setBusy?.(true);

                if (!trimmed) {
                    releaseComposerBusy();
                    showToast('Attachment messages still require text.', 'warn');
                    return;
                }

                const transportAttachments = [];

                for (const item of uploadItems) {
                    transportAttachments.push(await transferAttachment(item));
                }

                const requestId = client.sendRequest(
                    'chat.message.publish',
                    roomName,
                    buildChatPublishPayload(trimmed, transportAttachments),
                );

                if (!requestId) {
                    releaseComposerBusy();
                    showToast('Unable to send the message right now.', 'error');
                    return;
                }

                pendingPublishIds.add(String(requestId));

                if (requestErrorTimer) {
                    window.clearTimeout(requestErrorTimer);
                }

                requestErrorTimer = window.setTimeout(() => {
                    if (!active || pendingPublishIds.size === 0) {
                        return;
                    }

                    pendingPublishIds.clear();
                    releaseComposerBusy();
                    showToast('Live chat acknowledgement timed out.', 'warn');
                }, 8000);
            },
            onFilesSelected(files) {
                void addDraftFiles(files);
            },
        });
    };

    composerApi = attachComposer();

    try {
        const admission = await fetchJson(admissionPath, {
            method: 'post',
            data: {
                context_type: 'incident_chat',
                context_id: incidentId,
            },
        });

        const admissionRoom = String(admission?.room ?? '').trim() || roomName;

        if (!admission?.token || !admission?.websocket_url || !admissionRoom) {
            return {
                threadApi,
                composerApi,
                uploadQueueApi,
                destroy() {
                    active = false;
                    if (requestErrorTimer) {
                        window.clearTimeout(requestErrorTimer);
                    }
                    clearUploads();
                    uploadQueueApi?.destroy?.();
                    composerApi?.destroy?.();
                    threadApi?.destroy?.();
                },
            };
        }

        attachmentPolicy.maxAttachmentCount = Number(admission?.session?.attachment_policy?.max_attachment_count ?? attachmentPolicy.maxAttachmentCount ?? 0) || 0;
        attachmentPolicy.maxAttachmentBytes = Number(admission?.session?.attachment_policy?.max_attachment_bytes ?? attachmentPolicy.maxAttachmentBytes ?? 0) || 0;
        attachmentPolicy.maxTotalBytesPerMessage = Number(admission?.session?.attachment_policy?.max_total_bytes_per_message ?? attachmentPolicy.maxTotalBytesPerMessage ?? 0) || 0;

        client = new RealtimeSocketClient({
            websocketUrl: admission.websocket_url,
            token: admission.token,
            requestPrefix: `${viewerRole}_incident_chat_${incidentId}`,
            onMessage(raw) {
                if (!active) {
                    return;
                }

                let envelope;

                try {
                    envelope = parseRealtimeEnvelope(raw);
                } catch {
                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'session.auth.request') {
                    client.sendRequest('room.join.request', admissionRoom, buildRoomJoinPayload());
                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'room.join.request') {
                    joinedRoom = String(envelope?.room ?? '') === admissionRoom;
                    composerApi?.update?.({}, {
                        helperText: formatAttachmentPolicyHelperText(attachmentPolicy),
                        disabled: false,
                        showAttachmentButton: true,
                    });
                    return;
                }

                if (envelope?.phase === 'ack' && envelope?.type === 'chat.message.publish') {
                    const requestId = String(envelope?.id ?? '').trim();

                    if (requestId && pendingPublishIds.has(requestId)) {
                        pendingPublishIds.delete(requestId);
                        releaseComposerBusy();
                        composerApi?.clear?.();
                        clearUploads();

                        if (requestErrorTimer && pendingPublishIds.size === 0) {
                            window.clearTimeout(requestErrorTimer);
                            requestErrorTimer = null;
                        }
                    }

                    return;
                }

                if (envelope?.phase === 'error') {
                    const requestId = String(envelope?.id ?? '').trim();

                    if (requestId && pendingPublishIds.has(requestId)) {
                        pendingPublishIds.delete(requestId);
                        releaseComposerBusy();
                        showToast(String(envelope?.payload?.message ?? 'Unable to send the message.'), 'error');

                        if (requestErrorTimer && pendingPublishIds.size === 0) {
                            window.clearTimeout(requestErrorTimer);
                            requestErrorTimer = null;
                        }
                    }

                    return;
                }

                if (
                    envelope?.phase === 'event'
                    && envelope?.type === 'sandbox.attachment.chunk.event'
                    && String(envelope?.room ?? '') === admissionRoom
                ) {
                    receivedAttachments = reduceAttachmentChunkStore(receivedAttachments, envelope?.payload ?? {});
                    messageList = messageList.map(rehydrateMessageAttachments);
                    syncThread();
                    return;
                }

                if (
                    envelope?.phase === 'event'
                    && ['media.processing', 'media.available'].includes(String(envelope?.type ?? ''))
                    && String(envelope?.room ?? '') === admissionRoom
                ) {
                    onMediaEvent?.(String(envelope.type), envelope?.payload ?? {});
                    return;
                }

                if (
                    envelope?.phase !== 'event'
                    || envelope?.type !== 'chat.message.event'
                    || String(envelope?.room ?? '') !== admissionRoom
                ) {
                    return;
                }

                const nextMessage = normalizeChatMessageEvent(envelope?.payload ?? {}, {
                    currentUserId,
                    fallbackSenderName: currentDisplayName,
                    resolveAttachmentUrl,
                });
                const existingIndex = messageList.findIndex((item) => String(item?.id ?? '') === String(nextMessage.id));

                if (existingIndex >= 0) {
                    messageList.splice(existingIndex, 1, nextMessage);
                } else {
                    messageList = [...messageList, nextMessage];
                }

                syncThread();
                void persistRealtimeMessage(envelope?.payload ?? {});
            },
        });

        client.connect();
    } catch (error) {
        console.warn('Realtime incident chat is unavailable.', error);
        showToast('Live chat is unavailable right now.', 'warn');
    }

    return {
        threadApi,
        composerApi,
        uploadQueueApi,
        updateMessages(nextMessages = []) {
            messageList = normalizeChatMessages(nextMessages, viewerRole);
            syncThread();
        },
        getMessages() {
            return messageList.map((message) => ({
                ...message,
                attachments: Array.isArray(message?.attachments)
                    ? message.attachments.map((attachment) => ({ ...attachment }))
                    : [],
            }));
        },
        destroy() {
            active = false;
            joinedRoom = false;
            pendingPublishIds.clear();
            attachmentPersistenceTimers.forEach((timer) => window.clearTimeout(timer));
            attachmentPersistenceTimers.clear();
            if (requestErrorTimer) {
                window.clearTimeout(requestErrorTimer);
                requestErrorTimer = null;
            }
            releaseComposerBusy();
            clearUploads();
            client?.close?.();
            uploadQueueApi?.destroy?.();
            composerApi?.destroy?.();
            threadApi?.destroy?.();
        },
    };
}

function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result ?? ''));
        reader.onerror = () => reject(reader.error ?? new Error('Unable to read attachment.'));
        reader.readAsDataURL(file);
    });
}

function buildOptions(items, emptyLabel, labelBuilder = (item) => item.name) {
    if (!Array.isArray(items) || items.length === 0) {
        return `<option value="">${escapeHtml(emptyLabel)}</option>`;
    }

    return `
        <option value="">Select one</option>
        ${items.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(labelBuilder(item))}</option>`).join('')}
    `;
}

function deriveActiveCallSessionId(payload) {
    const currentSession = payload?.current_call_session ?? null;

    if (!currentSession || !['calling', 'in_progress'].includes(String(currentSession?.status ?? '').trim())) {
        return null;
    }

    return Number(currentSession?.id ?? 0) || null;
}

function handleCommandBroadcastEnvelope(envelope) {
    if (envelope?.phase !== 'event' || envelope?.type !== 'hotline.broadcast.created') {
        return false;
    }

    const payload = envelope?.payload && typeof envelope.payload === 'object'
        ? envelope.payload
        : {};
    const currentRole = String(appState.bootstrap?.user?.role ?? appState.activeSurface ?? '').trim().toLowerCase();

    if (currentRole === 'command') {
        return true;
    }

    const targetRoles = normalizeBroadcastTargetRoles(payload?.target_roles);

    const currentRoleAliases = ['citizen', 'caller'].includes(currentRole)
        ? ['citizen', 'caller']
        : [currentRole];

    if (targetRoles.length && !targetRoles.some((role) => currentRoleAliases.includes(role))) {
        return true;
    }

    const id = String(payload?.id ?? '').trim();
    const dedupeKey = id || `${String(payload?.published_at ?? '')}:${String(payload?.message ?? '')}`;

    if (!dedupeKey) {
        return true;
    }

    if (!(appState.runtime.receivedCommandBroadcastIds instanceof Set)) {
        appState.runtime.receivedCommandBroadcastIds = new Set();
    }

    if (appState.runtime.receivedCommandBroadcastIds.has(dedupeKey)) {
        return true;
    }

    const expiresAt = Date.parse(String(payload?.expires_at ?? ''));

    if (Number.isFinite(expiresAt) && expiresAt <= Date.now()) {
        return true;
    }

    appState.runtime.receivedCommandBroadcastIds.add(dedupeKey);

    const tone = normalizeBroadcastTone(payload?.tone);
    const title = String(payload?.title ?? '').trim() || broadcastDefaultTitle(tone);
    const message = String(payload?.message ?? '').trim();

    if (!message) {
        return true;
    }

    void openCommandBroadcastNotice({
        title,
        message,
        tone,
        createdBy: payload?.created_by?.name,
        publishedAt: payload?.published_at,
    });

    return true;
}

function normalizeBroadcastTargetRoles(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return Array.from(new Set(
        value
            .map((item) => String(item ?? '').trim().toLowerCase())
            .filter((item) => ['citizen', 'caller', 'operator'].includes(item))
    ));
}

function normalizeBroadcastTone(value) {
    const normalized = String(value ?? '').trim().toLowerCase();

    return ['info', 'warning', 'urgent'].includes(normalized) ? normalized : 'info';
}

function broadcastToastTone(tone) {
    if (tone === 'urgent') {
        return 'error';
    }

    return tone === 'warning' ? 'warn' : 'info';
}

function broadcastDefaultTitle(tone) {
    if (tone === 'urgent') {
        return 'Urgent Command Broadcast';
    }

    if (tone === 'warning') {
        return 'Command Advisory';
    }

    return 'Command Broadcast';
}

async function openCommandBroadcastNotice({ title, message, tone, createdBy, publishedAt }) {
    await ensureHelperUi();

    if (!appState.helper.uiAlert) {
        return;
    }

    const meta = [createdBy ? `From ${createdBy}` : '', publishedAt ? formatDateTime(publishedAt) : '']
        .filter(Boolean)
        .join(' • ');
    const normalizedTone = normalizeBroadcastTone(tone);

    await appState.helper.uiAlert(message, {
        title,
        description: meta,
        variant: normalizedTone === 'urgent' ? 'error' : (normalizedTone === 'warning' ? 'warning' : 'info'),
        size: normalizedTone === 'urgent' ? 'md' : 'sm',
        okText: 'Acknowledge',
        speak: true,
        speakText: `${title}. ${message}`,
        speakRate: 0.95,
        showCloseButton: false,
    });
}

export {
    INCOMING_MODAL_DISMISS_PREFIX,
    OPERATOR_WORKBENCH_CALL_SESSION_KEY,
    OPERATOR_WORKBENCH_KEY,
    TRANSFER_MODAL_DISMISS_PREFIX,
    appState,
    availabilityPillClass,
    buildOptions,
    card,
    clearCallerPendingState,
    confirmDeleteAction,
    createIconMarkup,
    deriveActiveCallSessionId,
    ensureHelperUi,
    escapeHtml,
    evaluateDevicePrimer,
    fetchJson,
    formatBlockedDeleteMessage,
    formatDateTime,
    formatIncidentStatusHeading,
    formatStatusLabel,
    getCallerPendingState,
    handleCommandBroadcastEnvelope,
    latestCallSession,
    logCallFlow,
    mergeIncidentMediaItems,
    mountChatComposer,
    mountChatThread,
    mountRealtimeCallSession,
    mountRealtimeIncidentChat,
    mountSurfaceChrome,
    openLoginModal,
    padIncidentId,
    primerStatusButton,
    renderAssignments,
    renderInfoList,
    renderMedia,
    renderMessages,
    renderStatChips,
    renderTransfers,
    resetSurfaceRuntime,
    roleHome,
    setCallerPendingState,
    sharedShell,
    showToast,
    syncBootstrapSessionState,
    trackSurfaceInstance,
    wirePrimer,
};
