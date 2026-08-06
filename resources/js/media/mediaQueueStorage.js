import { createOperatorMediaQueueStorage } from './operatorMediaQueueStorage.js';

export function createMediaQueueStorage(options = {}) {
    const storage = createOperatorMediaQueueStorage(options);

    return {
        ...storage,
        async enqueueDiagnosticChunk(record, chunk) {
            const mediaId = Number(record?.media_id ?? 0);
            const chunkIndex = Number(chunk?.chunk_index ?? 0);

            if (mediaId <= 0) {
                throw new Error('Diagnostic media queue requires media_id.');
            }

            await storage.putChunk({
                media_id: mediaId,
                call_session_id: 0,
                chunk_index: chunkIndex,
                chunk_blob: chunk.blob,
                mime_type: chunk.mime_type,
                extension: chunk.extension,
                status: 'queued',
                created_at: new Date().toISOString(),
            });

            return storage.listChunks(mediaId);
        },
        async clearDiagnosticMedia(mediaId) {
            const nextMediaId = Number(mediaId ?? 0);

            if (nextMediaId <= 0) {
                return;
            }

            await storage.deleteChunksFor(nextMediaId);
            await storage.deleteRecord(nextMediaId);
        },
    };
}
