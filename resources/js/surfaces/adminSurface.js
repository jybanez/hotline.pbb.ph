import { appState, confirmDeleteAction, ensureHelperUi, escapeHtml, fetchJson, formatBlockedDeleteMessage, formatStatusLabel, mountSurfaceChrome, sharedShell, showToast, trackSurfaceInstance } from './surfaceShared.js';
import { mountCategoryList, renderGroupedModule } from './adminSurfaceGrouped.js';

const MODULES = [
    { id: 'overview', label: 'Overview', description: 'Counts, quick links, and runtime posture.', implemented: true },
    { id: 'users', label: 'Users', description: 'Search, add, edit, and delete Hotline accounts.', implemented: true },
    { id: 'incidents', label: 'Incidents', description: 'Incident categories, incident types, and related incident taxonomy controls.', implemented: true },
    { id: 'teams', label: 'Teams', description: 'Team directory, memberships, and operational grouping controls.', implemented: true },
    { id: 'resources', label: 'Resources', description: 'Resource types and future resource catalog controls.', implemented: true },
];

const SETTINGS_ACTION_ID = 'admin-settings';
const INCIDENT_FIELD_INPUT_TYPE_OPTIONS = [
    { value: 'text', label: 'Text' },
    { value: 'textarea', label: 'Textarea' },
    { value: 'number', label: 'Number' },
    { value: 'select', label: 'Select' },
    { value: 'multiselect', label: 'Multi-select' },
    { value: 'group', label: 'Group Preset' },
];
const INCIDENT_FIELD_GROUP_PRESET_OPTIONS = [
    { value: 'person', label: 'Person' },
    { value: 'address', label: 'Address' },
    { value: 'missingPerson', label: 'Missing Person' },
    { value: 'evacuee', label: 'Evacuee' },
];

const adminRuntime = {
    mounted: [],
    root: null,
    bootstrap: null,
    state: null,
    searchTimer: null,
    settingsModal: null,
    teamInventoryModal: null,
    incidentTypeSetupModal: null,
};

function destroyAdminRuntime() {
    adminRuntime.mounted.splice(0).forEach((instance) => {
        instance?.destroy?.();
    });

    if (adminRuntime.searchTimer) {
        window.clearTimeout(adminRuntime.searchTimer);
        adminRuntime.searchTimer = null;
    }

    adminRuntime.teamInventoryModal = null;
    adminRuntime.incidentTypeSetupModal = null;
}

function rememberAdminInstance(instance) {
    if (!instance || typeof instance.destroy !== 'function') {
        return instance;
    }

    adminRuntime.mounted.push(instance);
    trackSurfaceInstance(instance);

    return instance;
}

function moduleById(moduleId) {
    const aliases = {
        'incident-categories': 'incidents',
        'incident-types': 'incidents',
        'resource-types': 'resources',
    };
    const normalizedId = aliases[moduleId] ?? moduleId;

    return MODULES.find((item) => item.id === normalizedId) ?? MODULES[0];
}

function resolveModuleFromUrl() {
    const params = new URL(window.location.href).searchParams;
    const requested = String(params.get('module') ?? '').trim();

    return moduleById(requested).id;
}

function writeModuleToUrl(moduleId) {
    const nextUrl = new URL(window.location.href);

    if (moduleId === 'overview') {
        nextUrl.searchParams.delete('module');
    } else {
        nextUrl.searchParams.set('module', moduleId);
    }

    window.history.replaceState({}, '', nextUrl);
}

function slugifyFieldKey(value) {
    return String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function buildAdminNavbarItems(activeModule) {
    return MODULES.map((module) => ({
        id: module.id,
        label: module.label,
        href: module.id === 'overview'
            ? '/admin'
            : `/admin?module=${encodeURIComponent(module.id)}`,
        disabled: !module.implemented,
        title: module.implemented ? module.label : `${module.label} is pending`,
    })).map((module) => ({
        ...module,
        active: module.id === activeModule,
    }));
}

function cardMetric(label, value, moduleId = 'overview') {
    return `
        <button class="admin-metric-card" type="button" data-admin-open-module="${moduleId}">
            <span class="admin-metric-label">${escapeHtml(label)}</span>
            <strong class="admin-metric-value">${escapeHtml(value)}</strong>
        </button>
    `;
}

function createStackedGridCell(titleText, subtitleText, titleFallback = 'Untitled', subtitleFallback = '') {
    const wrap = document.createElement('div');
    wrap.className = 'admin-grid-stacked-cell';

    const title = document.createElement('span');
    title.className = 'admin-grid-stacked-title';
    title.textContent = titleText || titleFallback;

    const subtitle = document.createElement('span');
    subtitle.className = 'admin-grid-stacked-subtitle';
    subtitle.textContent = subtitleText || subtitleFallback;
    subtitle.title = subtitle.textContent;

    wrap.append(title, subtitle);

    return wrap;
}

function buildGroupedToolbarEnd({
    addPrimaryLabel,
    onAddPrimary,
    addCategoryLabel = 'Add Category',
    onAddCategory,
    showAddCategory = true,
    countFormatter = (totalRows) => `${totalRows} records`,
}) {
    return ({ totalRows, createElement }) => {
        const countPill = createElement('span', {
            className: 'pill blue admin-grid-count',
            text: countFormatter(totalRows),
        });

        const addButton = createElement('button', {
            className: 'ui-button ui-button-primary',
            text: addPrimaryLabel,
            attrs: { type: 'button' },
        });
        addButton.addEventListener('click', async (event) => {
            event.preventDefault();
            await onAddPrimary();
        });

        const parts = [countPill];

        if (showAddCategory && typeof onAddCategory === 'function') {
            const addCategoryButton = createElement('button', {
                className: 'ui-button',
                text: addCategoryLabel,
                attrs: { type: 'button' },
            });
            addCategoryButton.addEventListener('click', async (event) => {
                event.preventDefault();
                await onAddCategory();
            });
            parts.push(addCategoryButton);
        }

        parts.push(addButton);

        return parts;
    };
}

function blockedDeleteError(error, fallback) {
    const payload = error?.response?.data ?? {};

    return new Error(formatBlockedDeleteMessage(payload, fallback));
}

function sortIncidentCategories(items) {
    return [...(Array.isArray(items) ? items : [])].sort((left, right) => {
        const leftSort = Number(left?.sort_order ?? 0);
        const rightSort = Number(right?.sort_order ?? 0);

        if (leftSort !== rightSort) {
            return leftSort - rightSort;
        }

        return String(left?.name ?? '').localeCompare(String(right?.name ?? ''), 'en');
    });
}

function sortIncidentTypes(items, categories) {
    const categoryMap = new Map((Array.isArray(categories) ? categories : []).map((item) => [Number(item.id), item]));

    return [...(Array.isArray(items) ? items : [])].sort((left, right) => {
        const leftCategory = categoryMap.get(Number(left?.incident_category_id));
        const rightCategory = categoryMap.get(Number(right?.incident_category_id));
        const leftSort = Number(leftCategory?.sort_order ?? 0);
        const rightSort = Number(rightCategory?.sort_order ?? 0);

        if (leftSort !== rightSort) {
            return leftSort - rightSort;
        }

        const leftCategoryName = String(leftCategory?.name ?? left?.category_name ?? '');
        const rightCategoryName = String(rightCategory?.name ?? right?.category_name ?? '');
        const categoryCompare = leftCategoryName.localeCompare(rightCategoryName, 'en');

        if (categoryCompare !== 0) {
            return categoryCompare;
        }

        return String(left?.name ?? '').localeCompare(String(right?.name ?? ''), 'en');
    });
}

function sortIncidentTypeFields(items) {
    return [...(Array.isArray(items) ? items : [])].sort((left, right) => {
        const leftSort = Number(left?.sort_order ?? 0);
        const rightSort = Number(right?.sort_order ?? 0);

        if (leftSort !== rightSort) {
            return leftSort - rightSort;
        }

        const labelCompare = String(left?.field_label ?? '').localeCompare(String(right?.field_label ?? ''), 'en');

        if (labelCompare !== 0) {
            return labelCompare;
        }

        return Number(left?.id ?? 0) - Number(right?.id ?? 0);
    });
}

function sortIncidentTypeDefaultResources(items) {
    return [...(Array.isArray(items) ? items : [])].sort((left, right) => {
        const leftSort = Number(left?.sort_order ?? 0);
        const rightSort = Number(right?.sort_order ?? 0);

        if (leftSort !== rightSort) {
            return leftSort - rightSort;
        }

        const nameCompare = String(left?.resource_name ?? '').localeCompare(String(right?.resource_name ?? ''), 'en');

        if (nameCompare !== 0) {
            return nameCompare;
        }

        return Number(left?.id ?? 0) - Number(right?.id ?? 0);
    });
}

function sortTeams(items, categories) {
    const categoryMap = new Map((Array.isArray(categories) ? categories : []).map((item) => [Number(item.id), item]));

    return [...(Array.isArray(items) ? items : [])].sort((left, right) => {
        const leftCategory = categoryMap.get(Number(left?.team_category_id));
        const rightCategory = categoryMap.get(Number(right?.team_category_id));
        const leftSort = Number(leftCategory?.sort_order ?? 0);
        const rightSort = Number(rightCategory?.sort_order ?? 0);

        if (leftSort !== rightSort) {
            return leftSort - rightSort;
        }

        const leftCategoryName = String(leftCategory?.name ?? left?.category_name ?? '');
        const rightCategoryName = String(rightCategory?.name ?? right?.category_name ?? '');
        const categoryCompare = leftCategoryName.localeCompare(rightCategoryName, 'en');

        if (categoryCompare !== 0) {
            return categoryCompare;
        }

        return String(left?.name ?? '').localeCompare(String(right?.name ?? ''), 'en');
    });
}

function sortResourceTypes(items, categories) {
    const categoryMap = new Map((Array.isArray(categories) ? categories : []).map((item) => [Number(item.id), item]));

    return [...(Array.isArray(items) ? items : [])].sort((left, right) => {
        const leftCategoryId = Number(left?.category_id ?? left?.category?.id);
        const rightCategoryId = Number(right?.category_id ?? right?.category?.id);
        const leftCategory = categoryMap.get(leftCategoryId);
        const rightCategory = categoryMap.get(rightCategoryId);
        const leftSort = Number(leftCategory?.sort_order ?? 0);
        const rightSort = Number(rightCategory?.sort_order ?? 0);

        if (leftSort !== rightSort) {
            return leftSort - rightSort;
        }

        const leftCategoryName = String(leftCategory?.name ?? left?.category?.name ?? '');
        const rightCategoryName = String(rightCategory?.name ?? right?.category?.name ?? '');
        const categoryCompare = leftCategoryName.localeCompare(rightCategoryName, 'en');

        if (categoryCompare !== 0) {
            return categoryCompare;
        }

        return String(left?.name ?? '').localeCompare(String(right?.name ?? ''), 'en');
    });
}

function sortTeamInventories(items) {
    return [...(Array.isArray(items) ? items : [])].sort((left, right) => {
        const leftCategoryName = String(left?.resource_category_name ?? '');
        const rightCategoryName = String(right?.resource_category_name ?? '');
        const categoryCompare = leftCategoryName.localeCompare(rightCategoryName, 'en');

        if (categoryCompare !== 0) {
            return categoryCompare;
        }

        const nameCompare = String(left?.resource_name ?? '').localeCompare(String(right?.resource_name ?? ''), 'en');

        if (nameCompare !== 0) {
            return nameCompare;
        }

        return Number(left?.id ?? 0) - Number(right?.id ?? 0);
    });
}

function sortUsers(items) {
    return [...(Array.isArray(items) ? items : [])].sort((left, right) => {
        return String(left?.name ?? '').localeCompare(String(right?.name ?? ''), 'en');
    });
}

function describeAlertLevel(alertLevel) {
    switch (String(alertLevel ?? '')) {
        case 'Elevated':
            return 'Heightened readiness is in effect due to increased local risk.';
        case 'Critical':
            return 'Critical local response conditions are active. Immediate coordination is required.';
        case 'Normal':
        default:
            return 'Standard barangay operations are in effect.';
    }
}

function renderOverviewModule(state) {
    if (!state.summary) {
        return `
            <section class="admin-module-page is-overview">
                <header class="admin-page-header">
                    <div class="admin-overview-hero">
                        <div class="admin-overview-hero-main">
                            <p class="ui-eyebrow">Admin</p>
                            <h1 class="ui-title admin-page-title">Control Center</h1>
                            <p class="hero-copy">Manage accounts, incident setup, teams, resources, and operational runtime settings from one admin surface.</p>
                        </div>
                        <aside class="panel-card admin-overview-status-card">
                            <span class="pill blue">Runtime</span>
                            <div class="admin-loading-host admin-overview-status-loading" data-admin-overview-status-skeleton></div>
                        </aside>
                    </div>
                </header>

                <section class="admin-metrics-grid" data-admin-overview-metrics-skeleton></section>
                <section class="admin-module-grid" data-admin-overview-modules-skeleton></section>
            </section>
        `;
    }

    const counts = state.summary?.counts ?? {};
    const focusModules = MODULES.filter((module) => module.id !== 'overview');
    const alertLevel = formatStatusLabel(state.summary?.alert_level ?? 'normal');
    const moduleStats = {
        users: `${counts.users ?? 0} accounts`,
        incidents: `${counts.incident_types ?? 0} incident types`,
        teams: `${counts.teams ?? 0} teams`,
        resources: `${counts.resource_types ?? 0} resource types`,
    };

    return `
        <section class="admin-module-page is-overview">
            <header class="admin-page-header">
                <div class="admin-overview-hero">
                    <div class="admin-overview-hero-main">
                        <p class="ui-eyebrow">Admin</p>
                        <h1 class="ui-title admin-page-title">Control Center</h1>
                        <p class="hero-copy">Manage accounts, incident setup, teams, resources, and operational runtime settings from one admin surface.</p>
                    </div>
                    <aside class="panel-card admin-overview-status-card">
                        <span class="pill blue">Runtime</span>
                        <strong class="admin-overview-status-level">Alert ${escapeHtml(alertLevel)}</strong>
                        <p class="admin-overview-status-copy">Hotline is using the current shared settings baseline for alert posture and call timing behavior.</p>
                    </aside>
                </div>
            </header>

            <section class="admin-metrics-grid">
                ${cardMetric('Users', counts.users ?? 0, 'users')}
                ${cardMetric('Operators', counts.operators ?? 0, 'users')}
                ${cardMetric('Teams', counts.teams ?? 0, 'teams')}
                ${cardMetric('Incidents', counts.incident_types ?? 0, 'incidents')}
                ${cardMetric('Resources', counts.resource_types ?? 0, 'resources')}
            </section>

            <section class="admin-module-grid">
                ${focusModules.map((module) => `
                    <article class="panel-card admin-module-card${module.implemented ? '' : ' is-pending'}">
                        <div class="admin-module-card-head">
                            <div>
                                <p class="ui-eyebrow">Module</p>
                                <h3>${escapeHtml(module.label)}</h3>
                            </div>
                            <span class="pill blue admin-module-card-pill">${escapeHtml(moduleStats[module.id] ?? '')}</span>
                        </div>
                        <div class="admin-module-card-body">
                            <p>${escapeHtml(module.description)}</p>
                        </div>
                        <div class="admin-module-card-actions">
                            <button
                                class="ui-button ui-button-primary${module.implemented ? '' : ' ui-button-secondary'}"
                                type="button"
                                data-admin-open-module="${module.id}"
                                ${module.implemented ? '' : 'disabled'}
                            >
                                ${module.implemented ? 'Open Module' : 'Pending'}
                            </button>
                        </div>
                    </article>
                `).join('')}
            </section>
        </section>
    `;
}

function adminModuleCountMeta(state, moduleId) {
    if (moduleId === 'users') {
        if (!state.users.loaded) {
            return 'Loading...';
        }
        return `${state.users.items.length ?? 0} accounts`;
    }

    if (moduleId === 'incidents') {
        if (!state.incidents.loaded) {
            return 'Loading...';
        }
        return `${state.incidents.items.length ?? 0} incident types`;
    }

    if (moduleId === 'teams') {
        if (!state.teams.loaded) {
            return 'Loading...';
        }
        return `${state.teams.items.length ?? 0} teams`;
    }

    if (moduleId === 'resources') {
        if (!state.resources.loaded) {
            return 'Loading...';
        }
        return `${state.resources.items.length ?? 0} resource types`;
    }

    return '';
}

function renderAdminModuleShell(state, {
    moduleId,
    title,
    description,
    bodyClass = 'admin-module-layout',
    bodyContent = '',
    pageClass = '',
}) {
    const countMeta = adminModuleCountMeta(state, moduleId);
    const pageClasses = ['admin-module-page'];

    if (pageClass) {
        pageClasses.push(pageClass);
    }

    return `
        <section class="${pageClasses.join(' ')}">
            <header class="panel-card admin-module-header">
                <div class="admin-module-header-main">
                    <div>
                        <p class="ui-eyebrow">Admin / ${escapeHtml(title)}</p>
                        <h1 class="ui-title admin-page-title">${escapeHtml(title)}</h1>
                    </div>
                    ${countMeta ? `<span class="pill blue admin-module-header-pill">${escapeHtml(countMeta)}</span>` : ''}
                </div>
                <p class="hero-copy admin-module-description">${escapeHtml(description)}</p>
            </header>

            <section class="${escapeHtml(bodyClass)}">
                ${bodyContent}
            </section>
        </section>
    `;
}

function renderPendingModule(state, moduleId) {
    const module = moduleById(moduleId);
    const placeholderCopy = {
        incidents: 'This grouped admin page will host incident categories and incident types once the CRUD editors land.',
        teams: 'This grouped admin page will host the Hotline team directory and membership tools once the CRUD editors land.',
        resources: 'This grouped admin page will host resource types and future resource inventory tools once the CRUD editors land.',
    };

    return renderAdminModuleShell(state, {
        moduleId: module.id,
        title: module.label,
        description: module.description,
        pageClass: 'is-pending',
        bodyContent: `
            <article class="panel-card admin-placeholder-card">
                <h3>Module Not Landed Yet</h3>
                <p>${escapeHtml(placeholderCopy[module.id] ?? 'This admin page still needs its backend CRUD endpoints and Helper-driven editor workflow.')}</p>
            </article>
        `,
    });
}

function renderUsersModule(state) {
    return renderAdminModuleShell(state, {
        moduleId: 'users',
        title: 'Users',
        description: 'Create and maintain Hotline browser accounts. Delete remains blocked when records are still referenced.',
        pageClass: 'is-users',
        bodyContent: state.users.loaded
            ? '<div class="admin-grid-host is-standalone" data-admin-users-grid></div>'
            : '<div class="admin-grid-host is-standalone admin-loading-host" data-admin-users-skeleton></div>',
    });
}

function renderIncidentsModule(state) {
    return renderGroupedModule(state, {
        moduleId: 'incidents',
        stateKey: 'incidents',
        title: 'Incidents',
        description: 'Create incident categories and maintain incident types for local hotline classification.',
        gridKey: 'incident-types',
        categoryActionKey: 'incident',
        categoryPanelClass: 'incident-category',
        categorySkeletonKey: 'incident-categories',
    }, renderAdminModuleShell);
}

function renderResourcesModule(state) {
    return renderGroupedModule(state, {
        moduleId: 'resources',
        stateKey: 'resources',
        title: 'Resources',
        description: 'Create resource categories and maintain Hotline resource types for team inventory and assignment workflows.',
        gridKey: 'resource-types',
        categoryActionKey: 'resource',
        categoryPanelClass: 'resource-category',
        categorySkeletonKey: 'resource-categories',
    }, renderAdminModuleShell);
}

function renderTeamsModule(state) {
    return renderGroupedModule(state, {
        moduleId: 'teams',
        stateKey: 'teams',
        title: 'Teams',
        description: 'Create team categories and maintain Hotline teams for dispatch and assignment workflows.',
        gridKey: 'teams',
        categoryActionKey: 'team',
        categoryPanelClass: 'team-category',
        categorySkeletonKey: 'team-categories',
    }, renderAdminModuleShell);
}

function renderModuleContent(state) {
    if (state.module === 'overview') {
        return renderOverviewModule(state);
    }

    if (state.module === 'users') {
        return renderUsersModule(state);
    }

    if (state.module === 'incidents') {
        return renderIncidentsModule(state);
    }

    if (state.module === 'resources') {
        return renderResourcesModule(state);
    }

    if (state.module === 'teams') {
        return renderTeamsModule(state);
    }

    return renderPendingModule(state, state.module);
}

function adminAlertToneClass(alertLevel) {
    const normalized = String(alertLevel ?? '').trim();

    if (normalized === 'Elevated') {
        return 'is-alert-elevated';
    }

    if (normalized === 'Critical') {
        return 'is-alert-critical';
    }

    return '';
}

function renderAdmin(root, bootstrap, state) {
    destroyAdminRuntime();

    appState.runtime.navbarItems = buildAdminNavbarItems(state.module);
    appState.runtime.navbarActiveId = state.module;
    appState.runtime.navbarActions = [{
        id: SETTINGS_ACTION_ID,
        label: 'Settings',
        icon: appState.helper.createIcon('actions.options', {
            size: 18,
            ariaLabel: 'Settings',
        }).outerHTML,
    }];
    appState.runtime.navbarOnAction = (action) => {
        if (action?.id === SETTINGS_ACTION_ID) {
            void openSettingsModal();
        }
    };

    root.innerHTML = sharedShell({
        title: 'Admin',
        kicker: 'Admin',
        statusLabel: `Alert ${bootstrap.alert_level}`,
        brandHref: '/admin',
        showHero: false,
        shellClass: `admin-shell ${adminAlertToneClass(bootstrap.alert_level)}`.trim(),
        mainClass: 'admin-main',
        toolbarClass: 'admin-toolbar',
        content: `
            <div class="admin-surface">
                ${renderModuleContent(state)}
            </div>
        `,
    });

    mountSurfaceChrome(root, 'admin', bootstrap);
}

function mountAdminSkeleton(host, data = {}, options = {}) {
    if (!host || !appState.helper.createSkeleton) {
        return null;
    }

    const skeleton = appState.helper.createSkeleton(host, data, options);
    rememberAdminInstance(skeleton);
    return skeleton;
}

function mountOverviewSkeletons(root) {
    mountAdminSkeleton(root.querySelector('[data-admin-overview-status-skeleton]'), { lines: 2 }, {
        variant: 'lines',
        className: 'admin-overview-status-skeleton',
    });
    mountAdminSkeleton(root.querySelector('[data-admin-overview-metrics-skeleton]'), { rows: 1 }, {
        variant: 'grid',
        columns: 5,
        className: 'admin-overview-metrics-skeleton',
    });
    mountAdminSkeleton(root.querySelector('[data-admin-overview-modules-skeleton]'), { rows: 1 }, {
        variant: 'grid',
        columns: 4,
        className: 'admin-overview-modules-skeleton',
    });
}

function mountSplitModuleSkeletons(root, gridSelector, listSelector, footerSelector) {
    mountAdminSkeleton(root.querySelector(gridSelector), { rows: 5 }, {
        variant: 'grid',
        columns: 1,
        className: 'admin-grid-skeleton',
    });
    mountAdminSkeleton(root.querySelector(listSelector), { rows: 4 }, {
        variant: 'grid',
        columns: 1,
        className: 'admin-category-list-skeleton',
    });
    mountAdminSkeleton(root.querySelector(footerSelector), { lines: 2 }, {
        variant: 'lines',
        className: 'admin-category-footer-skeleton',
    });
}

function iconButton(kind, label, dataset) {
    const button = document.createElement('button');
    const toneClass = kind === 'delete' ? 'ui-button-danger' : 'ui-button-borderless';
    const iconName = kind === 'delete'
        ? 'actions.delete'
        : kind === 'inventory'
            ? 'actions.options'
            : 'actions.edit';
    button.type = 'button';
    button.className = `ui-button ui-button-icon ${toneClass} ui-cell-action`;
    button.setAttribute('aria-label', label);
    button.title = label;
    button.appendChild(appState.helper.createIcon(iconName, {
        size: 16,
        ariaLabel: label,
    }));

    Object.entries(dataset ?? {}).forEach(([key, value]) => {
        button.dataset[key] = String(value);
    });

    return button;
}

function userFormRows(user = null) {
    return [
        [
            { type: 'input', input: 'text', name: 'name', label: 'Name', required: true },
            { type: 'input', input: 'email', name: 'email', label: 'Email', required: true },
        ],
        [
            { type: 'input', input: 'text', name: 'mobile', label: 'Mobile', required: true },
            {
                type: 'select',
                name: 'role',
                label: 'Role',
                required: true,
                options: [
                    { value: 'citizen', label: 'Citizen' },
                    { value: 'operator', label: 'Operator' },
                    { value: 'command', label: 'Command' },
                    { value: 'admin', label: 'Admin' },
                ],
            },
        ],
        [
            {
                type: 'select',
                name: 'status',
                label: 'Status',
                required: true,
                options: [
                    { value: 'active', label: 'Active' },
                    { value: 'suspended', label: 'Suspended' },
                    { value: 'disabled', label: 'Disabled' },
                    { value: 'pending', label: 'Pending' },
                ],
            },
            { type: 'input', input: 'text', name: 'avatar', label: 'Avatar Path' },
        ],
        [
            {
                type: 'input',
                input: 'password',
                name: 'password',
                label: user ? 'Password (leave blank to keep current)' : 'Password',
                required: !user,
            },
        ],
    ];
}

function normalizeUserPayload(values, editing = false) {
    const payload = {
        name: String(values?.name ?? '').trim(),
        email: String(values?.email ?? '').trim(),
        mobile: String(values?.mobile ?? '').trim(),
        role: String(values?.role ?? '').trim(),
        status: String(values?.status ?? 'active').trim() || 'active',
        avatar: String(values?.avatar ?? '').trim(),
    };
    const password = String(values?.password ?? '');

    if (!editing || password.trim() !== '') {
        payload.password = password;
    }

    return payload;
}

async function refreshSummaryAndUsers({ keepSelection = true } = {}) {
    const state = adminRuntime.state;

    if (!state) {
        return;
    }

    const usersPayload = await fetchJson('/api/admin/users');
    state.users.items = Array.isArray(usersPayload?.items) ? usersPayload.items : [];

    if (keepSelection && state.users.selectedId && state.users.items.some((item) => Number(item.id) === Number(state.users.selectedId))) {
        return;
    }

    state.users.selectedId = state.users.items[0]?.id ?? null;
}

async function refreshSummaryAndResourceTypes({ keepSelection = true } = {}) {
    const state = adminRuntime.state;

    if (!state) {
        return;
    }

    const [resourceTypesPayload, resourceTypeCategoriesPayload] = await Promise.all([
        fetchJson('/api/admin/resource-types'),
        fetchJson('/api/admin/resource-type-categories'),
    ]);
    state.resources.items = Array.isArray(resourceTypesPayload?.items) ? resourceTypesPayload.items : [];
    state.resources.categories = Array.isArray(resourceTypeCategoriesPayload?.items) ? resourceTypeCategoriesPayload.items : [];

    if (keepSelection && state.resources.selectedId && state.resources.items.some((item) => Number(item.id) === Number(state.resources.selectedId))) {
        if (keepSelection && state.resources.selectedCategoryId && state.resources.categories.some((item) => Number(item.id) === Number(state.resources.selectedCategoryId))) {
            return;
        }

        state.resources.selectedCategoryId = state.resources.categories[0]?.id ?? null;
        return;
    }

    state.resources.selectedId = state.resources.items[0]?.id ?? null;
    if (keepSelection && state.resources.selectedCategoryId && state.resources.categories.some((item) => Number(item.id) === Number(state.resources.selectedCategoryId))) {
        return;
    }

    state.resources.selectedCategoryId = state.resources.categories[0]?.id ?? null;
}

async function refreshIncidentsState({
    keepSelection = true,
    refreshSummary = true,
    refreshTypes = true,
    refreshCategories = true,
} = {}) {
    const state = adminRuntime.state;

    if (!state) {
        return;
    }

    const tasks = [];

    if (refreshSummary) {
        tasks.push(fetchJson('/api/admin/summary').then((summary) => {
            state.summary = summary;
        }));
    }

    if (refreshTypes) {
        tasks.push(fetchJson('/api/admin/incident-types').then((payload) => {
            state.incidents.items = Array.isArray(payload?.items) ? payload.items : [];
        }));
    }

    if (refreshCategories) {
        tasks.push(fetchJson('/api/admin/incident-categories').then((payload) => {
            state.incidents.categories = Array.isArray(payload?.items) ? payload.items : [];
        }));
    }

    await Promise.all(tasks);

    if (refreshTypes) {
        if (!(keepSelection && state.incidents.selectedId && state.incidents.items.some((item) => Number(item.id) === Number(state.incidents.selectedId)))) {
            state.incidents.selectedId = state.incidents.items[0]?.id ?? null;
        }
    }

    if (refreshCategories) {
        if (!(keepSelection && state.incidents.selectedCategoryId && state.incidents.categories.some((item) => Number(item.id) === Number(state.incidents.selectedCategoryId)))) {
            state.incidents.selectedCategoryId = state.incidents.categories[0]?.id ?? null;
        }
    }
}

async function refreshSummaryAndTeams({ keepSelection = true } = {}) {
    const state = adminRuntime.state;

    if (!state) {
        return;
    }

    const [teamsPayload, teamCategoriesPayload] = await Promise.all([
        fetchJson('/api/admin/teams'),
        fetchJson('/api/admin/team-categories'),
    ]);
    state.teams.items = Array.isArray(teamsPayload?.items) ? teamsPayload.items : [];
    state.teams.categories = Array.isArray(teamCategoriesPayload?.items) ? teamCategoriesPayload.items : [];

    if (keepSelection && state.teams.selectedId && state.teams.items.some((item) => Number(item.id) === Number(state.teams.selectedId))) {
        if (keepSelection && state.teams.selectedCategoryId && state.teams.categories.some((item) => Number(item.id) === Number(state.teams.selectedCategoryId))) {
            return;
        }

        state.teams.selectedCategoryId = state.teams.categories[0]?.id ?? null;
        return;
    }

    state.teams.selectedId = state.teams.items[0]?.id ?? null;
    if (keepSelection && state.teams.selectedCategoryId && state.teams.categories.some((item) => Number(item.id) === Number(state.teams.selectedCategoryId))) {
        return;
    }

    state.teams.selectedCategoryId = state.teams.categories[0]?.id ?? null;
}

function settingsEditorData(state) {
    const values = state.settings.draft;

    return {
        selectionLabel: 'Hotline Runtime Settings',
        sections: [
            {
                id: 'call-routing',
                title: 'Call Routing',
                description: 'Citizen hold and call timeout behavior.',
                properties: [
                    {
                        id: 'call_hold_seconds',
                        kind: 'number',
                        label: 'Call Hold Seconds',
                        value: values.call_hold_seconds ?? 3,
                        help: 'Number of seconds the citizen must hold the main button before routing starts.',
                    },
                    {
                        id: 'call_timeout_seconds',
                        kind: 'number',
                        label: 'Call Timeout Seconds',
                        value: values.call_timeout_seconds ?? 20,
                        help: 'How long a fresh call attempt rings before timeout logic applies.',
                    },
                    {
                        id: 'reconnect_timeout_seconds',
                        kind: 'number',
                        label: 'Reconnect Timeout Seconds',
                        value: values.reconnect_timeout_seconds ?? 20,
                        help: 'How long reconnect attempts can ring before expiring.',
                    },
                ],
            },
            {
                id: 'alerts',
                title: 'Alerts And UI',
                description: 'Shared runtime posture seen across surfaces.',
                properties: [
                    {
                        id: 'alert_level',
                        kind: 'select',
                        label: 'Alert Level',
                        value: values.alert_level ?? 'Normal',
                        options: [
                            { value: 'Normal', label: 'Normal' },
                            { value: 'Elevated', label: 'Elevated' },
                            { value: 'Critical', label: 'Critical' },
                        ],
                    },
                    {
                        id: 'alert_voice',
                        kind: 'text',
                        label: 'Alert Voice',
                        value: values.alert_voice ?? 'default',
                        help: 'Voice cue preset used when speech output is added.',
                    },
                    {
                        id: 'audio_graph_style',
                        kind: 'select',
                        label: 'Audio Graph Style',
                        value: values.audio_graph_style ?? 'vu',
                        options: [
                            { value: 'vu', label: 'VU Meter' },
                            { value: 'dots', label: 'Dots' },
                            { value: 'mirrored', label: 'Mirrored' },
                            { value: 'spectrum', label: 'Spectrum' },
                            { value: 'classic-waveform', label: 'Classic Waveform' },
                            { value: 'neon', label: 'Neon' },
                            { value: 'particle', label: 'Particle' },
                            { value: 'shockwave', label: 'Shockwave' },
                            { value: 'tsunami', label: 'Tsunami' },
                            { value: 'plasma', label: 'Plasma' },
                            { value: 'burst', label: 'Burst' },
                            { value: 'heartbeat', label: 'Heartbeat' },
                        ],
                        help: 'Available styles are aligned with the shared Helper audio graph component.',
                    },
                ],
            },
            {
                id: 'integration-realtime',
                title: 'Realtime',
                description: 'Live transport, signaling, and trusted admission settings.',
                properties: [
                    {
                        id: 'realtime_url',
                        kind: 'text',
                        label: 'Realtime URL',
                        value: values.realtime_url ?? 'https://realtime.pbb.ph',
                        placeholder: 'https://realtime.pbb.ph',
                        autocomplete: 'off',
                        help: 'Base URL for the PBB Realtime service used by Hotline admissions, backend publish, and client connections.',
                    },
                    {
                        id: 'realtime_client_code',
                        kind: 'text',
                        label: 'Realtime Client Code',
                        value: values.realtime_client_code ?? 'clt_01KMXFPRXCTHJAG10DMACJFMYB',
                        placeholder: 'clt_01KMXFPRXCTHJAG10DMACJFMYB',
                        autocomplete: 'off',
                        help: 'Trusted PBB Realtime client code assigned to Hotline.',
                    },
                    {
                        id: 'realtime_project_code_server',
                        kind: 'text',
                        label: 'Server Project Code',
                        value: values.realtime_project_code_server ?? 'prj_01KNGH5A0VAVWDT5Y8B35F2CV6',
                        placeholder: 'prj_01KNGH5A0VAVWDT5Y8B35F2CV6',
                        autocomplete: 'off',
                        help: 'Realtime project scope code reserved for Hotline backend/server-originated Realtime actions.',
                    },
                    {
                        id: 'realtime_project_code_caller',
                        kind: 'text',
                        label: 'Citizen Project Code',
                        value: values.realtime_project_code_caller ?? 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9',
                        placeholder: 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9',
                        autocomplete: 'off',
                        help: 'Realtime project scope code used for Hotline citizen admissions.',
                    },
                    {
                        id: 'realtime_project_code_operator',
                        kind: 'text',
                        label: 'Operator Project Code',
                        value: values.realtime_project_code_operator ?? 'prj_01KMXG0AXH58JZ2NQSGE5AYMH6',
                        placeholder: 'prj_01KMXG0AXH58JZ2NQSGE5AYMH6',
                        autocomplete: 'off',
                        help: 'Realtime project scope code used for Hotline operator admissions and presence.',
                    },
                    {
                        id: 'realtime_project_code_command',
                        kind: 'text',
                        label: 'Command Project Code',
                        value: values.realtime_project_code_command ?? 'prj_hotline_command',
                        placeholder: 'prj_hotline_command',
                        autocomplete: 'off',
                        help: 'Realtime project scope code used for Hotline command admissions and presence.',
                    },
                    {
                        id: 'realtime_project_code_media_ingest',
                        kind: 'text',
                        label: 'Media Ingest Project Code',
                        value: values.realtime_project_code_media_ingest ?? 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV',
                        placeholder: 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV',
                        autocomplete: 'off',
                        help: 'Realtime project scope code reserved for operator-to-server media ingest and saver workflows.',
                    },
                    {
                        id: 'realtime_backend_ingress_secret',
                        kind: 'password',
                        label: 'Realtime Backend Ingress Secret',
                        value: values.realtime_backend_ingress_secret ?? '',
                        placeholder: 'Enter backend ingress secret',
                        autocomplete: 'off',
                        help: 'Backend-only secret used when Hotline publishes server-originated events like alert-level changes into Realtime rooms.',
                    },
                    {
                        id: 'realtime_media_ingest_secret',
                        kind: 'password',
                        label: 'Realtime Media Ingest Secret',
                        value: values.realtime_media_ingest_secret ?? '',
                        placeholder: 'Enter media ingest secret',
                        autocomplete: 'off',
                        help: 'Shared secret expected from Realtime when it forwards media.chunk.publish payloads into Hotline internal chunk ingest.',
                    },
                    {
                        id: 'realtime_token_signing_secret',
                        kind: 'password',
                        label: 'Realtime Token Signing Secret',
                        value: values.realtime_token_signing_secret ?? '',
                        placeholder: 'Enter signing secret',
                        autocomplete: 'off',
                        help: 'Backend-side signing secret used when Hotline issues trusted Realtime admission payloads.',
                    },
                ],
            },
            {
                id: 'integration-relay',
                title: 'Relay',
                description: 'Cross-hub handoff and authenticated Relay integration settings.',
                properties: [
                    {
                        id: 'relay_url',
                        kind: 'text',
                        label: 'Relay URL',
                        value: values.relay_url ?? 'https://relay.pbb.ph',
                        placeholder: 'https://relay.pbb.ph',
                        autocomplete: 'off',
                        help: 'Base URL for the PBB Relay service used when Hotline hands off summaries or other cross-hub payloads.',
                    },
                    {
                        id: 'relay_token',
                        kind: 'password',
                        label: 'Relay Token',
                        value: values.relay_token ?? '',
                        placeholder: 'Enter relay token',
                        autocomplete: 'off',
                        help: 'Trusted token or shared credential used when Hotline authenticates with Relay-owned endpoints.',
                    },
                ],
            },
            {
                id: 'integration-map-server',
                title: 'Map Server',
                description: 'Map tiles and shared map-asset delivery for operator and future command views.',
                properties: [
                    {
                        id: 'map_server_url',
                        kind: 'text',
                        label: 'Map Server URL',
                        value: values.map_server_url ?? 'https://mapserver.pbb.ph',
                        placeholder: 'https://mapserver.pbb.ph',
                        autocomplete: 'off',
                        help: 'Base URL for the PBB Map Server used for tiles and map assets in operator and command views.',
                    },
                ],
            },
        ],
    };
}

function resourceTypeFormRows() {
    return [
        [{ type: 'input', input: 'text', name: 'name', label: 'Name', required: true }],
        [{
            type: 'ui.select',
            name: 'category_id',
            label: 'Category',
            required: true,
            multiple: false,
            searchable: true,
            clearable: false,
            options: adminRuntime.state?.resources?.categories?.map((category) => ({
                value: String(category.id),
                label: category.name,
            })) ?? [],
        }],
        [{ type: 'input', input: 'text', name: 'unit_label', label: 'Unit Label' }],
    ];
}

function normalizeResourceTypePayload(values) {
    return {
        category_id: Number(values?.category_id),
        name: String(values?.name ?? '').trim(),
        unit_label: String(values?.unit_label ?? '').trim() || null,
    };
}

function resourceTypeCategoryFormRows() {
    return [
        [
            { type: 'input', input: 'text', name: 'name', label: 'Name', required: true },
            { type: 'input', input: 'number', name: 'sort_order', label: 'Sort Order' },
        ],
        [
            { type: 'textarea', name: 'description', label: 'Description' },
        ],
    ];
}

function normalizeResourceTypeCategoryPayload(values) {
    return {
        name: String(values?.name ?? '').trim(),
        description: String(values?.description ?? '').trim() || null,
        sort_order: Number(values?.sort_order ?? 0) || 0,
    };
}

function incidentTypeFormRows() {
    return [
        [{
            type: 'ui.select',
            name: 'incident_category_id',
            label: 'Category',
            required: true,
            multiple: false,
            searchable: true,
            clearable: false,
            options: adminRuntime.state?.incidents?.categories?.map((category) => ({
                value: String(category.id),
                label: category.name,
            })) ?? [],
        }],
        [{ type: 'input', input: 'text', name: 'name', label: 'Name', required: true }],
        [{ type: 'textarea', name: 'description', label: 'Description' }],
    ];
}

function normalizeIncidentTypePayload(values) {
    return {
        incident_category_id: Number(values?.incident_category_id),
        name: String(values?.name ?? '').trim(),
        description: String(values?.description ?? '').trim() || null,
    };
}

function incidentCategoryFormRows() {
    return [
        [
            { type: 'input', input: 'text', name: 'name', label: 'Name', required: true },
            { type: 'input', input: 'number', name: 'sort_order', label: 'Sort Order' },
        ],
        [{ type: 'textarea', name: 'description', label: 'Description' }],
    ];
}

function normalizeIncidentCategoryPayload(values) {
    return {
        name: String(values?.name ?? '').trim(),
        description: String(values?.description ?? '').trim() || null,
        sort_order: Number(values?.sort_order ?? 0) || 0,
    };
}

function incidentTypeFieldFormRows() {
    return [
        [
            { type: 'input', input: 'text', name: 'field_label', label: 'Field Label', required: true },
            { type: 'input', input: 'text', name: 'field_key', label: 'Field Key', required: true },
        ],
        [
            {
                type: 'ui.select',
                name: 'input_type',
                label: 'Input Type',
                required: true,
                multiple: false,
                searchable: false,
                clearable: false,
                options: INCIDENT_FIELD_INPUT_TYPE_OPTIONS.map((option) => ({
                    value: option.value,
                    label: option.label,
                })),
            },
            { type: 'checkbox', name: 'is_required', label: 'Required Field' },
        ],
        [
            {
                type: 'input',
                input: 'text',
                name: 'placeholder',
                label: 'Placeholder',
                visibleWhen: {
                    input_type: ['text', 'textarea', 'number'],
                },
            },
            {
                type: 'input',
                input: 'text',
                name: 'unit',
                label: 'Unit',
                visibleWhen: {
                    input_type: 'number',
                },
            },
        ],
        [
            {
                type: 'input',
                input: 'text',
                name: 'default_value',
                label: 'Default Value',
                visibleWhen: {
                    input_type: ['text', 'textarea', 'number'],
                },
            },
            { type: 'input', input: 'number', name: 'sort_order', label: 'Sort Order' },
        ],
        [
            {
                type: 'input',
                input: 'number',
                name: 'min',
                label: 'Min',
                visibleWhen: {
                    input_type: 'number',
                },
            },
            {
                type: 'input',
                input: 'number',
                name: 'max',
                label: 'Max',
                visibleWhen: {
                    input_type: 'number',
                },
            },
        ],
        [
            {
                type: 'input',
                input: 'number',
                name: 'step',
                label: 'Step',
                visibleWhen: {
                    input_type: 'number',
                },
            },
        ],
        [
            {
                type: 'ui.select',
                name: 'group_preset',
                label: 'Group Preset',
                required: true,
                multiple: false,
                searchable: false,
                clearable: false,
                options: INCIDENT_FIELD_GROUP_PRESET_OPTIONS.map((option) => ({
                    value: option.value,
                    label: option.label,
                })),
                visibleWhen: {
                    input_type: 'group',
                },
                help: 'Stores the preset name and expanded child fields so existing incidents stay stable if Helper presets change later.',
            },
        ],
        [
            {
                type: 'textarea',
                name: 'options_text',
                label: 'Options',
                placeholder: 'One option per line',
                help: 'Used for select and multi-select fields. Leave empty for non-option input types.',
                visibleWhen: {
                    input_type: ['select', 'multiselect'],
                },
            },
        ],
    ];
}

function normalizeIncidentTypeFieldPayload(values) {
    const fieldLabel = String(values?.field_label ?? '').trim();
    const inputType = String(values?.input_type ?? 'text').trim() || 'text';
    const options = String(values?.options_text ?? '')
        .split(/\r?\n/)
        .map((value) => value.trim())
        .filter(Boolean);
    const supportsDefaultValue = ['text', 'textarea', 'number'].includes(inputType);
    const supportsOptions = ['select', 'multiselect'].includes(inputType);
    const isNumber = inputType === 'number';
    const groupPreset = String(values?.group_preset ?? '').trim();

    return {
        field_label: fieldLabel,
        field_key: String(values?.field_key ?? '').trim() || slugifyFieldKey(fieldLabel),
        input_type: inputType,
        options: supportsOptions ? options : [],
        config: inputType === 'group' ? { preset: groupPreset } : null,
        default_value: supportsDefaultValue ? (String(values?.default_value ?? '').trim() || null) : null,
        placeholder: ['text', 'textarea', 'number'].includes(inputType) ? (String(values?.placeholder ?? '').trim() || null) : null,
        unit: isNumber ? (String(values?.unit ?? '').trim() || null) : null,
        is_required: Boolean(values?.is_required),
        sort_order: Number(values?.sort_order ?? 0) || 0,
        min: isNumber && String(values?.min ?? '').trim() !== '' ? Number(values?.min) : null,
        max: isNumber && String(values?.max ?? '').trim() !== '' ? Number(values?.max) : null,
        step: isNumber && String(values?.step ?? '').trim() !== '' ? Number(values?.step) : null,
    };
}

function incidentTypeDefaultResourceFormRows(resourceTypeOptions = []) {
    return [
        [{
            type: 'ui.treeSelect',
            name: 'resource_type_id',
            label: 'Required Resource',
            required: true,
            searchable: true,
            clearable: false,
            defaultExpanded: false,
            options: buildResourceTypeTreeOptions(resourceTypeOptions),
        }],
        [{ type: 'input', input: 'number', name: 'quantity_required', label: 'Quantity Required', required: true }],
        [{ type: 'input', input: 'number', name: 'sort_order', label: 'Sort Order' }],
        [{ type: 'textarea', name: 'notes', label: 'Notes' }],
    ];
}

function normalizeIncidentTypeDefaultResourcePayload(values) {
    return {
        resource_type_id: Number(values?.resource_type_id),
        quantity_required: Number(values?.quantity_required ?? 1) || 1,
        sort_order: Number(values?.sort_order ?? 0) || 0,
        notes: String(values?.notes ?? '').trim() || null,
    };
}

function teamFormRows() {
    return [
        [{ type: 'input', input: 'text', name: 'name', label: 'Name', required: true }],
        [{
            type: 'ui.select',
            name: 'team_category_id',
            label: 'Category',
            required: true,
            multiple: false,
            searchable: true,
            clearable: false,
            options: adminRuntime.state?.teams?.categories?.map((category) => ({
                value: String(category.id),
                label: category.name,
            })) ?? [],
        }],
        [{
            type: 'select',
            name: 'status',
            label: 'Status',
            required: true,
            options: [
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Inactive' },
                { value: 'standby', label: 'Standby' },
            ],
        }],
    ];
}

function normalizeTeamPayload(values) {
    return {
        team_category_id: Number(values?.team_category_id),
        name: String(values?.name ?? '').trim(),
        status: String(values?.status ?? 'active').trim() || 'active',
    };
}

function teamCategoryFormRows() {
    return [
        [
            { type: 'input', input: 'text', name: 'name', label: 'Name', required: true },
            { type: 'input', input: 'number', name: 'sort_order', label: 'Sort Order' },
        ],
        [{ type: 'textarea', name: 'description', label: 'Description' }],
    ];
}

function normalizeTeamCategoryPayload(values) {
    return {
        name: String(values?.name ?? '').trim(),
        description: String(values?.description ?? '').trim() || null,
        sort_order: Number(values?.sort_order ?? 0) || 0,
    };
}

function buildResourceTypeTreeOptions(resourceTypeOptions = []) {
    const groups = new Map();

    resourceTypeOptions.forEach((option) => {
        const categoryName = String(option?.category_name ?? '').trim() || 'Uncategorized';
        const resourceName = String(option?.name ?? '').trim() || String(option?.label ?? '').trim() || 'Resource';

        if (!groups.has(categoryName)) {
            groups.set(categoryName, {
                id: `category:${categoryName}`,
                label: categoryName,
                children: [],
            });
        }

        groups.get(categoryName).children.push({
            id: String(option.id),
            label: resourceName,
        });
    });

    return Array.from(groups.values());
}

function teamInventoryFormRows(resourceTypeOptions = []) {
    return [
        [{
            type: 'ui.treeSelect',
            name: 'resource_type_id',
            label: 'Resource Type',
            required: true,
            searchable: true,
            clearable: false,
            defaultExpanded: false,
            options: buildResourceTypeTreeOptions(resourceTypeOptions),
        }],
        [{ type: 'input', input: 'number', name: 'quantity_available', label: 'Quantity Available', required: true }],
    ];
}

function normalizeTeamInventoryPayload(values) {
    return {
        resource_type_id: Number(values?.resource_type_id),
        quantity_available: Number(values?.quantity_available ?? 0) || 0,
    };
}

async function openUserForm(user = null) {
    await ensureHelperUi();

    const editing = Boolean(user?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${user.name}` : 'Add User',
        submitLabel: editing ? 'Save User' : 'Create User',
        busyMessage: editing ? 'Saving user...' : 'Creating user...',
        initialValues: {
            name: user?.name ?? '',
            email: user?.email ?? '',
            mobile: user?.mobile ?? '',
            role: user?.role ?? 'citizen',
            status: user?.status ?? 'active',
            avatar: user?.avatar_path ?? '',
            password: '',
        },
        rows: userFormRows(user),
        async onSubmit(values, context) {
            try {
                const payload = normalizeUserPayload(values, editing);
                const targetUrl = editing ? `/api/admin/users/${user.id}` : '/api/admin/users';

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });
                const savedUser = response?.user ?? null;

                if (savedUser) {
                    const nextItems = editing
                        ? adminRuntime.state.users.items.map((item) => (
                            Number(item.id) === Number(savedUser.id)
                                ? { ...item, ...savedUser }
                                : item
                        ))
                        : [...adminRuntime.state.users.items, savedUser];

                    adminRuntime.state.users.items = sortUsers(nextItems);
                    adminRuntime.state.users.selectedId = savedUser.id;
                }

                adminRuntime.state.users.blockedDeleteUserId = null;
                adminRuntime.state.users.blockedDeleteReferences = [];
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                showToast(editing ? 'User updated.' : 'User created.', 'success');
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openResourceTypeForm(resourceType = null) {
    await ensureHelperUi();
    const state = adminRuntime.state;

    if (!state.resources.categories.length) {
        await appState.helper.uiAlert('Create a resource category first before adding a resource type.', {
            title: 'Category Required',
            variant: 'warning',
            description: 'Resource types now belong to managed categories. Use "Add Category" first, then create the resource type.',
            okText: 'OK',
        });
        return;
    }

    const editing = Boolean(resourceType?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${resourceType.name}` : 'Add Resource Type',
        submitLabel: editing ? 'Save Resource Type' : 'Create Resource Type',
        busyMessage: editing ? 'Saving resource type...' : 'Creating resource type...',
        initialValues: {
            name: resourceType?.name ?? '',
            category_id: String(resourceType?.category_id ?? ''),
            unit_label: resourceType?.unit_label ?? '',
        },
        rows: resourceTypeFormRows(),
        async onSubmit(values, context) {
            try {
                const payload = normalizeResourceTypePayload(values);
                const targetUrl = editing ? `/api/admin/resource-types/${resourceType.id}` : '/api/admin/resource-types';

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });
                const savedType = response?.resource_type ?? null;

                if (savedType) {
                    const nextItems = editing
                        ? adminRuntime.state.resources.items.map((item) => (
                            Number(item.id) === Number(savedType.id)
                                ? { ...item, ...savedType }
                                : item
                        ))
                        : [...adminRuntime.state.resources.items, savedType];

                    adminRuntime.state.resources.items = sortResourceTypes(nextItems, adminRuntime.state.resources.categories);
                    adminRuntime.state.resources.selectedId = savedType.id;
                }

                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                showToast(editing ? 'Resource type updated.' : 'Resource type created.', 'success');
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openResourceTypeCategoryForm(category = null) {
    await ensureHelperUi();

    const editing = Boolean(category?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${category.name}` : 'Add Resource Category',
        submitLabel: editing ? 'Save Category' : 'Create Category',
        busyMessage: editing ? 'Saving category...' : 'Creating category...',
        initialValues: {
            name: category?.name ?? '',
            description: category?.description ?? '',
            sort_order: category?.sort_order ?? 0,
        },
        rows: resourceTypeCategoryFormRows(),
        async onSubmit(values, context) {
            try {
                const payload = normalizeResourceTypeCategoryPayload(values);
                const targetUrl = editing ? `/api/admin/resource-type-categories/${category.id}` : '/api/admin/resource-type-categories';

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });
                const savedCategory = response?.category ?? null;

                if (savedCategory) {
                    const nextCategories = editing
                        ? adminRuntime.state.resources.categories.map((item) => (
                            Number(item.id) === Number(savedCategory.id)
                                ? { ...item, ...savedCategory }
                                : item
                        ))
                        : [...adminRuntime.state.resources.categories, savedCategory];

                    adminRuntime.state.resources.categories = sortIncidentCategories(nextCategories);
                    adminRuntime.state.resources.selectedCategoryId = savedCategory.id;

                    if (editing) {
                        adminRuntime.state.resources.items = sortResourceTypes(
                            adminRuntime.state.resources.items.map((item) => (
                                Number(item.category_id ?? item.category?.id) === Number(savedCategory.id)
                                    ? {
                                        ...item,
                                        category_id: savedCategory.id,
                                        category: {
                                            ...(item.category ?? {}),
                                            ...savedCategory,
                                        },
                                    }
                                    : item
                            )),
                            adminRuntime.state.resources.categories,
                        );
                    }
                }

                showToast(editing ? 'Resource category updated.' : 'Resource category created.', 'success');
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openIncidentTypeForm(type = null) {
    await ensureHelperUi();
    const state = adminRuntime.state;

    if (!state.incidents.categories.length) {
        await appState.helper.uiAlert('Create an incident category first before adding an incident type.', {
            title: 'Category Required',
            variant: 'warning',
            description: 'Incident types now belong to managed categories. Use "Add Category" first, then create the incident type.',
            okText: 'OK',
        });
        return;
    }

    const editing = Boolean(type?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${type.name}` : 'Add Incident Type',
        submitLabel: editing ? 'Save Incident Type' : 'Create Incident Type',
        busyMessage: editing ? 'Saving incident type...' : 'Creating incident type...',
        initialValues: {
            incident_category_id: String(type?.incident_category_id ?? ''),
            name: type?.name ?? '',
            description: type?.description ?? '',
        },
        rows: incidentTypeFormRows(),
        async onSubmit(values, context) {
            try {
                const payload = normalizeIncidentTypePayload(values);
                const targetUrl = editing ? `/api/admin/incident-types/${type.id}` : '/api/admin/incident-types';

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });
                const savedType = response?.type ?? null;

                if (savedType) {
                    const nextItems = editing
                        ? adminRuntime.state.incidents.items.map((item) => (
                            Number(item.id) === Number(savedType.id)
                                ? { ...item, ...savedType }
                                : item
                        ))
                        : [...adminRuntime.state.incidents.items, savedType];

                    adminRuntime.state.incidents.items = sortIncidentTypes(nextItems, adminRuntime.state.incidents.categories);
                    adminRuntime.state.incidents.selectedId = savedType.id;
                }

                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                showToast(editing ? 'Incident type updated.' : 'Incident type created.', 'success');
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openIncidentCategoryForm(category = null) {
    await ensureHelperUi();

    const editing = Boolean(category?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${category.name}` : 'Add Incident Category',
        submitLabel: editing ? 'Save Category' : 'Create Category',
        busyMessage: editing ? 'Saving category...' : 'Creating category...',
        initialValues: {
            name: category?.name ?? '',
            description: category?.description ?? '',
            sort_order: category?.sort_order ?? 0,
        },
        rows: incidentCategoryFormRows(),
        async onSubmit(values, context) {
            try {
                const payload = normalizeIncidentCategoryPayload(values);
                const targetUrl = editing ? `/api/admin/incident-categories/${category.id}` : '/api/admin/incident-categories';

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });
                const savedCategory = response?.category ?? null;

                if (savedCategory) {
                    const nextCategories = editing
                        ? adminRuntime.state.incidents.categories.map((item) => (
                            Number(item.id) === Number(savedCategory.id)
                                ? { ...item, ...savedCategory }
                                : item
                        ))
                        : [...adminRuntime.state.incidents.categories, savedCategory];

                    adminRuntime.state.incidents.categories = sortIncidentCategories(nextCategories);
                    adminRuntime.state.incidents.selectedCategoryId = savedCategory.id;

                    if (editing) {
                        adminRuntime.state.incidents.items = adminRuntime.state.incidents.items.map((item) => (
                            Number(item.incident_category_id) === Number(savedCategory.id)
                                ? {
                                    ...item,
                                    category_name: savedCategory.name,
                                }
                                : item
                        ));
                    }
                }

                showToast(editing ? 'Incident category updated.' : 'Incident category created.', 'success');
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openIncidentTypeFieldForm(type, field = null, onSaved = null) {
    await ensureHelperUi();

    const editing = Boolean(field?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${field.field_label}` : `Add Field for ${type.name}`,
        submitLabel: editing ? 'Save Field' : 'Create Field',
        busyMessage: editing ? 'Saving field...' : 'Creating field...',
        initialValues: {
            incident_type_id: String(type.id),
            field_label: field?.field_label ?? '',
            field_key: field?.field_key ?? '',
            input_type: field?.input_type ?? 'text',
            group_preset: field?.config?.preset ?? field?.preset ?? 'person',
            options_text: Array.isArray(field?.options) ? field.options.join('\n') : '',
            default_value: field?.default_value ?? '',
            placeholder: field?.placeholder ?? '',
            unit: field?.unit ?? '',
            is_required: Boolean(field?.is_required),
            sort_order: Number(field?.sort_order ?? 0),
            min: field?.min ?? '',
            max: field?.max ?? '',
            step: field?.step ?? '',
        },
        rows: incidentTypeFieldFormRows(),
        async onSubmit(values, context) {
            try {
                const payload = {
                    incident_type_id: Number(type.id),
                    ...normalizeIncidentTypeFieldPayload(values),
                };
                const targetUrl = editing
                    ? `/api/admin/incident-type-fields/${field.id}`
                    : '/api/admin/incident-type-fields';

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });
                const savedField = response?.field ?? null;

                await onSaved?.(savedField, {
                    editing,
                    previousField: field,
                });
                showToast(editing ? 'Incident field updated.' : 'Incident field created.', 'success');
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openIncidentTypeDefaultResourceForm(type, resourceTypeOptions, defaultResource = null, onSaved = null) {
    await ensureHelperUi();

    if (!Array.isArray(resourceTypeOptions) || resourceTypeOptions.length === 0) {
        await appState.helper.uiAlert('Create resource types first before adding required resources.', {
            title: 'Resource Type Required',
            variant: 'warning',
            description: 'Incident type defaults depend on the resource types configured in the Resources admin module.',
            okText: 'OK',
        });
        return;
    }

    const editing = Boolean(defaultResource?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${defaultResource.resource_name}` : `Add Required Resource for ${type.name}`,
        submitLabel: editing ? 'Save Required Resource' : 'Create Required Resource',
        busyMessage: editing ? 'Saving required resource...' : 'Creating required resource...',
        initialValues: {
            resource_type_id: String(defaultResource?.resource_type_id ?? ''),
            quantity_required: Number(defaultResource?.quantity_required ?? 1),
            sort_order: Number(defaultResource?.sort_order ?? 0),
            notes: defaultResource?.notes ?? '',
        },
        rows: incidentTypeDefaultResourceFormRows(resourceTypeOptions),
        async onSubmit(values, context) {
            try {
                const payload = normalizeIncidentTypeDefaultResourcePayload(values);
                const targetUrl = editing
                    ? `/api/admin/incident-types/${type.id}/default-resources/${defaultResource.id}`
                    : `/api/admin/incident-types/${type.id}/default-resources`;

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });
                const savedDefaultResource = response?.default_resource ?? null;

                await onSaved?.(savedDefaultResource, {
                    editing,
                    previousDefaultResource: defaultResource,
                });
                showToast(editing ? 'Required resource updated.' : 'Required resource created.', 'success');
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openIncidentTypeSetupModal(type) {
    await ensureHelperUi();

    adminRuntime.incidentTypeSetupModal?.destroy?.();

    const host = document.createElement('div');
    host.className = 'admin-incident-type-setup-modal';

    let detail = null;
    let fieldsGridInstance = null;
    let defaultsGridInstance = null;
    let modalClosed = false;

    const reloadDetail = async () => {
        detail = await fetchJson(`/api/admin/incident-types/${type.id}`);
        renderSetup();
    };

    const syncIncidentTypeCounts = () => {
        const fieldCount = Array.isArray(detail?.fields) ? detail.fields.length : 0;
        const defaultResourceCount = Array.isArray(detail?.default_required_resources)
            ? detail.default_required_resources.length
            : 0;

        if (detail?.type) {
            detail.type.fields_count = fieldCount;
            detail.type.default_required_resources_count = defaultResourceCount;
        }

        adminRuntime.state.incidents.items = adminRuntime.state.incidents.items.map((item) => (
            Number(item.id) === Number(type.id)
                ? {
                    ...item,
                    fields_count: fieldCount,
                    default_required_resources_count: defaultResourceCount,
                }
                : item
        ));
    };

    const handleFieldSaved = async (savedField, meta = {}) => {
        if (!savedField) {
            await reloadDetail();
            return;
        }

        const existingFields = Array.isArray(detail?.fields) ? detail.fields : [];
        const nextFields = meta?.editing
            ? existingFields.map((item) => (
                Number(item.id) === Number(savedField.id)
                    ? { ...item, ...savedField }
                    : item
            ))
            : [...existingFields, savedField];

        detail.fields = sortIncidentTypeFields(nextFields);
        syncIncidentTypeCounts();
        renderSetup();
    };

    const handleDefaultResourceSaved = async (savedDefaultResource, meta = {}) => {
        if (!savedDefaultResource) {
            await reloadDetail();
            return;
        }

        const existingDefaults = Array.isArray(detail?.default_required_resources)
            ? detail.default_required_resources
            : [];
        const nextDefaults = meta?.editing
            ? existingDefaults.map((item) => (
                Number(item.id) === Number(savedDefaultResource.id)
                    ? { ...item, ...savedDefaultResource }
                    : item
            ))
            : [...existingDefaults, savedDefaultResource];

        detail.default_required_resources = sortIncidentTypeDefaultResources(nextDefaults);
        syncIncidentTypeCounts();
        renderSetup();
    };

    const deleteIncidentTypeField = async (field) => {
        await confirmDeleteAction(`Delete ${field.field_label}?`, {
            title: 'Delete Field',
            confirmText: 'Delete',
            confirmVariant: 'danger',
            confirmBusyMessage: 'Deleting field...',
            description: 'Deletion is blocked automatically when the field is already used by incident detail records.',
            errorText: 'Unable to delete field.',
            onConfirm: async () => {
                try {
                    await fetchJson(`/api/admin/incident-type-fields/${field.id}`, {
                        method: 'delete',
                    });
                    await reloadDetail();
                    showToast('Incident field deleted.', 'success');
                } catch (error) {
                    throw blockedDeleteError(error, 'Unable to delete field.');
                }
            },
        });
    };

    const deleteDefaultResource = async (defaultResource) => {
        await confirmDeleteAction(`Delete ${defaultResource.resource_name} from ${type.name}?`, {
            title: 'Delete Required Resource',
            confirmText: 'Delete',
            confirmVariant: 'danger',
            confirmBusyMessage: 'Deleting required resource...',
            description: 'This removes the resource from the default required resources for future incidents of this type.',
            errorText: 'Unable to delete required resource.',
            onConfirm: async () => {
                try {
                    await fetchJson(`/api/admin/incident-types/${type.id}/default-resources/${defaultResource.id}`, {
                        method: 'delete',
                    });
                    await reloadDetail();
                    showToast('Required resource deleted.', 'success');
                } catch (error) {
                    throw blockedDeleteError(error, 'Unable to delete required resource.');
                }
            },
        });
    };

    const renderSetupLoading = () => {
        fieldsGridInstance?.destroy?.();
        defaultsGridInstance?.destroy?.();

        host.innerHTML = `
            <div class="admin-incident-type-setup-shell">
                <div class="admin-team-inventory-header">
                    <div class="admin-team-inventory-headings">
                        <h2 class="admin-team-inventory-title">${escapeHtml(type.name)}</h2>
                        <p class="admin-team-inventory-subtitle">${escapeHtml(type.category_name ?? 'Category')}</p>
                    </div>
                </div>
                <div class="admin-incident-type-setup-grids">
                    <div class="admin-grid-host is-standalone admin-loading-host" data-admin-incident-type-fields-skeleton></div>
                    <div class="admin-grid-host is-standalone admin-loading-host" data-admin-incident-type-defaults-skeleton></div>
                </div>
            </div>
        `;

        mountAdminSkeleton(host.querySelector('[data-admin-incident-type-fields-skeleton]'), { rows: 5 }, {
            shimmer: true,
            className: 'admin-grid-skeleton',
        });
        mountAdminSkeleton(host.querySelector('[data-admin-incident-type-defaults-skeleton]'), { rows: 5 }, {
            shimmer: true,
            className: 'admin-grid-skeleton',
        });
    };

    const renderSetupError = (message) => {
        fieldsGridInstance?.destroy?.();
        defaultsGridInstance?.destroy?.();

        host.innerHTML = `
            <div class="admin-incident-type-setup-shell">
                <div class="admin-team-inventory-header">
                    <div class="admin-team-inventory-headings">
                        <h2 class="admin-team-inventory-title">${escapeHtml(type.name)}</h2>
                        <p class="admin-team-inventory-subtitle">${escapeHtml(type.category_name ?? 'Category')}</p>
                    </div>
                </div>
                <div class="admin-empty-state">
                    <p>${escapeHtml(message)}</p>
                </div>
            </div>
        `;
    };

    const renderSetup = () => {
        fieldsGridInstance?.destroy?.();
        defaultsGridInstance?.destroy?.();

        const fields = Array.isArray(detail?.fields) ? detail.fields : [];
        const defaults = Array.isArray(detail?.default_required_resources) ? detail.default_required_resources : [];
        const resourceTypeOptions = Array.isArray(detail?.resource_type_options) ? detail.resource_type_options : [];

        host.innerHTML = `
            <div class="admin-incident-type-setup-shell">
                <div class="admin-team-inventory-header">
                    <div class="admin-team-inventory-headings">
                        <h2 class="admin-team-inventory-title">${escapeHtml(detail?.type?.name ?? type.name)}</h2>
                        <p class="admin-team-inventory-subtitle">${escapeHtml(detail?.type?.category_name ?? type.category_name ?? 'Category')}</p>
                    </div>
                </div>
                <div class="admin-incident-type-setup-grids">
                    <div class="admin-grid-host is-standalone" data-admin-incident-type-fields-grid></div>
                    <div class="admin-grid-host is-standalone" data-admin-incident-type-default-resources-grid></div>
                </div>
            </div>
        `;

        const fieldsGridHost = host.querySelector('[data-admin-incident-type-fields-grid]');
        const defaultsGridHost = host.querySelector('[data-admin-incident-type-default-resources-grid]');

        if (fieldsGridHost) {
            fieldsGridInstance = appState.helper.createGrid(fieldsGridHost, fields.map((item) => ({
                ...item,
                type_label: formatStatusLabel(item.input_type),
            })), {
                chrome: true,
                className: 'admin-incident-type-fields-grid',
                rowKey: 'id',
                selectable: 'none',
                enableSearch: true,
                enableSort: true,
                enablePagination: false,
                enableColumnResize: true,
                searchPlaceholder: 'Search field label, key, or type',
                minColumnWidth: 92,
                columnWidths: {
                    field_label: 320,
                    is_required: 76,
                    actions: 104,
                },
                columns: [
                    {
                        key: 'field_label',
                        label: 'Field',
                        width: 320,
                        sortable: true,
                        wrap: false,
                        renderCell: ({ row }) => {
                            const wrap = document.createElement('div');
                            wrap.className = 'admin-grid-stacked-cell';

                            const title = document.createElement('span');
                            title.className = 'admin-grid-stacked-title';
                            title.textContent = row.field_label ?? 'Untitled';

                            const subtitle = document.createElement('span');
                            subtitle.className = 'admin-grid-stacked-subtitle';
                            subtitle.textContent = `${row.field_key ?? 'field_key'} • ${row.type_label ?? 'Type'}`;
                            subtitle.title = subtitle.textContent;

                            wrap.append(title, subtitle);
                            return wrap;
                        },
                    },
                    {
                        key: 'is_required',
                        label: 'Req',
                        width: 76,
                        sortable: true,
                        wrap: false,
                        align: 'center',
                        renderCell: ({ row }) => {
                            const wrap = document.createElement('div');
                            wrap.className = `admin-grid-required-cell${row.is_required ? ' is-required' : ' is-optional'}`;
                            wrap.title = row.is_required ? 'Required' : 'Optional';
                            wrap.setAttribute('aria-label', wrap.title);

                            const iconName = row.is_required ? 'status.success' : 'actions.close';
                            wrap.appendChild(appState.helper.createIcon(iconName, {
                                size: 16,
                                ariaLabel: wrap.title,
                            }));

                            return wrap;
                        },
                    },
                    {
                        key: 'actions',
                        label: 'Actions',
                        width: 104,
                        sortable: false,
                        resizable: false,
                        align: 'center',
                        renderCell: ({ row }) => {
                            const wrap = document.createElement('div');
                            wrap.className = 'ui-cell-actions';

                            const editButton = iconButton('edit', `Edit ${row.field_label}`, {});
                            const deleteButton = iconButton('delete', `Delete ${row.field_label}`, {});

                            editButton.addEventListener('click', (event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                void openIncidentTypeFieldForm(detail.type, row, handleFieldSaved);
                            });

                            deleteButton.addEventListener('click', (event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                void deleteIncidentTypeField(row);
                            });

                            wrap.append(editButton, deleteButton);
                            return wrap;
                        },
                    },
                ],
                toolbarEnd: ({ totalRows, createElement }) => {
                    const countPill = createElement('span', {
                        className: 'pill blue admin-grid-count',
                        text: `${totalRows} fields`,
                    });

                    const addButton = createElement('button', {
                        className: 'ui-button ui-button-primary',
                        text: 'Add Field',
                        attrs: { type: 'button' },
                    });
                    addButton.addEventListener('click', async (event) => {
                        event.preventDefault();
                        await openIncidentTypeFieldForm(detail.type, null, handleFieldSaved);
                    });

                    return [countPill, addButton];
                },
            });

            rememberAdminInstance(fieldsGridInstance);
        }

        if (defaultsGridHost) {
            defaultsGridInstance = appState.helper.createGrid(defaultsGridHost, defaults.map((item) => ({
                ...item,
                updated_at_label: item.updated_at ? new Date(item.updated_at).toLocaleString('en-PH', {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                }) : 'Pending',
            })), {
                chrome: true,
                className: 'admin-incident-type-default-resources-grid',
                rowKey: 'id',
                selectable: 'none',
                enableSearch: true,
                enableSort: true,
                enablePagination: false,
                enableColumnResize: true,
                searchPlaceholder: 'Search resource or category',
                minColumnWidth: 92,
                columnWidths: {
                    resource_name: 320,
                    quantity_required: 84,
                    actions: 104,
                },
                columns: [
                    {
                        key: 'resource_name',
                        label: 'Resource',
                        width: 320,
                        sortable: true,
                        renderCell: ({ row }) => {
                            const wrap = document.createElement('div');
                            wrap.className = 'admin-grid-stacked-cell';

                            const title = document.createElement('span');
                            title.className = 'admin-grid-stacked-title';
                            title.textContent = row.resource_name ?? 'Unnamed resource';

                            const subtitle = document.createElement('span');
                            subtitle.className = 'admin-grid-stacked-subtitle';
                            subtitle.textContent = row.resource_category_name ?? 'Uncategorized';
                            subtitle.title = subtitle.textContent;

                            wrap.append(title, subtitle);
                            return wrap;
                        },
                    },
                    { key: 'quantity_required', label: 'Qty', width: 84, sortable: true, wrap: false, align: 'center' },
                    {
                        key: 'actions',
                        label: 'Actions',
                        width: 104,
                        sortable: false,
                        resizable: false,
                        align: 'center',
                        renderCell: ({ row }) => {
                            const wrap = document.createElement('div');
                            wrap.className = 'ui-cell-actions';

                            const editButton = iconButton('edit', `Edit ${row.resource_name}`, {});
                            const deleteButton = iconButton('delete', `Delete ${row.resource_name}`, {});

                            editButton.addEventListener('click', (event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                void openIncidentTypeDefaultResourceForm(detail.type, resourceTypeOptions, row, handleDefaultResourceSaved);
                            });

                            deleteButton.addEventListener('click', (event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                void deleteDefaultResource(row);
                            });

                            wrap.append(editButton, deleteButton);
                            return wrap;
                        },
                    },
                ],
                toolbarEnd: ({ totalRows, createElement }) => {
                    const countPill = createElement('span', {
                        className: 'pill blue admin-grid-count',
                        text: `${totalRows} resources`,
                    });

                    const addButton = createElement('button', {
                        className: 'ui-button ui-button-primary',
                        text: 'Add Required Resource',
                        attrs: { type: 'button' },
                    });
                    addButton.addEventListener('click', async (event) => {
                        event.preventDefault();
                        await openIncidentTypeDefaultResourceForm(detail.type, resourceTypeOptions, null, handleDefaultResourceSaved);
                    });

                    return [countPill, addButton];
                },
            });

            rememberAdminInstance(defaultsGridInstance);
        }
    };

    const modal = appState.helper.createActionModal({
        title: 'Incident Type Setup',
        size: 'full',
        content: host,
        actions: [
            {
                id: 'close',
                label: 'Close',
                variant: 'default',
            },
        ],
        onClose() {
            modalClosed = true;
            fieldsGridInstance?.destroy?.();
            defaultsGridInstance?.destroy?.();
            adminRuntime.incidentTypeSetupModal = null;
        },
    });

    adminRuntime.incidentTypeSetupModal = modal;
    renderSetupLoading();
    rememberAdminInstance(modal);

    const loadDetail = async () => {
        try {
            detail = await fetchJson(`/api/admin/incident-types/${type.id}`);

            if (modalClosed) {
                return;
            }

            renderSetup();
        } catch (error) {
            if (modalClosed) {
                return;
            }

            renderSetupError('Unable to load incident type setup right now.');
        }
    };

    const openPromise = modal.open();
    void loadDetail();
    await openPromise;
}

async function openTeamForm(team = null) {
    await ensureHelperUi();
    const state = adminRuntime.state;

    if (!state.teams.categories.length) {
        await appState.helper.uiAlert('Create a team category first before adding a team.', {
            title: 'Category Required',
            variant: 'warning',
            description: 'Teams belong to managed categories. Use "Add Category" first, then create the team.',
            okText: 'OK',
        });
        return;
    }

    const editing = Boolean(team?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${team.name}` : 'Add Team',
        submitLabel: editing ? 'Save Team' : 'Create Team',
        busyMessage: editing ? 'Saving team...' : 'Creating team...',
        initialValues: {
            name: team?.name ?? '',
            team_category_id: String(team?.team_category_id ?? ''),
            status: team?.status ?? 'active',
        },
        rows: teamFormRows(),
        async onSubmit(values, context) {
            try {
                const payload = normalizeTeamPayload(values);
                const targetUrl = editing ? `/api/admin/teams/${team.id}` : '/api/admin/teams';

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });
                const savedTeam = response?.team ?? null;

                if (savedTeam) {
                    const nextItems = editing
                        ? adminRuntime.state.teams.items.map((item) => (
                            Number(item.id) === Number(savedTeam.id)
                                ? { ...item, ...savedTeam }
                                : item
                        ))
                        : [...adminRuntime.state.teams.items, savedTeam];

                    adminRuntime.state.teams.items = sortTeams(nextItems, adminRuntime.state.teams.categories);
                    adminRuntime.state.teams.selectedId = savedTeam.id;
                }

                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                showToast(editing ? 'Team updated.' : 'Team created.', 'success');
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openTeamCategoryForm(category = null) {
    await ensureHelperUi();

    const editing = Boolean(category?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${category.name}` : 'Add Team Category',
        submitLabel: editing ? 'Save Category' : 'Create Category',
        busyMessage: editing ? 'Saving category...' : 'Creating category...',
        initialValues: {
            name: category?.name ?? '',
            description: category?.description ?? '',
            sort_order: category?.sort_order ?? 0,
        },
        rows: teamCategoryFormRows(),
        async onSubmit(values, context) {
            try {
                const payload = normalizeTeamCategoryPayload(values);
                const targetUrl = editing ? `/api/admin/team-categories/${category.id}` : '/api/admin/team-categories';

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });
                const savedCategory = response?.category ?? null;

                if (savedCategory) {
                    const nextCategories = editing
                        ? adminRuntime.state.teams.categories.map((item) => (
                            Number(item.id) === Number(savedCategory.id)
                                ? { ...item, ...savedCategory }
                                : item
                        ))
                        : [...adminRuntime.state.teams.categories, savedCategory];

                    adminRuntime.state.teams.categories = sortIncidentCategories(nextCategories);
                    adminRuntime.state.teams.selectedCategoryId = savedCategory.id;

                    if (editing) {
                        adminRuntime.state.teams.items = sortTeams(
                            adminRuntime.state.teams.items.map((item) => (
                                Number(item.team_category_id) === Number(savedCategory.id)
                                    ? {
                                        ...item,
                                        category_name: savedCategory.name,
                                    }
                                    : item
                            )),
                            adminRuntime.state.teams.categories,
                        );
                    }
                }

                showToast(editing ? 'Team category updated.' : 'Team category created.', 'success');
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, adminRuntime.state);
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openTeamInventoryForm(team, resourceTypeOptions, inventory = null, onSaved = null) {
    await ensureHelperUi();

    if (!Array.isArray(resourceTypeOptions) || resourceTypeOptions.length === 0) {
        await appState.helper.uiAlert('Create resource types first before managing team inventory.', {
            title: 'Resource Type Required',
            variant: 'warning',
            description: 'Team resource inventory depends on the resource types configured in the Resources admin module.',
            okText: 'OK',
        });
        return;
    }

    const editing = Boolean(inventory?.id);
    const modal = appState.helper.createFormModal({
        title: editing ? `Edit ${inventory.resource_name}` : `Add Inventory for ${team.name}`,
        submitLabel: editing ? 'Save Inventory' : 'Create Inventory',
        busyMessage: editing ? 'Saving inventory...' : 'Creating inventory...',
        initialValues: {
            resource_type_id: String(inventory?.resource_type_id ?? ''),
            quantity_available: Number(inventory?.quantity_available ?? 0),
        },
        rows: teamInventoryFormRows(resourceTypeOptions),
        async onSubmit(values, context) {
            try {
                const payload = normalizeTeamInventoryPayload(values);
                const targetUrl = editing
                    ? `/api/admin/teams/${team.id}/inventories/${inventory.id}`
                    : `/api/admin/teams/${team.id}/inventories`;

                const response = await fetchJson(targetUrl, {
                    method: 'post',
                    data: payload,
                });

                await onSaved?.(response?.inventory ?? null, {
                    editing,
                    previousInventory: inventory,
                });
                showToast(editing ? 'Team inventory updated.' : 'Team inventory created.', 'success');
                return true;
            } catch (error) {
                context?.applyApiErrors?.(error?.response?.data ?? {});
                return false;
            }
        },
    });

    rememberAdminInstance(modal);
    await modal.open();
}

async function openTeamInventoryModal(team) {
    await ensureHelperUi();

    adminRuntime.teamInventoryModal?.destroy?.();

    const host = document.createElement('div');
    host.className = 'admin-team-inventory-modal';

    let detail = null;
    let gridInstance = null;
    let modalClosed = false;

    const syncTeamInventoryCount = (inventoryCount) => {
        const state = adminRuntime.state;

        if (!state) {
            return;
        }

        state.teams.items = state.teams.items.map((item) => (
            Number(item.id) === Number(team.id)
                ? { ...item, inventory_count: inventoryCount }
                : item
        ));
    };

    const syncModalTeam = () => {
        const matchingTeam = adminRuntime.state?.teams?.items?.find((item) => Number(item.id) === Number(team.id));

        if (!matchingTeam || !detail?.team) {
            return;
        }

        detail.team = {
            ...detail.team,
            inventory_count: matchingTeam.inventory_count,
        };
    };

    const reloadDetail = async () => {
        detail = await fetchJson(`/api/admin/teams/${team.id}`);
        syncTeamInventoryCount(Array.isArray(detail?.inventories) ? detail.inventories.length : 0);
        renderInventory();
    };

    const handleInventorySaved = async (savedInventory, meta = {}) => {
        if (!savedInventory) {
            await reloadDetail();
            return;
        }

        const editing = Boolean(meta?.editing);
        const currentInventories = Array.isArray(detail?.inventories) ? detail.inventories : [];
        const nextInventories = editing
            ? currentInventories.map((item) => (
                Number(item.id) === Number(savedInventory.id)
                    ? { ...item, ...savedInventory }
                    : item
            ))
            : [...currentInventories, savedInventory];

        detail.inventories = sortTeamInventories(nextInventories);
        syncTeamInventoryCount(detail.inventories.length);
        syncModalTeam();
        renderInventory();
    };

    const deleteTeamInventory = async (inventory) => {
        await confirmDeleteAction(`Delete ${inventory.resource_name} from ${team.name}?`, {
            title: 'Delete Inventory',
            confirmText: 'Delete',
            confirmVariant: 'danger',
            confirmBusyMessage: 'Deleting inventory...',
            description: 'This removes the resource from the team inventory list.',
            errorText: 'Unable to delete inventory.',
            onConfirm: async () => {
                try {
                    await fetchJson(`/api/admin/teams/${team.id}/inventories/${inventory.id}`, {
                        method: 'delete',
                    });
                    detail.inventories = Array.isArray(detail?.inventories)
                        ? detail.inventories.filter((item) => Number(item.id) !== Number(inventory.id))
                        : [];
                    detail.inventories = sortTeamInventories(detail.inventories);
                    syncTeamInventoryCount(detail.inventories.length);
                    syncModalTeam();
                    renderInventory();
                    showToast('Team inventory deleted.', 'success');
                } catch (error) {
                    throw blockedDeleteError(error, 'Unable to delete inventory.');
                }
            },
        });
    };

    const renderInventoryLoading = () => {
        gridInstance?.destroy?.();

        host.innerHTML = `
            <div class="admin-team-inventory-shell">
                <div class="admin-team-inventory-header">
                    <div class="admin-team-inventory-headings">
                        <h2 class="admin-team-inventory-title">${escapeHtml(team.name)}</h2>
                        <p class="admin-team-inventory-subtitle">${escapeHtml(team.category_name ?? 'Category')}</p>
                    </div>
                    <span class="pill">Loading</span>
                </div>
                <div class="admin-grid-host is-standalone admin-loading-host" data-admin-team-inventory-skeleton></div>
            </div>
        `;

        mountAdminSkeleton(host.querySelector('[data-admin-team-inventory-skeleton]'), { rows: 5 }, {
            shimmer: true,
            className: 'admin-grid-skeleton',
        });
    };

    const renderInventoryError = (message) => {
        gridInstance?.destroy?.();

        host.innerHTML = `
            <div class="admin-team-inventory-shell">
                <div class="admin-team-inventory-header">
                    <div class="admin-team-inventory-headings">
                        <h2 class="admin-team-inventory-title">${escapeHtml(team.name)}</h2>
                        <p class="admin-team-inventory-subtitle">${escapeHtml(team.category_name ?? 'Category')}</p>
                    </div>
                    <span class="pill">Unavailable</span>
                </div>
                <div class="admin-empty-state">
                    <p>${escapeHtml(message)}</p>
                </div>
            </div>
        `;
    };

    const renderInventory = () => {
        gridInstance?.destroy?.();

        const inventories = Array.isArray(detail?.inventories) ? detail.inventories : [];
        const resourceTypeOptions = Array.isArray(detail?.resource_type_options) ? detail.resource_type_options : [];

        host.innerHTML = `
            <div class="admin-team-inventory-shell">
                <div class="admin-team-inventory-header">
                    <div class="admin-team-inventory-headings">
                        <h2 class="admin-team-inventory-title">${escapeHtml(detail?.team?.name ?? team.name)}</h2>
                        <p class="admin-team-inventory-subtitle">${escapeHtml(detail?.team?.category_name ?? team.category_name ?? 'Category')}</p>
                    </div>
                    <span class="pill">${escapeHtml(formatStatusLabel(detail?.team?.status ?? team.status ?? 'active'))}</span>
                </div>
                <div class="admin-grid-host is-standalone" data-admin-team-inventory-grid></div>
            </div>
        `;

        const gridHost = host.querySelector('[data-admin-team-inventory-grid]');

        if (!gridHost) {
            return;
        }

        gridInstance = appState.helper.createGrid(gridHost, inventories.map((item) => ({
            ...item,
            updated_at_label: item.updated_at ? new Date(item.updated_at).toLocaleString('en-PH', {
                dateStyle: 'medium',
                timeStyle: 'short',
            }) : 'Pending',
        })), {
            chrome: true,
            className: 'admin-team-inventory-grid',
            rowKey: 'id',
            selectable: 'none',
            enableSearch: true,
            enableSort: true,
            enablePagination: false,
            enableColumnResize: true,
            searchPlaceholder: 'Search resource or category',
            minColumnWidth: 92,
            columnWidths: {
                resource_category_name: 180,
                resource_name: 240,
                quantity_available: 150,
                updated_at_label: 220,
                actions: 104,
            },
            columns: [
                { key: 'resource_category_name', label: 'Category', width: 180, sortable: true, wrap: false },
                { key: 'resource_name', label: 'Resource', width: 240, sortable: true, wrap: false },
                { key: 'quantity_available', label: 'Qty', width: 150, sortable: true, wrap: false },
                { key: 'updated_at_label', label: 'Updated', width: 220, sortable: true, wrap: false },
                {
                    key: 'actions',
                    label: 'Actions',
                    width: 104,
                    sortable: false,
                    resizable: false,
                    align: 'center',
                    renderCell: ({ row }) => {
                        const wrap = document.createElement('div');
                        wrap.className = 'ui-cell-actions';

                        const editButton = iconButton('edit', `Edit ${row.resource_name}`, {});
                        const deleteButton = iconButton('delete', `Delete ${row.resource_name}`, {});

                        editButton.addEventListener('click', (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            void openTeamInventoryForm(detail.team, resourceTypeOptions, row, handleInventorySaved);
                        });

                        deleteButton.addEventListener('click', (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            void deleteTeamInventory(row);
                        });

                        wrap.append(editButton, deleteButton);

                        return wrap;
                    },
                },
            ],
            toolbarEnd: ({ totalRows, createElement }) => {
                const countPill = createElement('span', {
                    className: 'pill blue admin-grid-count',
                    text: `${totalRows} records`,
                });

                const addButton = createElement('button', {
                    className: 'ui-button ui-button-primary',
                    text: 'Add Inventory',
                    attrs: { type: 'button' },
                });
                addButton.addEventListener('click', async (event) => {
                    event.preventDefault();
                    await openTeamInventoryForm(detail.team, resourceTypeOptions, null, handleInventorySaved);
                });

                return [countPill, addButton];
            },
        });

        rememberAdminInstance(gridInstance);
    };

    const modal = appState.helper.createActionModal({
        title: 'Team Resources',
        size: 'xl',
        content: host,
        actions: [
            {
                id: 'close',
                label: 'Close',
                variant: 'default',
            },
        ],
        onClose() {
            modalClosed = true;
            gridInstance?.destroy?.();
            adminRuntime.teamInventoryModal = null;
        },
    });

    adminRuntime.teamInventoryModal = modal;
    renderInventoryLoading();
    rememberAdminInstance(modal);

    const loadDetail = async () => {
        try {
            detail = await fetchJson(`/api/admin/teams/${team.id}`);

            if (modalClosed) {
                return;
            }

            syncTeamInventoryCount(Array.isArray(detail?.inventories) ? detail.inventories.length : 0);
            syncModalTeam();
            renderInventory();
        } catch (error) {
            if (modalClosed) {
                return;
            }

            renderInventoryError('Unable to load team resources right now.');
        }
    };

    const openPromise = modal.open();
    void loadDetail();
    await openPromise;
}

async function deleteUser(userId) {
    const state = adminRuntime.state;
    const user = state.users.items.find((item) => Number(item.id) === Number(userId));

    if (!user) {
        return;
    }

    await ensureHelperUi();

    await confirmDeleteAction(`Delete ${user.name}? This permanently removes the account when no references remain.`, {
        title: 'Delete User',
        confirmText: 'Delete',
        confirmVariant: 'danger',
        confirmBusyMessage: 'Deleting user...',
        description: 'Deletion is blocked automatically when the account is still referenced by incidents, assignments, or call records.',
        errorText: 'Unable to delete user.',
        onConfirm: async () => {
            try {
                await fetchJson(`/api/admin/users/${user.id}`, {
                    method: 'delete',
                });

                state.users.blockedDeleteUserId = null;
                state.users.blockedDeleteReferences = [];
                await refreshSummaryAndUsers({ keepSelection: false });
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                showToast('User deleted.', 'success');
            } catch (error) {
                if (error?.response?.status === 409) {
                    state.users.selectedId = user.id;
                    state.users.blockedDeleteUserId = user.id;
                    state.users.blockedDeleteReferences = Array.isArray(error.response?.data?.references)
                        ? error.response.data.references
                        : [];
                    renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                    wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                }

                throw blockedDeleteError(error, 'Unable to delete user.');
            }
        },
    });
}

async function deleteResourceType(resourceTypeId) {
    const state = adminRuntime.state;
    const resourceType = state.resources.items.find((item) => Number(item.id) === Number(resourceTypeId));

    if (!resourceType) {
        return;
    }

    await ensureHelperUi();

    await confirmDeleteAction(`Delete ${resourceType.name}? This removes the resource type when no references remain.`, {
        title: 'Delete Resource Type',
        confirmText: 'Delete',
        confirmVariant: 'danger',
        confirmBusyMessage: 'Deleting resource type...',
        description: 'Deletion is blocked automatically when the resource type is still used by incident defaults, incident demand, team inventory, or team assignment allocations.',
        errorText: 'Unable to delete resource type.',
        onConfirm: async () => {
            try {
                await fetchJson(`/api/admin/resource-types/${resourceType.id}`, {
                    method: 'delete',
                });

                await refreshSummaryAndResourceTypes({ keepSelection: false });
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                showToast('Resource type deleted.', 'success');
            } catch (error) {
                throw blockedDeleteError(error, 'Unable to delete resource type.');
            }
        },
    });
}

async function deleteResourceTypeCategory(categoryId) {
    const state = adminRuntime.state;
    const category = state.resources.categories.find((item) => Number(item.id) === Number(categoryId));

    if (!category) {
        return;
    }

    await ensureHelperUi();

    await confirmDeleteAction(`Delete ${category.name}? This removes the resource category when no references remain.`, {
        title: 'Delete Resource Category',
        confirmText: 'Delete',
        confirmVariant: 'danger',
        confirmBusyMessage: 'Deleting resource category...',
        description: 'Deletion is blocked automatically when the category is still referenced by resource types or inventory data.',
        errorText: 'Unable to delete resource category.',
        onConfirm: async () => {
            try {
                await fetchJson(`/api/admin/resource-type-categories/${category.id}`, {
                    method: 'delete',
                });

                state.resources.categories = state.resources.categories.filter((item) => Number(item.id) !== Number(category.id));
                if (Number(state.resources.selectedCategoryId) === Number(category.id)) {
                    state.resources.selectedCategoryId = state.resources.categories[0]?.id ?? null;
                }
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                showToast('Resource category deleted.', 'success');
            } catch (error) {
                throw blockedDeleteError(error, 'Unable to delete resource category.');
            }
        },
    });
}

async function deleteIncidentType(typeId) {
    const state = adminRuntime.state;
    const type = state.incidents.items.find((item) => Number(item.id) === Number(typeId));

    if (!type) {
        return;
    }

    await ensureHelperUi();

    await confirmDeleteAction(`Delete ${type.name}? This removes the incident type when no references remain.`, {
        title: 'Delete Incident Type',
        confirmText: 'Delete',
        confirmVariant: 'danger',
        confirmBusyMessage: 'Deleting incident type...',
        description: 'Deletion is blocked automatically when the incident type is still used by fields, default resources, incident details, or incident resource demand records.',
        errorText: 'Unable to delete incident type.',
        onConfirm: async () => {
            try {
                await fetchJson(`/api/admin/incident-types/${type.id}`, {
                    method: 'delete',
                });

                await refreshIncidentsState({
                    keepSelection: false,
                    refreshSummary: false,
                    refreshTypes: true,
                    refreshCategories: false,
                });
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                showToast('Incident type deleted.', 'success');
            } catch (error) {
                throw blockedDeleteError(error, 'Unable to delete incident type.');
            }
        },
    });
}

async function deleteIncidentCategory(categoryId) {
    const state = adminRuntime.state;
    const category = state.incidents.categories.find((item) => Number(item.id) === Number(categoryId));

    if (!category) {
        return;
    }

    await ensureHelperUi();

    await confirmDeleteAction(`Delete ${category.name}? This removes the incident category when no references remain.`, {
        title: 'Delete Incident Category',
        confirmText: 'Delete',
        confirmVariant: 'danger',
        confirmBusyMessage: 'Deleting incident category...',
        description: 'Deletion is blocked automatically when the category is still referenced by incident types or incident records.',
        errorText: 'Unable to delete incident category.',
        onConfirm: async () => {
            try {
                await fetchJson(`/api/admin/incident-categories/${category.id}`, {
                    method: 'delete',
                });

                await refreshIncidentsState({
                    keepSelection: false,
                    refreshSummary: false,
                    refreshTypes: false,
                    refreshCategories: true,
                });
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                showToast('Incident category deleted.', 'success');
            } catch (error) {
                throw blockedDeleteError(error, 'Unable to delete incident category.');
            }
        },
    });
}

async function deleteTeam(teamId) {
    const state = adminRuntime.state;
    const team = state.teams.items.find((item) => Number(item.id) === Number(teamId));

    if (!team) {
        return;
    }

    await ensureHelperUi();

    await confirmDeleteAction(`Delete ${team.name}? This removes the team when no references remain.`, {
        title: 'Delete Team',
        confirmText: 'Delete',
        confirmVariant: 'danger',
        confirmBusyMessage: 'Deleting team...',
        description: 'Deletion is blocked automatically when the team is still used by team inventory or team assignment records.',
        errorText: 'Unable to delete team.',
        onConfirm: async () => {
            try {
                await fetchJson(`/api/admin/teams/${team.id}`, {
                    method: 'delete',
                });

                await refreshSummaryAndTeams({ keepSelection: false });
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                showToast('Team deleted.', 'success');
            } catch (error) {
                throw blockedDeleteError(error, 'Unable to delete team.');
            }
        },
    });
}

async function deleteTeamCategory(categoryId) {
    const state = adminRuntime.state;
    const category = state.teams.categories.find((item) => Number(item.id) === Number(categoryId));

    if (!category) {
        return;
    }

    await ensureHelperUi();

    await confirmDeleteAction(`Delete ${category.name}? This removes the team category when no references remain.`, {
        title: 'Delete Team Category',
        confirmText: 'Delete',
        confirmVariant: 'danger',
        confirmBusyMessage: 'Deleting team category...',
        description: 'Deletion is blocked automatically when the category is still referenced by teams or assignment data.',
        errorText: 'Unable to delete team category.',
        onConfirm: async () => {
            try {
                await fetchJson(`/api/admin/team-categories/${category.id}`, {
                    method: 'delete',
                });

                state.teams.categories = state.teams.categories.filter((item) => Number(item.id) !== Number(category.id));
                if (Number(state.teams.selectedCategoryId) === Number(category.id)) {
                    state.teams.selectedCategoryId = state.teams.categories[0]?.id ?? null;
                }
                renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
                showToast('Team category deleted.', 'success');
            } catch (error) {
                throw blockedDeleteError(error, 'Unable to delete team category.');
            }
        },
    });
}

function mountUsersGrid(root, state) {
    const host = root.querySelector('[data-admin-users-grid]');

    if (!host || !appState.helper.createGrid) {
        return;
    }

    const rows = state.users.items.map((item) => ({
        ...item,
        role_label: formatStatusLabel(item.role),
        status_label: formatStatusLabel(item.status),
    }));

    const buildUsersToolbarEnd = ({ totalRows, createElement }) => {
        const countPill = createElement('span', {
            className: 'pill blue admin-grid-count',
            text: `${totalRows} records`,
        });

        const addButton = createElement('button', {
            className: 'ui-button ui-button-primary',
            text: 'Add User',
            attrs: { type: 'button' },
        });
        addButton.addEventListener('click', async (event) => {
            event.preventDefault();
            await openUserForm(null);
        });

        return [countPill, addButton];
    };

    const grid = appState.helper.createGrid(host, rows, {
        chrome: true,
        className: 'admin-users-grid',
        rowKey: 'id',
        selectable: 'none',
        enableSearch: true,
        enableSort: true,
        enablePagination: false,
        enableColumnResize: true,
        searchPlaceholder: 'Search name, email, or mobile',
        minColumnWidth: 92,
        columnWidths: {
            name: 200,
            role_label: 110,
            status_label: 110,
            email: 240,
            mobile: 160,
            actions: 104,
        },
        columns: [
            { key: 'name', label: 'Name', width: 200, sortable: true, wrap: false },
            { key: 'role_label', label: 'Role', width: 110 },
            { key: 'status_label', label: 'Status', width: 120 },
            { key: 'email', label: 'Email', width: 240, wrap: false },
            { key: 'mobile', label: 'Mobile', width: 150 },
            {
                key: 'actions',
                label: 'Actions',
                width: 104,
                sortable: false,
                resizable: false,
                align: 'center',
                renderCell: ({ row }) => {
                    const wrap = document.createElement('div');
                    wrap.className = 'ui-cell-actions';

                    const editButton = iconButton('edit', `Edit ${row.name}`, { adminUserEdit: row.id });
                    const deleteButton = iconButton('delete', `Delete ${row.name}`, { adminUserDelete: row.id });

                    editButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void openUserForm(row);
                    });

                    deleteButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void deleteUser(row.id);
                    });

                    wrap.append(editButton, deleteButton);

                    return wrap;
                },
            },
        ],
        toolbarEnd: buildUsersToolbarEnd,
        selectedKeys: state.users.selectedId ? [String(state.users.selectedId)] : [],
        onRowClick: (row) => {
            state.users.selectedId = row.id;
            state.users.blockedDeleteUserId = null;
            state.users.blockedDeleteReferences = [];
            renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
            wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
        },
    });

    rememberAdminInstance(grid);
}

function mountIncidentTypesGrid(root, state) {
    const host = root.querySelector('[data-admin-incident-types-grid]');

    if (!host || !appState.helper.createGrid) {
        return;
    }

    const rows = state.incidents.items.map((item) => ({
        ...item,
        created_at_label: item.created_at ? new Date(item.created_at).toLocaleString('en-PH', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }) : 'Pending',
    }));

    const buildIncidentsToolbarEnd = buildGroupedToolbarEnd({
        addPrimaryLabel: 'Add Incident Type',
        onAddPrimary: () => openIncidentTypeForm(null),
        showAddCategory: false,
    });

    const grid = appState.helper.createGrid(host, rows, {
        chrome: true,
        className: 'admin-incident-types-grid',
        rowKey: 'id',
        selectable: 'none',
        enableSearch: true,
        enableSort: true,
        enablePagination: false,
        enableColumnResize: true,
        searchPlaceholder: 'Search name, category, or description',
        minColumnWidth: 92,
        columnWidths: {
            category_name: 180,
            name: 360,
            fields_count: 72,
            default_required_resources_count: 72,
            actions: 148,
        },
        columns: [
            { key: 'category_name', label: 'Category', width: 180, sortable: true, wrap: false },
            {
                key: 'name',
                label: 'Name',
                width: 360,
                sortable: true,
                wrap: false,
                renderCell: ({ row }) => {
                    return createStackedGridCell(
                        row.name,
                        String(row.description ?? '').trim() || 'No description',
                    );
                },
            },
            { key: 'fields_count', label: 'Flds', width: 72, sortable: true, wrap: false, align: 'center' },
            { key: 'default_required_resources_count', label: 'Res', width: 72, sortable: true, wrap: false, align: 'center' },
            {
                key: 'actions',
                label: 'Actions',
                width: 148,
                sortable: false,
                resizable: false,
                align: 'center',
                renderCell: ({ row }) => {
                    const wrap = document.createElement('div');
                    wrap.className = 'ui-cell-actions';

                    const editButton = iconButton('edit', `Edit ${row.name}`, { adminIncidentTypeEdit: row.id });
                    const setupButton = iconButton('inventory', `Manage setup for ${row.name}`, { adminIncidentTypeSetup: row.id });
                    const deleteButton = iconButton('delete', `Delete ${row.name}`, { adminIncidentTypeDelete: row.id });

                    editButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void openIncidentTypeForm(row);
                    });

                    setupButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void openIncidentTypeSetupModal(row);
                    });

                    deleteButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void deleteIncidentType(row.id);
                    });

                    wrap.append(editButton, setupButton, deleteButton);

                    return wrap;
                },
            },
        ],
        toolbarEnd: buildIncidentsToolbarEnd,
        selectedKeys: state.incidents.selectedId ? [String(state.incidents.selectedId)] : [],
        onRowClick: (row) => {
            state.incidents.selectedId = row.id;
            renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
            wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
        },
    });

    rememberAdminInstance(grid);
}

function mountIncidentCategoryList(root, state) {
    mountCategoryList(root, state, {
        stateKey: 'incidents',
        categoryActionKey: 'incident',
        ariaLabel: 'Incident categories',
        getItemCategoryId: (item) => item.incident_category_id,
        formatCount: (count) => `${count} type${count === 1 ? '' : 's'}`,
    }, {
        rememberInstance: rememberAdminInstance,
        rerender: () => {
            renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
            wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
        },
    });
}

function mountResourceTypesGrid(root, state) {
    const host = root.querySelector('[data-admin-resource-types-grid]');

    if (!host || !appState.helper.createGrid) {
        return;
    }

    const rows = state.resources.items.map((item) => ({
        ...item,
        category_name: item.category?.name ?? 'Uncategorized',
    }));

    const buildResourcesToolbarEnd = buildGroupedToolbarEnd({
        addPrimaryLabel: 'Add Resource',
        onAddPrimary: () => openResourceTypeForm(null),
        showAddCategory: false,
    });

    const grid = appState.helper.createGrid(host, rows, {
        chrome: true,
        className: 'admin-resource-types-grid',
        rowKey: 'id',
        selectable: 'none',
        enableSearch: true,
        enableSort: true,
        enablePagination: false,
        enableColumnResize: true,
        searchPlaceholder: 'Search name, category, or unit label',
        minColumnWidth: 92,
        columnWidths: {
            name: 320,
            unit_label: 160,
            actions: 104,
        },
        columns: [
            {
                key: 'name',
                label: 'Name',
                width: 320,
                sortable: true,
                wrap: false,
                renderCell: ({ row }) => createStackedGridCell(
                    row.name,
                    row.category_name,
                ),
            },
            { key: 'unit_label', label: 'Unit Label', width: 160, sortable: true, wrap: false },
            {
                key: 'actions',
                label: 'Actions',
                width: 104,
                sortable: false,
                resizable: false,
                align: 'center',
                renderCell: ({ row }) => {
                    const wrap = document.createElement('div');
                    wrap.className = 'ui-cell-actions';

                    const editButton = iconButton('edit', `Edit ${row.name}`, { adminResourceTypeEdit: row.id });
                    const deleteButton = iconButton('delete', `Delete ${row.name}`, { adminResourceTypeDelete: row.id });

                    editButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void openResourceTypeForm(row);
                    });

                    deleteButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void deleteResourceType(row.id);
                    });

                    wrap.append(editButton, deleteButton);

                    return wrap;
                },
            },
        ],
        toolbarEnd: buildResourcesToolbarEnd,
        selectedKeys: state.resources.selectedId ? [String(state.resources.selectedId)] : [],
        onRowClick: (row) => {
            state.resources.selectedId = row.id;
            renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
            wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
        },
    });

    rememberAdminInstance(grid);
}

function mountResourceCategoryList(root, state) {
    mountCategoryList(root, state, {
        stateKey: 'resources',
        categoryActionKey: 'resource',
        ariaLabel: 'Resource categories',
        getItemCategoryId: (item) => item.category_id ?? item.category?.id,
        formatCount: (count) => `${count} type${count === 1 ? '' : 's'}`,
    }, {
        rememberInstance: rememberAdminInstance,
        rerender: () => {
            renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
            wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
        },
    });
}

function mountTeamCategoryList(root, state) {
    mountCategoryList(root, state, {
        stateKey: 'teams',
        categoryActionKey: 'team',
        ariaLabel: 'Team categories',
        getItemCategoryId: (item) => item.team_category_id,
        formatCount: (count) => `${count} team${count === 1 ? '' : 's'}`,
    }, {
        rememberInstance: rememberAdminInstance,
        rerender: () => {
            renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
            wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
        },
    });
}

function mountTeamsGrid(root, state) {
    const host = root.querySelector('[data-admin-teams-grid]');

    if (!host || !appState.helper.createGrid) {
        return;
    }

    const rows = state.teams.items.map((item) => ({
        ...item,
        status_label: formatStatusLabel(item.status),
        inventory_count_label: Number(item.inventory_count ?? 0),
    }));

    const buildTeamsToolbarEnd = buildGroupedToolbarEnd({
        addPrimaryLabel: 'Add Team',
        onAddPrimary: () => openTeamForm(null),
        showAddCategory: false,
    });

    const grid = appState.helper.createGrid(host, rows, {
        chrome: true,
        className: 'admin-teams-grid',
        rowKey: 'id',
        selectable: 'none',
        enableSearch: true,
        enableSort: true,
        enablePagination: false,
        enableColumnResize: true,
        searchPlaceholder: 'Search team, category, or status',
        minColumnWidth: 92,
        columnWidths: {
            name: 320,
            status_label: 140,
            inventory_count_label: 84,
            actions: 148,
        },
        columns: [
            {
                key: 'name',
                label: 'Name',
                width: 320,
                sortable: true,
                wrap: false,
                renderCell: ({ row }) => createStackedGridCell(
                    row.name,
                    row.category_name,
                ),
            },
            { key: 'status_label', label: 'Status', width: 140, sortable: true, wrap: false },
            { key: 'inventory_count_label', label: 'Res', width: 84, sortable: true, wrap: false, align: 'center' },
            {
                key: 'actions',
                label: 'Actions',
                width: 148,
                sortable: false,
                resizable: false,
                align: 'center',
                renderCell: ({ row }) => {
                    const wrap = document.createElement('div');
                    wrap.className = 'ui-cell-actions';

                    const editButton = iconButton('edit', `Edit ${row.name}`, { adminTeamEdit: row.id });
                    const inventoryButton = iconButton('inventory', `Manage inventory for ${row.name}`, { adminTeamInventory: row.id });
                    const deleteButton = iconButton('delete', `Delete ${row.name}`, { adminTeamDelete: row.id });

                    editButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void openTeamForm(row);
                    });

                    inventoryButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void openTeamInventoryModal(row);
                    });

                    deleteButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        void deleteTeam(row.id);
                    });

                    wrap.append(editButton, inventoryButton, deleteButton);

                    return wrap;
                },
            },
        ],
        toolbarEnd: buildTeamsToolbarEnd,
        selectedKeys: state.teams.selectedId ? [String(state.teams.selectedId)] : [],
        onRowClick: (row) => {
            state.teams.selectedId = row.id;
            renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
            wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
        },
    });

    rememberAdminInstance(grid);
}

function mountSettingsEditor(root, state) {
    const host = root.querySelector('[data-admin-settings-editor]');

    if (!host || !appState.helper.createPropertyEditor) {
        return;
    }

    const editor = appState.helper.createPropertyEditor(host, settingsEditorData(state), {
        labelWidth: '15rem',
        onPropertyChange: ({ propertyId, value }) => {
            state.settings.draft[propertyId] = value;
        },
    });

    rememberAdminInstance(editor);
}

async function ensureSettingsLoaded() {
    const state = adminRuntime.state;

    if (!state) {
        return;
    }

    if (state.settings.loaded) {
        return;
    }

    await hydrateAdminState(state, { forceSettings: true });
}

async function saveSettings() {
    const state = adminRuntime.state;
    const items = Object.entries(state.settings.draft).map(([key, value]) => ({ key, value }));

    try {
        const payload = await fetchJson('/api/admin/settings', {
            method: 'post',
            data: { items },
        });
        const publishMeta = payload?.meta?.realtime_publish ?? null;

        state.settings.items = Array.isArray(payload?.items) ? payload.items : [];
        state.settings.draft = Object.fromEntries(state.settings.items.map((item) => [item.key, item.value]));
        const nextSettings = { ...(adminRuntime.bootstrap?.settings ?? appState.bootstrap?.settings ?? {}) };
        const nextAlertLevel = String(state.settings.draft.alert_level ?? adminRuntime.bootstrap?.alert_level ?? appState.bootstrap?.alert_level ?? 'Normal');

        nextSettings.alert_level = nextAlertLevel;
        nextSettings.call_hold_seconds = Number(state.settings.draft.call_hold_seconds ?? nextSettings.call_hold_seconds ?? 0);
        nextSettings.call_timeout_seconds = Number(state.settings.draft.call_timeout_seconds ?? nextSettings.call_timeout_seconds ?? 0);
        nextSettings.reconnect_timeout_seconds = Number(state.settings.draft.reconnect_timeout_seconds ?? nextSettings.reconnect_timeout_seconds ?? 0);
        nextSettings.audio_graph_style = String(state.settings.draft.audio_graph_style ?? nextSettings.audio_graph_style ?? 'vu').trim() || 'vu';

        const nextBootstrap = {
            ...(adminRuntime.bootstrap ?? appState.bootstrap ?? {}),
            alert_level: nextAlertLevel,
            alert_level_description: describeAlertLevel(nextAlertLevel),
            settings: nextSettings,
        };

        appState.bootstrap = nextBootstrap;
        adminRuntime.bootstrap = nextBootstrap;

        if (state.summary) {
            state.summary = {
                ...state.summary,
                alert_level: nextAlertLevel,
            };
        }

        renderAdmin(adminRuntime.root, nextBootstrap, state);
        wireAdmin(adminRuntime.root, nextBootstrap, state);

        if (publishMeta?.status === 'accepted') {
            showToast('Settings saved. Live alert broadcast queued.', 'success');
        } else if (publishMeta?.status === 'rejected' || publishMeta?.status === 'skipped') {
            showToast(`Settings saved. ${publishMeta?.message ?? 'Realtime alert broadcast did not complete.'}`, 'warn');
        } else {
            showToast('Settings saved.', 'success');
        }

        return true;
    } catch (error) {
        showToast(error.response?.data?.message ?? 'Unable to save settings.');
        return false;
    }
}

async function openSettingsModal() {
    const state = adminRuntime.state;

    if (!state) {
        return;
    }

    await ensureHelperUi();

    adminRuntime.settingsModal?.destroy?.();

    const host = document.createElement('div');
    host.className = 'admin-settings-modal-host';
    let editor = null;
    let modalClosed = false;

    const renderSettingsLoading = () => {
        editor?.destroy?.();
        editor = null;
        host.innerHTML = '<div class="admin-loading-host" data-admin-settings-skeleton></div>';
        mountAdminSkeleton(host.querySelector('[data-admin-settings-skeleton]'), { lines: 8 }, {
            shimmer: true,
            className: 'admin-grid-skeleton',
        });
    };

    const renderSettingsEditor = () => {
        editor?.destroy?.();
        host.innerHTML = '';
        editor = appState.helper.createPropertyEditor(host, settingsEditorData(state), {
            labelWidth: '15rem',
            onPropertyChange: ({ propertyId, value }) => {
                state.settings.draft[propertyId] = value;
            },
        });

        rememberAdminInstance(editor);
    };

    const renderSettingsError = (message) => {
        editor?.destroy?.();
        editor = null;
        host.innerHTML = `
            <div class="admin-empty-state">
                <p>${escapeHtml(message)}</p>
            </div>
        `;
    };

    const modal = appState.helper.createActionModal({
        title: 'Runtime Settings',
        size: 'lg',
        content: host,
        autoBusy: true,
        actions: [
            {
                id: 'cancel',
                label: 'Cancel',
                variant: 'default',
            },
            {
                id: 'save',
                label: 'Save',
                variant: 'primary',
                busyMessage: 'Saving settings...',
                onClick: async () => {
                    if (!state.settings.loaded) {
                        showToast('Settings are still loading.', 'warn');
                        return false;
                    }

                    const saved = await saveSettings();

                    return saved ? true : false;
                },
            },
        ],
        onClose() {
            modalClosed = true;
            editor?.destroy?.();
            adminRuntime.settingsModal = null;
        },
    });

    adminRuntime.settingsModal = modal;

    if (state.settings.loaded) {
        renderSettingsEditor();
    } else {
        renderSettingsLoading();
    }

    const loadSettings = async () => {
        if (state.settings.loaded) {
            return;
        }

        try {
            await ensureSettingsLoaded();

            if (modalClosed) {
                return;
            }

            renderSettingsEditor();
        } catch (error) {
            if (modalClosed) {
                return;
            }

            renderSettingsError('Unable to load runtime settings right now.');
        }
    };

    const openPromise = modal.open();
    void loadSettings();
    await openPromise;
}

async function hydrateAdminState(state, options = {}) {
    const tasks = [];

    if (options.forceSummary || options.refreshSummary || (state.module === 'overview' && !state.summary)) {
        tasks.push(fetchJson('/api/admin/summary').then((payload) => {
            state.summary = payload;
        }));
    }

    if ((state.module === 'users' || options.forceUsers) && (!state.users.loaded || options.refreshUsers)) {
        tasks.push(fetchJson('/api/admin/users').then((payload) => {
            state.users.items = Array.isArray(payload?.items) ? payload.items : [];
            state.users.loaded = true;
            if (!state.users.items.some((item) => Number(item.id) === Number(state.users.selectedId))) {
                state.users.selectedId = state.users.items[0]?.id ?? null;
            }
        }));
    }

    if ((state.module === 'incidents' || options.forceIncidents) && (!state.incidents.loaded || options.refreshIncidents)) {
        tasks.push(Promise.all([
            fetchJson('/api/admin/incident-types'),
            fetchJson('/api/admin/incident-categories'),
        ]).then(([incidentTypesPayload, incidentCategoriesPayload]) => {
            state.incidents.items = Array.isArray(incidentTypesPayload?.items) ? incidentTypesPayload.items : [];
            state.incidents.categories = Array.isArray(incidentCategoriesPayload?.items) ? incidentCategoriesPayload.items : [];
            state.incidents.loaded = true;
            if (!state.incidents.items.some((item) => Number(item.id) === Number(state.incidents.selectedId))) {
                state.incidents.selectedId = state.incidents.items[0]?.id ?? null;
            }
            if (!state.incidents.categories.some((item) => Number(item.id) === Number(state.incidents.selectedCategoryId))) {
                state.incidents.selectedCategoryId = state.incidents.categories[0]?.id ?? null;
            }
        }));
    }

    if ((state.module === 'resources' || options.forceResources) && (!state.resources.loaded || options.refreshResources)) {
        tasks.push(Promise.all([
            fetchJson('/api/admin/resource-types'),
            fetchJson('/api/admin/resource-type-categories'),
        ]).then(([resourceTypesPayload, resourceTypeCategoriesPayload]) => {
            state.resources.items = Array.isArray(resourceTypesPayload?.items) ? resourceTypesPayload.items : [];
            state.resources.categories = Array.isArray(resourceTypeCategoriesPayload?.items) ? resourceTypeCategoriesPayload.items : [];
            state.resources.loaded = true;
            if (!state.resources.items.some((item) => Number(item.id) === Number(state.resources.selectedId))) {
                state.resources.selectedId = state.resources.items[0]?.id ?? null;
            }
            if (!state.resources.categories.some((item) => Number(item.id) === Number(state.resources.selectedCategoryId))) {
                state.resources.selectedCategoryId = state.resources.categories[0]?.id ?? null;
            }
        }));
    }

    if ((state.module === 'teams' || options.forceTeams) && (!state.teams.loaded || options.refreshTeams)) {
        tasks.push(Promise.all([
            fetchJson('/api/admin/teams'),
            fetchJson('/api/admin/team-categories'),
        ]).then(([teamsPayload, teamCategoriesPayload]) => {
            state.teams.items = Array.isArray(teamsPayload?.items) ? teamsPayload.items : [];
            state.teams.categories = Array.isArray(teamCategoriesPayload?.items) ? teamCategoriesPayload.items : [];
            state.teams.loaded = true;
            if (!state.teams.items.some((item) => Number(item.id) === Number(state.teams.selectedId))) {
                state.teams.selectedId = state.teams.items[0]?.id ?? null;
            }
            if (!state.teams.categories.some((item) => Number(item.id) === Number(state.teams.selectedCategoryId))) {
                state.teams.selectedCategoryId = state.teams.categories[0]?.id ?? null;
            }
        }));
    }

    if (options.forceSettings || options.refreshSettings) {
        tasks.push(fetchJson('/api/admin/settings').then((payload) => {
            state.settings.items = Array.isArray(payload?.items) ? payload.items : [];
            state.settings.loaded = true;
            state.settings.draft = Object.fromEntries(state.settings.items.map((item) => [item.key, item.value]));
        }));
    }

    await Promise.all(tasks);
}

async function hydrateAdminStateAndRefresh(state, options = {}) {
    await hydrateAdminState(state, options);

    if (adminRuntime.state !== state) {
        return;
    }

    renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
    wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
}

async function switchModule(moduleId) {
    const state = adminRuntime.state;

    if (!state) {
        return;
    }

    state.module = moduleById(moduleId).id;
    writeModuleToUrl(state.module);

    renderAdmin(adminRuntime.root, adminRuntime.bootstrap, state);
    wireAdmin(adminRuntime.root, adminRuntime.bootstrap, state);

    await hydrateAdminStateAndRefresh(state, {
        forceSummary: state.module === 'overview',
        forceUsers: state.module === 'users',
        forceIncidents: state.module === 'incidents',
        forceTeams: state.module === 'teams',
        forceResources: state.module === 'resources',
    });
}

function wireAdmin(root, bootstrap, state) {
    root.querySelectorAll('[data-admin-open-module]').forEach((button) => {
        button.addEventListener('click', async () => {
            await switchModule(button.dataset.adminOpenModule);
        });
    });

    if (state.module === 'overview' && !state.summary) {
        mountOverviewSkeletons(root);
    }

    if (state.module === 'users') {
        if (!state.users.loaded) {
            mountAdminSkeleton(root.querySelector('[data-admin-users-skeleton]'), { rows: 6 }, {
                variant: 'grid',
                columns: 1,
                className: 'admin-grid-skeleton',
            });
            return;
        }

        mountUsersGrid(root, state);

        root.querySelectorAll('[data-admin-edit-user]').forEach((button) => {
            button.addEventListener('click', async () => {
                const user = state.users.items.find((item) => Number(item.id) === Number(button.dataset.adminEditUser));

                if (user) {
                    await openUserForm(user);
                }
            });
        });

        root.querySelectorAll('[data-admin-delete-user]').forEach((button) => {
            button.addEventListener('click', async () => {
                await deleteUser(button.dataset.adminDeleteUser);
            });
        });

    }

    if (state.module === 'incidents') {
        if (!state.incidents.loaded) {
            mountSplitModuleSkeletons(
                root,
                '[data-admin-incident-types-skeleton]',
                '[data-admin-incident-categories-skeleton]',
                '[data-admin-incident-categories-footer-skeleton]',
            );
            return;
        }

        mountIncidentTypesGrid(root, state);
        mountIncidentCategoryList(root, state);

        root.querySelectorAll('[data-admin-incident-category-add]').forEach((button) => {
            button.addEventListener('click', async () => {
                await openIncidentCategoryForm(null);
            });
        });

        root.querySelectorAll('[data-admin-incident-category-edit]').forEach((button) => {
            button.addEventListener('click', async () => {
                const category = state.incidents.categories.find((item) => Number(item.id) === Number(button.dataset.adminIncidentCategoryEdit));

                if (category) {
                    await openIncidentCategoryForm(category);
                }
            });
        });

        root.querySelectorAll('[data-admin-incident-category-delete]').forEach((button) => {
            button.addEventListener('click', async () => {
                await deleteIncidentCategory(button.dataset.adminIncidentCategoryDelete);
            });
        });
    }

    if (state.module === 'resources') {
        if (!state.resources.loaded) {
            mountSplitModuleSkeletons(
                root,
                '[data-admin-resource-types-skeleton]',
                '[data-admin-resource-categories-skeleton]',
                '[data-admin-resource-categories-footer-skeleton]',
            );
            return;
        }

        mountResourceTypesGrid(root, state);

        mountResourceCategoryList(root, state);

        root.querySelectorAll('[data-admin-resource-category-add]').forEach((button) => {
            button.addEventListener('click', async () => {
                await openResourceTypeCategoryForm(null);
            });
        });

        root.querySelectorAll('[data-admin-resource-category-edit]').forEach((button) => {
            button.addEventListener('click', async () => {
                const category = state.resources.categories.find((item) => Number(item.id) === Number(button.dataset.adminResourceCategoryEdit));

                if (category) {
                    await openResourceTypeCategoryForm(category);
                }
            });
        });

        root.querySelectorAll('[data-admin-resource-category-delete]').forEach((button) => {
            button.addEventListener('click', async () => {
                await deleteResourceTypeCategory(button.dataset.adminResourceCategoryDelete);
            });
        });
    }

    if (state.module === 'teams') {
        if (!state.teams.loaded) {
            mountSplitModuleSkeletons(
                root,
                '[data-admin-teams-skeleton]',
                '[data-admin-team-categories-skeleton]',
                '[data-admin-team-categories-footer-skeleton]',
            );
            return;
        }

        mountTeamsGrid(root, state);

        mountTeamCategoryList(root, state);

        root.querySelectorAll('[data-admin-team-category-add]').forEach((button) => {
            button.addEventListener('click', async () => {
                await openTeamCategoryForm(null);
            });
        });

        root.querySelectorAll('[data-admin-team-category-edit]').forEach((button) => {
            button.addEventListener('click', async () => {
                const category = state.teams.categories.find((item) => Number(item.id) === Number(button.dataset.adminTeamCategoryEdit));

                if (category) {
                    await openTeamCategoryForm(category);
                }
            });
        });

        root.querySelectorAll('[data-admin-team-category-delete]').forEach((button) => {
            button.addEventListener('click', async () => {
                await deleteTeamCategory(button.dataset.adminTeamCategoryDelete);
            });
        });
    }

}

export async function renderAdminSurface(root, bootstrap, options = {}) {
    adminRuntime.root = root;
    adminRuntime.bootstrap = bootstrap;

    if (options?.preserveState && adminRuntime.state) {
        const state = adminRuntime.state;
        state.module = resolveModuleFromUrl();
        renderAdmin(root, bootstrap, state);
        wireAdmin(root, bootstrap, state);
        return;
    }

    const state = {
        module: resolveModuleFromUrl(),
        summary: null,
        users: {
            loaded: false,
            items: [],
            search: '',
            selectedId: null,
            blockedDeleteUserId: null,
            blockedDeleteReferences: [],
        },
        incidents: {
            loaded: false,
            items: [],
            categories: [],
            selectedId: null,
            selectedCategoryId: null,
        },
        resources: {
            loaded: false,
            items: [],
            categories: [],
            selectedId: null,
            selectedCategoryId: null,
        },
        teams: {
            loaded: false,
            items: [],
            categories: [],
            selectedId: null,
            selectedCategoryId: null,
        },
        settings: {
            loaded: false,
            items: [],
            draft: {},
        },
    };

    adminRuntime.state = state;

    renderAdmin(root, bootstrap, state);
    wireAdmin(root, bootstrap, state);

    await hydrateAdminStateAndRefresh(state, {
        forceSummary: state.module === 'overview',
        forceUsers: state.module === 'users',
        forceIncidents: state.module === 'incidents',
        forceTeams: state.module === 'teams',
        forceResources: state.module === 'resources',
    });
}
