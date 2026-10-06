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
            if (Number.isInteger(record?.expected_chunk_count)) data.append('expected_chunk_count', String(record.expected_chunk_count));
            data.append('ended_at', String(record?.ended_at ?? new Date().toISOString()));
            data.append('extension', String(record?.extension ?? ''));

            // Keep the final multipart request below PHP file-count/body limits.
            // Upload large backlogs individually, then finalize only after all succeed.
            const uploadSeparately = finalChunks.length > 10
                || finalChunks.reduce((size, chunk) => size + Number(chunk?.payload?.chunk_blob?.size ?? 0), 0) > 6 * 1024 * 1024;
            if (uploadSeparately) {
                for (const [index, chunk] of finalChunks.entries()) {
                    const payload = chunk?.payload ?? {};
                    const blob = payload.chunk_blob instanceof Blob ? payload.chunk_blob : null;
                    if (!blob) {
                        throw new Error('Recording chunk is unavailable. The local recording has been retained.');
                    }
                    const chunkIndex = Number(chunk?.chunk_index ?? payload.chunk_index ?? index);
                    const extension = String(payload.extension ?? record?.extension ?? 'webm').trim() || 'webm';
                    const chunkData = new FormData();
                    chunkData.append('chunk_index', String(chunkIndex));
                    chunkData.append('chunk', blob, `final-${String(chunkIndex).padStart(6, '0')}.${extension}`);
                    await window.axios({
                        url: `/api/operator/media/${nextMediaId}/chunks`,
                        method: 'post',
                        data: chunkData,
                        headers: { Accept: 'application/json' },
                    });
                }
            }

            (uploadSeparately ? [] : finalChunks).forEach((chunk, index) => {
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
