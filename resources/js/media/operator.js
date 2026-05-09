import { ConsumerManager, ProducerManager } from './operatorMediaManagers.js';
import { createOperatorMediaQueueStorage } from './operatorMediaQueueStorage.js';

export function createOperatorMediaManagers(services = {}) {
    const storage = createOperatorMediaQueueStorage();
    const producerManager = new ProducerManager({ storage });
    const consumerManager = new ConsumerManager({
        storage,
        enabled: services.enabled,
        pollMs: services.pollMs,
        transport: {
            publishChunk: services.publishChunk,
            publishBootstrapChunk: services.publishBootstrapChunk,
            flushChunks: services.flushChunks,
        },
        finalizer: {
            finalizeRecord: services.finalizeRecord,
        },
    });

    return {
        producerManager,
        consumerManager,
        setHooks(hooks = {}) {
            producerManager.setHooks(hooks);
            consumerManager.setHooks(hooks);
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
