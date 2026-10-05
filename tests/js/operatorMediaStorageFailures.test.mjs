import assert from 'node:assert/strict';
import { createOperatorMediaManagers } from '../../resources/js/media/operator.js';
import { ConsumerManager } from '../../resources/js/media/operatorMediaManagers.js';
import { readFile } from 'node:fs/promises';

const unhandled = [];
process.on('unhandledRejection', (error) => unhandled.push(error));
const settle = () => new Promise((resolve) => setImmediate(resolve));
const timers = new Map();
let timerId = 0;
globalThis.window = {
    setInterval(callback) { timers.set(++timerId, callback); return timerId; },
    clearInterval(id) { timers.delete(id); },
    setTimeout,
};
const internalError = () => new DOMException('Internal error opening backing store', 'InternalError');
let opens = 0;
let deletions = 0;
globalThis.indexedDB = {
    open() {
        opens++;
        const request = new EventTarget();
        request.error = internalError();
        queueMicrotask(() => request.dispatchEvent(new Event('error')));
        return request;
    },
    deleteDatabase() { deletions++; throw new Error('Must not delete queue'); },
};

// Exercise the actual IndexedDB adapter, including failure before UI hooks install.
const unavailable = createOperatorMediaManagers({ enabled: true });
await settle();
let notices = 0;
unavailable.setHooks({ onError: () => { notices++; } });
void unavailable.start();
unavailable.setConsumerEnabled(false);
unavailable.setConsumerEnabled(true);
void unavailable.scanConsumers();
await settle();
assert.equal(notices, 1);
assert.equal(unavailable.getStatus().consumer.storageAvailable, false);
assert.equal(unavailable.getStatus().consumer.started, false);
assert.match(unavailable.getStatus().consumer.lastError, /backing store/);
assert.equal(await unavailable.drainConsumers(), false);
await assert.rejects(unavailable.producerManager.create({}, { media_id: 1 }), { name: 'InternalError' });
assert.equal(opens, 1, 'failure does not trigger automatic storage replay');
assert.equal(deletions, 0);
assert.equal(timers.size, 0);

function fixture() {
    const records = new Map();
    const chunks = new Map();
    const mutations = [];
    let failAt = '';
    const check = (name) => { if (name === failAt) throw internalError(); };
    const storage = {
        async closeOpenRecords() { check('initialize'); },
        async listRecords() { check('listRecords'); return [...records.values()]; },
        async getRecord(id) { check('getRecord'); return records.get(id); },
        async putRecord(record) { check('putRecord'); mutations.push('putRecord'); records.set(record.media_id, record); },
        async listChunks(id) { check('listChunks'); return chunks.get(id) ?? []; },
        async putChunk(chunk) { check('putChunk'); mutations.push('putChunk'); chunks.set(chunk.media_id, [...(chunks.get(chunk.media_id) ?? []), { payload: chunk, ...chunk }]); },
        async deleteRecord(id) { mutations.push('deleteRecord'); records.delete(id); },
        async deleteChunksFor(id) { mutations.push('deleteChunksFor'); chunks.delete(id); },
        async deleteChunk() { mutations.push('deleteChunk'); },
        async updateChunkMeta() { mutations.push('updateChunkMeta'); },
    };
    return { storage, records, chunks, mutations, fail(name) { failAt = name; } };
}

// Initial and interval scans handle errors, stop polling, retain pending media.
for (const operation of ['listRecords', 'getRecord', 'listChunks']) {
    const f = fixture();
    f.records.set(12, { media_id: 12, status: 'open' });
    f.chunks.set(12, [{ chunk_index: 0, payload: { media_id: 12 } }]);
    let published = 0;
    let finalized = 0;
    let notified = 0;
    const managers = createOperatorMediaManagers({ storage: f.storage, enabled: true,
        publishBootstrapChunk: async () => { published++; },
        finalizeRecord: async () => { finalized++; },
    });
    managers.setHooks({ onError: () => { notified++; } });
    // Start healthy then fail the timer scan. Hook exceptions/rejections cannot leak.
    await managers.start();
    await settle();
    const mutationsBeforeFailure = [...f.mutations];
    f.fail(operation);
    for (const callback of [...timers.values()]) callback();
    await settle();
    await settle();
    assert.equal(managers.getStatus().consumer.storageAvailable, false, operation);
    assert.equal(managers.getStatus().consumer.started, false);
    assert.equal(notified, 1);
    assert.equal(f.records.size, 1);
    assert.equal(f.chunks.get(12).length, 1);
    assert.equal(finalized, 0);
    // The healthy initial scan's upload behavior is irrelevant; failure never discards.
    const before = published;
    assert.equal(await managers.scanConsumers(), false);
    assert.equal(published, before);
    assert.deepEqual(f.mutations, mutationsBeforeFailure, 'failed scans never mutate or discard saved media');
}

// Concurrent starts install one timer; the first scan failure is contained too.
const initialScan = fixture();
initialScan.fail('listRecords');
const initialScanManagers = createOperatorMediaManagers({ storage: initialScan.storage, enabled: true });
await Promise.all([initialScanManagers.start(), initialScanManagers.start()]);
await settle();
assert.equal(initialScanManagers.getStatus().consumer.started, false);
assert.equal(timers.size, 0);
const concurrent = createOperatorMediaManagers({ storage: fixture().storage, enabled: true });
await Promise.all([concurrent.start(), concurrent.start()]);
assert.equal(timers.size, 1);
concurrent.stop();
assert.equal(timers.size, 0);

// Producer readiness cannot create a recording after failed initialization.
const init = fixture();
init.fail('initialize');
const initManagers = createOperatorMediaManagers({ storage: init.storage });
await assert.rejects(initManagers.producerManager.create({}, { media_id: 13 }), { name: 'InternalError' });
assert.equal(init.mutations.length, 0);

// Mid-recording durable-write failure stops clones, never primes/finalizes/deletes.
const f = fixture();
const managers = createOperatorMediaManagers({ storage: f.storage });
let primed = 0;
let cloneStops = 0;
const recorder = new EventTarget();
recorder.state = 'inactive';
recorder.start = () => { recorder.state = 'recording'; };
recorder.stop = () => { recorder.state = 'inactive'; recorder.dispatchEvent(new Event('stop')); };
const producer = await managers.producerManager.create(recorder, { media_id: 14, status: 'open', extension: 'weba', started_at: new Date().toISOString() }, {
    state: {}, captureReadyAt: Date.now(), showToast() {}, onRecorderPrimed() { primed++; },
    clonedTracks: [{ stop() { cloneStops++; } }],
});
assert.equal(producer.start(), true);
f.chunks.set(14, [{ chunk_index: 0, payload: { media_id: 14 } }]);
f.fail('putChunk');
const warn = console.warn;
console.warn = () => {}; // Expected injected persistence failure.
producer.addChunk({ media_id: 14, chunk_index: 1, total_bytes: 4, chunk_blob: new Blob(['tail']) }, { markInitial: true });
await settle();
console.warn = warn;
assert.equal(primed, 0);
assert.equal(producer.hasAcceptedInitialChunk, false);
assert.equal(producer.getItem().status, 'storage-unavailable');
assert.equal(producer.recorderStarted, false);
assert.equal(recorder.state, 'inactive');
assert.ok(cloneStops > 0);
assert.equal(producer.start(), false);
await producer.close();
assert.equal(f.records.get(14).status, 'open');
assert.equal(f.chunks.get(14).length, 1);
assert.deepEqual(f.mutations, ['putRecord']);

// Healthy capture primes only after a durable chunk write and closes normally.
const healthy = fixture();
const healthyManagers = createOperatorMediaManagers({ storage: healthy.storage });
const healthyRecorder = new EventTarget();
healthyRecorder.state = 'inactive';
healthyRecorder.start = () => { healthyRecorder.state = 'recording'; };
healthyRecorder.stop = () => { healthyRecorder.state = 'inactive'; healthyRecorder.dispatchEvent(new Event('stop')); };
let healthyPrimed = 0;
const healthyProducer = await healthyManagers.producerManager.create(healthyRecorder,
    { media_id: 16, status: 'open', extension: 'webm', started_at: new Date().toISOString() },
    { state: {}, captureReadyAt: Date.now(), showToast() {}, onRecorderPrimed() { healthyPrimed++; } },
);
healthyProducer.start();
const data = new Event('dataavailable');
data.data = new Blob([new Uint8Array([0x1A, 0x45, 0xDF, 0xA3, 1])]);
healthyRecorder.dispatchEvent(data);
assert.equal(healthyPrimed, 0);
await settle();
await settle();
assert.equal(healthyPrimed, 1);
assert.equal(healthyProducer.hasAcceptedInitialChunk, true);
assert.equal(healthy.chunks.get(16).length, 1);
await healthyProducer.close();
assert.equal(healthy.records.get(16).status, 'closed');
assert.equal(healthyManagers.getStatus().consumer.storageAvailable, true);

// A failed record write never registers the producer or starts its recorder.
const write = fixture();
write.fail('putRecord');
const writeManagers = createOperatorMediaManagers({ storage: write.storage });
await assert.rejects(writeManagers.producerManager.create(recorder, { media_id: 15 }), { name: 'InternalError' });
assert.equal(writeManagers.getStatus().producerCount, 0);

// Even rejecting/throwing UI feedback is contained, while readiness still rejects.
const brokenUi = new ConsumerManager({ storage: { closeOpenRecords: async () => { throw internalError(); } }, enabled: true });
brokenUi.setHooks({ onError: async () => { throw new Error('Feedback failed'); } });
void brokenUi.start();
await settle();
await settle();
assert.equal(unhandled.length, 0);
assert.equal(timers.size, 0);

// Execute the actual app runtime hook against canonical Helper API stand-ins.
const source = await readFile(new URL('../../resources/js/surfaces/operatorSurface.js', import.meta.url), 'utf8');
const runtimeSource = source.slice(source.indexOf('function operatorMediaManagersRuntime()'), source.indexOf('\nfunction handleOperatorCaptureFailure('));
let hook;
let alerts = 0;
const state = { runtime: {}, helper: { async uiAlert(message, options) {
    alerts++; assert.match(message, /Recording and queued uploads are paused/);
    assert.equal(options.title, 'Recording storage unavailable');
    assert.equal(options.variant, 'error');
} } };
const runtime = new Function('appState', 'createOperatorMediaTransportAdapter', 'createOperatorMediaManagers', 'debugMediaCapture', 'ensureHelperUi', 'showToast', `${runtimeSource}; return operatorMediaManagersRuntime;`)(
    state, () => ({}), () => ({ setHooks(value) { hook = value; } }), () => {}, async () => {}, () => { throw new Error('Unexpected toast'); },
);
runtime();
hook.onError(); hook.onError();
await settle();
assert.equal(alerts, 1);
// Execute the actual call-capture preflight and its fire-and-forget callback.
const captureSource = source.slice(source.indexOf('function createOperatorCallCaptureManager('), source.indexOf('\nasync function loadSharedWorkbenchLookups()'));
let clones = 0, serverRequests = 0;
class FakeMediaStream {
    getAudioTracks() { return [{ clone() { clones++; } }]; }
}
const capture = new Function('MediaStream', 'mediaRecorderSupport', 'operatorMediaManagersRuntime', 'debugMediaCapture', 'fetchJson', `${captureSource}; return createOperatorCallCaptureManager({callSessionId: 33, incidentId: 31});`)(
    FakeMediaStream, () => true, () => unavailable, () => {}, () => { serverRequests++; },
);
let callbackFailures = 0;
const callback = source.match(/void captureManager\?\.ensureLocalAudio\?\.\(stream\)\?\.catch\(handleOperatorCaptureFailure\);/)[0];
new Function('captureManager', 'stream', 'handleOperatorCaptureFailure', callback)(capture, new FakeMediaStream(), () => { callbackFailures++; });
await settle();
assert.equal(callbackFailures, 1);
assert.equal(clones, 0, 'readiness failure happens before recording tracks are cloned');
assert.equal(serverRequests, 0, 'readiness failure happens before media creation requests');
assert.equal(unhandled.length, 0);

console.log('Operator media storage failure injection passed (IndexedDB open, initialization, timed scans, record/chunk writes, preservation, readiness, canonical alert, unhandled rejection).');
