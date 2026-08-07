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
        const api = createAudioCallSession(host, {
            media: [{
                id: media.id,
                type: media.type ?? 'operator_media_stream_test',
                path: playbackUrl,
                url: playbackUrl,
                playback_url: playbackUrl,
                duration_seconds: media.duration_seconds ?? null,
                peer_label: media.peer_label ?? 'Operator diagnostic',
                available_at: media.available_at ?? null,
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
