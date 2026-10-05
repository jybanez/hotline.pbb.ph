import assert from 'node:assert/strict';
import { createIndexedDbStore } from '../../resources/js/storage/indexedDbStore.js';
const tick = () => new Promise(resolve => setImmediate(resolve));
const db = () => Object.assign(new EventTarget(), { close() { this.closed = true; } });
let calls = 0;
const healthy = db();
globalThis.indexedDB = { open() { calls++; if (calls === 1) throw new DOMException('Temporary open failure', 'InternalError');
    const request = new EventTarget(); request.result = healthy; queueMicrotask(() => request.dispatchEvent(new Event('success'))); return request;
} };
const store = createIndexedDbStore({name:'test'});
await assert.rejects(store.open(), {name:'InternalError'});
assert.equal(await store.open(), healthy);
assert.equal(calls, 2, 'synchronous open rejection is not permanently cached');

const requests = [];
globalThis.indexedDB = { open() { const request = new EventTarget(); requests.push(request); return request; } };
const blocked = createIndexedDbStore({name:'blocked-test'});
const abandoned = blocked.open(); requests[0].dispatchEvent(new Event('blocked'));
await assert.rejects(abandoned, /blocked/);
const current = blocked.open();
const oldDb = db(); requests[0].result = oldDb; requests[0].dispatchEvent(new Event('success'));
assert.equal(oldDb.closed, true, 'late abandoned handle is closed, never installed');
const newDb = db(); requests[1].result = newDb; requests[1].dispatchEvent(new Event('success'));
assert.equal(await current, newDb);
assert.equal(await blocked.open(), newDb);

globalThis.indexedDB = { open() { const request = new EventTarget(); requests.push(request); return request; } };
const timed = createIndexedDbStore({name:'timeout-test',operationTimeoutMs:5});
await assert.rejects(timed.open(), {name:'TimeoutError'});
const timedDb = db(); requests.at(-1).result = timedDb; requests.at(-1).dispatchEvent(new Event('success'));
assert.equal(timedDb.closed, true, 'timed-out late handles cannot silently recover');

let writes = 0;
const transactional = db();
transactional.transaction = () => {
    const tx = new EventTarget();
    tx.objectStore = () => ({
        get() { const request = new EventTarget(); request.result = 'read-before-abort';
            queueMicrotask(() => {request.dispatchEvent(new Event('success')); setImmediate(() => { tx.error = new DOMException('Transaction aborted', 'AbortError'); tx.dispatchEvent(new Event('abort')); });});
            return request;
        },
        put() { writes++; throw new DOMException('connection is closing', 'InvalidStateError'); },
    });
    return tx;
};
globalThis.indexedDB = { open() { const request = new EventTarget(); request.result = transactional; queueMicrotask(()=>request.dispatchEvent(new Event('success'))); return request; } };
const transactions = createIndexedDbStore({name:'transaction-test'});
await assert.rejects(transactions.get('records', 1), {name:'AbortError'});
await assert.rejects(transactions.put('records', {id:1}), {name:'InvalidStateError'});
assert.equal(writes, 1, 'an action that started writing is never automatically replayed');
await tick();
console.log('IndexedDB lifecycle recovery passed: synchronous open, blocked late success, commit-aware reads, no uncertain write replay.');
