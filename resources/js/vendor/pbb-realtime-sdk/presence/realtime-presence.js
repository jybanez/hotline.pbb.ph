export function buildRoomJoinPayload() {
    return {};
}

export function buildPresenceSubscribePayload(room) {
    return {
        room: String(room || "").trim(),
    };
}

function normalizePresenceMeta(meta) {
    if (!meta || typeof meta !== "object" || Array.isArray(meta)) {
        return undefined;
    }

    const next = {};
    for (const [key, value] of Object.entries(meta)) {
        const trimmedKey = String(key || "").trim();
        if (!trimmedKey) {
            continue;
        }

        if (
            value === null ||
            typeof value === "string" ||
            typeof value === "number" ||
            typeof value === "boolean"
        ) {
            next[trimmedKey] = typeof value === "string" ? value.trim() : value;
        }
    }

    return Object.keys(next).length > 0 ? next : undefined;
}

export function buildPresencePublishPayload(room, presenceState = "online", statusText = "", meta) {
    const payload = {
        room: String(room || "").trim(),
        state: String(presenceState || "online").trim() || "online",
        status_text: String(statusText || "").trim(),
    };

    const normalizedMeta = normalizePresenceMeta(meta);
    if (normalizedMeta) {
        payload.meta = normalizedMeta;
    }

    return payload;
}

export function derivePresenceRosterKey(payload) {
    const subject = payload?.subject || {};
    return String(subject.session_id || subject.user_id || "").trim();
}

export function reducePresenceRosterEvent(roster, payload) {
    const rosterKey = derivePresenceRosterKey(payload);
    if (!rosterKey) {
        return roster;
    }

    const subject = payload?.subject || {};
    const presenceState = String(payload?.state || "online").trim() || "online";
    const next = {
        ...(roster || {}),
    };

    if (presenceState === "offline") {
        delete next[rosterKey];
        return next;
    }

    next[rosterKey] = {
        key: rosterKey,
        sessionId: String(subject.session_id || "").trim(),
        userId: String(subject.user_id || "").trim(),
        displayName: String(payload?.display_name || payload?.status_text || subject.user_id || "").trim(),
        projectCode: String(subject.project_code || "").trim(),
        appCode: String(subject.app_code || "").trim(),
        state: presenceState,
        statusText: String(payload?.status_text || "").trim(),
        meta: payload?.meta && typeof payload.meta === "object" && !Array.isArray(payload.meta) ? payload.meta : undefined,
        updatedAt: String(payload?.updated_at || "").trim(),
        expiresAt: String(payload?.expires_at || "").trim(),
    };

    return next;
}

export function listPresenceRosterItems(roster) {
    return Object.values(roster || {})
        .filter((entry) => entry && entry.state !== "offline")
        .sort((a, b) => String(a.updatedAt || "").localeCompare(String(b.updatedAt || "")) * -1);
}
