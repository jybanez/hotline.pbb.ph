import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import fs from 'node:fs';
import path from 'node:path';
import {startStaticServer} from '../../public/vendor/helpers.pbb.ph/tests/_support/static-server.mjs';
const browser = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
const server = await startStaticServer({rootDir: process.cwd(), port: 0});
const evidence = path.resolve('storage/logs/incident-layout');
fs.mkdirSync(evidence, {recursive: true});
try {
    for (const width of [1280, 1920]) for (const suffix of ['', '?bundle']) {
        const {stdout} = await promisify(execFile)(browser, ['--headless=new', '--disable-gpu', `--window-size=${width},1080`, `--screenshot=${path.join(evidence, `${width}-${suffix ? 'bundle' : 'source'}.png`)}`, '--virtual-time-budget=10000', '--dump-dom', `${server.origin}/tests/browser/operator-incident-layout.html${suffix}`], {timeout: 60000, maxBuffer: 4*1024*1024});
        const result = stdout.match(/<pre id="results">([\s\S]*?)<\/pre>/)?.[1];
        if (!stdout.includes('data-status="pass"')) throw new Error(result || stdout);
        console.log(`${suffix ? 'bundle' : 'source'}: ${result}`);
    }
} finally {await server.close();}
