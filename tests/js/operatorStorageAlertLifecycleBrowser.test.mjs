import {chromium} from 'playwright';
import {startStaticServer} from '../../public/vendor/helpers.pbb.ph/tests/_support/static-server.mjs';
const server=await startStaticServer({rootDir:process.cwd(),port:0});
const browser=await chromium.launch({channel:'chrome',headless:true,timeout:20000});
try{
 for(const suffix of ['', '?bundle']){
  const page=await browser.newPage();
  await page.goto(server.origin+'/tests/browser/operator-storage-alert-lifecycle.html'+suffix);
  await page.locator('#results[data-status]').waitFor({timeout:20000});
  if(await page.locator('#results').getAttribute('data-status')!=='pass')throw new Error(await page.locator('#results').textContent());
  console.log(`App storage alert lifecycle passed (${suffix?'bundle':'source'}).`);
  await page.close();
 }
}finally{await browser.close();await server.close()}
