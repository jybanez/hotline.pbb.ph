import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const moduleSource = await readFile(new URL('../../packages/pbb-hotline-community-sdk/js/hotline-community.js', import.meta.url), 'utf8');
const moduleUrl = 'http://hotline-community.test/js/hotline-community.js';

const browser = await chromium.launch();
const page = await browser.newPage();

try {
  await page.route(moduleUrl, (route) => route.fulfill({
    status: 200,
    contentType: 'text/javascript',
    body: moduleSource,
  }));
  await page.route('http://hotline-community.test/', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><body></body></html>',
  }));

  await page.goto('http://hotline-community.test/');
  await page.addScriptTag({
    type: 'module',
    content: `
      import { createHotlineCommunityClient, normalizeAlertStatus, normalizeBroadcastMessage } from '${moduleUrl}';
      window.communitySdk = { createHotlineCommunityClient, normalizeAlertStatus, normalizeBroadcastMessage };
    `,
  });
  await page.waitForFunction(() => window.communitySdk);

  const normalized = await page.evaluate(() => ({
    alert: window.communitySdk.normalizeAlertStatus({ alert_level: 'critical', description: 'Immediate coordination.' }),
    broadcast: window.communitySdk.normalizeBroadcastMessage({
      id: 7,
      title: 'Road Advisory',
      message: 'Avoid flooded route.',
      target_roles: ['citizen'],
      published_at: '2026-07-09T08:00:00+08:00',
    }),
    operatorOnly: window.communitySdk.normalizeBroadcastMessage({
      id: 8,
      message: 'Operator-only note.',
      target_roles: ['operator'],
    }),
  }));

  assert.equal(normalized.alert.level, 'Critical');
  assert.equal(normalized.alert.severity, 2);
  assert.equal(normalized.broadcast.id, '7');
  assert.equal(normalized.operatorOnly, null);

  const loaded = await page.evaluate(async () => {
    const requests = [];
    const fetchImpl = async (url) => {
      requests.push(url);

      if (url.endsWith('/api/public/community-status')) {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            alert: { level: 'Elevated', description: 'Heightened readiness.' },
            broadcasts: [
              { id: 1, message: 'Shelter is open.', target_roles: ['citizen'] },
            ],
          }),
        };
      }

      return {
        ok: true,
        status: 200,
        json: async () => ({
          token: 'token-123',
          websocket_url: 'wss://realtime.pbb.ph/realtime',
          rooms: ['hotline.settings.global', 'hotline.broadcast.global'],
        }),
      };
    };

    const events = [];
    const client = window.communitySdk.createHotlineCommunityClient({
      baseUrl: 'https://hotline.pbb.ph',
      fetchImpl,
      realtimeFactory: async (admission, sdk) => ({
        admission,
        emit(message) {
          sdk.handleRealtimeMessage(message);
        },
      }),
    });

    client.on('*', (type) => events.push(type));
    await client.start();
    const realtime = client.realtimeClient;
    realtime.emit({
      type: 'hotline.alert_level.changed',
      payload: { alert_level: 'Critical', description: 'Critical local response.' },
    });
    realtime.emit({
      type: 'hotline.broadcast.created',
      payload: { id: 2, message: 'Use evacuation center.', target_roles: ['caller'] },
    });

    return {
      requests,
      events,
      alert: client.currentAlert(),
      broadcasts: client.currentBroadcasts(),
      rooms: realtime.admission.rooms,
    };
  });

  assert.deepEqual(loaded.requests, [
    'https://hotline.pbb.ph/api/public/community-status',
    'https://hotline.pbb.ph/api/public/community-realtime',
  ]);
  assert.equal(loaded.alert.level, 'Critical');
  assert.equal(loaded.broadcasts.length, 2);
  assert.ok(loaded.events.includes('community.loaded'));
  assert.ok(loaded.events.includes('alert.changed'));
  assert.ok(loaded.events.includes('broadcast.received'));
  assert.deepEqual(loaded.rooms, ['hotline.settings.global', 'hotline.broadcast.global']);

  const rawSocket = await page.evaluate(async () => {
    const requests = [];
    class FakeWebSocket {
      static instances = [];

      constructor(url) {
        this.url = url;
        this.sent = [];
        this.listeners = {};
        FakeWebSocket.instances.push(this);
      }

      addEventListener(type, handler) {
        this.listeners[type] ??= [];
        this.listeners[type].push(handler);
      }

      send(message) {
        this.sent.push(JSON.parse(message));
      }

      open() {
        for (const handler of this.listeners.open ?? []) {
          handler({ type: 'open' });
        }
      }
    }

    const fetchImpl = async (url) => {
      requests.push(url);

      if (url.endsWith('/api/public/community-status')) {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            alert: { level: 'Normal', description: 'Standard operations.' },
            broadcasts: [],
          }),
        };
      }

      return {
        ok: true,
        status: 200,
        json: async () => ({
          token: 'token-456',
          websocket_url: 'wss://realtime.pbb.ph/realtime',
          rooms: ['hotline.settings.global', 'hotline.broadcast.global'],
        }),
      };
    };

    const client = window.communitySdk.createHotlineCommunityClient({
      baseUrl: 'https://hotline.pbb.ph',
      fetchImpl,
      WebSocketImpl: FakeWebSocket,
    });

    await client.start();
    const socket = FakeWebSocket.instances[0];
    socket.open();

    return {
      requests,
      url: socket.url,
      sent: socket.sent,
    };
  });

  assert.equal(rawSocket.url, 'wss://realtime.pbb.ph/realtime?token=token-456');
  assert.equal(rawSocket.sent.length, 2);
  assert.deepEqual(rawSocket.sent.map((message) => message.type), ['room.join.request', 'room.join.request']);
  assert.deepEqual(rawSocket.sent.map((message) => message.room), ['hotline.settings.global', 'hotline.broadcast.global']);
  assert.ok(rawSocket.sent.every((message) => message.namespace === 'pbb.realtime.v1'));
  assert.ok(rawSocket.sent.every((message) => message.phase === 'request'));
} finally {
  await browser.close();
}
