import { fetchJson } from '../../surfaces/surfaceShared.js';

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

export function createOperatorMediaBootstrapTransport() {
    return {
        async publishChunk(payload, record) {
            const nextMediaId = Number(record?.media_id ?? payload?.media_id ?? 0);
            const chunkIndex = Number(payload?.chunk_index ?? 0);

            if (nextMediaId <= 0) {
                throw new Error('Bootstrap media chunk upload requires media_id.');
            }

            if (chunkIndex !== 0) {
                throw new Error('Only chunk_index 0 can use the direct bootstrap upload path.');
            }

            const formData = new FormData();
            const extension = String(payload?.extension ?? record?.extension ?? 'webm').trim() || 'webm';
            const chunkBlob = resolveMediaChunkBlob(payload);

            formData.append('chunk', chunkBlob, `bootstrap-000000.${extension}`);
            formData.append('chunk_index', '0');

            await fetchJson(`/api/operator/media/${nextMediaId}/chunks`, {
                method: 'post',
                data: formData,
            });
        },
    };
}
