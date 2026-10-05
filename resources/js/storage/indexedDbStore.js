export function createIndexedDbStore({ name, version, upgrade, unavailableMessage, blockedMessage, openErrorMessage, operationTimeoutMs = 15000 } = {}) {
    const runtime = {
        openPromise: null,
        db: null,
    };

    const resetHandle = (db = null) => {
        if (!db || runtime.db === db) {
            runtime.db = null;
            runtime.openPromise = null;
        }
    };

    const requestToPromise = (request, fallbackMessage = 'IndexedDB request failed.') => new Promise((resolve, reject) => {
        request.addEventListener('success', () => resolve(request.result));
        request.addEventListener('error', () => reject(request.error ?? new Error(fallbackMessage)));
    });

    const open = ({ requireExisting = false } = {}) => {
        if (runtime.db) return Promise.resolve(runtime.db);
        if (runtime.openPromise) return runtime.openPromise;
        let abandoned = false;
        let pending;
        let timeout;
        pending = new Promise((resolve, reject) => {
            if (typeof indexedDB === 'undefined') {
                reject(new Error(unavailableMessage ?? 'IndexedDB is unavailable.'));
                return;
            }
            // Synchronous open errors reject this promise too; the shared catch below
            // clears its handle only when this attempt still owns it.
            timeout = setTimeout(() => { abandoned = true; reject(new DOMException(`Timed out opening ${name}.`, 'TimeoutError')); }, operationTimeoutMs);
            const request = indexedDB.open(name, version);
            const fail = (error) => { clearTimeout(timeout); abandoned = true; reject(error); };
            request.addEventListener('upgradeneeded', (event) => {
                if (abandoned || (requireExisting && event.oldVersion === 0)) {
                    request.transaction.abort();
                    fail(new Error('Original recording queue is missing. Recovery cannot replace it.'));
                    return;
                }
                try { upgrade?.(request.result, event); }
                catch (error) { request.transaction.abort(); fail(error); }
            });
            request.addEventListener('success', () => {
                clearTimeout(timeout);
                const db = request.result;
                if (abandoned) { db.close(); return; }
                db.addEventListener('close', () => resetHandle(db));
                db.addEventListener('versionchange', () => { resetHandle(db); db.close(); });
                runtime.db = db;
                if (runtime.openPromise === pending) runtime.openPromise = null;
                resolve(db);
            });
            request.addEventListener('error', () => fail(request.error ?? new Error(openErrorMessage ?? `Unable to open ${name} database.`)));
            request.addEventListener('blocked', () => fail(new Error(blockedMessage ?? `${name} database open is blocked.`)));
        });
        runtime.openPromise = pending;
        // Keep rejection observable to callers without permanently caching it.
        void pending.catch(() => { clearTimeout(timeout); if (runtime.openPromise === pending) runtime.openPromise = null; });
        return pending;
    };

    const transaction = async (storeName, mode, action, attempt = 0) => {
        const db = await open();

        let actionStarted = false;
        try {
            return await new Promise((resolve, reject) => {
                const tx = db.transaction(storeName, mode);
                const store = tx.objectStore(storeName);
                let settled = false;
                let resultValue;
                const timeout = setTimeout(() => {
                    try { tx.abort(); } catch (_error) {}
                    finishReject(new DOMException(`Timed out committing ${name} ${storeName}.`, 'TimeoutError'));
                }, operationTimeoutMs);

                const finishResolve = (value) => { resultValue = value; };

                const finishReject = (error) => {
                    if (!settled) {
                        clearTimeout(timeout);
                        settled = true;
                        reject(error);
                    }
                };

                tx.addEventListener('complete', () => {
                    if (!settled) { clearTimeout(timeout); settled = true; resolve(resultValue); }
                });
                tx.addEventListener('abort', () => finishReject(tx.error ?? new Error(`${name} transaction aborted for ${storeName}.`)));
                tx.addEventListener('error', () => finishReject(tx.error ?? new Error(`${name} transaction failed for ${storeName}.`)));

                Promise.resolve()
                    .then(() => {
                        if (settled) return;
                        actionStarted = true;
                        return action(store, tx, finishResolve);
                    })
                    .catch(error => {
                        // An action failure must abort all earlier writes in this transaction.
                        try { tx.abort(); } catch (_error) {}
                        finishReject(error);
                    });
            });
        } catch (error) {
            const message = String(error?.message ?? error);
            const recoverable = error?.name === 'InvalidStateError'
                || message.includes('connection is closing')
                || message.includes('database connection is closing');

            if (!recoverable || attempt >= 1 || (mode === 'readwrite' && actionStarted)) {
                throw error;
            }

            console.warn(`Retrying ${name} transaction after refreshing closed IndexedDB handle.`, {
                storeName,
                mode,
                attempt,
                message,
            });
            try {
                db.close?.();
            } catch (_error) {
            }
            resetHandle(db);
            return transaction(storeName, mode, action, attempt + 1);
        }
    };

    return {
        open,
        close() {
            const db = runtime.db;
            resetHandle(db);
            db?.close?.();
        },
        transaction,
        requestToPromise,
        async put(storeName, value) {
            await transaction(storeName, 'readwrite', async (store) => {
                await requestToPromise(store.put(value));
            });
        },
        get(storeName, key) {
            return transaction(storeName, 'readonly', async (store, _tx, resolve) => {
                resolve(await requestToPromise(store.get(key)));
            });
        },
        getAll(storeName) {
            return transaction(storeName, 'readonly', async (store, _tx, resolve) => {
                const items = await requestToPromise(store.getAll());
                resolve(Array.isArray(items) ? items : []);
            });
        },
        async delete(storeName, key) {
            await transaction(storeName, 'readwrite', async (store) => {
                await requestToPromise(store.delete(key));
            });
        },
        getAllFromIndex(storeName, indexName, key) {
            return transaction(storeName, 'readonly', async (store, _tx, resolve) => {
                const index = store.index(indexName);
                const items = await requestToPromise(index.getAll(key));
                resolve(Array.isArray(items) ? items : []);
            });
        },
    };
}
