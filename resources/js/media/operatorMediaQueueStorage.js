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

    // A fresh manager fences every retained record before exposing any consumer.
    // Put all holds in one transaction: a partial hold write cannot commit.
    const fenceRetainedRecords = async () => {
        const records = await listRecords();
        const pending = records.filter(record => Number.isFinite(Number(record.media_id)) && Number(record.media_id) > 0);
        await db.transaction(OPERATOR_MEDIA_RECORDS_STORE, 'readwrite', async store => {
            for (const record of pending) {
                const key = `__hotline_recovery_hold__:${record.media_id}`;
                await db.requestToPromise(store.put({media_id: key, status: 'recovery-hold', held_media_id: Number(record.media_id)}));
            }
        });
        return pending;
    };

    return {
        putRecord,
        fenceRetainedRecords,
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
        async verifyHealth() {
            let stage = "open-original-queue";
            try {
            db.close();
            await db.open({ requireExisting: true });
            stage = "fence-retained-records";
            await fenceRetainedRecords();
            stage = "read-original-records";
            const records = await listRecords();
            stage = "read-original-chunks";
            const savedChunks = await db.getAll(OPERATOR_MEDIA_CHUNKS_STORE);
            const key = `__hotline_health__${crypto.randomUUID()}`;
            const probe = { media_id: key, status: 'health-check', token: key };
            const chunkProbe = { chunk_key: key, media_id: 0, token: key };
            // Each write must commit before a separate transaction reads it.
            // Unique reserved keys never overwrite queue items or replay a failed write.
            stage = "write-record-probe";
            await db.put(OPERATOR_MEDIA_RECORDS_STORE, probe);
            stage = "write-chunk-probe";
            await db.put(OPERATOR_MEDIA_CHUNKS_STORE, chunkProbe);
            stage = "read-record-probe";
            const readRecord = await db.get(OPERATOR_MEDIA_RECORDS_STORE, key);
            stage = "read-chunk-probe";
            const readChunk = await db.get(OPERATOR_MEDIA_CHUNKS_STORE, key);
            if (readRecord?.token !== key || readChunk?.token !== key) throw new Error('Recording storage verification failed.');
            stage = "delete-record-probe";
            await db.delete(OPERATOR_MEDIA_RECORDS_STORE, key);
            stage = "delete-chunk-probe";
            await db.delete(OPERATOR_MEDIA_CHUNKS_STORE, key);
            stage = "verify-original-records";
            const afterRecords = await listRecords();
            stage = "verify-original-chunks";
            const afterChunks = await db.getAll(OPERATOR_MEDIA_CHUNKS_STORE);
            if (records.some(record => !afterRecords.some(item => item.media_id === record.media_id))
                || savedChunks.some(chunk => !afterChunks.some(item => item.chunk_key === chunk.chunk_key))) {
                throw new Error('The saved recording queue changed during verification. Keep uploads paused for review.');
            }
            const pendingRecords = afterRecords.filter(record => Number(record.media_id) > 0);
            return { records: pendingRecords };
            } catch (error) {
                error.recordingStorageStage = stage;
                throw error;
            }
        },
        async closeOpenRecords() {
            const records = await listRecords();
            const heldIds = new Set(records.filter(record => record.status === 'recovery-hold').map(record => Number(record.held_media_id)));
            const openRecords = records.filter((record) => !heldIds.has(Number(record.media_id)) && ['open', 'closing'].includes(String(record?.status ?? '')));

            await Promise.all(openRecords.map((record) => putRecord({
                ...record,
                status: 'closed',
                updated_at: new Date().toISOString(),
            })));
        },
    };
}
