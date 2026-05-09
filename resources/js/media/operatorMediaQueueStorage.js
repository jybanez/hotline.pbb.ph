import { createIndexedDbStore } from '../storage/indexedDbStore.js';

const OPERATOR_MEDIA_QUEUE_DB_NAME = 'hotline-operator-media-queue';
const OPERATOR_MEDIA_QUEUE_DB_VERSION = 1;
const OPERATOR_MEDIA_RECORDS_STORE = 'media_records';
const OPERATOR_MEDIA_CHUNKS_STORE = 'media_chunks';

function createMediaQueueDb() {
    return createIndexedDbStore({
        name: OPERATOR_MEDIA_QUEUE_DB_NAME,
        version: OPERATOR_MEDIA_QUEUE_DB_VERSION,
        unavailableMessage: 'IndexedDB is unavailable.',
        openErrorMessage: 'Unable to open operator media queue database.',
        blockedMessage: 'Operator media queue database open is blocked.',
        upgrade(db) {
            if (!db.objectStoreNames.contains(OPERATOR_MEDIA_RECORDS_STORE)) {
                const records = db.createObjectStore(OPERATOR_MEDIA_RECORDS_STORE, {
                    keyPath: 'media_id',
                });
                records.createIndex('by_status', 'status', { unique: false });
                records.createIndex('by_call_session', 'call_session_id', { unique: false });
            }

            if (!db.objectStoreNames.contains(OPERATOR_MEDIA_CHUNKS_STORE)) {
                const chunks = db.createObjectStore(OPERATOR_MEDIA_CHUNKS_STORE, {
                    keyPath: 'chunk_key',
                });
                chunks.createIndex('by_media', 'media_id', { unique: false });
                chunks.createIndex('by_call_session', 'call_session_id', { unique: false });
            }
        },
    });
}

export function createOperatorMediaQueueStorage() {
    const db = createMediaQueueDb();

    const putRecord = (record) => db.put(OPERATOR_MEDIA_RECORDS_STORE, record);

    const listRecords = () => db.getAll(OPERATOR_MEDIA_RECORDS_STORE);

    const listChunks = async (mediaId) => {
        const chunks = await db.getAllFromIndex(OPERATOR_MEDIA_CHUNKS_STORE, 'by_media', Number(mediaId));
        return chunks.sort((left, right) => Number(left?.chunk_index ?? 0) - Number(right?.chunk_index ?? 0));
    };

    return {
        putRecord,
        async getRecord(mediaId) {
            return (await db.get(OPERATOR_MEDIA_RECORDS_STORE, Number(mediaId))) ?? null;
        },
        listRecords,
        deleteRecord(mediaId) {
            return db.delete(OPERATOR_MEDIA_RECORDS_STORE, Number(mediaId));
        },
        putChunk(payload) {
            const mediaId = Number(payload?.media_id ?? 0);
            const chunkIndex = Number(payload?.chunk_index ?? 0);

            if (mediaId <= 0) {
                throw new Error('Chunk payload is missing media_id.');
            }

            return db.put(OPERATOR_MEDIA_CHUNKS_STORE, {
                chunk_key: `${mediaId}:${chunkIndex}`,
                media_id: mediaId,
                call_session_id: Number(payload?.call_session_id ?? 0),
                chunk_index: chunkIndex,
                payload,
                created_at: new Date().toISOString(),
            });
        },
        listChunks,
        async updateChunkMeta(mediaId, chunkIndex, updates = {}) {
            const chunkKey = `${Number(mediaId)}:${Number(chunkIndex)}`;

            await db.transaction(OPERATOR_MEDIA_CHUNKS_STORE, 'readwrite', async (store) => {
                const existing = await db.requestToPromise(store.get(chunkKey));

                if (!existing) {
                    return;
                }

                await db.requestToPromise(store.put({
                    ...existing,
                    ...updates,
                }));
            });
        },
        deleteChunk(mediaId, chunkIndex) {
            return db.delete(OPERATOR_MEDIA_CHUNKS_STORE, `${Number(mediaId)}:${Number(chunkIndex)}`);
        },
        async deleteChunksFor(mediaId) {
            const chunks = await listChunks(mediaId);

            if (!chunks.length) {
                return;
            }

            await db.transaction(OPERATOR_MEDIA_CHUNKS_STORE, 'readwrite', async (store) => {
                for (const chunk of chunks) {
                    await db.requestToPromise(store.delete(String(chunk?.chunk_key ?? '')));
                }
            });
        },
        async closeOpenRecords() {
            const records = await listRecords();
            const openRecords = records.filter((record) => ['open', 'closing'].includes(String(record?.status ?? '')));

            await Promise.all(openRecords.map((record) => putRecord({
                ...record,
                status: 'closed',
                updated_at: new Date().toISOString(),
            })));
        },
    };
}
