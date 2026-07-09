const DEFAULT_STATUS_PATH = '/api/public/community-status';
const DEFAULT_REALTIME_PATH = '/api/public/community-realtime';

const ALERT_SEVERITY = {
  normal: 0,
  elevated: 1,
  critical: 2,
};

const COMMUNITY_BROADCAST_AUDIENCES = new Set(['public', 'community', 'citizen', 'caller', 'global']);
const PRIVATE_BROADCAST_ROLES = new Set(['operator', 'command', 'admin', 'administrator']);

export function createHotlineCommunityClient(options = {}) {
  return new HotlineCommunityClient(options);
}

export class HotlineCommunityClient {
  constructor(options = {}) {
    this.baseUrl = normalizeBaseUrl(options.baseUrl ?? options.hotlineBaseUrl ?? '');
    this.statusPath = options.statusPath ?? DEFAULT_STATUS_PATH;
    this.realtimePath = options.realtimePath ?? DEFAULT_REALTIME_PATH;
    this.fetchImpl = options.fetchImpl ?? globalThis.fetch?.bind(globalThis);
    this.WebSocketImpl = options.WebSocketImpl ?? globalThis.WebSocket;
    this.realtimeClient = options.realtimeClient ?? null;
    this.realtimeFactory = options.realtimeFactory ?? null;
    this.autoRealtime = options.autoRealtime !== false;
    this.logger = options.logger ?? console;
    this.listeners = new Map();
    this.broadcasts = new Map();
    this.alert = null;
    this.realtimeAdmission = null;
    this.socket = null;
  }

  on(type, handler) {
    if (typeof handler !== 'function') {
      throw new TypeError('Hotline Community SDK listener must be a function.');
    }

    const handlers = this.listeners.get(type) ?? new Set();
    handlers.add(handler);
    this.listeners.set(type, handlers);

    return () => this.off(type, handler);
  }

  off(type, handler) {
    this.listeners.get(type)?.delete(handler);
  }

  async start() {
    await this.load();

    if (this.autoRealtime) {
      await this.connectRealtime();
    }

    return this;
  }

  async load() {
    const payload = await this.getJson(this.statusUrl());
    const alert = normalizeAlertStatus(payload.alert ?? payload);
    const broadcasts = Array.isArray(payload.broadcasts)
      ? payload.broadcasts.map((broadcast) => normalizeBroadcastMessage(broadcast)).filter(Boolean)
      : [];

    this.alert = alert;
    this.broadcasts = new Map(broadcasts.map((broadcast) => [broadcast.id, broadcast]));

    this.emit('community.loaded', {
      alert,
      broadcasts,
      raw: payload,
    });
    this.emit('alert.loaded', alert);

    return {
      alert,
      broadcasts,
      raw: payload,
    };
  }

  async connectRealtime() {
    this.realtimeAdmission = await this.getJson(this.realtimeUrl());

    if (this.realtimeClient) {
      this.bindRealtimeClient(this.realtimeClient, this.realtimeAdmission);
      return this.realtimeClient;
    }

    if (this.realtimeFactory) {
      this.realtimeClient = await this.realtimeFactory(this.realtimeAdmission, this);
      this.bindRealtimeClient(this.realtimeClient, this.realtimeAdmission);
      return this.realtimeClient;
    }

    if (typeof this.WebSocketImpl !== 'function') {
      return null;
    }

    const socket = new this.WebSocketImpl(withToken(this.realtimeAdmission.websocket_url, this.realtimeAdmission.token));

    if (typeof socket.addEventListener === 'function') {
      socket.addEventListener('message', (event) => this.handleRealtimeMessage(event.data));
    } else {
      socket.onmessage = (event) => this.handleRealtimeMessage(event.data);
    }

    this.socket = socket;

    return socket;
  }

  close() {
    this.socket?.close?.();
    this.realtimeClient?.close?.();
  }

  currentAlert() {
    return this.alert;
  }

  currentBroadcasts() {
    return [...this.broadcasts.values()];
  }

  handleRealtimeMessage(message) {
    const event = parseRealtimeMessage(message);

    if (! event) {
      return null;
    }

    const eventType = event.type ?? event.event_type ?? event.name;
    const payload = event.payload ?? event.data ?? {};

    if (isAlertEvent(eventType)) {
      const previous = this.alert;
      const next = normalizeAlertStatus(payload.alert ?? payload);
      this.alert = next;
      this.emit('alert.changed', {
        previous,
        current: next,
        raw: event,
      });

      return { type: 'alert.changed', alert: next };
    }

    if (isBroadcastPublishedEvent(eventType)) {
      const broadcast = normalizeBroadcastMessage(payload.broadcast ?? payload);

      if (! broadcast) {
        return null;
      }

      this.broadcasts.set(broadcast.id, broadcast);
      this.emit('broadcast.received', {
        broadcast,
        raw: event,
      });

      return { type: 'broadcast.received', broadcast };
    }

    if (isBroadcastRemovedEvent(eventType)) {
      const id = String(payload.id ?? payload.broadcast_id ?? '');

      if (id !== '') {
        const broadcast = this.broadcasts.get(id) ?? null;
        this.broadcasts.delete(id);
        this.emit('broadcast.removed', {
          id,
          broadcast,
          raw: event,
        });
      }

      return { type: 'broadcast.removed', id };
    }

    return null;
  }

  bindRealtimeClient(client, admission) {
    if (! client || typeof client !== 'object') {
      return;
    }

    const rooms = Array.isArray(admission?.rooms)
      ? admission.rooms
      : admission?.room ? [admission.room] : [];

    if (typeof client.connect === 'function') {
      client.connect(admission);
    }

    for (const room of rooms) {
      client.join?.(room);
      client.subscribe?.(room);
    }

    if (typeof client.on === 'function') {
      client.on('message', (message) => this.handleRealtimeMessage(message));
      client.on('event', (message) => this.handleRealtimeMessage(message));
      client.on('hotline.alert_level.changed', (payload) => this.handleRealtimeMessage({ type: 'hotline.alert_level.changed', payload }));
      client.on('hotline.broadcast.created', (payload) => this.handleRealtimeMessage({ type: 'hotline.broadcast.created', payload }));
    }
  }

  statusUrl() {
    return toUrl(this.baseUrl, this.statusPath);
  }

  realtimeUrl() {
    return toUrl(this.baseUrl, this.realtimePath);
  }

  async getJson(url) {
    if (typeof this.fetchImpl !== 'function') {
      throw new Error('Hotline Community SDK requires fetch or a fetchImpl option.');
    }

    const response = await this.fetchImpl(url, {
      headers: {
        Accept: 'application/json',
      },
    });

    if (! response?.ok) {
      throw new Error(`Hotline Community SDK request failed: ${response?.status ?? 'network-error'}`);
    }

    return response.json();
  }

  emit(type, payload) {
    for (const handler of this.listeners.get(type) ?? []) {
      handler(payload);
    }

    for (const handler of this.listeners.get('*') ?? []) {
      handler(type, payload);
    }
  }
}

export function normalizeAlertStatus(input = {}) {
  const rawLevel = input.level ?? input.alert_level ?? input.status ?? 'Normal';
  const level = titleCase(String(rawLevel || 'Normal'));

  return {
    level,
    code: level.toLowerCase(),
    severity: ALERT_SEVERITY[level.toLowerCase()] ?? -1,
    description: input.description ?? input.message ?? '',
    changed_at: input.changed_at ?? input.updated_at ?? input.generated_at ?? null,
    room: input.room ?? null,
  };
}

export function normalizeBroadcastMessage(input = {}) {
  const id = input.id ?? input.broadcast_id;

  if (id === undefined || id === null || String(id).trim() === '') {
    return null;
  }

  const roles = Array.isArray(input.target_roles) ? input.target_roles.map((role) => String(role).toLowerCase()) : [];
  const audience = String(input.audience ?? 'community').toLowerCase();

  if (! COMMUNITY_BROADCAST_AUDIENCES.has(audience) && roles.length === 0) {
    return null;
  }

  if (roles.length > 0 && roles.every((role) => PRIVATE_BROADCAST_ROLES.has(role))) {
    return null;
  }

  return {
    id: String(id),
    title: input.title ?? '',
    message: input.message ?? input.body ?? '',
    tone: input.tone ?? input.level ?? 'info',
    audience: COMMUNITY_BROADCAST_AUDIENCES.has(audience) ? audience : 'community',
    target_roles: roles,
    published_at: input.published_at ?? input.created_at ?? null,
    expires_at: input.expires_at ?? null,
    raw: input,
  };
}

function parseRealtimeMessage(message) {
  if (typeof message === 'string') {
    try {
      return JSON.parse(message);
    } catch {
      return null;
    }
  }

  return message && typeof message === 'object' ? message : null;
}

function isAlertEvent(type) {
  return [
    'hotline.alert.changed',
    'hotline.alert_level.changed',
    'alert.changed',
  ].includes(String(type ?? ''));
}

function isBroadcastPublishedEvent(type) {
  return [
    'hotline.broadcast.created',
    'hotline.broadcast.published',
    'hotline.broadcast.updated',
    'broadcast.created',
    'broadcast.published',
  ].includes(String(type ?? ''));
}

function isBroadcastRemovedEvent(type) {
  return [
    'hotline.broadcast.expired',
    'hotline.broadcast.retracted',
    'broadcast.expired',
    'broadcast.retracted',
  ].includes(String(type ?? ''));
}

function normalizeBaseUrl(value) {
  return String(value ?? '').replace(/\/+$/, '');
}

function toUrl(baseUrl, path) {
  const normalizedPath = String(path).startsWith('/') ? String(path) : `/${path}`;

  return `${baseUrl}${normalizedPath}`;
}

function withToken(url, token) {
  const separator = String(url).includes('?') ? '&' : '?';

  return `${url}${separator}token=${encodeURIComponent(token)}`;
}

function titleCase(value) {
  const lower = value.trim().toLowerCase();

  return lower ? lower.charAt(0).toUpperCase() + lower.slice(1) : 'Normal';
}
