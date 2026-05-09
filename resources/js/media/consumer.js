export class Consumer {
    constructor({ storage, transport = {}, finalizer = {}, record, debug } = {}) {
        this.storage = storage;
        this.transport = transport;
        this.finalizer = finalizer;
        this.record = record ?? null;
        this.debug = typeof debug === 'function' ? debug : null;
        this.mediaId = Number(record?.media_id ?? 0);
        this.state = 'idle';
        this.busy = false;
        this.lastError = '';
        this.updatedAt = new Date().toISOString();

        if (!this.storage) {
            throw new Error('Consumer requires storage.');
        }

        if (this.mediaId <= 0) {
            throw new Error('Consumer requires media_id.');
        }
    }

    updateRecord(record) {
        this.record = record ?? this.record;
        this.updatedAt = new Date().toISOString();
    }

    getItem() {
        return {
            mediaId: this.mediaId,
            state: this.state,
            busy: this.busy,
            lastError: this.lastError,
            updatedAt: this.updatedAt,
        };
    }

    async tick() {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.state = 'draining';
        this.lastError = '';
        this.updatedAt = new Date().toISOString();

        try {
            const record = await this.storage.getRecord(this.mediaId);

            if (!record) {
                this.state = 'missing';
                return;
            }

            this.updateRecord(record);

            const chunks = await this.storage.listChunks(this.mediaId);
            this.debug?.('consumer-tick-begin', {
                debugSource: 'Consumer',
                mediaId: this.mediaId,
                key: String(record?.key ?? ''),
                mediaType: String(record?.media_type ?? ''),
                trackKind: String(record?.track_kind ?? ''),
                status: String(record?.status ?? ''),
                chunkCount: chunks.length,
                source: 'consumer-manager',
            });

            if (chunks.length === 0) {
                if (String(record?.status ?? '') === 'closed') {
                    if (Boolean(record?.skip_finalize)) {
                        await this.deleteRecord(record);
                        this.state = 'discarded';
                        return;
                    }
                    await this.finalizeAndDelete(record);
                }
                this.state = 'idle';
                return;
            }

            if (
                String(record?.status ?? '') === 'closed'
                && !Boolean(record?.batch_flush_disabled)
                && typeof this.transport.flushChunks === 'function'
            ) {
                await this.flushChunksAndFinalize(record, chunks);
                this.state = 'idle';
                return;
            }

            for (const chunk of chunks) {
                const latestRecord = await this.storage.getRecord(this.mediaId);

                if (!latestRecord) {
                    this.state = 'missing';
                    return;
                }

                this.updateRecord(latestRecord);

                if (String(latestRecord?.status ?? '') === 'closed') {
                    break;
                }

                await this.publishAndDeleteChunk(chunk, latestRecord);
            }

            const remainingChunks = await this.storage.listChunks(this.mediaId);
            const latestRecord = await this.storage.getRecord(this.mediaId);
            const nextRecord = latestRecord ?? record;

            if (remainingChunks.length > 0 && String(nextRecord?.status ?? '') === 'closed') {
                this.state = 'idle';
                return;
            }

            if (remainingChunks.length === 0 && String(nextRecord?.status ?? '') === 'closed') {
                if (Boolean(nextRecord?.skip_finalize)) {
                    await this.deleteRecord(nextRecord);
                    this.state = 'discarded';
                    return;
                }
                await this.finalizeAndDelete(nextRecord);
            }

            this.state = 'idle';
        } catch (error) {
            this.state = 'failed';
            this.lastError = String(error?.message ?? error);
            await this.handleFailure(error);
        } finally {
            this.busy = false;
            this.updatedAt = new Date().toISOString();
        }
    }

    async flushChunksAndFinalize(record, chunks) {
        try {
            await this.transport.flushChunks(record, chunks);
        } catch (error) {
            error.batchFlush = true;
            throw error;
        }

        await this.storage.deleteChunksFor(this.mediaId);
        this.debug?.('consumer-batch-flushed', {
            debugSource: 'Consumer',
            mediaId: this.mediaId,
            key: String(record?.key ?? ''),
            mediaType: String(record?.media_type ?? ''),
            trackKind: String(record?.track_kind ?? ''),
            chunkCount: chunks.length,
            source: 'consumer-manager',
        });

        if (Boolean(record?.skip_finalize)) {
            await this.deleteRecord(record);
            this.state = 'discarded';
            return;
        }

        await this.finalizeAndDelete(record);
    }

    async publishAndDeleteChunk(chunk, record) {
        const payload = chunk?.payload ?? null;
        const chunkIndex = Number(chunk?.chunk_index ?? payload?.chunk_index ?? 0);

        if (!payload) {
            await this.storage.deleteChunk(this.mediaId, chunkIndex);
            return;
        }

        if (chunkIndex === 0) {
            await this.transport.publishBootstrapChunk(payload, record);
        } else {
            await this.transport.publishChunk(payload, record);
        }

        await this.storage.deleteChunk(this.mediaId, chunkIndex);
        this.debug?.('consumer-chunk-forwarded', {
            debugSource: 'Consumer',
            mediaId: this.mediaId,
            key: String(record?.key ?? ''),
            mediaType: String(record?.media_type ?? ''),
            trackKind: String(record?.track_kind ?? ''),
            chunkIndex,
            source: 'consumer-manager',
        });
    }

    async handleFailure(error) {
        const record = await this.storage.getRecord(this.mediaId);

        if (!record) {
            return;
        }

        const chunks = await this.storage.listChunks(this.mediaId);
        const chunk = chunks.at(0) ?? null;

        this.debug?.('consumer-tick-fail', {
            debugSource: 'Consumer',
            mediaId: this.mediaId,
            message: String(error?.message ?? error),
            source: 'consumer-manager',
        });

        if (Boolean(error?.batchFlush)) {
            const retryCount = Number(record?.batch_flush_retry_count ?? 0) + 1;

            await this.storage.putRecord({
                ...record,
                batch_flush_retry_count: retryCount,
                batch_flush_disabled: retryCount >= 3,
                batch_flush_last_error: String(error?.message ?? error),
                updated_at: new Date().toISOString(),
            });

            if (retryCount >= 3) {
                this.debug?.('consumer-batch-flush-disabled', {
                    debugSource: 'Consumer',
                    mediaId: this.mediaId,
                    retryCount,
                    source: 'consumer-manager',
                });
            }

            return;
        }

        if (!chunk) {
            return;
        }

        const retryCount = Number(chunk?.retry_count ?? 0) + 1;
        const chunkIndex = Number(chunk?.chunk_index ?? 0);

        if (retryCount >= 3) {
            await this.storage.deleteChunk(this.mediaId, chunkIndex);
            this.debug?.('consumer-chunk-discarded', {
                debugSource: 'Consumer',
                mediaId: this.mediaId,
                chunkIndex,
                retryCount,
                source: 'consumer-manager',
            });
            return;
        }

        await this.storage.updateChunkMeta(this.mediaId, chunkIndex, {
            retry_count: retryCount,
            last_error: String(error?.message ?? error),
            updated_at: new Date().toISOString(),
        });
    }

    async finalizeAndDelete(record) {
        const nextMediaId = Number(record?.media_id ?? 0);

        if (nextMediaId <= 0) {
            return;
        }

        try {
            await this.finalizer.finalizeRecord?.(record);
        } finally {
            await this.storage.deleteChunksFor(nextMediaId);
            await this.storage.deleteRecord(nextMediaId);
            this.state = 'finalized';
            this.updatedAt = new Date().toISOString();
            this.debug?.('consumer-record-finalized', {
                debugSource: 'Consumer',
                mediaId: nextMediaId,
                key: String(record?.key ?? ''),
                mediaType: String(record?.media_type ?? ''),
                trackKind: String(record?.track_kind ?? ''),
                source: 'consumer-manager',
            });
        }
    }

    async deleteRecord(record) {
        const nextMediaId = Number(record?.media_id ?? 0);

        if (nextMediaId <= 0) {
            return;
        }

        await this.storage.deleteChunksFor(nextMediaId);
        await this.storage.deleteRecord(nextMediaId);
        this.updatedAt = new Date().toISOString();
        this.debug?.('consumer-record-discarded', {
            debugSource: 'Consumer',
            mediaId: nextMediaId,
            key: String(record?.key ?? ''),
            mediaType: String(record?.media_type ?? ''),
            trackKind: String(record?.track_kind ?? ''),
            reason: String(record?.failure_reason ?? 'skip_finalize'),
            source: 'consumer-manager',
        });
    }
}
