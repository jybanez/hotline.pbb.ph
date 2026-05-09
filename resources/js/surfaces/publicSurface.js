import { appState, escapeHtml, fetchJson, formatDateTime, mountSurfaceChrome, roleHome, sharedShell, trackSurfaceInstance } from './surfaceShared.js';

let publicSplitterInstance = null;
const PUBLIC_ALERT_REFRESH_MS = 15000;

async function renderPublic(root, bootstrap) {
    const publicSitrep = bootstrap.surface_payload?.sitrep ?? {};
    const latestSitrepHtml = typeof publicSitrep.latest_html === 'string' ? publicSitrep.latest_html : '';
    const archiveSitreps = Array.isArray(publicSitrep.archive) ? publicSitrep.archive : [];

    const content = `
        <div class="public-home-content">
            <div class="public-sitrep-splitter-host" data-public-sitrep-splitter>
                <section class="public-sitrep-main-pane" aria-label="Current public SITREP">
                    ${latestSitrepHtml || renderEmptySitrep()}
                </section>
                <aside class="public-sitrep-archive-pane" aria-label="Recent public SITREPs">
                    ${renderPublicSitrepArchive(archiveSitreps)}
                </aside>
            </div>
        </div>
    `;

    root.innerHTML = sharedShell({
        title: 'Public SITREP',
        kicker: 'Public',
        statusLabel: '',
        content,
        brandHref: bootstrap.authenticated ? roleHome(bootstrap.user?.role) : '/',
        showHero: false,
        shellClass: ['public-shell', publicAlertToneClass(bootstrap?.alert_level)].filter(Boolean).join(' '),
        mainClass: 'public-home-main',
    });

    mountSurfaceChrome(root, 'public', bootstrap);
    setPublicAlertLevel(root, bootstrap?.alert_level);
    schedulePublicAlertRefresh(root);
    await mountPublicSitrepSplitter(root);
}

export async function renderPublicSurface(root, bootstrap) {
    await renderPublic(root, bootstrap);
}

async function mountPublicSitrepSplitter(root) {
    const splitterHost = root.querySelector('[data-public-sitrep-splitter]');
    const mainPane = root.querySelector('.public-sitrep-main-pane');
    const archivePane = root.querySelector('.public-sitrep-archive-pane');

    if (!splitterHost || !mainPane || !archivePane) {
        return;
    }

    try {
        const createSplitter = await appState.helper.uiLoader?.get?.('ui.splitter');

        if (!createSplitter) {
            return;
        }

        publicSplitterInstance?.destroy?.();
        splitterHost.classList.add('has-helper-splitter');
        publicSplitterInstance = createSplitter(splitterHost, {
            className: 'public-sitrep-main-splitter',
            orientation: 'horizontal',
            initialRatio: 0.82,
            minRatio: 0.7,
            maxRatio: 0.88,
            paneA: mainPane,
            paneB: archivePane,
        });
        trackSurfaceInstance(publicSplitterInstance);
    } catch (_) {}
}

function publicAlertToneClass(alertLevel) {
    const normalized = String(alertLevel ?? '').trim().toLowerCase();

    if (normalized === 'elevated') {
        return 'is-alert-elevated';
    }

    if (normalized === 'critical') {
        return 'is-alert-critical';
    }

    return '';
}

function setPublicAlertLevel(root, alertLevel) {
    const shell = root?.querySelector('.public-shell');
    const toneClass = publicAlertToneClass(alertLevel);

    if (!shell) {
        return;
    }

    shell.classList.remove('is-alert-elevated', 'is-alert-critical');

    if (toneClass) {
        shell.classList.add(toneClass);
    }
}

function applyPublicAlertLevel(root, alertLevel) {
    appState.bootstrap = {
        ...(appState.bootstrap ?? {}),
        alert_level: alertLevel,
        settings: {
            ...(appState.bootstrap?.settings ?? {}),
            alert_level: alertLevel,
        },
    };

    setPublicAlertLevel(root, alertLevel);
}

function schedulePublicAlertRefresh(root) {
    if (appState.runtime.surfaceRefreshTimer) {
        window.clearTimeout(appState.runtime.surfaceRefreshTimer);
        appState.runtime.surfaceRefreshTimer = null;
    }

    appState.runtime.surfaceRefreshTimer = window.setTimeout(async () => {
        try {
            const payload = await fetchJson('/api/public/alert-level');
            const nextAlertLevel = String(payload?.alert_level ?? '').trim();

            if (nextAlertLevel && String(appState.bootstrap?.alert_level ?? '').trim() !== nextAlertLevel) {
                applyPublicAlertLevel(root, nextAlertLevel);
            }
        } catch {
            // Keep the current visual state; public alert polling retries on the next cycle.
        } finally {
            if (appState.activeSurface === 'public') {
                schedulePublicAlertRefresh(root);
            }
        }
    }, PUBLIC_ALERT_REFRESH_MS);
}

function renderEmptySitrep() {
    return `
        <article class="sitrep-document public-home-empty">
            <header class="sitrep-header">
                <div>
                    <p class="sitrep-eyebrow">PBB Hotline Periodic SITREP</p>
                    <h1>No public SITREP posted</h1>
                    <p class="sitrep-headline">Published public situation reports will appear here when available.</p>
                </div>
                <dl class="sitrep-meta">
                    <div><dt>Status</dt><dd>Monitoring</dd></div>
                    <div><dt>Visibility</dt><dd>Public only</dd></div>
                </dl>
            </header>
        </article>
    `;
}

function renderPublicSitrepArchive(items) {
    const rows = items.length ? items.map((sitrep) => `
        <a class="sitrep-home-archive-row" href="${escapeHtml(sitrep.public_url ?? '#')}">
            <span>
                <strong>${escapeHtml(sitrep.title ?? 'Public SITREP')}</strong>
                <small>${escapeHtml(sitrep.report_number ?? formatSitrepNumber(sitrep.sequence_number ?? sitrep.id))} · ${escapeHtml(formatDateTime(sitrep.generated_at))}</small>
            </span>
            <span>${escapeHtml(sitrep.alert_level ?? 'Normal')}</span>
        </a>
    `).join('') : '<p class="sitrep-empty">No older public SITREPs available.</p>';

    return `
        <section class="sitrep-home-archive" aria-label="Recent public SITREPs">
            <div class="sitrep-section-head">
                <p class="sitrep-eyebrow">Archive</p>
                <h2>Recent Public SITREPs</h2>
            </div>
            <div class="sitrep-home-archive-list">
                ${rows}
            </div>
        </section>
    `;
}

function formatSitrepNumber(value) {
    const numeric = Number.parseInt(value, 10);

    if (Number.isNaN(numeric)) {
        return '#----';
    }

    return `#${String(numeric).padStart(4, '0')}`;
}
