import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { Consumer } from '../../resources/js/media/consumer.js';

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

    async listChunks(mediaId) {
        return [...(this.chunks.get(Number(mediaId)) ?? [])];
    }

    async deleteChunksFor(mediaId) {
        this.chunks.delete(Number(mediaId));
    }

    async deleteRecord(mediaId) {
        this.records.delete(Number(mediaId));
    }

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
