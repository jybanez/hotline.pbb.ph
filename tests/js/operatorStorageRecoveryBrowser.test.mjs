import {chromium} from 'playwright';
import {startStaticServer} from '../../public/vendor/helpers.pbb.ph/tests/_support/static-server.mjs';
const server=await startStaticServer({rootDir:process.cwd(),port:0});
const browser=await chromium.launch({channel:'chrome',headless:true,timeout:20000});
try{
 const page=await browser.newPage();
 await page.goto(server.origin+'/tests/browser/operator-storage-recovery.html');
 await page.locator('#results[data-status]').waitFor({timeout:20000});
 const result=await page.locator('#results').getAttribute('data-status');
 if(result!=='pass')throw new Error(await page.locator('#results').textContent());
 console.log('Native IndexedDB durable recovery browser regression passed.');
}finally{await browser.close();await server.close()}
