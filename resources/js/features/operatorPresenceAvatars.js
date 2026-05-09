function normalizePresenceMeta(entry) {
    return entry?.meta && typeof entry.meta === 'object' ? entry.meta : {};
}

function normalizePresenceState(entry) {
    const meta = normalizePresenceMeta(entry);
    const state = String(entry?.state ?? '').trim().toLowerCase();
    const statusText = String(entry?.statusText ?? '').trim().toLowerCase();
    const workbenchActive = meta.workbench_active === true
        || String(meta.workbench_active ?? '').trim().toLowerCase() === 'true';
    const incidentId = Number(meta.incident_id ?? 0);
    const busy = state === 'busy'
        || statusText === 'busy'
        || workbenchActive
        || incidentId > 0;

    if (state === 'offline') {
        return 'offline';
    }

    return busy ? 'busy' : 'available';
}

function operatorInitials(name) {
    return String(name ?? 'OP')
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('') || 'OP';
}

function padIncidentId(incidentId) {
    const value = Number(incidentId ?? 0);

    if (!Number.isFinite(value) || value <= 0) {
        return '';
    }

    return String(value).padStart(6, '0');
}

function operatorPresenceSort(a, b) {
    if (a.state !== b.state) {
        return a.state === 'available' ? -1 : 1;
    }

    return a.name.localeCompare(b.name);
}

function operatorPresenceItemSignature(item) {
    return [
        item.id,
        item.name,
        item.avatar,
        item.initials,
        item.state,
        item.incidentId ?? '',
    ].join('|');
}

function operatorPresenceLabels(item) {
    const stateLabel = item.state === 'busy' ? 'Busy' : 'Available';
    const incidentLabel = item.incidentId ? `Incident #${padIncidentId(item.incidentId)}` : 'No active incident';
    const tooltip = `${item.name}\n${stateLabel}\n${incidentLabel}`;

    return {
        incidentLabel,
        stateLabel,
        tooltip,
    };
}

export function normalizeOperatorPresenceItems(rosterItems, options = {}) {
    const currentUserId = String(options.currentUserId ?? '').trim();
    const items = Array.isArray(rosterItems) ? rosterItems : [];

    return items
        .map((entry) => {
            const meta = normalizePresenceMeta(entry);
            const userId = String(entry?.userId ?? entry?.id ?? meta.operator_id ?? '').trim();
            const incidentId = Number(meta.incident_id ?? entry?.incidentId ?? 0);
            const name = String(
                meta.operator_name
                ?? entry?.name
                ?? entry?.displayName
                ?? (userId ? `Operator #${userId}` : 'Operator'),
            ).trim();
            const state = normalizePresenceState(entry);

            return {
                id: userId,
                name,
                avatar: String(meta.operator_avatar ?? meta.avatar ?? entry?.avatar ?? '').trim(),
                initials: operatorInitials(name),
                state,
                incidentId: Number.isFinite(incidentId) && incidentId > 0 ? incidentId : null,
                updatedAt: String(entry?.updatedAt ?? '').trim(),
            };
        })
        .filter((item) => item.id && item.id !== currentUserId && item.state !== 'offline')
        .sort(operatorPresenceSort);
}

function createOperatorPresenceAvatarElement(item) {
    const element = document.createElement('span');
    element.className = 'operator-presence-avatar';
    element.setAttribute('role', 'img');
    element.dataset.operatorPresenceId = item.id;
    updateOperatorPresenceAvatarElement(element, item, true);

    return element;
}

function updateOperatorPresenceAvatarElement(element, item, forceVisual = false) {
    const { incidentLabel, stateLabel, tooltip } = operatorPresenceLabels(item);
    const signature = operatorPresenceItemSignature(item);

    if (!forceVisual && element.dataset.signature === signature) {
        return;
    }

    element.dataset.signature = signature;
    element.classList.toggle('is-available', item.state === 'available');
    element.classList.toggle('is-busy', item.state === 'busy');
    element.setAttribute('aria-label', `${item.name}, ${stateLabel}, ${incidentLabel}`);
    element.setAttribute('title', tooltip);
    element.dataset.tooltip = tooltip;

    if (item.avatar) {
        const existingImage = element.querySelector('img');

        if (existingImage) {
            if (existingImage.getAttribute('src') !== item.avatar) {
                existingImage.setAttribute('src', item.avatar);
            }
        } else {
            element.textContent = '';
            const image = document.createElement('img');
            image.setAttribute('src', item.avatar);
            image.setAttribute('alt', '');
            element.appendChild(image);
        }

        return;
    }

    const existingInitials = element.querySelector('span');

    if (existingInitials) {
        existingInitials.textContent = item.initials;
        element.querySelector('img')?.remove();
        return;
    }

    element.textContent = '';
    const initials = document.createElement('span');
    initials.textContent = item.initials;
    element.appendChild(initials);
}

export function createOperatorPresenceAvatars(host, options = {}) {
    let items = normalizeOperatorPresenceItems(options.items ?? [], {
        currentUserId: options.currentUserId,
    });
    const emptyText = String(options.emptyText ?? 'No online operators.');
    const avatarNodes = new Map();
    let container = null;
    let emptyNode = null;

    const ensureContainer = () => {
        if (!host) {
            return null;
        }

        if (!container) {
            container = document.createElement('div');
            container.className = 'operator-presence-avatars';
            container.setAttribute('aria-label', 'Online operators');
            host.appendChild(container);
        }

        return container;
    };

    const showEmpty = () => {
        if (!host) {
            return;
        }

        container?.remove();
        container = null;
        avatarNodes.clear();

        if (!emptyNode) {
            emptyNode = document.createElement('div');
            emptyNode.className = 'operator-presence-avatars-empty';
            emptyNode.textContent = emptyText;
        }

        if (!emptyNode.parentNode) {
            host.appendChild(emptyNode);
        }
    };

    const hideEmpty = () => {
        emptyNode?.remove();
    };

    const reconcile = () => {
        if (!host) {
            return;
        }

        if (!items.length) {
            showEmpty();
            return;
        }

        hideEmpty();

        const nextContainer = ensureContainer();
        const nextIds = new Set(items.map((item) => item.id));

        for (const [id, node] of avatarNodes.entries()) {
            if (!nextIds.has(id)) {
                node.remove();
                avatarNodes.delete(id);
            }
        }

        items.forEach((item, index) => {
            let node = avatarNodes.get(item.id);

            if (!node) {
                node = createOperatorPresenceAvatarElement(item);
                avatarNodes.set(item.id, node);
            } else {
                updateOperatorPresenceAvatarElement(node, item);
            }

            if (nextContainer.children[index] !== node) {
                nextContainer.insertBefore(node, nextContainer.children[index] ?? null);
            }
        });
    };

    reconcile();

    return {
        update(nextItems) {
            items = normalizeOperatorPresenceItems(nextItems, {
                currentUserId: options.currentUserId,
            });
            reconcile();
        },
        getItems() {
            return [...items];
        },
        destroy() {
            if (host) {
                container?.remove();
                emptyNode?.remove();
            }
            avatarNodes.clear();
            container = null;
            emptyNode = null;
        },
    };
}
