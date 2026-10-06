import assert from 'node:assert/strict';
import {Consumer} from '../../resources/js/media/consumer.js';
import {createOperatorMediaBatchChunkTransport,AUDIO_BATCH_BYTES} from '../../resources/js/media/transports/batchChunkTransport.js';
let record={media_id:200,status:'open'};
let chunks=[];let requests=[];let finalized=0;
const storage={getRecord:async()=>record,listChunks:async()=>chunks,deleteChunk:async(id,index)=>{chunks=chunks.filter(c=>c.chunk_index!==index);},deleteChunksFor:async()=>{chunks=[];},deleteRecord:async()=>{record=null;},updateChunkMeta:async()=>{}};
const makeChunk=(index,size)=>({chunk_index:index,payload:{chunk_index:index,chunk_blob:new Blob([new Uint8Array(size).fill(index+1)])}});
globalThis.window={axios:async req=>{requests.push(req);const manifest=JSON.parse(req.data.get('manifest'));assert.ok(req.data.get('batch').size<=AUDIO_BATCH_BYTES);return{data:{ok:true,chunk_indices:manifest.map(i=>i.chunk_index)}};}};
const consumer=new Consumer({storage,record,transport:createOperatorMediaBatchChunkTransport(),finalizer:{finalizeRecord:async()=>{finalized++;return{ok:true};}}});
chunks=[makeChunk(0,500000)];await consumer.tick();assert.equal(requests.length,0);assert.equal(chunks.length,1);
chunks.push(makeChunk(1,500000),makeChunk(2,500000),makeChunk(3,500000));await consumer.tick();assert.equal(requests.length,1);assert.deepEqual(chunks.map(c=>c.chunk_index),[3]);assert.equal(finalized,0);
record.status='closed';window.axios=async()=>{throw new Error('offline');};
for(let i=0;i<5;i++){consumer.nextRetryAt=0;await consumer.tick();}
assert.equal(chunks.length,1,'All failed chunks must remain after more than three retries');assert.equal(finalized,0);
window.axios=async req=>{requests.push(req);return{data:{ok:true,chunk_indices:[3]}};};consumer.nextRetryAt=0;await consumer.tick();assert.equal(finalized,1);assert.equal(record,null);
const transport=createOperatorMediaBatchChunkTransport();window.axios=async()=>({data:{ok:true,chunk_indices:[]}});
await assert.rejects(()=>transport.publishBatch({media_id:200},[makeChunk(0,10)]),/not confirmed/);
console.log('PASS HTTP batch threshold, bounded size, retry retention, receipt validation, and closed-call drain');
