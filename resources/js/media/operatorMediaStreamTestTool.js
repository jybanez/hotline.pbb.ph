import { createDiagnosticMediaClient, finalizeStoppedDiagnosticRecording } from './diagnosticMediaClient.js';
import { mountHelperAudioPlayback } from './helperAudioPlayback.js';
import { createMediaStreamSession, resolveAudioRecorderSpec } from './mediaStreamSession.js';
import { createRealtimeOperatorDiagnosticMediaChunkTransport } from './transports/realtimeChunkTransport.js';
import { ensureHelperUi, showToast } from '../surfaces/surfaceShared.js';

function formatElapsed(seconds) {
    const total = Math.max(0, Number(seconds ?? 0));
    const minutes = Math.floor(total / 60);
    const remainder = total % 60;
    return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
}

function setText(host, selector, value) {
    const nodes = host?.querySelectorAll?.(selector) ?? [];

    nodes.forEach((node) => {
        node.textContent = value;
    });
}

function setPhase(host, phase, message, tone = 'info') {
    const statuses = host?.querySelectorAll?.('[data-media-test-status]') ?? [];

    statuses.forEach((status) => {
        status.dataset.tone = tone;
        status.textContent = `${phase}: ${message}`;
    });
}

function metricMarkup() {
    return `
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
    `;
}

function createStagePage(stage, innerHtml) {
    const page = document.createElement('div');
    page.className = `operator-media-test-stage is-${stage}`;
    page.dataset.mediaTestStagePage = stage;
    page.innerHTML = innerHtml;

    return page;
}

function createFallbackStageStack(container, pages = []) {
    const root = document.createElement('section');
    root.className = 'operator-media-test-stage-stack is-fallback';
    container.appendChild(root);

    const byId = new Map(pages.map((page) => [page.id, page]));
    let currentId = null;
    let currentPage = null;
    let transitionTimer = null;

    const finishTransition = (nextPage, previousPage = null) => {
        transitionTimer = null;
        nextPage.classList.remove('is-entering', 'is-push');
        nextPage.classList.add('is-active');
        previousPage?.remove();
        currentPage = nextPage;
    };

    const goTo = (id, options = {}) => {
        const page = byId.get(id);

        if (!page || currentId === id) {
            return null;
        }

        const previousPage = currentPage;
        const nextPage = createStagePage(id, page.innerHtml ?? '');
        const animate = options.animate !== false && Boolean(previousPage);

        window.clearTimeout(transitionTimer);
        currentId = id;

        if (!animate) {
            root.replaceChildren(nextPage);
            currentPage = nextPage;
            return { id };
        }

        previousPage.classList.remove('is-active', 'is-entering', 'is-push');
        previousPage.classList.add('is-exiting', 'is-push');
        nextPage.classList.add('is-entering', 'is-push');
        root.appendChild(nextPage);
        root.dataset.transitioning = 'true';

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                nextPage.classList.add('is-active');
                nextPage.classList.remove('is-entering');
                previousPage.classList.add('is-hidden');
            });
        });

        transitionTimer = window.setTimeout(() => {
            delete root.dataset.transitioning;
            finishTransition(nextPage, previousPage);
        }, 320);

        return { id };
    };

    goTo(pages[0]?.id, { animate: false });

    return {
        root,
        goTo,
        destroy() {
            window.clearTimeout(transitionTimer);
            root.replaceChildren();
            root.remove();
        },
    };
}

function createStageStack(helper, container, pages) {
    const byId = new Map(pages.map((page) => [page.id, page]));

    if (typeof helper?.createNavigationStack === 'function') {
        const stack = helper.createNavigationStack(container, {
            ariaLabel: 'Media stream storage test flow',
            chrome: false,
            className: 'operator-media-test-stage-stack',
            transition: 'slide',
            duration: 260,
            initialPages: [byId.get('ready')],
        });

        return {
            ...stack,
            goTo(id) {
                const page = byId.get(id);

                if (!page || stack.getState?.().currentPage?.id === id) {
                    return null;
                }

                return stack.push(page);
            },
        };
    }

    return createFallbackStageStack(container, pages);
}

function createLocalReferenceRecorder(stream, spec = {}, MediaRecorderCtor = globalThis.MediaRecorder) {
    if (!stream || !MediaRecorderCtor) {
        return null;
    }

    const referenceStream = typeof stream.clone === 'function' ? stream.clone() : stream;
    const chunks = [];
    let recorder = null;
    let startedAt = null;
    let stopped = false;
    let objectUrl = '';

    function stopTracks() {
        referenceStream?.getTracks?.().forEach((track) => {
            try {
                track.stop?.();
            } catch {
                // Browser media track teardown is best-effort.
            }
        });
    }

    function revoke() {
        if (objectUrl && typeof URL !== 'undefined' && typeof URL.revokeObjectURL === 'function') {
            URL.revokeObjectURL(objectUrl);
        }
        objectUrl = '';
    }

    try {
        recorder = new MediaRecorderCtor(referenceStream, spec?.mimeType ? { mimeType: spec.mimeType } : undefined);
    } catch {
        stopTracks();
        return null;
    }

    recorder.addEventListener('dataavailable', (event) => {
        const blob = event?.data;

        if (blob && Number(blob.size ?? 0) > 0) {
            chunks.push(blob);
        }
    });

    return {
        start() {
            if (!recorder || recorder.state !== 'inactive') {
                return;
            }
            startedAt = Date.now();
            recorder.start();
        },
        async stop() {
            if (!recorder || stopped) {
                return null;
            }

            stopped = true;

            if (recorder.state === 'recording') {
                await new Promise((resolve) => {
                    recorder.addEventListener('stop', resolve, { once: true });
                    recorder.stop();
                });
            }

            stopTracks();

            if (chunks.length === 0) {
                return null;
            }

            revoke();

            const mimeType = chunks.find((chunk) => chunk?.type)?.type || spec?.mimeType || 'audio/webm';
            const blob = new Blob(chunks, { type: mimeType });
            objectUrl = typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function'
                ? URL.createObjectURL(blob)
                : '';

            if (!objectUrl) {
                return null;
            }

            return {
                id: `local-reference-${startedAt ?? Date.now()}`,
                type: 'local_reference_audio',
                playback_url: objectUrl,
                duration_seconds: startedAt ? Math.max(0, Math.round((Date.now() - startedAt) / 1000)) : null,
                peer_label: 'Local reference',
                created_at: startedAt ? new Date(startedAt).toISOString() : new Date().toISOString(),
                available_at: new Date().toISOString(),
                metadata: {
                    diagnostic: true,
                    diagnostic_type: 'operator_media_stream_storage',
                    local_reference: true,
                },
            };
        },
        destroy() {
            try {
                if (recorder?.state === 'recording') {
                    recorder.stop();
                }
            } catch {
                // Ignore recorder shutdown races during modal close.
            }
            stopTracks();
            revoke();
        },
    };
}

async function mountRecordingAudioGraph(host, stream, helper) {
    if (!host || !stream) {
        return { destroy() {} };
    }

    host.replaceChildren();

    const createAudioGraph = typeof helper?.createAudioGraph === 'function'
        ? helper.createAudioGraph
        : await helper?.uiLoader?.get?.('ui.audio.audiograph');

    if (typeof createAudioGraph !== 'function') {
        return { destroy() {} };
    }

    helper.createAudioGraph = createAudioGraph;

    const api = createAudioGraph(host, {
        role: 'operator',
        roleLabel: 'Operator',
        isPlaying: true,
        isLive: true,
        isActive: true,
    }, {
        ariaLabel: 'Live microphone audio graph',
        className: 'operator-media-test-live-audiograph',
        style: 'tsunami',
        transparentBackground: true,
        freezeOnPause: false,
        showMute: false,
        sensitivity: 3.4,
    });

    api?.attachMediaStream?.(stream);
    api?.setPlayback?.({
        isPlaying: true,
        isLive: true,
        isActive: true,
    });
    api?.resume?.();

    return {
        destroy() {
            api?.destroy?.();
            host.replaceChildren();
        },
    };
}

function modalContent(helper) {
    const content = document.createElement('div');
    content.className = 'operator-media-test-tool';
    const stackHost = document.createElement('div');
    stackHost.className = 'operator-media-test-stack-host';
    stackHost.dataset.mediaTestStack = '';

    const readyMarkup = `
        <div class="operator-media-test-status" data-media-test-status data-tone="info">Idle: ready to test microphone capture and media storage.</div>
        <p class="operator-media-test-copy">This diagnostic records microphone audio, sends chunks through Realtime, finalizes them in Hotline storage, and plays back the saved result.</p>
        <footer class="operator-media-test-stage-footer operator-media-test-controls">
            <button class="surface-button primary" type="button" data-media-test-start>Start</button>
        </footer>
    `;
    const recordingMarkup = `
        <div class="operator-media-test-status" data-media-test-status data-tone="info">Recording: capturing microphone audio.</div>
        ${metricMarkup()}
        <div class="operator-media-test-live-graph" data-media-test-live-graph></div>
        <footer class="operator-media-test-stage-footer operator-media-test-controls">
            <button class="surface-button primary" type="button" data-media-test-stop>Stop</button>
        </footer>
    `;
    const finalizingMarkup = `
        <div class="operator-media-test-status" data-media-test-status data-tone="info">Finalize: assembling diagnostic audio.</div>
        ${metricMarkup()}
        <footer class="operator-media-test-stage-footer operator-media-test-controls"></footer>
    `;
    const finalizedMarkup = `
        <div class="operator-media-test-status" data-media-test-status data-tone="success">Complete: diagnostic audio finalized and ready for playback.</div>
        ${metricMarkup()}
        <div class="operator-media-test-playback-compare">
            <section class="operator-media-test-playback-card">
                <h3>Local Reference</h3>
                <div class="operator-media-test-playback" data-media-test-local-playback></div>
            </section>
            <section class="operator-media-test-playback-card">
                <h3>Stored Finalized</h3>
                <div class="operator-media-test-playback" data-media-test-playback></div>
            </section>
        </div>
        <footer class="operator-media-test-stage-footer operator-media-test-controls">
            <button class="surface-button primary" type="button" data-media-test-start>Start Again</button>
        </footer>
    `;
    const errorMarkup = `
        <div class="operator-media-test-status" data-media-test-status data-tone="error">Error: unable to complete media stream test.</div>
        <div class="operator-media-test-error" data-media-test-error></div>
        <footer class="operator-media-test-stage-footer operator-media-test-controls">
            <button class="surface-button primary" type="button" data-media-test-start>Try Again</button>
        </footer>
    `;
    const pages = [
        { id: 'ready', title: 'Ready', innerHtml: readyMarkup, render: () => createStagePage('ready', readyMarkup) },
        { id: 'recording', title: 'Recording', innerHtml: recordingMarkup, render: () => createStagePage('recording', recordingMarkup) },
        { id: 'finalizing', title: 'Finalizing', innerHtml: finalizingMarkup, render: () => createStagePage('finalizing', finalizingMarkup) },
        { id: 'finalized', title: 'Finalized', innerHtml: finalizedMarkup, render: () => createStagePage('finalized', finalizedMarkup) },
        { id: 'error', title: 'Error', innerHtml: errorMarkup, render: () => createStagePage('error', errorMarkup) },
    ];

    content.appendChild(stackHost);
    content.__mediaTestStack = createStageStack(helper, stackHost, pages);
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
    content.__mediaTestStack?.goTo?.('error');
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

    const content = modalContent(helper);
    const client = options.client ?? createDiagnosticMediaClient();
    const chunkTransport = options.chunkTransport ?? createRealtimeOperatorDiagnosticMediaChunkTransport({
        mode: options.transportMode ?? 'realtime-base64',
    });
    const stageStack = content.__mediaTestStack;

    let media = null;
    let record = null;
    let session = null;
    let recordingStartedAt = null;
    let elapsedTimer = null;
    let playbackApi = null;
    let localPlaybackApi = null;
    let liveGraphApi = null;
    let localReferenceRecorder = null;
    let localReferenceMedia = null;
    let chunkCount = 0;
    let isBusy = false;

    const goToStage = (stage) => {
        stageStack?.goTo?.(stage);
    };

    const setButtonsDisabled = (selector, disabled) => {
        content.querySelectorAll(selector).forEach((button) => {
            button.disabled = disabled;
        });
    };

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
        liveGraphApi?.destroy?.();
        liveGraphApi = null;
        localReferenceRecorder?.destroy?.();
        localReferenceRecorder = null;
        localPlaybackApi?.destroy?.();
        localPlaybackApi = null;
        playbackApi?.destroy?.();
        playbackApi = null;
        stopElapsedTimer();

        if (media?.id) {
            try {
                await cancelDiagnosticMedia('operator_reset');
            } catch (error) {
                showError(content, 'Reset', error);
            }
        }

        media = null;
        record = null;
        localReferenceMedia = null;
        recordingStartedAt = null;
        chunkCount = 0;
        isBusy = false;
        setButtonsDisabled('[data-media-test-start], [data-media-test-stop]', false);
        setText(content, '[data-media-test-chunks]', '0');
        setText(content, '[data-media-test-elapsed]', '00:00');
        setText(content, '[data-media-test-finalize]', 'Not started');
        setPhase(content, 'Idle', 'ready to test microphone capture and media storage.');
        clearError(content);
        goToStage('ready');
    };

    const startRecording = async () => {
        if (isBusy) {
            return;
        }

        isBusy = true;
        clearError(content);
        localReferenceRecorder?.destroy?.();
        localReferenceRecorder = null;
        localPlaybackApi?.destroy?.();
        localPlaybackApi = null;
        playbackApi?.destroy?.();
        playbackApi = null;
        stopElapsedTimer();
        recordingStartedAt = null;
        chunkCount = 0;
        setPhase(content, 'Create', 'creating diagnostic media record.');
        setButtonsDisabled('[data-media-test-start], [data-media-test-stop]', true);

        try {
            const spec = resolveAudioRecorderSpec();
            const segmentKey = `operator-diagnostic-${Date.now()}`;
            const response = await client.createSession({
                mime_type: spec.mimeType,
                extension: spec.extension,
                track_kind: 'audio',
                segment_key: segmentKey,
            });

            media = response?.media;
            record = {
                media_id: media?.id,
                call_session_id: 0,
                status: 'recording',
                extension: spec.extension,
                mime_type: spec.mimeType,
                segment_key: segmentKey,
                created_at: new Date().toISOString(),
            };

            session = createMediaStreamSession({
                onStateChange(event) {
                    if (event.state === 'recording') {
                        setPhase(content, 'Recording', 'capturing microphone audio.');
                    }
                },
                async onChunk(chunk) {
                    await chunkTransport.publishChunk({
                        media_id: media.id,
                        incident_id: null,
                        call_session_id: null,
                        segment_key: media?.metadata?.segment_key ?? record?.segment_key ?? '',
                        type: 'operator_media_stream_test',
                        peer_user_id: media?.peer_user_id,
                        peer_role: 'operator',
                        track_kind: 'audio',
                        mime_type: chunk?.mime_type ?? spec.mimeType,
                        extension: chunk?.extension ?? spec.extension,
                        chunk_index: chunk?.chunk_index ?? 0,
                        chunk_total: null,
                        total_bytes: chunk?.blob?.size ?? null,
                        chunk_blob: chunk.blob,
                    }, record);
                    chunkCount = Math.max(chunkCount, Number(chunk?.chunk_index ?? 0) + 1);
                    setText(content, '[data-media-test-chunks]', String(chunkCount));
                },
                onError(error, detail = {}) {
                    showError(content, detail.phase ?? 'Recording', error);
                },
            });

            await session.start();
            localReferenceRecorder = createLocalReferenceRecorder(session.getStream?.(), spec);
            localReferenceRecorder?.start?.();
            recordingStartedAt = Date.now();
            elapsedTimer = setInterval(updateElapsed, 500);
            goToStage('recording');
            liveGraphApi?.destroy?.();
            const graphHost = content.querySelector('[data-media-test-live-graph]');
            liveGraphApi = await mountRecordingAudioGraph(graphHost, session.getStream?.(), helper);
            setText(content, '[data-media-test-chunks]', '0');
            setText(content, '[data-media-test-finalize]', 'Waiting for stop');
            updateElapsed();
        } catch (error) {
            showError(content, 'Start', error);
            liveGraphApi?.destroy?.();
            liveGraphApi = null;
            localReferenceRecorder?.destroy?.();
            localReferenceRecorder = null;
            session?.destroy?.();
            session = null;
            try {
                await cancelDiagnosticMedia('start_failed');
            } catch (cancelError) {
                showError(content, 'Cleanup', cancelError);
            }
        } finally {
            isBusy = false;
            setButtonsDisabled('[data-media-test-start], [data-media-test-stop]', false);
        }
    };

    const stopRecording = async () => {
        if (isBusy || !session || !media?.id) {
            return;
        }

        isBusy = true;
        liveGraphApi?.destroy?.();
        liveGraphApi = null;
        setButtonsDisabled('[data-media-test-start]', true);
        setButtonsDisabled('[data-media-test-stop]', true);
        clearError(content);
        goToStage('finalizing');
        setText(content, '[data-media-test-chunks]', String(chunkCount));
        updateElapsed();
        setPhase(content, 'Stop', 'flushing final media chunks.');

        try {
            setText(content, '[data-media-test-finalize]', 'Uploading final metadata...');
            setPhase(content, 'Finalize', 'assembling diagnostic audio.');
            modal?.setBusy?.(true, { message: 'Uploading final metadata...' });
            const stoppedAtMs = Date.now();
            localReferenceMedia = await localReferenceRecorder?.stop?.();
            localReferenceRecorder = null;
            const { stopped, finalized } = await finalizeStoppedDiagnosticRecording({
                session,
                client,
                media,
                record,
                recordingStartedAt,
                now: () => stoppedAtMs,
            });
            chunkCount = Math.max(chunkCount, Number(stopped?.chunk_count ?? 0));
            stopElapsedTimer();

            media = finalized?.media ?? media;
            goToStage('finalized');
            setText(content, '[data-media-test-chunks]', String(chunkCount));
            updateElapsed();
            setText(content, '[data-media-test-finalize]', media?.discarded ? 'Discarded: no chunks captured' : 'Complete');
            setPhase(content, 'Complete', media?.discarded ? 'no audio chunks were captured.' : 'diagnostic audio finalized and ready for playback.', media?.discarded ? 'warn' : 'success');
            localPlaybackApi?.destroy?.();
            const localPlaybackHost = content.querySelector('[data-media-test-local-playback]');
            localPlaybackApi = await mountHelperAudioPlayback(localPlaybackHost, localReferenceMedia, { helper });
            playbackApi?.destroy?.();
            const playbackHost = content.querySelector('[data-media-test-playback]');
            playbackApi = await mountHelperAudioPlayback(playbackHost, media, { helper });
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
            modal?.setBusy?.(false);
            isBusy = false;
            setButtonsDisabled('[data-media-test-start]', false);
            setButtonsDisabled('[data-media-test-stop]', false);
        }
    };

    content.addEventListener('click', (event) => {
        const button = event.target?.closest?.('[data-media-test-start], [data-media-test-stop]');

        if (!button) {
            return;
        }

        if (button.matches('[data-media-test-start]')) {
            void startRecording();
            return;
        }

        if (button.matches('[data-media-test-stop]')) {
            void stopRecording();
            return;
        }

    });

    const modal = helper.createActionModal({
        title: 'Test Media Stream Storage',
        ariaLabel: 'Test media stream storage',
        size: 'md',
        content,
        closeOnBackdrop: false,
        onClose() {
            session?.destroy?.();
            liveGraphApi?.destroy?.();
            localReferenceRecorder?.destroy?.();
            localPlaybackApi?.destroy?.();
            playbackApi?.destroy?.();
            stageStack?.destroy?.();
            stopElapsedTimer();
            chunkTransport?.destroy?.(media?.id);
            void cancelDiagnosticMedia('modal_closed');
        },
    });

    modal.open();
    root?.dispatchEvent?.(new CustomEvent('operator-media-test-opened'));

    return modal;
}
