export function createOperatorMediaFinalizer() {
    return {
        async finalizeRecord(record, options = {}) {
            const nextMediaId = Number(record?.media_id ?? 0);

            if (nextMediaId <= 0) {
                return null;
            }

            const finalChunks = Array.isArray(options?.finalChunks) ? options.finalChunks : [];
            const data = new FormData();

            data.append('duration_seconds', String(Number(record?.duration_seconds ?? 0)));
            data.append('ended_at', String(record?.ended_at ?? new Date().toISOString()));
            data.append('extension', String(record?.extension ?? ''));

            finalChunks.forEach((chunk, index) => {
                const payload = chunk?.payload ?? {};
                const chunkIndex = Number(chunk?.chunk_index ?? payload?.chunk_index ?? index);
                const blob = payload?.chunk_blob instanceof Blob ? payload.chunk_blob : null;

                if (!blob) {
                    return;
                }

                const extension = String(payload?.extension ?? record?.extension ?? 'webm').trim() || 'webm';
                data.append(`final_chunks[${index}][chunk_index]`, String(chunkIndex));
                data.append(`final_chunks[${index}][chunk]`, blob, `final-${String(chunkIndex).padStart(6, '0')}.${extension}`);
            });

            const response = await window.axios({
                url: `/api/operator/media/${nextMediaId}/finalize`,
                method: 'post',
                data,
                headers: {
                    Accept: 'application/json',
                },
            });

            return response?.data ?? null;
        },
    };
}
