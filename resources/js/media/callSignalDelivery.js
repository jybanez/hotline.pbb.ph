export function createCallSignalDelivery({ send, timeoutMs = 10000, onResult = () => {} }) {
    const pending = new Map();
    const finish = (id, result) => {
        const entry = pending.get(id);
        if (!entry) return;
        clearTimeout(entry.timer);
        pending.delete(id);
        onResult(result);
        entry.resolve(result);
    };
    return {
        publish(type, payload) {
            let id;
            try { id = send(type, payload); } catch { /* Preserve the confirmed HTTP outcome. */ }
            if (!id) return Promise.resolve({ accepted: false, reason: 'socket-unavailable', signalType: type });
            return new Promise(resolve => {
                const timer = setTimeout(() => finish(id, { accepted: false, reason: 'ack-timeout', signalType: type }), timeoutMs);
                pending.set(id, {resolve, timer, type});
            });
        },
        handle(envelope) {
            const entry = pending.get(String(envelope?.id ?? ''));
            if (!entry || envelope?.type !== 'call.signal.publish' || !['ack', 'error'].includes(envelope?.phase)) return false;
            finish(String(envelope.id), {accepted: envelope.phase === 'ack', reason: envelope.phase, signalType: entry.type});
            return true;
        },
        destroy() {
            for (const [id, entry] of pending) finish(id, {accepted: false, reason: 'runtime-closed', signalType: entry.type});
        },
    };
}
