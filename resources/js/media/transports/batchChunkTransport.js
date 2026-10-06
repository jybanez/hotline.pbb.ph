export const AUDIO_BATCH_BYTES = 1536 * 1024;

export function createOperatorMediaBatchChunkTransport() {
    return {
        batchMaxBytes: AUDIO_BATCH_BYTES,
        async publishBatch(record, chunks) {
            const items = chunks.map(chunk => {
                const blob = chunk?.payload?.chunk_blob;
                if (!(blob instanceof Blob) || !blob.size) throw new Error('Recording chunk is unavailable; local data retained.');
                return { blob, chunk_index: Number(chunk.chunk_index ?? chunk.payload.chunk_index), size: blob.size };
            });
            const blob = new Blob(items.map(item => item.blob), {type: 'application/octet-stream'});
            if (blob.size > AUDIO_BATCH_BYTES) throw new Error('Recording batch exceeds 1.5 MB; local data retained.');
            const data = new FormData();
            data.append('batch', blob, 'recording.batch');
            data.append('manifest', JSON.stringify(items.map(({chunk_index, size}) => ({chunk_index, size}))));
            const response = await window.axios({url: `/api/operator/media/${Number(record.media_id)}/batches`, method:'post', timeout:30000, data, headers:{Accept:'application/json'}});
            const confirmed = response?.data?.chunk_indices;
            if (response?.data?.ok !== true || JSON.stringify(confirmed) !== JSON.stringify(items.map(item => item.chunk_index))) {
                throw new Error('Recording batch storage was not confirmed; local data retained.');
            }
        },
    };
}
