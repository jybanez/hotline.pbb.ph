import {chromium} from 'playwright';
import {startStaticServer} from '../../public/vendor/helpers.pbb.ph/tests/_support/static-server.mjs';
const server=await startStaticServer({rootDir:process.cwd(),port:0});const browser=await chromium.launch({channel:'chrome',headless:true});
const url=server.origin+'/tests/browser/operator-storage-admission.html';
try{
 for(const scenario of ['healthy','malformed','unreadable','unavailable','readback','newer']){
  const context=await browser.newContext();const page=await context.newPage();await page.goto(url);await page.waitForFunction(()=>window.ready);
  await page.evaluate(async scenario=>{if(scenario==='newer')return window.newerFailure();if(['unavailable','readback'].includes(scenario))return window.markerFault(scenario);return window.run(scenario)},scenario);
  await context.close();
 }
 const context=await browser.newContext();const first=await context.newPage();await first.goto(url);await first.waitForFunction(()=>window.ready);
 if(!(await first.evaluate(()=>window.acquire())).storageAvailable)throw Error('First owner unavailable');
 const second=await context.newPage();await second.goto(url);await second.waitForFunction(()=>window.ready);
 const blocked=await second.evaluate(()=>window.acquire());if(blocked.storageAvailable||!blocked.lastError.includes('Another Hotline tab'))throw Error('Competing tab admitted');
 await first.close();
 await second.waitForFunction(async()=>!(await navigator.locks.query()).held.some(lock=>lock.name==='hotline-operator-media-queue-owner-v1'),{timeout:5000});
 const third=await context.newPage();await third.goto(url);await third.waitForFunction(()=>window.ready);
 const handed=await third.evaluate(()=>window.acquire());
 if(!handed.storageAvailable){
  // Browser teardown and lock-service admission may complete on different tasks.
  // Retry only the denied ownership admission; it has performed no queue writes.
  await third.evaluate(async()=>{for(let i=0;i<100;i++){try{await window.queue.prepareStartup();return}catch(error){if(!error.message.includes('Another Hotline tab'))throw error;await new Promise(r=>setTimeout(r,10))}}throw Error('Owner handoff timed out')});
  await third.evaluate(()=>window.manager.recoverStorage());
  if(!(await third.evaluate(()=>window.manager.getStatus().consumer)).storageAvailable)throw Error('Explicit owner handoff failed');
 }await context.close();
 console.log('Storage admission passed: healthy drain, malformed/read-error metadata, marker persistence/readback, newer failure and exclusive cross-tab ownership.');
}finally{await browser.close();await server.close()}
