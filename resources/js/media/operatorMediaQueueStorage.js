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
    const markerKey = 'hotline-operator-media-recovery-v1';
    const localOwners = globalThis[Symbol.for('hotline.operatorMediaQueue.owners')] ??= new Set();
    const ownerToken = {};
    let ownsQueue = false, ownershipPromise, releaseLock;
    const readMarker = () => {
        const raw = localStorage.getItem(markerKey);
        if (raw === null) return null;
        const marker = JSON.parse(raw);
        if (marker?.version !== 1 || typeof marker.generation !== 'string' || !marker.generation) throw new Error('Recording recovery metadata is malformed. Keep this page open and contact support.');
        return marker;
    };
    const ensureOwnership = () => {
        if (ownsQueue) return Promise.resolve();
        if (ownershipPromise) return ownershipPromise;
        ownershipPromise = new Promise((resolve, reject) => {
            if (!globalThis.navigator?.locks) { reject(new Error('Recording queue ownership is unavailable in this browser.')); return; }
            void navigator.locks.request('hotline-operator-media-queue-owner-v1', {ifAvailable:true}, async lock => {
                if (!lock) { const error = new Error('Another Hotline tab owns recording storage. Use that tab; do not restart an active call.'); error.recordingStorageStage='queue-ownership'; reject(error); return; }
                ownsQueue = true;
                localOwners.add(ownerToken);
                const lifetime = new Promise(release => { releaseLock = release; });
                resolve();
                await lifetime;
                ownsQueue = false;
                localOwners.delete(ownerToken);
                ownershipPromise = null;
            }).catch(reject);
        }).catch(error => { ownershipPromise = null; throw error; });
        return ownershipPromise;
    };
    const markFailure = () => {
        // Only the exclusive owner may create/retire safety metadata. It is never media storage.
        if (!ownsQueue) return false;
        readMarker(); // Never overwrite malformed or unreadable safety state.
        const marker = {version:1, generation:crypto.randomUUID()};
        const raw = JSON.stringify(marker);
        localStorage.setItem(markerKey, raw);
        if (localStorage.getItem(markerKey) !== raw) throw new Error('Recording recovery marker could not be verified. Keep this page open; cross-reload safety is unverified.');
        return true;
    };

    const putRecord = (record) => db.put(OPERATOR_MEDIA_RECORDS_STORE, record);

    const listRecords = () => db.getAll(OPERATOR_MEDIA_RECORDS_STORE);

    const listChunks = async (mediaId) => {
        const chunks = await db.getAllFromIndex(OPERATOR_MEDIA_CHUNKS_STORE, 'by_media', Number(mediaId));
        return chunks.sort((left, right) => Number(left?.chunk_index ?? 0) - Number(right?.chunk_index ?? 0));
    };

    // Only an actual marked failure/retry fences retained records.
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
        markFailure,
        getOwnershipStatus() { return { ownedByThisQueue: ownsQueue, localPageOwnerCount: localOwners.size }; },
        async prepareStartup() {
            await ensureOwnership();
            if (readMarker()) {
                await fenceRetainedRecords();
                const error = new Error('A previous recording storage failure requires explicit verification. Saved media remains paused.');
                error.recordingStorageStage = 'recovery-required';
                throw error;
            }
        },
        releaseOwnership() { releaseLock?.(); releaseLock = null; },
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
            let stage = "queue-ownership";
            try {
            await ensureOwnership();
            stage = 'read-recovery-marker';
            if (!readMarker()) markFailure();
            const marker = readMarker();
            stage = 'open-original-queue';
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
            stage = 'retire-recovery-marker';
            if (readMarker()?.generation !== marker.generation) throw new Error('A newer recording failure requires another verification.');
            localStorage.removeItem(markerKey);
            if (localStorage.getItem(markerKey) !== null) throw new Error('Recording recovery marker remains active.');
            return { records: pendingRecords };
            } catch (error) {
                error.recordingStorageStage ??= stage;
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
