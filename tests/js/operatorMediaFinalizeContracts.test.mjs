import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { Consumer } from '../../resources/js/media/consumer.js';
import { ConsumerManager } from '../../resources/js/media/operatorMediaManagers.js';

class MemoryMediaStorage {
    constructor() {
        this.records = new Map();
        this.chunks = new Map();
    }

    async getRecord(mediaId) {
        return this.records.get(Number(mediaId)) ?? null;
    }

    async putRecord(record) {
        this.records.set(Number(record.media_id), record);
    }

    async deleteChunk(mediaId, chunkIndex) {
        const mediaChunks = this.chunks.get(Number(mediaId)) ?? [];
        this.chunks.set(
            Number(mediaId),
            mediaChunks.filter((chunk) => Number(chunk?.chunk_index ?? 0) !== Number(chunkIndex)),
        );
    }

    async updateChunkMeta() {}

    async listRecords() {
        return [...this.records.values()];
    }

    async listChunks(mediaId) {
        return [...(this.chunks.get(Number(mediaId)) ?? [])];
    }

    async deleteChunksFor(mediaId) {
        this.chunks.delete(Number(mediaId));
    }

    async deleteRecord(mediaId) {
        this.records.delete(Number(mediaId));
    }

    async closeOpenRecords() {}

    seed(record, chunks = []) {
        this.records.set(Number(record.media_id), record);
        this.chunks.set(Number(record.media_id), chunks);
    }
}

const finalizerSource = await readFile(new URL('../../resources/js/media/finalizers/operatorMediaFinalizer.js', import.meta.url), 'utf8');

assert.match(finalizerSource, /const response = await window\.axios\(/);
assert.doesNotMatch(finalizerSource, /\.catch\(\(\) => undefined\)/);

const failedStorage = new MemoryMediaStorage();
failedStorage.seed({
    media_id: 17,
    status: 'closed',
}, [{
    chunk_key: '17:0',
    media_id: 17,
    chunk_index: 0,
    payload: { media_id: 17, chunk_index: 0 },
}]);

const failedConsumer = new Consumer({
    storage: failedStorage,
    record: { media_id: 17, status: 'closed' },
    finalizer: {
        async finalizeRecord() {
            throw new Error('Finalize rejected');
        },
    },
});

await assert.rejects(
    () => failedConsumer.finalizeAndDelete({ media_id: 17, status: 'closed' }),
    /Finalize rejected/,
);
assert.ok(await failedStorage.getRecord(17));
assert.equal((await failedStorage.listChunks(17)).length, 1);

const successStorage = new MemoryMediaStorage();
successStorage.seed({ media_id: 18, status: 'closed' }, []);

const successConsumer = new Consumer({
    storage: successStorage,
    record: { media_id: 18, status: 'closed' },
    finalizer: {
        async finalizeRecord() {
            return { ok: true, media: { discarded: true } };
        },
    },
});

await successConsumer.finalizeAndDelete({ media_id: 18, status: 'closed' });
assert.equal(await successStorage.getRecord(18), null);
assert.equal(successConsumer.getItem().state, 'discarded');

const drainStorage = new MemoryMediaStorage();
drainStorage.seed({ media_id: 19, status: 'closed' }, [{
    chunk_key: '19:1',
    media_id: 19,
    chunk_index: 1,
    payload: { media_id: 19, chunk_index: 1, chunk_blob: new Blob(['tail']) },
}]);
let drainFinalized = false;
let drainFinalChunks = [];

const consumerManager = new ConsumerManager({
    storage: drainStorage,
    enabled: true,
    pollMs: 250,
    finalizer: {
        async finalizeRecord(record, options = {}) {
            drainFinalized = true;
            drainFinalChunks = options.finalChunks ?? [];
            return { ok: true, media: { id: 19 } };
        },
    },
});

await consumerManager.drain({ maxPasses: 2, delayMs: 50 });
assert.equal(drainFinalized, true);
assert.equal(drainFinalChunks.length, 1);
assert.equal(drainFinalChunks[0].chunk_index, 1);
assert.equal((await drainStorage.listRecords()).length, 0);

const managerSource = await readFile(new URL('../../resources/js/media/operatorMediaManagers.js', import.meta.url), 'utf8');
const operatorFactorySource = await readFile(new URL('../../resources/js/media/operator.js', import.meta.url), 'utf8');
const operatorSurfaceSource = await readFile(new URL('../../resources/js/surfaces/operatorSurface.js', import.meta.url), 'utf8');
const finalizerSourceNext = await readFile(new URL('../../resources/js/media/finalizers/operatorMediaFinalizer.js', import.meta.url), 'utf8');
const consumerSource = await readFile(new URL('../../resources/js/media/consumer.js', import.meta.url), 'utf8');

assert.match(managerSource, /async drain\(\{ maxPasses = 20, delayMs = 250 \} = \{\}\)/);
assert.match(operatorFactorySource, /drainConsumers\(options = \{\}\) \{\s+return consumerManager\.drain\(options\);/);
assert.match(operatorSurfaceSource, /void mediaManagers\.scanConsumers\?\.\(\);/);
assert.doesNotMatch(operatorSurfaceSource, /onMediaFinalized\(media\)/);
assert.doesNotMatch(operatorSurfaceSource, /await mediaManagers\.drainConsumers/);
assert.doesNotMatch(operatorSurfaceSource, /createOperatorMediaBatchChunkTransport/);
assert.doesNotMatch(operatorFactorySource, /flushChunks/);
assert.doesNotMatch(consumerSource, /flushChunksAndFinalize/);
assert.match(consumerSource, /finalizeAndDelete\(record, \{ finalChunks: chunks \}\)/);
assert.match(finalizerSourceNext, /final_chunks\[\$\{index\}\]\[chunk_index\]/);
assert.match(finalizerSourceNext, /final_chunks\[\$\{index\}\]\[chunk\]/);
