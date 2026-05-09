import { Consumer } from './consumer.js';
import { Producer } from './producer.js';

export class ProducerManager {
    constructor({ storage } = {}) {
        this.storage = storage;
        this.producers = new Map();
        this.debug = null;
        this.readyPromise = Promise.resolve();

        if (!this.storage) {
            throw new Error('ProducerManager requires storage.');
        }
    }

    setHooks({ debug } = {}) {
        if (typeof debug === 'function') {
            this.debug = debug;
        }
    }

    async ensureReady() {
        await this.readyPromise;
    }

    async create(mediaRecorder, mediaRecord, options = {}) {
        await this.ensureReady();
        const producer = new Producer(this, mediaRecorder, mediaRecord, options);
        await producer.initialize();
        this.producers.set(producer.mediaId, producer);

        return producer;
    }

    async close() {
        await Promise.allSettled(Array.from(this.producers.values()).map((producer) => producer.close()));
    }

    remove(mediaId) {
        const nextMediaId = Number(mediaId ?? 0);
        this.producers.delete(nextMediaId);
    }

    getItems() {
        return Array.from(this.producers.values()).map((producer) => producer.getItem());
    }

    clear() {
        this.producers.clear();
    }
}

export class ConsumerManager {
    constructor({ storage, transport = {}, finalizer = {}, enabled = false, pollMs = 1000 } = {}) {
        this.storage = storage;
        this.transport = transport;
        this.finalizer = finalizer;
        this.enabled = Boolean(enabled);
        this.pollMs = Math.max(250, Number(pollMs ?? 1000));
        this.started = false;
        this.initialized = false;
        this.intervalId = null;
        this.debug = null;
        this.consumers = new Map();

        if (!this.storage) {
            throw new Error('ConsumerManager requires storage.');
        }

        this.initializing = this.initialize();
    }

    setHooks({ debug } = {}) {
        if (typeof debug === 'function') {
            this.debug = debug;
        }
    }

    async initialize() {
        if (this.initialized) {
            return;
        }

        try {
            await this.storage.closeOpenRecords?.();
            this.initialized = true;
        } catch (error) {
            this.debug?.('consumer-init-fail', {
                debugSource: 'ConsumerManager',
                message: String(error?.message ?? error),
                source: 'consumer-manager',
            });
            throw error;
        }
    }

    async start() {
        if (this.started) {
            return;
        }

        await this.initializing;
        this.started = true;

        if (!this.enabled) {
            this.debug?.('consumer-manager-disabled', {
                debugSource: 'ConsumerManager',
                source: 'consumer-manager',
            });
            return;
        }

        this.intervalId = window.setInterval(() => {
            void this.scan();
        }, this.pollMs);

        void this.scan();
    }

    async ensureReady() {
        await this.initializing;
    }

    stop() {
        this.started = false;
        if (this.intervalId) {
            window.clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    setEnabled(enabled) {
        const nextEnabled = Boolean(enabled);

        if (this.enabled === nextEnabled) {
            return;
        }

        this.enabled = nextEnabled;

        if (!this.enabled) {
            this.stop();
            this.debug?.('consumer-manager-disabled', {
                debugSource: 'ConsumerManager',
                source: 'consumer-manager',
            });
            return;
        }

        this.start();
    }

    async scan() {
        if (!this.enabled) {
            return;
        }

        await this.ensureReady();

        const records = await this.storage.listRecords();
        const seen = new Set();

        for (const record of records) {
            const mediaId = Number(record?.media_id ?? 0);

            if (mediaId <= 0) {
                continue;
            }

            seen.add(mediaId);

            if (!this.consumers.has(mediaId)) {
                this.consumers.set(mediaId, new Consumer({
                    storage: this.storage,
                    transport: this.transport,
                    finalizer: this.finalizer,
                    record,
                    debug: this.debug,
                }));
            }

            const consumer = this.consumers.get(mediaId);
            consumer.updateRecord(record);
            void consumer.tick();
        }

        for (const mediaId of Array.from(this.consumers.keys())) {
            if (!seen.has(mediaId)) {
                this.consumers.delete(mediaId);
            }
        }
    }

    getItems() {
        return Array.from(this.consumers.values()).map((consumer) => consumer.getItem());
    }

    getStatus() {
        return {
            enabled: this.enabled,
            started: this.started,
            pollMs: this.pollMs,
            itemCount: this.consumers.size,
        };
    }

    clear() {
        this.consumers.clear();
    }
}
