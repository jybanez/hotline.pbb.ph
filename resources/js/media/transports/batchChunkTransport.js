import { fetchJson } from '../../surfaces/surfaceShared.js';

const DEFAULT_COMPOSITE_UPLOAD_MAX_BYTES = 1536 * 1024;

function base64MediaChunkToBlob(chunkData, mimeType = '') {
    const encoded = String(chunkData ?? '').replace(/^data:[^;]+;base64,/, '').trim();

    if (!encoded) {
        throw new Error('Media chunk payload is empty.');
    }

    const binary = window.atob(encoded);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return new Blob([bytes], {
        type: String(mimeType ?? '').trim() || 'application/octet-stream',
    });
}

function resolveMediaChunkBlob(payload = {}) {
    if (payload?.chunk_blob instanceof Blob) {
        return payload.chunk_blob;
    }

    return base64MediaChunkToBlob(payload?.chunk_data, payload?.mime_type);
}

function chunkFilename(payload = {}, chunkIndex = 0) {
    const extension = String(payload?.extension ?? 'webm').trim() || 'webm';
    return `composite-${String(Number(chunkIndex)).padStart(6, '0')}.${extension}`;
}

function buildCompositeGroups(chunks = [], maxBytes = DEFAULT_COMPOSITE_UPLOAD_MAX_BYTES) {
    const groups = [];
    let current = [];
    let currentBytes = 0;

    for (const chunk of chunks) {
        const payload = chunk?.payload ?? null;

        if (!payload) {
            continue;
        }

        const blob = resolveMediaChunkBlob(payload);
        const chunkIndex = Number(chunk?.chunk_index ?? payload?.chunk_index ?? 0);
        const item = {
            chunk,
            payload,
            blob,
            chunkIndex,
        };

        if (current.length > 0 && currentBytes + blob.size > maxBytes) {
            groups.push(current);
            current = [];
            currentBytes = 0;
        }

        current.push(item);
        currentBytes += blob.size;
    }

    if (current.length > 0) {
        groups.push(current);
    }

    return groups;
}

export function createOperatorMediaBatchChunkTransport({ maxCompositeBytes = DEFAULT_COMPOSITE_UPLOAD_MAX_BYTES } = {}) {
    const uploadMaxBytes = Math.max(256 * 1024, Number(maxCompositeBytes ?? DEFAULT_COMPOSITE_UPLOAD_MAX_BYTES) || DEFAULT_COMPOSITE_UPLOAD_MAX_BYTES);

    return {
        async flushChunks(record, chunks = []) {
            const mediaId = Number(record?.media_id ?? 0);

            if (mediaId <= 0) {
                throw new Error('Composite media chunk upload requires media_id.');
            }

            const orderedChunks = (Array.isArray(chunks) ? chunks : [])
                .filter((chunk) => chunk?.payload)
                .sort((left, right) => Number(left?.chunk_index ?? 0) - Number(right?.chunk_index ?? 0));
            const groups = buildCompositeGroups(orderedChunks, uploadMaxBytes);

            for (const group of groups) {
                const first = group.at(0);
                const firstChunkIndex = Number(first?.chunkIndex ?? 0);
                const firstPayload = first?.payload ?? {};
                const compositeBlob = new Blob(
                    group.map((item) => item.blob),
                    {
                        type: String(firstPayload?.mime_type ?? record?.mime_type ?? '').trim() || 'application/octet-stream',
                    },
                );
                const formData = new FormData();

                formData.append('chunk', compositeBlob, chunkFilename(firstPayload, firstChunkIndex));
                formData.append('chunk_index', String(firstChunkIndex));

                try {
                    await fetchJson(`/api/operator/media/${mediaId}/chunks`, {
                        method: 'post',
                        data: formData,
                    });
                } catch (error) {
                    const status = Number(error?.response?.status ?? 0);
                    const response = error?.response?.data && typeof error.response.data === 'object'
                        ? error.response.data
                        : {};
                    const message = String(response?.message ?? error?.message ?? 'Composite media chunk upload failed.');
                    const details = response?.errors && typeof response.errors === 'object'
                        ? ` ${JSON.stringify(response.errors)}`
                        : '';

                    throw new Error(`Composite media chunk upload failed${status ? ` (${status})` : ''}: ${message}${details}`);
                }
            }
        },
    };
}
