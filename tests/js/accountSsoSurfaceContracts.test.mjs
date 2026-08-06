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

assert.doesNotMatch(
  source,
  /intentional_logout|hasIntentionalAccountLogout|setIntentionalAccountLogout/,
  'Shared surface login flow must not persistently suppress Account SSO after logout; callback errors are the loop breaker.',
);

assert.match(
  source,
  /function\s+initAccountSessionSdk\s*\(/,
  'Shared surface must initialize Account browser session sync.',
);

assert.match(
  source,
  /window\.PbbAccountSession/,
  'Shared surface must use the Account-hosted browser SDK when it is loaded.',
);

assert.match(
  source,
  /admissionUrl:\s*accountRealtimeAdmissionUrl\(accountSso\)/,
  'Account session sync must use the Account realtime admission endpoint.',
);

assert.match(
  source,
  /onLogout:\s*\(\)\s*=>\s*\{[\s\S]*handleAccountSessionLogout/,
  'Account logout events must clear the local Hotline session.',
);

assert.match(
  source,
  /fetchJson\('\/api\/logout',\s*\{\s*method:\s*'post'\s*\}\)/,
  'Account logout event handling must use local /api/logout, not /auth/logout.',
);

assert.match(
  source,
  /target\.searchParams\.set\('return_to',\s*returnPath\)/,
  'Account SSO redirects must preserve the current Hotline path via return_to.',
);
