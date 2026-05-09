export function createIndexedDbStore({ name, version, upgrade, unavailableMessage, blockedMessage, openErrorMessage } = {}) {
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

    const open = () => {
        if (runtime.db) {
            return Promise.resolve(runtime.db);
        }

        if (runtime.openPromise) {
            return runtime.openPromise;
        }

        runtime.openPromise = new Promise((resolve, reject) => {
            if (typeof indexedDB === 'undefined') {
                reject(new Error(unavailableMessage ?? 'IndexedDB is unavailable.'));
                return;
            }

            const request = indexedDB.open(name, version);

            request.addEventListener('upgradeneeded', (event) => {
                upgrade?.(request.result, event);
            });

            request.addEventListener('success', () => {
                const db = request.result;
                db.addEventListener('close', () => {
                    console.warn(`${name} database connection closed.`);
                    resetHandle(db);
                });
                db.addEventListener('versionchange', () => {
                    console.warn(`${name} database version changed; refreshing handle.`);
                    resetHandle(db);
                    db.close();
                });
                runtime.db = db;
                runtime.openPromise = null;
                resolve(db);
            });
            request.addEventListener('error', () => {
                resetHandle();
                reject(request.error ?? new Error(openErrorMessage ?? `Unable to open ${name} database.`));
            });
            request.addEventListener('blocked', () => {
                resetHandle();
                reject(new Error(blockedMessage ?? `${name} database open is blocked.`));
            });
        });

        return runtime.openPromise;
    };

    const transaction = async (storeName, mode, action, attempt = 0) => {
        const db = await open();

        try {
            return await new Promise((resolve, reject) => {
                const tx = db.transaction(storeName, mode);
                const store = tx.objectStore(storeName);
                let settled = false;

                const finishResolve = (value) => {
                    if (!settled) {
                        settled = true;
                        resolve(value);
                    }
                };

                const finishReject = (error) => {
                    if (!settled) {
                        settled = true;
                        reject(error);
                    }
                };

                tx.addEventListener('complete', () => finishResolve(undefined));
                tx.addEventListener('abort', () => finishReject(tx.error ?? new Error(`${name} transaction aborted for ${storeName}.`)));
                tx.addEventListener('error', () => finishReject(tx.error ?? new Error(`${name} transaction failed for ${storeName}.`)));

                Promise.resolve()
                    .then(() => action(store, tx, finishResolve))
                    .catch(finishReject);
            });
        } catch (error) {
            const message = String(error?.message ?? error);
            const recoverable = error?.name === 'InvalidStateError'
                || message.includes('connection is closing')
                || message.includes('database connection is closing');

            if (!recoverable || attempt >= 1) {
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
