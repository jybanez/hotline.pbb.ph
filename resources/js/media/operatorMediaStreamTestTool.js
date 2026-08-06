import { createDiagnosticMediaClient, finalizeStoppedDiagnosticRecording } from './diagnosticMediaClient.js';
import { mountHelperAudioPlayback } from './helperAudioPlayback.js';
import { createMediaQueueStorage } from './mediaQueueStorage.js';
import { createMediaStreamSession, resolveAudioRecorderSpec } from './mediaStreamSession.js';
import { ensureHelperUi, showToast } from '../surfaces/surfaceShared.js';

function formatElapsed(seconds) {
    const total = Math.max(0, Number(seconds ?? 0));
    const minutes = Math.floor(total / 60);
    const remainder = total % 60;
    return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
}

function setText(host, selector, value) {
    const node = host?.querySelector?.(selector);

    if (node) {
        node.textContent = value;
    }
}

function setPhase(host, phase, message, tone = 'info') {
    const status = host?.querySelector?.('[data-media-test-status]');

    if (!status) {
        return;
    }

    status.dataset.tone = tone;
    status.textContent = `${phase}: ${message}`;
}

function modalContent() {
    const content = document.createElement('div');
    content.className = 'operator-media-test-tool';
    content.innerHTML = `
        <div class="operator-media-test-status" data-media-test-status data-tone="info">Idle: ready to test microphone capture and media storage.</div>
        <div class="operator-media-test-controls">
            <button class="surface-button primary" type="button" data-media-test-toggle>Start</button>
            <button class="surface-button secondary" type="button" data-media-test-reset disabled>Reset</button>
        </div>
        <dl class="operator-media-test-metrics">
            <div>
                <dt>Chunks</dt>
                <dd data-media-test-chunks>0</dd>
            </div>
            <div>
                <dt>Elapsed</dt>
                <dd data-media-test-elapsed>00:00</dd>
            </div>
            <div>
                <dt>Finalize</dt>
                <dd data-media-test-finalize>Not started</dd>
            </div>
        </dl>
        <div class="operator-media-test-error" data-media-test-error hidden></div>
        <div class="operator-media-test-playback" data-media-test-playback></div>
    `;

    return content;
}

function showError(content, phase, error) {
    const node = content.querySelector('[data-media-test-error]');
    const message = error?.response?.data?.message
        ?? error?.message
        ?? 'Unable to complete media stream test.';

    if (node) {
        node.hidden = false;
        node.textContent = `${phase}: ${message}`;
    }

    setPhase(content, phase, message, 'error');
}

function clearError(content) {
    const node = content.querySelector('[data-media-test-error]');

    if (node) {
        node.hidden = true;
        node.textContent = '';
    }
}

function isProcessingDiagnostic(media) {
    return Boolean(media?.id && media?.processing && !media?.discarded);
}

export async function openOperatorMediaStreamTestTool(root, options = {}) {
    const helper = await ensureHelperUi();

    if (typeof helper.createActionModal !== 'function') {
        showToast('Media stream test modal is unavailable right now.', 'warn');
        return null;
    }

    const content = modalContent();
    const client = options.client ?? createDiagnosticMediaClient();
    const queue = options.queue ?? createMediaQueueStorage();
    const toggleButton = content.querySelector('[data-media-test-toggle]');
    const resetButton = content.querySelector('[data-media-test-reset]');
    const playbackHost = content.querySelector('[data-media-test-playback]');

    let media = null;
    let record = null;
    let session = null;
    let recordingStartedAt = null;
    let elapsedTimer = null;
    let playbackApi = null;
    let chunkCount = 0;
    let isBusy = false;

    const cancelDiagnosticMedia = async (reason) => {
        if (!isProcessingDiagnostic(media)) {
            return;
        }

        const mediaId = media.id;
        const response = await client.cancel(mediaId, { reason });
        media = response?.media ?? {
            ...media,
            processing: false,
            discarded: true,
        };
        await queue.clearDiagnosticMedia(mediaId);
    };

    const updateElapsed = () => {
        if (!recordingStartedAt) {
            setText(content, '[data-media-test-elapsed]', '00:00');
            return;
        }

        setText(content, '[data-media-test-elapsed]', formatElapsed(Math.floor((Date.now() - recordingStartedAt) / 1000)));
    };

    const stopElapsedTimer = () => {
        if (elapsedTimer) {
            clearInterval(elapsedTimer);
            elapsedTimer = null;
        }
    };

    const resetState = async () => {
        session?.destroy?.();
        session = null;
        playbackApi?.destroy?.();
        playbackApi = null;
        stopElapsedTimer();

        if (media?.id) {
            try {
                await cancelDiagnosticMedia('operator_reset');
            } catch (error) {
                showError(content, 'Reset', error);
            }

            await queue.clearDiagnosticMedia(media.id);
        }

        media = null;
        record = null;
        recordingStartedAt = null;
        chunkCount = 0;
        isBusy = false;
        toggleButton.disabled = false;
        toggleButton.textContent = 'Start';
        resetButton.disabled = true;
        setText(content, '[data-media-test-chunks]', '0');
        setText(content, '[data-media-test-elapsed]', '00:00');
        setText(content, '[data-media-test-finalize]', 'Not started');
        setPhase(content, 'Idle', 'ready to test microphone capture and media storage.');
        clearError(content);
        playbackHost?.replaceChildren();
    };

    const startRecording = async () => {
        if (isBusy) {
            return;
        }

        isBusy = true;
        clearError(content);
        setPhase(content, 'Create', 'creating diagnostic media record.');
        toggleButton.disabled = true;

        try {
            const spec = resolveAudioRecorderSpec();
            const response = await client.createSession({
                mime_type: spec.mimeType,
                extension: spec.extension,
                track_kind: 'audio',
                segment_key: `operator-diagnostic-${Date.now()}`,
            });

            media = response?.media;
            record = {
                media_id: media?.id,
                call_session_id: 0,
                status: 'recording',
                extension: spec.extension,
                mime_type: spec.mimeType,
                created_at: new Date().toISOString(),
            };
            await queue.putRecord(record);

            session = createMediaStreamSession({
                onStateChange(event) {
                    if (event.state === 'recording') {
                        setPhase(content, 'Recording', 'capturing microphone audio.');
                    }
                },
                async onChunk(chunk) {
                    await queue.enqueueDiagnosticChunk(record, chunk);
                    const uploaded = await client.uploadChunk(media.id, {
                        ...chunk,
                        chunk_blob: chunk.blob,
                    });
                    chunkCount = Number(uploaded?.chunk?.chunk_count ?? chunk.chunk_count ?? chunkCount + 1);
                    setText(content, '[data-media-test-chunks]', String(chunkCount));
                },
                onError(error, detail = {}) {
                    showError(content, detail.phase ?? 'Recording', error);
                },
            });

            await session.start();
            recordingStartedAt = Date.now();
            elapsedTimer = setInterval(updateElapsed, 500);
            updateElapsed();
            toggleButton.textContent = 'Stop';
            resetButton.disabled = false;
        } catch (error) {
            showError(content, 'Start', error);
            session?.destroy?.();
            session = null;
            try {
                await cancelDiagnosticMedia('start_failed');
            } catch (cancelError) {
                showError(content, 'Cleanup', cancelError);
            }
            toggleButton.textContent = 'Start';
            resetButton.disabled = false;
        } finally {
            isBusy = false;
            toggleButton.disabled = false;
        }
    };

    const stopRecording = async () => {
        if (isBusy || !session || !media?.id) {
            return;
        }

        isBusy = true;
        toggleButton.disabled = true;
        clearError(content);
        setPhase(content, 'Stop', 'flushing final media chunks.');

        try {
            setText(content, '[data-media-test-finalize]', 'Uploading final metadata...');
            setPhase(content, 'Finalize', 'assembling diagnostic audio.');
            const { stopped, finalized } = await finalizeStoppedDiagnosticRecording({
                session,
                client,
                media,
                record,
                recordingStartedAt,
            });
            chunkCount = Math.max(chunkCount, Number(stopped?.chunk_count ?? 0));
            setText(content, '[data-media-test-chunks]', String(chunkCount));
            stopElapsedTimer();

            media = finalized?.media ?? media;
            await queue.clearDiagnosticMedia(media.id);
            setText(content, '[data-media-test-finalize]', media?.discarded ? 'Discarded: no chunks captured' : 'Complete');
            setPhase(content, 'Complete', media?.discarded ? 'no audio chunks were captured.' : 'diagnostic audio finalized and ready for playback.', media?.discarded ? 'warn' : 'success');
            playbackApi?.destroy?.();
            playbackApi = mountHelperAudioPlayback(playbackHost, media, { helper });
            toggleButton.textContent = 'Start';
            resetButton.disabled = false;
            session = null;
        } catch (error) {
            showError(content, 'Finalize', error);
            setText(content, '[data-media-test-finalize]', 'Failed');
            try {
                await cancelDiagnosticMedia('recording_failed');
            } catch (cancelError) {
                showError(content, 'Cleanup', cancelError);
            }
        } finally {
            isBusy = false;
            toggleButton.disabled = false;
        }
    };

    toggleButton?.addEventListener('click', () => {
        if (session?.getState?.() === 'recording') {
            void stopRecording();
        } else {
            void startRecording();
        }
    });

    resetButton?.addEventListener('click', () => {
        void resetState();
    });

    const modal = helper.createActionModal({
        title: 'Test Media Stream Storage',
        ariaLabel: 'Test media stream storage',
        size: 'md',
        content,
        closeOnBackdrop: false,
        actions: [
            {
                id: 'close',
                label: 'Close',
                variant: 'default',
            },
        ],
        onClose() {
            session?.destroy?.();
            playbackApi?.destroy?.();
            stopElapsedTimer();
            void cancelDiagnosticMedia('modal_closed');
        },
    });

    modal.open();
    root?.dispatchEvent?.(new CustomEvent('operator-media-test-opened'));

    return modal;
}
