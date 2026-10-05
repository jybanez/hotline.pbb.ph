import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync('public/citizen-sw.js', 'utf8');
const failure = () => Promise.reject(new Error('CacheStorage UnknownError'));
function worker(overrides = {}) {
    const handlers = {}, removed = [];
    const cached = new Response('offline');
    const cache = {put: async () => {}, addAll: async () => {}};
    const context = vm.createContext({URL, caches: {
        open: async () => cache, match: async () => cached,
        keys: async () => ['citizen-pwa-v2-static', 'caller-pwa-old', 'other-app', 'citizen-pwa-v3-static'],
        delete: async key => { removed.push(key); }, ...overrides.caches,
    }, fetch: overrides.fetch || (async () => new Response('network')),
    self: {location: {origin: 'https://hotline.pbb.ph'}, addEventListener: (name, fn) => handlers[name] = fn,
        skipWaiting: async () => {}, clients: {claim: async () => {}}}});
    vm.runInContext(source, context);
    return {context, handlers, cached, removed};
}
const request = {url: 'https://hotline.pbb.ph/build/assets/operator.js', method: 'GET', mode: 'cors'};
for (const caches of [{open: failure}, {open: async () => ({put: failure})}, {match: failure, open: failure}]) {
    const w = worker({caches});
    assert.equal(await (await w.context.networkFirst(request)).text(), 'network');
}
const networkError = new Error('Network unavailable');
for (const strategy of ['networkFirst', 'networkFirstNavigation']) {
    const w = worker({fetch: async () => {throw networkError;}});
    assert.equal(await w.context[strategy](request), w.cached);
    for (const match of [failure, async () => undefined]) {
        const broken = worker({fetch: async () => {throw networkError;}, caches: {match}});
        await assert.rejects(broken.context[strategy](request), error => error === networkError);
    }
}
const badStatus = new Response('server error', {status: 500});
assert.equal(await worker({fetch: async () => badStatus, caches: {open: failure}}).context.networkFirst(request), badStatus);
for (const caches of [{open: failure}, {open: async () => ({addAll: failure})}, {keys: failure}, {delete: failure}, {}]) {
    const w = worker({caches});
    for (const eventName of ['install', 'activate']) {
        let pending;
        w.handlers[eventName]({waitUntil: value => pending = value});
        await pending;
    }
    if (!caches.delete && !caches.keys) assert.deepEqual(w.removed, ['citizen-pwa-v2-static', 'caller-pwa-old']);
}
const w = worker();
for (const path of ['/api/private', '/broadcasting/auth', '/login', '/logout', '/sanctum/csrf-cookie', '/storage/private', '/operator', '/auth/account/callback']) {
    let intercepted = false;
    w.handlers.fetch({request: {...request, url: 'https://hotline.pbb.ph' + path, mode: 'navigate'}, respondWith: () => intercepted = true});
    assert.equal(intercepted, false, path);
}
for (const changed of [{method: 'POST'}, {url: 'https://other.test/build/assets/app.js'}]) {
    let intercepted = false;
    w.handlers.fetch({request: {...request, ...changed}, respondWith: () => intercepted = true});
    assert.equal(intercepted, false);
}
for (const path of ['/build/assets/operator.js', '/citizen/offline', '/citizen']) {
    let pending;
    w.handlers.fetch({request: {...request, url: 'https://hotline.pbb.ph' + path, mode: path === '/citizen' ? 'navigate' : 'cors'}, respondWith: value => pending = value});
    assert.ok(pending); await pending;
}
console.log('PASS: cache failures, lifecycle cleanup, network/offline semantics and private exclusions');
