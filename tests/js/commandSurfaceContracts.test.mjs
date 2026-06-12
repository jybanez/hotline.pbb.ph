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

assert.match(
  source,
  /function\s+commandSupportIncidentRows\s*\(/,
  'Command Support Request modal must expose incident-selection rows from the selected resource evidence.',
);

assert.match(
  source,
  /selected_incident_ids:\s*selectedIncidentIds/,
  'Command Support Request payload must include the selected incident scope.',
);

assert.match(
  source,
  /support_context:\s*buildCommandSupportContext\(context,\s*selectedIncidentIds\)/,
  'Command Support Request payload must include compact resource/evidence/request scope context.',
);

assert.match(
  source,
  /Quantity remains a Command decision and will not auto-change\./,
  'Command Support Request modal must not auto-compute quantity from selected incidents.',
);
