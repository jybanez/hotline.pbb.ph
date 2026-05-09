export function createOperatorMediaFinalizer() {
    return {
        async finalizeRecord(record) {
            const nextMediaId = Number(record?.media_id ?? 0);
            window.axios({
                url: `/api/operator/media/${nextMediaId}/finalize`,
                method: 'post',
                data: {
                    duration_seconds: Number(record?.duration_seconds ?? 0),
                    ended_at: String(record?.ended_at ?? new Date().toISOString()),
                    extension: String(record?.extension ?? ''),
                },
                headers: {
                    Accept: 'application/json',
                },
            }).catch(() => undefined);
        },
    };
}
