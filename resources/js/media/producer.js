const WEBM_HEADER_SIGNATURE = [0x1A, 0x45, 0xDF, 0xA3];

async function blobStartsWithWebmHeader(blob) {
    if (!(blob instanceof Blob) || blob.size < WEBM_HEADER_SIGNATURE.length) {
        return false;
    }

    try {
        const bytes = new Uint8Array(await blob.slice(0, WEBM_HEADER_SIGNATURE.length).arrayBuffer());

        return WEBM_HEADER_SIGNATURE.every((value, index) => bytes[index] === value);
    } catch {
        return false;
    }
}

export class Producer {
    constructor(manager, mediaRecorder, mediaRecord, options = {}) {
        this.manager = manager;
        this.storage = manager.storage;
        this.mediaRecorder = mediaRecorder;
        this.mediaRecord = { ...mediaRecord };
        this.options = options;
        this.mediaId = Number(mediaRecord?.media_id ?? 0);
        this.key = String(mediaRecord?.key ?? '');
        this.mediaType = String(mediaRecord?.media_type ?? '');
        this.trackKind = String(mediaRecord?.track_kind ?? '');
        this.peerUserId = Number(mediaRecord?.peer_user_id ?? 0);
        this.peerRole = String(mediaRecord?.peer_role ?? '');
        this.peerLabel = String(mediaRecord?.peer_label ?? '');
        this.extension = String(mediaRecord?.extension ?? '');
        this.mimeType = String(mediaRecord?.mime_type ?? '');
        this.segmentKey = String(mediaRecord?.segment_key ?? '');
        this.startedAt = String(mediaRecord?.started_at ?? new Date().toISOString());
        this.timesliceMs = Math.max(250, Number(options.timesliceMs ?? 1000));
        this.captureReadyAt = options.captureReadyAt ?? null;
        this.recordingStartedAt = null;
        this.nextChunkIndex = 0;
        this.recorderStarted = false;
        this.stopPromise = null;
        this.stopRequestedAt = null;
        this.stopping = false;
        this.finalizing = false;
        this.acceptingStopChunks = false;
        this.stopEventSeen = false;
        this.hasAcceptedInitialChunk = false;
        this.hasQueuedInitialChunk = false;
        this.pendingPersists = new Set();
        this.pendingChunkTasks = new Set();
        this.sourceTrack = options.sourceTrack ?? null;
        this.clonedTrack = options.clonedTrack ?? null;
        this.clonedTracks = Array.isArray(options.clonedTracks) ? options.clonedTracks : [this.clonedTrack].filter(Boolean);
        this.unmutedAt = null;
        this.postUnmuteChunkSamples = 0;
        this.item = this.snapshot();
        this.boundHandleDataAvailable = (event) => {
            this.handleDataAvailable(event);
        };
    }

    get debug() {
        return this.manager.debug;
    }

    async initialize() {
        if (this.mediaId <= 0) {
            throw new Error('ProducerManager.create requires media_id.');
        }

        await this.storage.putRecord(this.mediaRecord);
        this.mediaRecorder.addEventListener('dataavailable', this.boundHandleDataAvailable);
        this.updateItem(this.snapshot());
        this.debug?.('producer-created', {
            debugSource: 'ProducerManager',
            mediaId: this.mediaId,
            key: this.key,
            mediaType: this.mediaType,
            trackKind: this.trackKind,
            status: String(this.mediaRecord?.status ?? ''),
            source: 'producer-manager',
        });
    }

    snapshot() {
        return {
            mediaId: this.mediaId,
            key: this.key,
            mediaType: this.mediaType,
            trackKind: this.trackKind,
            status: String(this.mediaRecord?.status ?? ''),
            updatedAt: new Date().toISOString(),
        };
    }

    updateItem(item = {}) {
        this.item = {
            ...this.item,
            ...item,
            updatedAt: item?.updatedAt ?? new Date().toISOString(),
        };
    }

    getItem() {
        return { ...this.item };
    }

    attachHandle(handle) {
        if (!handle || typeof handle !== 'object') {
            return handle;
        }

        const getters = {
            recorderStarted: () => this.recorderStarted,
            recordingStartedAt: () => this.recordingStartedAt,
            nextChunkIndex: () => this.nextChunkIndex,
            stopping: () => this.stopping,
            finalizing: () => this.finalizing,
            stopRequestedAt: () => this.stopRequestedAt,
            acceptingStopChunks: () => this.acceptingStopChunks,
            hasAcceptedInitialChunk: () => this.hasAcceptedInitialChunk,
            hasQueuedInitialChunk: () => this.hasQueuedInitialChunk,
            pendingPersists: () => this.pendingPersists,
            pendingChunkTasks: () => this.pendingChunkTasks,
            stopEventSeen: () => this.stopEventSeen,
            stopPromise: () => this.stopPromise,
        };

        for (const [key, get] of Object.entries(getters)) {
            Object.defineProperty(handle, key, {
                configurable: true,
                enumerable: true,
                get,
            });
        }

        handle.producer = this;

        return handle;
    }

    setCaptureReadyAt(captureReadyAt) {
        this.captureReadyAt = captureReadyAt ?? null;
    }

    setStopRequestedAt(stoppedAt) {
        if (!this.stopRequestedAt || Number(stoppedAt ?? 0) < this.stopRequestedAt) {
            this.stopRequestedAt = Number(stoppedAt ?? Date.now());
        }
    }

    markUnmuted(unmutedAt = Date.now()) {
        this.unmutedAt = unmutedAt;
        this.postUnmuteChunkSamples = 0;
    }

    start(timesliceMs = this.timesliceMs) {
        const nextTimesliceMs = Math.max(250, Number(timesliceMs ?? this.timesliceMs));

        if (this.recorderStarted) {
            return true;
        }

        this.timesliceMs = nextTimesliceMs;
        this.recordingStartedAt = this.captureReadyAt ?? Date.now();
        try {
            this.mediaRecorder.start(nextTimesliceMs);
        } catch (error) {
            this.debug?.('recorder-start-fail', {
                callSessionId: this.options.callSessionId,
                mediaId: this.mediaId,
                key: this.key,
                mediaType: this.mediaType,
                trackKind: this.trackKind,
                timesliceMs: nextTimesliceMs,
                sourceTrackId: this.sourceTrack?.id ?? '',
                clonedTrackId: this.clonedTrack?.id ?? '',
                message: String(error?.message ?? error),
            });
            this.clonedTracks.forEach((cloned) => {
                cloned?.stop?.();
            });
            void this.markStartFailed(error);
            return false;
        }

        this.recorderStarted = true;
        this.debug?.('recorder-start', {
            debugSource: 'Producer',
            callSessionId: this.options.callSessionId,
            mediaId: this.mediaId,
            key: this.key,
            mediaType: this.mediaType,
            trackKind: this.trackKind,
            timesliceMs: nextTimesliceMs,
            captureReadyAt: this.captureReadyAt ? new Date(this.captureReadyAt).toISOString() : null,
        });

        return true;
    }

    async markStartFailed(error) {
        const failedAt = Date.now();
        const failedRecord = {
            ...this.mediaRecord,
            ended_at: new Date(failedAt).toISOString(),
            duration_seconds: 0,
            status: 'closed',
            skip_finalize: true,
            failure_reason: String(error?.message ?? error),
            updated_at: new Date().toISOString(),
        };

        try {
            await this.storage.putRecord(failedRecord);
            this.mediaRecord = failedRecord;
            this.updateItem({
                status: 'closed',
                updatedAt: new Date().toISOString(),
            });
        } catch (persistError) {
            this.debug?.('recorder-start-fail-record-update-fail', {
                debugSource: 'Producer',
                callSessionId: this.options.callSessionId,
                mediaId: this.mediaId,
                key: this.key,
                mediaType: this.mediaType,
                trackKind: this.trackKind,
                message: String(persistError?.message ?? persistError),
            });
        }
    }

    handleDataAvailable(event) {
        const {
            callSessionId,
            incidentId,
            onRecorderPrimed,
            showToast,
            state,
        } = this.options;

        this.debug?.('dataavailable', {
            debugSource: 'Producer',
            callSessionId,
            mediaId: this.mediaId,
            key: this.key,
            mediaType: this.mediaType,
            trackKind: this.trackKind,
            bytes: event.data instanceof Blob ? event.data.size : 0,
            recorderState: this.mediaRecorder?.state,
            hasAcceptedInitialChunk: this.hasAcceptedInitialChunk,
            hasQueuedInitialChunk: this.hasQueuedInitialChunk,
            nextChunkIndex: this.nextChunkIndex,
            stopping: this.stopping,
            acceptingStopChunks: this.acceptingStopChunks,
            stopEventSeen: this.stopEventSeen,
        });

        if (!(event.data instanceof Blob) || event.data.size <= 0) {
            return;
        }

        const chunkTask = (async () => {
            if (this.stopping && !this.acceptingStopChunks) {
                this.debug?.('dataavailable-drop-stopping', {
                    debugSource: 'Producer',
                    callSessionId,
                    mediaId: this.mediaId,
                    key: this.key,
                    mediaType: this.mediaType,
                    trackKind: this.trackKind,
                    bytes: event.data.size,
                    nextChunkIndex: this.nextChunkIndex,
                });
                return;
            }

            const isWebmLike = this.extension === 'webm' || this.extension === 'weba';
            let markInitial = false;
            const nextChunkIndex = this.nextChunkIndex;
            const recordingStartedAt = this.recordingStartedAt ?? this.captureReadyAt ?? new Date(this.startedAt).getTime();
            const effectiveChunkStartMs = recordingStartedAt + (nextChunkIndex * this.timesliceMs);

            if (!this.captureReadyAt || effectiveChunkStartMs < this.captureReadyAt) {
                this.debug?.('chunk-drop-pre-gate', {
                    debugSource: 'Producer',
                    callSessionId,
                    mediaId: this.mediaId,
                    key: this.key,
                    mediaType: this.mediaType,
                    trackKind: this.trackKind,
                    chunkIndex: nextChunkIndex,
                    bytes: event.data.size,
                    effectiveChunkStartAt: new Date(effectiveChunkStartMs).toISOString(),
                    captureReadyAt: this.captureReadyAt ? new Date(this.captureReadyAt).toISOString() : null,
                });
                return;
            }

            if (this.stopRequestedAt && effectiveChunkStartMs >= this.stopRequestedAt) {
                this.debug?.('chunk-drop-stop-boundary', {
                    debugSource: 'Producer',
                    callSessionId,
                    mediaId: this.mediaId,
                    key: this.key,
                    mediaType: this.mediaType,
                    trackKind: this.trackKind,
                    chunkIndex: nextChunkIndex,
                    bytes: event.data.size,
                    effectiveChunkStartAt: new Date(effectiveChunkStartMs).toISOString(),
                    stopRequestedAt: new Date(this.stopRequestedAt).toISOString(),
                });
                return;
            }

            if (isWebmLike && !this.hasQueuedInitialChunk) {
                const hasHeader = await blobStartsWithWebmHeader(event.data);
                this.debug?.('initial-header-check', {
                    debugSource: 'Producer',
                    callSessionId,
                    mediaId: this.mediaId,
                    key: this.key,
                    mediaType: this.mediaType,
                    trackKind: this.trackKind,
                    bytes: event.data.size,
                    hasHeader,
                });

                if (!hasHeader) {
                    return;
                }

                markInitial = true;
                this.hasQueuedInitialChunk = true;
                this.hasAcceptedInitialChunk = true;
            }

            const currentChunkIndex = this.nextChunkIndex;
            this.nextChunkIndex += 1;

            if (
                this.key === 'operator-audio'
                && this.unmutedAt
                && this.postUnmuteChunkSamples < 3
            ) {
                this.postUnmuteChunkSamples += 1;
                this.debug?.('post-unmute-chunk-sample', {
                    debugSource: 'Producer',
                    callSessionId,
                    mediaId: this.mediaId,
                    key: this.key,
                    mediaType: this.mediaType,
                    trackKind: this.trackKind,
                    chunkIndex: currentChunkIndex,
                    bytes: event.data.size,
                    deltaMs: Date.now() - this.unmutedAt,
                    sourceTrackEnabled: Boolean(this.sourceTrack?.enabled),
                    sourceTrackMuted: Boolean(this.sourceTrack?.muted),
                    sourceTrackReadyState: this.sourceTrack?.readyState ?? '',
                    clonedTrackEnabled: Boolean(this.clonedTrack?.enabled),
                    clonedTrackMuted: Boolean(this.clonedTrack?.muted),
                    clonedTrackReadyState: this.clonedTrack?.readyState ?? '',
                });
            }

            const payload = {
                incident_id: incidentId,
                call_session_id: callSessionId,
                media_id: this.mediaId,
                type: this.mediaType,
                peer_user_id: this.peerUserId,
                peer_role: this.peerRole,
                track_kind: this.trackKind,
                mime_type: event.data.type || this.mimeType || '',
                extension: this.extension,
                segment_key: this.segmentKey,
                chunk_index: currentChunkIndex,
                total_bytes: event.data.size,
                chunk_blob: event.data,
            };

            this.addChunk(payload, { markInitial });
        })()
            .catch((error) => {
                this.debug?.('dataavailable-process-fail', {
                    debugSource: 'Producer',
                    callSessionId,
                    mediaId: this.mediaId,
                    key: this.key,
                    mediaType: this.mediaType,
                    trackKind: this.trackKind,
                    message: String(error?.message ?? error),
                    nextChunkIndex: this.nextChunkIndex,
                });
            })
            .finally(() => {
                this.pendingChunkTasks.delete(chunkTask);
            });

        this.pendingChunkTasks.add(chunkTask);
    }

    addChunk(payload, { markInitial = false } = {}) {
        const { callSessionId, onRecorderPrimed, showToast, state } = this.options;
        const persistPromise = this.storage.putChunk(payload)
            .then(() => {
                if (markInitial) {
                    this.hasAcceptedInitialChunk = true;
                    if (typeof onRecorderPrimed === 'function') {
                        onRecorderPrimed({
                            key: this.key,
                            mediaId: this.mediaId,
                            mediaType: this.mediaType,
                            trackKind: this.trackKind,
                            segmentKey: this.segmentKey,
                        });
                    }
                }
                state.chunkFailureNotified = false;
                this.updateItem({
                    lastChunkIndex: Number(payload?.chunk_index ?? 0),
                    updatedAt: new Date().toISOString(),
                });
            })
            .catch((error) => {
                if (!state.shuttingDown) {
                    console.warn('Persisting operator media chunk failed.', error);
                }
                this.debug?.('chunk-persist-fail', {
                    debugSource: 'Producer',
                    callSessionId,
                    mediaId: this.mediaId,
                    key: this.key,
                    mediaType: this.mediaType,
                    trackKind: this.trackKind,
                    chunkIndex: payload.chunk_index,
                    bytes: payload.total_bytes,
                    message: String(error?.message ?? error),
                });
                if (!state.shuttingDown && !state.chunkFailureNotified) {
                    state.chunkFailureNotified = true;
                    showToast('Unable to persist media chunk locally.', 'warn');
                }
            })
            .finally(() => {
                this.pendingPersists.delete(persistPromise);
            });

        this.pendingPersists.add(persistPromise);
    }

    close() {
        const { callSessionId, showToast, state } = this.options;

        if (this.stopPromise) {
            return this.stopPromise ?? Promise.resolve();
        }

        this.finalizing = true;
        this.stopRequestedAt = this.stopRequestedAt ?? Date.now();
        this.acceptingStopChunks = true;
        this.debug?.('recorder-stop-begin', {
            debugSource: 'Producer',
            callSessionId,
            mediaId: this.mediaId,
            key: this.key,
            mediaType: this.mediaType,
            trackKind: this.trackKind,
            nextChunkIndex: this.nextChunkIndex,
            hasAcceptedInitialChunk: this.hasAcceptedInitialChunk,
            shuttingDown: state.shuttingDown,
            stopRequestedAt: new Date(this.stopRequestedAt).toISOString(),
        });

        this.stopPromise = new Promise((resolve) => {
            const finalize = async () => {
                try {
                    if (this.pendingChunkTasks.size > 0) {
                        this.debug?.('recorder-stop-await-chunk-tasks', {
                            debugSource: 'Producer',
                            callSessionId,
                            mediaId: this.mediaId,
                            key: this.key,
                            mediaType: this.mediaType,
                            trackKind: this.trackKind,
                            pendingChunkTasks: this.pendingChunkTasks.size,
                        });
                        await Promise.allSettled(Array.from(this.pendingChunkTasks));
                    }

                    if (this.pendingPersists.size > 0) {
                        this.debug?.('recorder-stop-await-persists', {
                            debugSource: 'Producer',
                            callSessionId,
                            mediaId: this.mediaId,
                            key: this.key,
                            mediaType: this.mediaType,
                            trackKind: this.trackKind,
                            pendingPersists: this.pendingPersists.size,
                        });
                        await Promise.allSettled(Array.from(this.pendingPersists));
                    }

                    const stoppedAt = this.stopRequestedAt ?? Date.now();
                    const recordingStartedAt = this.recordingStartedAt ?? this.captureReadyAt ?? new Date(this.startedAt).getTime();
                    const durationSeconds = Math.max(0, Math.round((stoppedAt - recordingStartedAt) / 1000));
                    const closedRecord = {
                        ...this.mediaRecord,
                        ended_at: new Date(stoppedAt).toISOString(),
                        duration_seconds: durationSeconds,
                        status: 'closed',
                        updated_at: new Date().toISOString(),
                    };

                    await this.storage.putRecord(closedRecord);
                    this.mediaRecord = closedRecord;
                    this.updateItem({
                        status: 'closed',
                        updatedAt: new Date().toISOString(),
                    });
                    this.debug?.('producer-closing', {
                        debugSource: 'ProducerManager',
                        mediaId: this.mediaId,
                        key: this.key,
                        mediaType: this.mediaType,
                        trackKind: this.trackKind,
                        status: 'closed',
                        source: 'producer-manager',
                    });
                } catch (error) {
                    console.warn('Operator media finalize failed.', error);
                    this.debug?.('recorder-finalize-fail', {
                        debugSource: 'Producer',
                        callSessionId,
                        mediaId: this.mediaId,
                        key: this.key,
                        mediaType: this.mediaType,
                        trackKind: this.trackKind,
                        message: String(error?.message ?? error),
                        stack: String(error?.stack ?? ''),
                        nextChunkIndex: this.nextChunkIndex,
                    });
                    showToast('Unable to finalize captured media.', 'warn');
                } finally {
                    this.acceptingStopChunks = false;
                    this.stopping = true;
                    this.clonedTracks.forEach((cloned) => {
                        cloned?.stop?.();
                    });
                    this.mediaRecorder.removeEventListener('dataavailable', this.boundHandleDataAvailable);
                    resolve();
                }
            };

            this.mediaRecorder.addEventListener('stop', () => {
                this.stopEventSeen = true;
                this.debug?.('recorder-stop-event', {
                    debugSource: 'Producer',
                    callSessionId,
                    mediaId: this.mediaId,
                    key: this.key,
                    mediaType: this.mediaType,
                    trackKind: this.trackKind,
                    nextChunkIndex: this.nextChunkIndex,
                    pendingChunkTasks: this.pendingChunkTasks.size,
                    pendingPersists: this.pendingPersists.size,
                });
                void finalize();
            }, { once: true });

            if (this.mediaRecorder.state === 'inactive') {
                void finalize();
                return;
            }

            this.mediaRecorder.stop();
        });

        return this.stopPromise;
    }
}
