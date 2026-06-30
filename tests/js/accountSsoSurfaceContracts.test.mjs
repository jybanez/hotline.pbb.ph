import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const source = readFileSync(resolve(__dirname, '../../resources/js/surfaces/surfaceShared.js'), 'utf8');

assert.match(
  source,
  /function\s+accountSsoLoginError\s*\(/,
  'Shared surface login flow must read Account SSO callback errors from bootstrap auth payload.',
);

assert.match(
  source,
  /showToast\(accountSsoError,\s*'error'\)/,
  'Shared surface login flow must display Account SSO callback errors to the user.',
);

assert.match(
  source,
  /function\s+shouldUseAccountSsoLogin\s*\([^)]*accountSsoError/,
  'Shared surface login flow must pass Account SSO errors into the redirect guard.',
);

assert.match(
  source,
  /&&\s*!accountSsoError[\s\S]*\['public',\s*'citizen',\s*'caller'\]\.includes\(surface\)/,
  'Shared surface login flow must suppress automatic Account redirect when a callback error is present.',
);
