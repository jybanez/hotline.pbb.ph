import assert from 'node:assert/strict';
import fs from 'node:fs';
import {Producer} from '../../resources/js/media/producer.js';
const now=Date.now;
let clock=20000;Date.now=()=>clock;
class Recorder extends EventTarget {state='inactive';start(){this.state='recording';}stop(){this.state='inactive';this.dispatchEvent(new Event('stop'));}}
try {
 let closed;
 const storage={putRecord:async r=>{closed=r;}};
 const producer=new Producer({storage},new Recorder(),{media_id:1,key:'operator-audio',started_at:new Date(1000).toISOString()}, {captureReadyAt:1000,state:{},showToast:()=>{}});
 await producer.initialize();producer.start();assert.equal(producer.recordingStartedAt,20000);
 clock=34000;await producer.close();assert.equal(closed.duration_seconds,14,'Exclude initialization delay from recording duration');
 assert.equal(closed.started_at,new Date(20000).toISOString());
 const source=fs.readFileSync('resources/js/surfaces/operatorSurface.js','utf8');
 const handlers=source.split('onDisconnectRequest() {').slice(1);assert.equal(handlers.length,2);
 for(const h of handlers){const request=h.slice(0,h.indexOf('sendHangupComplete'));assert.ok(request.includes('/citizen-disconnect`'));assert.ok(!request.includes('/hangup`'));}
 console.log('PASS recording clock excludes initialization and both caller hangup handlers use citizen outcome');
} finally {Date.now=now;}
