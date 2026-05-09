import { fetchJson } from '../../surfaces/surfaceShared.js';
import { buildBinaryMediaChunkFrame, buildMediaChunkPreparePayload, buildMediaChunkPublishPayload, buildRoomJoinPayload, parseRealtimeEnvelope, RealtimeSocketClient } from '../../../../../realtime/resources/js/sdk/index.js';

function createTransferId(payload = {}) {
    const existing = String(payload?.transfer_id ?? payload?.correlation_id ?? '').trim();

    if (existing) {
        return existing;
    }

    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return `media-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

async function blobToBase64(blob) {
    if (!(blob instanceof Blob)) {
        return '';
    }

    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onerror = () => reject(new Error('Unable to read media chunk.'));
        reader.onload = () => {
            const result = String(reader.result ?? '');
            const encoded = result.includes(',') ? result.split(',').at(-1) : result;
            resolve(String(encoded ?? ''));
        };
        reader.readAsDataURL(blob);
    });
}

async function ensureBase64ChunkPayload(payload = {}) {
    if (String(payload?.chunk_data ?? '').trim() !== '') {
        return payload;
    }

    if (!(payload?.chunk_blob instanceof Blob)) {
        throw new Error('Base64 media transport requires chunk_data or chunk_blob.');
    }

    return {
        ...payload,
        chunk_data: await blobToBase64(payload.chunk_blob),
        chunk_blob: undefined,
    };
}

function ensureBinaryChunkPayload(payload = {}) {
    if (!(payload?.chunk_blob instanceof Blob)) {
        throw new Error('Binary media transport requires chunk_blob.');
    }

    const transferId = createTransferId(payload);

    return {
        ...payload,
        transfer_id: transferId,
        correlation_id: String(payload?.correlation_id ?? transferId),
    };
}

function createRealtimeMediaIngestClient({ callSessionId, mode = 'realtime-base64' }) {
    if (!callSessionId) {
        return null;
    }

    const MEDIA_INGEST_ACK_TIMEOUT_MS = 30000;
    const MEDIA_INGEST_FORWARD_TIMEOUT_MS = 60000;
    const state = {
        active: true,
        client: null,
        room: '',
        joined: false,
        connectPromise: null,
        pending: new Map(),
        queuedWaiters: new Map(),
        queuedCache: new Map(),
        outcomeWaiters: new Map(),
        outcomeCache: new Map(),
        publishQueue: Promise.resolve(),
        joinTimeoutId: null,
    };

    const transferKey = (payload = {}) => String(payload?.transfer_id ?? '').trim();

    const clearQueuedWaiter = (key) => {
        const waiter = state.queuedWaiters.get(key);

        if (!waiter) {
            return null;
        }

        if (waiter.timeoutId) {
            window.clearTimeout(waiter.timeoutId);
        }

        state.queuedWaiters.delete(key);

        return waiter;
    };

    const resolveChunkQueued = (payload = {}) => {
        const key = transferKey(payload);

        if (!key) {
            return;
        }

        const waiter = clearQueuedWaiter(key);

        if (waiter) {
            waiter.resolve(payload);
            return;
        }

        state.queuedCache.set(key, payload);
    };

    const waitForChunkQueued = (payload = {}) => {
        const key = transferKey(payload);

        if (!key) {
            return Promise.reject(new Error('Realtime binary media queued key is missing.'));
        }

        const cached = state.queuedCache.get(key);

        if (cached) {
            state.queuedCache.delete(key);
            return Promise.resolve(cached);
        }

        return new Promise((resolve, reject) => {
            const timeoutId = window.setTimeout(() => {
                state.queuedWaiters.delete(key);
                reject(new Error('Realtime binary media queued acknowledgement timed out.'));
            }, MEDIA_INGEST_ACK_TIMEOUT_MS);

            state.queuedWaiters.set(key, { resolve, reject, timeoutId });
        });
    };

    const chunkOutcomeKey = (payload = {}) => {
        const mediaId = Number(payload?.media_id ?? 0);
        const chunkIndex = Number(payload?.chunk_index ?? 0);

        if (mediaId > 0) {
            return `media:${mediaId}:${chunkIndex}`;
        }

        const segmentKey = String(payload?.segment_key ?? '').trim();

        return segmentKey !== '' ? `segment:${segmentKey}:${chunkIndex}` : '';
    };

    const clearOutcomeWaiter = (key) => {
        const waiter = state.outcomeWaiters.get(key);

        if (!waiter) {
            return null;
        }

        if (waiter.timeoutId) {
            window.clearTimeout(waiter.timeoutId);
        }

        state.outcomeWaiters.delete(key);

        return waiter;
    };

    const resolveChunkOutcome = (eventType, payload = {}) => {
        const key = chunkOutcomeKey(payload);

        if (!key) {
            return;
        }

        const outcome = { eventType, payload };
        const waiter = clearOutcomeWaiter(key);

        if (waiter) {
            waiter.resolve(outcome);
            return;
        }

        state.outcomeCache.set(key, outcome);
    };

    const waitForChunkOutcome = (payload = {}) => {
        const key = chunkOutcomeKey(payload);

        if (!key) {
            return Promise.reject(new Error('Realtime media ingest outcome key is missing.'));
        }

        const cachedOutcome = state.outcomeCache.get(key);

        if (cachedOutcome) {
            state.outcomeCache.delete(key);
            return Promise.resolve(cachedOutcome);
        }

        return new Promise((resolve, reject) => {
            const timeoutId = window.setTimeout(() => {
                state.outcomeWaiters.delete(key);
                reject(new Error('Realtime media ingest forwarding outcome timed out.'));
            }, MEDIA_INGEST_FORWARD_TIMEOUT_MS);

            state.outcomeWaiters.set(key, { resolve, reject, timeoutId });
        });
    };

    const failPending = (error) => {
        state.pending.forEach((entry) => {
            if (entry?.timeoutId) {
                window.clearTimeout(entry.timeoutId);
            }

            entry?.reject?.(error);
        });
        state.pending.clear();

        state.queuedWaiters.forEach((entry) => {
            if (entry?.timeoutId) {
                window.clearTimeout(entry.timeoutId);
            }

            entry?.reject?.(error);
        });
        state.queuedWaiters.clear();
        state.queuedCache.clear();

        state.outcomeWaiters.forEach((entry) => {
            if (entry?.timeoutId) {
                window.clearTimeout(entry.timeoutId);
            }

            entry?.reject?.(error);
        });
        state.outcomeWaiters.clear();
        state.outcomeCache.clear();
    };

    const cleanupConnection = () => {
        state.joined = false;
        state.room = '';
        state.connectPromise = null;
        if (state.joinTimeoutId) {
            window.clearTimeout(state.joinTimeoutId);
            state.joinTimeoutId = null;
        }
    };

    const ensureConnected = async () => {
        if (!state.active) {
            throw new Error('Realtime media ingest transport is no longer active.');
        }

        if (state.client?.isOpen?.() && state.joined && state.room) {
            return { client: state.client, room: state.room };
        }

        if (state.connectPromise) {
            return state.connectPromise;
        }

        state.connectPromise = (async () => {
            const admission = await fetchJson('/api/realtime/admission/operator', {
                method: 'post',
                data: {
                    context_type: 'media_ingest',
                    context_id: callSessionId,
                },
            });

            const room = String(admission?.room ?? '').trim();

            if (!admission?.token || !admission?.websocket_url || !room) {
                throw new Error('Realtime media ingest admission is unavailable.');
            }

            return new Promise((resolve, reject) => {
                let settled = false;
                const settleResolve = () => {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    if (state.joinTimeoutId) {
                        window.clearTimeout(state.joinTimeoutId);
                        state.joinTimeoutId = null;
                    }

                    resolve({ client: state.client, room: state.room });
                };
                const settleReject = (error) => {
                    if (settled) {
                        return;
                    }

                    settled = true;
                    cleanupConnection();
                    reject(error);
                };

                const client = new RealtimeSocketClient({
                    websocketUrl: admission.websocket_url,
                    token: admission.token,
                    requestPrefix: `operator_media_ingest_${callSessionId}`,
                    onMessage(raw) {
                        let envelope;

                        try {
                            envelope = parseRealtimeEnvelope(raw);
                        } catch {
                            return;
                        }

                        if (envelope?.phase === 'ack' && envelope?.type === 'session.auth.request') {
                            client.sendRequest('room.join.request', room, buildRoomJoinPayload());
                            return;
                        }

                        if (
                            ['event', 'ack'].includes(String(envelope?.phase ?? ''))
                            && String(envelope?.type ?? '') === 'media.chunk.queued'
                            && String(envelope?.room ?? '') === room
                        ) {
                            resolveChunkQueued(envelope?.payload ?? {});
                            return;
                        }

                        if (
                            envelope?.phase === 'event'
                            && ['media.chunk.forwarded', 'media.chunk.failed'].includes(String(envelope?.type ?? ''))
                            && String(envelope?.room ?? '') === room
                        ) {
                            resolveChunkOutcome(String(envelope.type), envelope?.payload ?? {});
                            return;
                        }

                        if (
                            envelope?.phase === 'ack'
                            && envelope?.type === 'room.join.request'
                            && String(envelope?.room ?? '') === room
                        ) {
                            state.joined = true;
                            state.room = room;
                            settleResolve();
                            return;
                        }

                        const requestId = String(envelope?.id ?? '').trim();

                        if (!requestId || !state.pending.has(requestId)) {
                            return;
                        }

                        const pending = state.pending.get(requestId);
                        state.pending.delete(requestId);

                        if (pending?.timeoutId) {
                            window.clearTimeout(pending.timeoutId);
                        }

                        if (envelope?.phase === 'ack') {
                            pending?.resolve?.(envelope);
                            return;
                        }

                        if (envelope?.phase === 'error') {
                            pending?.reject?.(new Error(
                                String(envelope?.payload?.message ?? envelope?.type ?? 'Realtime media ingest request failed.')
                            ));
                        }
                    },
                    onClose() {
                        failPending(new Error('Realtime media ingest socket closed.'));
                        cleanupConnection();
                        if (!settled) {
                            settleReject(new Error('Realtime media ingest socket closed before room join.'));
                        }
                        if (state.client === client) {
                            state.client = null;
                        }
                    },
                    onError() {
                        if (!settled) {
                            settleReject(new Error('Realtime media ingest socket error.'));
                        }
                    },
                });

                state.client = client;
                client.connect();
                state.joinTimeoutId = window.setTimeout(() => {
                    settleReject(new Error('Realtime media ingest room join timed out.'));
                    client.close();
                }, 10000);
            });
        })().catch((error) => {
            cleanupConnection();
            if (state.client) {
                state.client.close();
                state.client = null;
            }
            throw error;
        });

        return state.connectPromise;
    };

    return {
        ready() {
            return ensureConnected();
        },
        async publishChunk(payload) {
            const runBase64Publish = async (nextPayload) => {
                const connection = await ensureConnected();
                const requestId = connection.client?.sendRequest(
                    'media.chunk.publish',
                    connection.room,
                    buildMediaChunkPublishPayload(nextPayload),
                );

                if (!requestId) {
                    throw new Error('Realtime media ingest socket is not open.');
                }

                return new Promise((resolve, reject) => {
                    const timeoutId = window.setTimeout(() => {
                        state.pending.delete(requestId);
                        reject(new Error('Realtime media ingest acknowledgement timed out.'));
                    }, MEDIA_INGEST_ACK_TIMEOUT_MS);

                    state.pending.set(requestId, { resolve, reject, timeoutId });
                });
            };

            const runBinaryPublish = async (nextPayload) => {
                const connection = await ensureConnected();
                const requestId = connection.client?.sendRequest(
                    'media.chunk.prepare',
                    connection.room,
                    buildMediaChunkPreparePayload(nextPayload),
                );

                if (!requestId) {
                    throw new Error('Realtime media ingest socket is not open.');
                }

                await new Promise((resolve, reject) => {
                    const timeoutId = window.setTimeout(() => {
                        state.pending.delete(requestId);
                        reject(new Error('Realtime binary media prepare acknowledgement timed out.'));
                    }, MEDIA_INGEST_ACK_TIMEOUT_MS);

                    state.pending.set(requestId, { resolve, reject, timeoutId });
                });

                const frame = await buildBinaryMediaChunkFrame(nextPayload.transfer_id, nextPayload.chunk_blob);
                const sent = connection.client?.sendRaw?.(frame);

                if (!sent) {
                    throw new Error('Realtime binary media frame could not be sent.');
                }

                await waitForChunkQueued(nextPayload);
            };

            const queuedPublish = state.publishQueue
                .catch(() => undefined)
                .then(async () => {
                    const nextPayload = mode === 'realtime-binary'
                        ? ensureBinaryChunkPayload(payload)
                        : await ensureBase64ChunkPayload(payload);

                    if (mode === 'realtime-binary') {
                        await runBinaryPublish(nextPayload);
                    } else {
                        await runBase64Publish(nextPayload);
                    }

                    const outcome = await waitForChunkOutcome(nextPayload);

                    if (outcome?.eventType === 'media.chunk.forwarded') {
                        return outcome;
                    }

                    throw new Error(
                        String(
                            outcome?.payload?.message
                            ?? outcome?.payload?.code
                            ?? 'Realtime media ingest forwarding failed.'
                        )
                    );
                });

            state.publishQueue = queuedPublish.catch(() => undefined);

            return queuedPublish;
        },
        destroy() {
            state.active = false;
            failPending(new Error('Realtime media ingest transport closed.'));
            cleanupConnection();
            state.client?.close?.();
            state.client = null;
        },
    };
}

export function createRealtimeOperatorMediaChunkTransport({ mode = 'realtime-base64' } = {}) {
    const ingestClients = new Map();

    const ingestClient = (callSessionId) => {
        const nextCallSessionId = Number(callSessionId ?? 0);

        if (nextCallSessionId <= 0) {
            return null;
        }

        if (!ingestClients.has(nextCallSessionId)) {
            ingestClients.set(nextCallSessionId, createRealtimeMediaIngestClient({
                callSessionId: nextCallSessionId,
                mode,
            }));
        }

        return ingestClients.get(nextCallSessionId) ?? null;
    };

    return {
        async publishChunk(payload, record) {
            const client = ingestClient(record?.call_session_id ?? payload?.call_session_id ?? 0);

            if (!client) {
                throw new Error('Realtime media ingest client is unavailable.');
            }

            await client.ready();
            await client.publishChunk(payload);
        },
        destroy(callSessionId) {
            const nextCallSessionId = Number(callSessionId ?? 0);
            ingestClients.get(nextCallSessionId)?.destroy?.();
            ingestClients.delete(nextCallSessionId);
        },
        destroyAll() {
            ingestClients.forEach((client) => client?.destroy?.());
            ingestClients.clear();
        },
    };
}
