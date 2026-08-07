async function resolveAudioCallSession(helper) {
    if (typeof helper?.createAudioCallSession === 'function') {
        return helper.createAudioCallSession;
    }

    if (typeof helper?.uiLoader?.get === 'function') {
        const createAudioCallSession = await helper.uiLoader.get('ui.audio.callSession');
        helper.createAudioCallSession = createAudioCallSession;
        return createAudioCallSession;
    }

    return null;
}

function helperRecordingRole(media) {
    const startedAt = String(
        media?.metadata?.started_at
        ?? media?.available_at
        ?? media?.created_at
        ?? ''
    ).trim();
    const startedMs = Date.parse(startedAt);
    const timestampToken = Number.isFinite(startedMs)
        ? new Date(startedMs).toISOString().replace(/\.\d{3}Z$/, 'Z').replace(/:/g, '-')
        : '';
    const mediaId = Number(media?.id ?? 0);

    return timestampToken && mediaId
        ? `operator-${mediaId}-${timestampToken}`
        : '';
}

export async function mountHelperAudioPlayback(host, media = {}, options = {}) {
    if (!host) {
        return { destroy() {} };
    }

    const helper = options.helper ?? null;
    const playbackUrl = String(media?.playback_url ?? '').trim();

    host.replaceChildren();

    if (!playbackUrl) {
        const empty = document.createElement('p');
        empty.className = 'surface-empty';
        empty.textContent = 'No finalized audio is available yet.';
        host.appendChild(empty);
        return { destroy() {} };
    }

    const createAudioCallSession = await resolveAudioCallSession(helper);

    if (typeof createAudioCallSession === 'function') {
        const metadata = media?.metadata && typeof media.metadata === 'object' ? media.metadata : {};
        const recordingRole = helperRecordingRole(media);

        const api = createAudioCallSession(host, {
            call_duration_seconds: media.duration_seconds ?? null,
            media: [{
                id: media.id,
                type: 'audio',
                path: playbackUrl,
                srcUrl: playbackUrl,
                duration_seconds: media.duration_seconds ?? null,
                peer_role: 'operator',
                peer_label: media.peer_label ?? 'Operator diagnostic',
                created_at: media.created_at ?? null,
                available_at: media.available_at ?? null,
                metadata: {
                    ...metadata,
                    peer_role: 'operator',
                    recording_role: recordingRole || metadata.recording_role || '',
                },
            }],
            call_session: {
                id: `diagnostic-${media.id ?? 'media'}`,
                status: 'Ended',
            },
        }, {
            className: 'operator-media-test-audio-player',
            readonly: true,
        });

        return {
            destroy() {
                api?.destroy?.();
            },
        };
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'operator-media-test-native-audio';

    const label = document.createElement('p');
    label.className = 'hero-copy';
    label.textContent = 'Helper audio playback component is unavailable in this vendored runtime; using native browser audio controls.';

    const audio = document.createElement('audio');
    audio.controls = true;
    audio.preload = 'metadata';
    audio.src = playbackUrl;

    wrapper.append(label, audio);
    host.appendChild(wrapper);

    return {
        destroy() {
            audio.pause();
            host.replaceChildren();
        },
    };
}
