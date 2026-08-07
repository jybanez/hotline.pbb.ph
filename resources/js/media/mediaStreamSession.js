export function resolveAudioRecorderSpec(MediaRecorderCtor = globalThis.MediaRecorder) {
    const candidates = [
        { mimeType: 'audio/webm;codecs=opus', extension: 'weba' },
        { mimeType: 'audio/webm', extension: 'weba' },
        { mimeType: 'audio/ogg;codecs=opus', extension: 'ogg' },
    ];

    const supported = candidates.find((candidate) => {
        if (!MediaRecorderCtor || typeof MediaRecorderCtor.isTypeSupported !== 'function') {
            return true;
        }

        return MediaRecorderCtor.isTypeSupported(candidate.mimeType);
    });

    return supported ?? { mimeType: '', extension: 'webm' };
}

export function createMediaStreamSession(options = {}) {
    const {
        getUserMedia = (constraints) => globalThis.navigator?.mediaDevices?.getUserMedia?.(constraints),
        MediaRecorderCtor = globalThis.MediaRecorder,
        timesliceMs = 2000,
        constraints = { audio: true },
        onChunk = () => {},
        onStateChange = () => {},
        onError = () => {},
    } = options;

    let recorder = null;
    let stream = null;
    let state = 'idle';
    let chunkIndex = 0;
    const pendingChunks = new Set();
    const failedChunks = [];
    const spec = resolveAudioRecorderSpec(MediaRecorderCtor);

    function emitState(nextState, detail = {}) {
        state = nextState;
        onStateChange({ state, ...detail });
    }

    function stopTracks() {
        stream?.getTracks?.().forEach((track) => {
            try {
                track.stop?.();
            } catch {
                // Browser media track teardown is best-effort.
            }
        });
        stream = null;
    }

    async function waitForPendingChunks() {
        if (pendingChunks.size === 0) {
            if (failedChunks.length > 0) {
                throw new AggregateError(
                    failedChunks.map((failure) => failure.error),
                    'One or more media chunks failed to upload.',
                );
            }

            return;
        }

        await Promise.allSettled([...pendingChunks]);

        if (failedChunks.length > 0) {
            throw new AggregateError(
                failedChunks.map((failure) => failure.error),
                'One or more media chunks failed to upload.',
            );
        }
    }

    async function start() {
        if (state === 'recording' || state === 'starting') {
            return { state, spec };
        }

        if (!getUserMedia || !MediaRecorderCtor) {
            throw new Error('Browser media recording APIs are unavailable.');
        }

        emitState('starting');

        try {
            stream = await getUserMedia(constraints);
            recorder = new MediaRecorderCtor(stream, spec.mimeType ? { mimeType: spec.mimeType } : undefined);
            chunkIndex = 0;
            failedChunks.splice(0, failedChunks.length);

            recorder.addEventListener('dataavailable', (event) => {
                const blob = event?.data;

                if (!blob || Number(blob.size ?? 0) <= 0) {
                    return;
                }

                const nextIndex = chunkIndex;
                chunkIndex += 1;

                const promise = Promise.resolve(onChunk({
                    blob,
                    chunk_index: nextIndex,
                    chunk_count: chunkIndex,
                    mime_type: blob.type || spec.mimeType,
                    extension: spec.extension,
                })).catch((error) => {
                    failedChunks.push({ chunk_index: nextIndex, error });
                    onError(error, { phase: 'chunk', chunk_index: nextIndex });
                    throw error;
                }).finally(() => {
                    pendingChunks.delete(promise);
                });

                pendingChunks.add(promise);
            });

            recorder.addEventListener('error', (event) => {
                const error = event?.error ?? new Error('MediaRecorder failed.');
                onError(error, { phase: 'recording' });
            });

            recorder.start(timesliceMs);
            emitState('recording', { spec });
            return { state, spec };
        } catch (error) {
            stopTracks();
            recorder = null;
            emitState('error', { error });
            throw error;
        }
    }

    async function stop() {
        if (!recorder || state !== 'recording') {
            try {
                await waitForPendingChunks();
                stopTracks();
                emitState('idle');
                return { state, chunk_count: chunkIndex };
            } catch (error) {
                stopTracks();
                emitState('error', { error });
                throw error;
            }
        }

        emitState('stopping');

        await new Promise((resolve) => {
            const currentRecorder = recorder;
            currentRecorder.addEventListener('stop', resolve, { once: true });
            currentRecorder.stop();
        });

        stopTracks();
        recorder = null;
        try {
            await waitForPendingChunks();
        } catch (error) {
            emitState('error', { error });
            throw error;
        }

        emitState('idle');

        return { state, chunk_count: chunkIndex };
    }

    function destroy() {
        if (recorder && state === 'recording') {
            try {
                recorder.stop();
            } catch {
                // Ignore recorder shutdown races during modal close.
            }
        }

        stopTracks();
        recorder = null;
        pendingChunks.clear();
        emitState('idle');
    }

    return {
        start,
        stop,
        destroy,
        getState: () => state,
        getStream: () => stream,
        getChunkCount: () => chunkIndex,
        getFailedChunks: () => [...failedChunks],
        getSpec: () => spec,
    };
}
