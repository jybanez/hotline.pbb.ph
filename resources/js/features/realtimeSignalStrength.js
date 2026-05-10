const DEFAULT_MEASURE_INTERVAL_MS = 10000;
const DEFAULT_STALE_AFTER_MS = 25000;
const DEFAULT_TIMEOUT_MS = 3000;

function nowIso() {
    return new Date().toISOString();
}

function normalizeConnectionState(value) {
    return String(value ?? '').trim() || 'idle';
}

function classifyLatency(rttMs) {
    const rtt = Number(rttMs);

    if (!Number.isFinite(rtt) || rtt < 0) {
        return 3;
    }

    if (rtt < 150) {
        return 4;
    }

    if (rtt < 400) {
        return 3;
    }

    if (rtt < 1000) {
        return 2;
    }

    return 1;
}

function isRealtimeOpen(client) {
    if (!client) {
        return false;
    }

    if (typeof client.isOpen === 'function') {
        return Boolean(client.isOpen());
    }

    const state = normalizeConnectionState(client.getConnectionState?.());

    return state === 'open' || state === 'authenticated';
}

function readClientState(client) {
    if (!client) {
        return 'idle';
    }

    if (typeof client.getConnectionState === 'function') {
        return normalizeConnectionState(client.getConnectionState());
    }

    return isRealtimeOpen(client) ? 'open' : 'idle';
}

function browserOffline() {
    return typeof navigator !== 'undefined' && navigator.onLine === false;
}

function createRoot(label) {
    const root = document.createElement('div');
    root.className = 'realtime-signal';
    root.setAttribute('role', 'status');
    root.setAttribute('aria-live', 'polite');

    const bars = document.createElement('span');
    bars.className = 'realtime-signal-bars';
    bars.setAttribute('aria-hidden', 'true');

    for (let index = 1; index <= 4; index += 1) {
        const bar = document.createElement('span');
        bar.className = 'realtime-signal-bar';
        bar.dataset.bar = String(index);
        bars.appendChild(bar);
    }

    const text = document.createElement('span');
    text.className = 'realtime-signal-text';
    text.textContent = label;

    root.append(bars, text);

    return { root, text };
}

function renderSnapshot(view, snapshot) {
    const level = Math.max(0, Math.min(4, Number(snapshot.level ?? 0) || 0));
    const state = normalizeConnectionState(snapshot.state);
    const tone = String(snapshot.tone ?? 'offline').trim() || 'offline';
    const label = String(snapshot.label ?? 'Realtime').trim() || 'Realtime';
    const signalOptions = {
        level,
        tone,
        text: label,
        title: snapshot.title ?? label,
        ariaLabel: snapshot.ariaLabel ?? label,
    };

    if (view.signal?.update) {
        view.signal.update(signalOptions);
        return;
    }

    view.root.dataset.level = String(level);
    view.root.dataset.state = state;
    view.root.dataset.tone = tone;
    view.text.textContent = label;
    view.root.setAttribute('aria-label', snapshot.ariaLabel ?? label);
    view.root.title = snapshot.title ?? label;
}

function destroyView(view) {
    view.signal?.destroy?.();

    if (!view.signal) {
        view.root?.remove?.();
    }
}

function snapshotFromState(state, latestHealth, reconnectRuntime) {
    const normalized = normalizeConnectionState(state);
    const reconnecting = Boolean(reconnectRuntime?.connecting || reconnectRuntime?.timerId);

    if (browserOffline()) {
        return {
            level: 0,
            state: 'browser-offline',
            tone: 'offline',
            label: 'Offline',
            ariaLabel: 'Browser is offline',
            title: 'Browser is offline',
        };
    }

    if (reconnecting) {
        return {
            level: 1,
            state: 'reconnecting',
            tone: 'warn',
            label: 'Reconnecting',
            ariaLabel: 'Realtime reconnecting',
            title: 'Realtime reconnecting',
        };
    }

    if (normalized === 'connecting') {
        return {
            level: 1,
            state: normalized,
            tone: 'warn',
            label: 'Connecting',
            ariaLabel: 'Realtime connecting',
            title: 'Realtime connecting',
        };
    }

    if (['closed', 'error', 'idle'].includes(normalized)) {
        return {
            level: 0,
            state: normalized,
            tone: 'offline',
            label: 'Offline',
            ariaLabel: 'Realtime offline',
            title: 'Realtime offline',
        };
    }

    if (latestHealth?.ok) {
        const rttMs = Number(latestHealth.rtt_ms ?? 0);
        const level = classifyLatency(rttMs);
        const label = Number.isFinite(rttMs) ? `${Math.round(rttMs)} ms` : 'Online';
        const tone = level >= 3 ? 'ok' : level === 2 ? 'warn' : 'danger';

        return {
            level,
            state: normalized,
            tone,
            label,
            ariaLabel: `Realtime connected, ${label}`,
            title: `Realtime connected (${label})`,
        };
    }

    if (latestHealth?.timed_out) {
        return {
            level: 1,
            state: normalized,
            tone: 'warn',
            label: 'Weak',
            ariaLabel: 'Realtime connected but health check timed out',
            title: 'Realtime health check timed out',
        };
    }

    return {
        level: 3,
        state: normalized,
        tone: 'ok',
        label: 'Online',
        ariaLabel: 'Realtime connected',
        title: 'Realtime connected',
    };
}

export function mountRealtimeSignalStrength(host, options = {}) {
    const label = String(options.label ?? 'Realtime').trim() || 'Realtime';
    const measureIntervalMs = Math.max(2000, Number(options.measureIntervalMs ?? DEFAULT_MEASURE_INTERVAL_MS) || DEFAULT_MEASURE_INTERVAL_MS);
    const staleAfterMs = Math.max(measureIntervalMs, Number(options.staleAfterMs ?? DEFAULT_STALE_AFTER_MS) || DEFAULT_STALE_AFTER_MS);
    const timeoutMs = Math.max(500, Number(options.timeoutMs ?? DEFAULT_TIMEOUT_MS) || DEFAULT_TIMEOUT_MS);
    const onSnapshot = typeof options.onSnapshot === 'function'
        ? options.onSnapshot
        : null;
    const createSignalStrength = typeof options.createSignalStrength === 'function'
        ? options.createSignalStrength
        : null;
    const createView = (container, viewOptions = {}) => {
        const mergedOptions = {
            ...options,
            ...viewOptions,
        };

        if (createSignalStrength) {
            return {
                root: container,
                signal: createSignalStrength(container, {
                    label,
                    level: 0,
                    tone: 'neutral',
                    text: 'Realtime',
                    title: 'Realtime status pending',
                    ariaLabel: 'Realtime status pending',
                    ariaLive: mergedOptions.ariaLive ?? 'off',
                    showText: mergedOptions.showText ?? true,
                    size: mergedOptions.size ?? 'compact',
                }),
            };
        }

        const fallbackView = createRoot(label);
        container?.appendChild?.(fallbackView.root);

        return fallbackView;
    };
    const views = [createView(host)];
    const cleanups = [];
    let client = null;
    let reconnectRuntime = null;
    let measureTimer = null;
    let staleTimer = null;
    let latestHealth = null;
    let latestSnapshot = null;
    let destroyed = false;

    const addView = (container, viewOptions = {}) => {
        if (destroyed || !container) {
            return null;
        }

        const view = createView(container, viewOptions);
        views.push(view);

        if (latestSnapshot) {
            renderSnapshot(view, latestSnapshot);
        }

        return {
            destroy() {
                const index = views.indexOf(view);

                if (index >= 0) {
                    views.splice(index, 1);
                }

                destroyView(view);
            },
        };
    };

    const update = (state = readClientState(client)) => {
        if (destroyed) {
            return;
        }

        latestSnapshot = snapshotFromState(state, latestHealth, reconnectRuntime);
        views.forEach((view) => renderSnapshot(view, latestSnapshot));
        onSnapshot?.({ ...latestSnapshot });
    };

    const clearMeasureTimer = () => {
        if (measureTimer) {
            window.clearInterval(measureTimer);
            measureTimer = null;
        }
    };

    const clearStaleTimer = () => {
        if (staleTimer) {
            window.clearTimeout(staleTimer);
            staleTimer = null;
        }
    };

    const markStaleLater = () => {
        clearStaleTimer();

        staleTimer = window.setTimeout(() => {
            if (latestHealth?.ok) {
                latestHealth = {
                    ok: false,
                    timed_out: true,
                    measured_at: nowIso(),
                };
                update();
            }
        }, staleAfterMs);
    };

    const measure = async () => {
        if (destroyed || !isRealtimeOpen(client)) {
            update();
            return;
        }

        if (typeof client?.measureLatency !== 'function') {
            latestHealth = {
                ok: true,
                measured_at: nowIso(),
            };
            update();
            return;
        }

        const snapshot = await client.measureLatency({ timeoutMs });

        if (destroyed) {
            return;
        }

        latestHealth = snapshot && typeof snapshot === 'object'
            ? snapshot
            : {
                ok: false,
                measured_at: nowIso(),
            };
        update();

        if (latestHealth?.ok) {
            markStaleLater();
        }
    };

    const startMeasuring = () => {
        clearMeasureTimer();
        void measure();
        measureTimer = window.setInterval(() => {
            void measure();
        }, measureIntervalMs);
    };

    const bindClient = (nextClient) => {
        cleanups.splice(0).forEach((cleanup) => cleanup());
        clearMeasureTimer();
        clearStaleTimer();
        latestHealth = null;
        client = nextClient ?? null;

        if (!client) {
            update('idle');
            return;
        }

        if (typeof client.on === 'function' && typeof client.off === 'function') {
            const onState = (event) => {
                update(event?.state ?? readClientState(client));
                if ((event?.state ?? '') === 'authenticated') {
                    startMeasuring();
                }
            };
            const onHealth = (snapshot) => {
                if (snapshot && typeof snapshot === 'object') {
                    latestHealth = snapshot;
                    update();

                    if (snapshot.ok) {
                        markStaleLater();
                    }
                }
            };
            const onOpen = () => update('open');
            const onClose = () => {
                clearMeasureTimer();
                clearStaleTimer();
                latestHealth = null;
                update('closed');
            };
            const onError = () => update('error');

            client.on('state', onState);
            client.on('health', onHealth);
            client.on('open', onOpen);
            client.on('close', onClose);
            client.on('error', onError);
            cleanups.push(() => client.off('state', onState));
            cleanups.push(() => client.off('health', onHealth));
            cleanups.push(() => client.off('open', onOpen));
            cleanups.push(() => client.off('close', onClose));
            cleanups.push(() => client.off('error', onError));
        }

        update(readClientState(client));

        if (isRealtimeOpen(client)) {
            startMeasuring();
        }
    };

    const handleBrowserNetworkChange = () => {
        update(readClientState(client));

        if (!browserOffline() && isRealtimeOpen(client)) {
            startMeasuring();
        }
    };

    if (typeof window !== 'undefined' && window.addEventListener) {
        window.addEventListener('offline', handleBrowserNetworkChange);
        window.addEventListener('online', handleBrowserNetworkChange);
    }

    const setReconnectRuntime = (runtime) => {
        reconnectRuntime = runtime ?? null;
        update();
    };

    const setState = (state) => {
        update(state);

        if (state === 'authenticated' || state === 'open') {
            startMeasuring();
        }
    };

    update('idle');

    return {
        bindClient,
        addView,
        setReconnectRuntime,
        setState,
        measure,
        getSnapshot: () => latestSnapshot ? { ...latestSnapshot } : null,
        destroy() {
            destroyed = true;
            if (typeof window !== 'undefined' && window.removeEventListener) {
                window.removeEventListener('offline', handleBrowserNetworkChange);
                window.removeEventListener('online', handleBrowserNetworkChange);
            }
            cleanups.splice(0).forEach((cleanup) => cleanup());
            clearMeasureTimer();
            clearStaleTimer();
            views.splice(0).forEach((view) => destroyView(view));
            client = null;
            reconnectRuntime = null;
        },
    };
}
