import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const source = readFileSync(resolve(__dirname, '../../resources/js/surfaces/commandSurface.js'), 'utf8');

assert.match(
  source,
  /function\s+formatSitrepRecordNumber\s*\(/,
  'Command Support Request modal record label formatter must be defined.',
);

assert.match(
  source,
  /formatSitrepRecordNumber\(sitrep\.id\)/,
  'Command Support Request modal should use the stable record formatter for sitrep_ref.',
);

assert.match(
  source,
  /function\s+commandIsResourceSupplyGap\s*\(/,
  'Command Support Request row action must keep the resource-supply gap guard.',
);

assert.match(
  source,
  /gap\?\.type[\s\S]*open_needs/,
  'Command Support Request row action must require the open_needs gap type.',
);
