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
        await this.readinessCheck?.();
    }

    async create(mediaRecorder, mediaRecord, options = {}) {
        await this.ensureReady();
        const producer = new Producer(this, mediaRecorder, mediaRecord, options);
        await producer.initialize();
        try {
            await this.ensureReady();
        } catch (error) {
            producer.pauseForStorageFailure();
            throw error;
        }
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

    getProducers() {
        return Array.from(this.producers.values());
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
        this.scanPromise = null;
        this.consumers = new Map();

        if (!this.storage) {
            throw new Error('ConsumerManager requires storage.');
        }

        this.pausedMediaIds = new Set();
        this.lastError = null;
        this.onError = null;
        // Retain failure state without leaving an eager rejected promise unobserved.
        this.initializing = this.initialize().catch((error) => this.reportFailure(error));
    }

    setHooks({ debug, onError } = {}) {
        if (typeof debug === 'function') {
            this.debug = debug;
        }
        if (typeof onError === 'function') {
            this.onError = onError;
            if (this.lastError) this.notifyFailure();
        }
    }

    notifyFailure() {
        try {
            Promise.resolve(this.onError?.(this.lastError)).catch(() => {});
        } catch (_error) {
            // Feedback cannot turn a handled storage failure into a rejection.
        }
    }

    reportFailure(error, stage = 'queue-initialization') {
        const firstFailure = !this.lastError;
        this.lastError = this.lastError ?? error;
        if (firstFailure) this.failureStage = stage;
        this.stop();
        if (firstFailure) this.notifyFailure();
        return false;
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

        try {
            await this.ensureReady();
        } catch (error) {
            return this.reportFailure(error);
        }
        if (this.started) return;
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
        if (this.lastError) throw this.lastError;
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
        if (!this.enabled || this.lastError) {
            return false;
        }

        if (this.scanPromise) {
            return this.scanPromise;
        }

        this.scanPromise = (async () => {
            await this.ensureReady();

            const records = await this.storage.listRecords();
            records.filter(record => record.status === 'recovery-hold').forEach(record => this.pausedMediaIds.add(Number(record.held_media_id)));
            const seen = new Set();
            const ticks = [];

            for (const record of records) {
                const mediaId = Number(record?.media_id ?? 0);

                if (!Number.isFinite(mediaId) || mediaId <= 0 || this.pausedMediaIds.has(mediaId)) {
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
                ticks.push(consumer.tick());
            }

            const results = await Promise.allSettled(ticks);
            const failure = results.find((result) => result.status === 'rejected');
            if (failure) throw failure.reason;

            for (const mediaId of Array.from(this.consumers.keys())) {
                if (!seen.has(mediaId)) {
                    this.consumers.delete(mediaId);
                }
            }
        })().catch((error) => this.reportFailure(error)).finally(() => {
            this.scanPromise = null;
        });

        return this.scanPromise;
    }

    async drain({ maxPasses = 20, delayMs = 250 } = {}) {
        try {
            await this.ensureReady();
        } catch (error) {
            return this.reportFailure(error);
        }
        if (!this.enabled) {
            return true;
        }

        for (let pass = 0; pass < maxPasses; pass += 1) {
            await this.scan();
            if (this.lastError) return false;

            const records = await this.storage.listRecords();

            if (records.length === 0) {
                return true;
            }

            await new Promise((resolve) => {
                window.setTimeout(resolve, Math.max(50, Number(delayMs ?? 250)));
            });
        }

        this.debug?.('consumer-drain-timeout', {
            debugSource: 'ConsumerManager',
            remainingRecords: (await this.storage.listRecords()).length,
            maxPasses,
            source: 'consumer-manager',
        });

        return false;
    }

    getItems() {
        return Array.from(this.consumers.values()).map((consumer) => consumer.getItem());
    }

    getStatus() {
        return {
            storageAvailable: this.initialized && !this.lastError,
            failureStage: this.failureStage ?? '',
            errorName: this.lastError?.name ?? '',
            pausedMediaCount: this.pausedMediaIds.size,
            lastError: this.lastError ? String(this.lastError.message ?? this.lastError) : '',
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
