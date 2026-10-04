import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import { startStaticServer } from '../../public/vendor/helpers.pbb.ph/tests/_support/static-server.mjs';

const browser = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
if (!browser) throw new Error('Chrome or Edge is required for the intake modal regression.');
const server = await startStaticServer({ rootDir: process.cwd(), port: 0 });
try {
    for (const suffix of ['', '?bundle']) {
        const { stdout } = await promisify(execFile)(browser, ['--headless=new', '--disable-gpu', '--virtual-time-budget=10000', '--dump-dom', `${server.origin}/tests/browser/operator-intake.html${suffix}`], { timeout: 60000, maxBuffer: 4 * 1024 * 1024 });
        if (!stdout.includes('data-status="pass"')) throw new Error(stdout);
        console.log(`Intake modal regression passed (${suffix ? 'bundle' : 'source'}).`);
    }
} finally { await server.close(); }
