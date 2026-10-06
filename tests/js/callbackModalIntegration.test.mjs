import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {startStaticServer} from '../../public/vendor/helpers.pbb.ph/tests/_support/static-server.mjs';
const server=await startStaticServer({rootDir:process.cwd(),port:0});
const browser=await chromium.launch({channel:'msedge',headless:true});
try {for(const bundle of [false,true]) {
 const page=await browser.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto(server.origin+'/tests/browser/callback-lifecycle.html'+(bundle?'?bundle':''));
 await page.waitForFunction(()=>window.ready&&requests.length===1);
 await page.getByRole('button',{name:'Hang up',exact:true}).click();
 await page.getByRole('button',{name:'Check callback status',exact:true}).waitFor();
 assert.equal(await page.locator('#call').isDisabled(),true);
 assert.equal(await page.evaluate(()=>signals.some(item=>item.type==='operator.callback.cancelled')),false);
 assert.equal(await page.evaluate(()=>alerts.length),0);
 await page.getByRole('button',{name:'Check callback status',exact:true}).click();
 await page.waitForFunction(()=>!document.querySelector('#call').disabled);
 assert.equal(await page.evaluate(()=>requests.filter(item=>item.url.endsWith('/cancel')).length),1);
 assert.equal(await page.evaluate(()=>requests.at(-1).options.method),'get');
 assert.equal(await page.evaluate(()=>requests[1].options.timeout),15000);
 assert.equal(await page.evaluate(()=>requests[1].options.data.outcome),'cancelled_by_operator');
 assert.deepEqual(errors,[]);await page.close();console.log('PASS complete Helper callback cancellation '+(bundle?'bundle':'source'));
}}finally{await browser.close();await server.close();}
