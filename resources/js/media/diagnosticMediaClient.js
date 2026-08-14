function appendFormChunk(formData, chunk, extension) {
    const blob = chunk?.chunk_blob ?? chunk?.blob;

    if (!(blob instanceof Blob)) {
        throw new Error('Diagnostic chunk upload requires a Blob.');
    }

    formData.append('chunk', blob, `diagnostic-${String(chunk?.chunk_index ?? 0).padStart(6, '0')}.${extension || 'weba'}`);
    formData.append('chunk_index', String(Number(chunk?.chunk_index ?? 0)));
}

async function defaultRequest(url, options = {}) {
    if (typeof globalThis.window?.axios !== 'function') {
        throw new Error('HTTP client is unavailable.');
    }

    const response = await globalThis.window.axios({
        url,
        method: options.method ?? 'get',
        data: options.data,
        headers: {
            Accept: 'application/json',
            ...(options.headers ?? {}),
        },
    });

    return response?.data ?? null;
}

export function createDiagnosticMediaClient(options = {}) {
    const request = options.request ?? defaultRequest;

    return {
        async createSession(payload = {}) {
            return request('/api/operator/media-tests', {
                method: 'post',
                data: {
                    mime_type: payload.mime_type ?? '',
                    extension: payload.extension ?? '',
                    track_kind: payload.track_kind ?? 'audio',
                    segment_key: payload.segment_key ?? `diagnostic-${Date.now()}`,
                    started_at: payload.started_at ?? new Date().toISOString(),
                    metadata: {
                        browser_user_agent: globalThis.navigator?.userAgent ?? null,
                    },
                },
            });
        },
        async uploadChunk(mediaId, chunk = {}) {
            const nextMediaId = Number(mediaId ?? 0);

            if (nextMediaId <= 0) {
                throw new Error('Diagnostic chunk upload requires media_id.');
            }

            const extension = String(chunk?.extension ?? 'weba').trim() || 'weba';
            const formData = new FormData();
            appendFormChunk(formData, chunk, extension);

            return request(`/api/operator/media-tests/${nextMediaId}/chunks`, {
                method: 'post',
                data: formData,
            });
        },
        async finalize(mediaId, payload = {}) {
            const nextMediaId = Number(mediaId ?? 0);

            if (nextMediaId <= 0) {
                throw new Error('Diagnostic finalize requires media_id.');
            }

            return request(`/api/operator/media-tests/${nextMediaId}/finalize`, {
                method: 'post',
                data: {
                    duration_seconds: Number(payload.duration_seconds ?? 0),
                    ended_at: payload.ended_at ?? new Date().toISOString(),
                    extension: payload.extension ?? '',
                },
            });
        },
        async cancel(mediaId, payload = {}) {
            const nextMediaId = Number(mediaId ?? 0);

            if (nextMediaId <= 0) {
                throw new Error('Diagnostic cancel requires media_id.');
            }

            return request(`/api/operator/media-tests/${nextMediaId}`, {
                method: 'delete',
                data: {
                    reason: payload.reason ?? 'operator_cancelled',
                },
            });
        },
    };
}

export async function finalizeStoppedDiagnosticRecording({
    session,
    client,
    media,
    record = {},
    recordingStartedAt,
    now = () => Date.now(),
} = {}) {
    if (!session || !client || !media?.id) {
        throw new Error('Diagnostic finalize requires an active media session.');
    }

    const endedAtMs = now();
    const durationSeconds = recordingStartedAt
        ? Math.max(0, Math.floor((endedAtMs - recordingStartedAt) / 1000))
        : 0;
    const stopped = await session.stop();
    const finalized = await client.finalize(media.id, {
        duration_seconds: durationSeconds,
        ended_at: new Date(endedAtMs).toISOString(),
        extension: record?.extension ?? '',
    });

    return { stopped, finalized };
}
