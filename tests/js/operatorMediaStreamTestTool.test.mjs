import assert from 'node:assert/strict';
import {
    createDiagnosticMediaClient,
    finalizeStoppedDiagnosticRecording,
} from '../../resources/js/media/diagnosticMediaClient.js';
import { createMediaStreamSession, resolveAudioRecorderSpec } from '../../resources/js/media/mediaStreamSession.js';

class FakeTrack {
    constructor() {
        this.stopped = false;
    }

    stop() {
        this.stopped = true;
    }
}

class FakeMediaRecorder extends EventTarget {
    static supported = new Set(['audio/webm;codecs=opus']);

    static isTypeSupported(mimeType) {
        return FakeMediaRecorder.supported.has(mimeType);
    }

    constructor(stream, options = {}) {
        super();
        this.stream = stream;
        this.options = options;
        this.state = 'inactive';
        FakeMediaRecorder.instances.push(this);
    }

    start(timeslice) {
        this.timeslice = timeslice;
        this.state = 'recording';
    }

    stop() {
        this.dispatchEvent(new MessageEvent('dataavailable', {
            data: new Blob(['diagnostic-audio'], { type: this.options.mimeType }),
        }));
        this.state = 'inactive';
        this.dispatchEvent(new Event('stop'));
    }
}

FakeMediaRecorder.instances = [];

const spec = resolveAudioRecorderSpec(FakeMediaRecorder);
assert.deepEqual(spec, { mimeType: 'audio/webm;codecs=opus', extension: 'weba' });

const track = new FakeTrack();
const chunks = [];
const states = [];
const session = createMediaStreamSession({
    MediaRecorderCtor: FakeMediaRecorder,
    timesliceMs: 250,
    getUserMedia: async () => ({
        getTracks: () => [track],
    }),
    onChunk: async (chunk) => {
        chunks.push(chunk);
    },
    onStateChange: (event) => {
        states.push(event.state);
    },
});

await session.start();
assert.equal(session.getState(), 'recording');
assert.equal(FakeMediaRecorder.instances[0].timeslice, 250);
await session.stop();
assert.equal(session.getState(), 'idle');
assert.equal(chunks.length, 1);
assert.equal(chunks[0].chunk_index, 0);
assert.equal(chunks[0].extension, 'weba');
assert.equal(track.stopped, true);
assert.deepEqual(states, ['starting', 'recording', 'stopping', 'idle']);

const failedTrack = new FakeTrack();
const failedSession = createMediaStreamSession({
    MediaRecorderCtor: FakeMediaRecorder,
    getUserMedia: async () => ({
        getTracks: () => [failedTrack],
    }),
    onChunk: async () => {
        throw new Error('chunk upload failed');
    },
});

await failedSession.start();
await assert.rejects(
    () => failedSession.stop(),
    /One or more media chunks failed to upload/,
);
assert.equal(failedSession.getState(), 'error');
assert.equal(failedSession.getFailedChunks().length, 1);
assert.equal(failedTrack.stopped, true);

let finalizeCalledAfterFailure = false;
await failedSession.start();
await assert.rejects(
    () => finalizeStoppedDiagnosticRecording({
        session: failedSession,
        client: {
            async finalize() {
                finalizeCalledAfterFailure = true;
                return { media: { id: 77 } };
            },
        },
        media: { id: 77, processing: true },
        record: { extension: 'weba' },
        recordingStartedAt: 1000,
        now: () => 3000,
    }),
    /One or more media chunks failed to upload/,
);
assert.equal(finalizeCalledAfterFailure, false);

const requests = [];
const client = createDiagnosticMediaClient({
    async request(url, options = {}) {
        requests.push({ url, options });

        if (url === '/api/operator/media-tests') {
            return { media: { id: 42 } };
        }

        if (url.includes('/chunks')) {
            assert.ok(options.data instanceof FormData);
            return { chunk: { chunk_count: 1 } };
        }

        return { media: { id: 42, available_at: new Date().toISOString() } };
    },
});

await client.createSession({ mime_type: spec.mimeType, extension: spec.extension });
await client.uploadChunk(42, {
    chunk_index: 0,
    chunk_blob: new Blob(['chunk'], { type: spec.mimeType }),
    extension: spec.extension,
});
await client.finalize(42, { duration_seconds: 1, extension: spec.extension });
await client.cancel(42, { reason: 'operator_reset' });

assert.equal(requests[0].url, '/api/operator/media-tests');
assert.equal(requests[0].options.data.track_kind, 'audio');
assert.equal(requests[1].url, '/api/operator/media-tests/42/chunks');
assert.equal(requests[2].url, '/api/operator/media-tests/42/finalize');
assert.equal(requests[2].options.data.duration_seconds, 1);
assert.equal(requests[3].url, '/api/operator/media-tests/42');
assert.equal(requests[3].options.method, 'delete');
assert.equal(requests[3].options.data.reason, 'operator_reset');
