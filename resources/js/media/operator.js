import { ConsumerManager, ProducerManager } from './operatorMediaManagers.js';
import { createOperatorMediaQueueStorage } from './operatorMediaQueueStorage.js';

export function createOperatorMediaManagers(services = {}) {
    const queue = services.storage ?? createOperatorMediaQueueStorage();
    const storageErrors = new WeakSet();
    let consumerManager;
    let producerManager;
    const storage = Object.fromEntries(Object.entries(queue).map(([name, operation]) => [name,
        typeof operation !== 'function' ? operation : async (...args) => {
            try {
                if (consumerManager?.lastError) throw consumerManager.lastError;
                return await operation.apply(queue, args);
            } catch (error) {
                if (error && typeof error === 'object') storageErrors.add(error);
                producerManager?.getProducers().forEach((producer) => producer.pauseForStorageFailure());
                consumerManager?.reportFailure(error, name);
                throw error;
            }
        },
    ]));
    storage.isStorageFailure = (error) => error && typeof error === 'object' && storageErrors.has(error);
    producerManager = new ProducerManager({ storage });
    consumerManager = new ConsumerManager({
        storage,
        enabled: services.enabled,
        pollMs: services.pollMs,
        transport: {
            publishChunk: services.publishChunk,
            publishBootstrapChunk: services.publishBootstrapChunk,
        },
        finalizer: {
            finalizeRecord: services.finalizeRecord,
        },
    });

    producerManager.readinessCheck = () => consumerManager.ensureReady();

    return {
        producerManager,
        consumerManager,
        setHooks(hooks = {}) {
            producerManager.setHooks(hooks);
            consumerManager.setHooks(hooks);
        },
        async recoverStorage() {
            if (this.recoveryPromise) return this.recoveryPromise;
            this.recoveryPromise = (async () => {
                await consumerManager.initializing;
                await consumerManager.scanPromise;
                await producerManager.close();
                const health = await queue.verifyHealth();
                // Previous media may have an uncertain write/upload outcome. Never
                // resume those records or stopped recording clones automatically.
                health.records.forEach(record => consumerManager.pausedMediaIds.add(Number(record.media_id)));
                consumerManager.lastError = null;
                consumerManager.failureStage = '';
                consumerManager.initialized = true;
                await consumerManager.start();
                await consumerManager.scanPromise;
                await consumerManager.ensureReady();
                return { pausedMediaCount: consumerManager.pausedMediaIds.size };
            })().catch(error => {
                consumerManager.reportFailure(error, 'durable-health-verification');
                throw error;
            }).finally(() => { this.recoveryPromise = null; });
            return this.recoveryPromise;
        },
        start() {
            return consumerManager.start();
        },
        stop() {
            consumerManager.stop();
        },
        setConsumerEnabled(enabled) {
            consumerManager.setEnabled(enabled);
        },
        scanConsumers() {
            return consumerManager.scan();
        },
        drainConsumers(options = {}) {
            return consumerManager.drain(options);
        },
        getItems() {
            return {
                producers: producerManager.getItems(),
                consumers: consumerManager.getItems(),
            };
        },
        getStatus() {
            return {
                consumer: consumerManager.getStatus(),
                producerCount: producerManager.getItems().length,
            };
        },
        clear() {
            producerManager.clear();
            consumerManager.clear();
        },
    };
}
