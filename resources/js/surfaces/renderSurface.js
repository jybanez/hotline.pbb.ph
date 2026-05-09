import { appState, ensureHelperUi, fetchJson, resetSurfaceRuntime, syncBootstrapSessionState } from './surfaceShared.js';

export async function renderSurface(surface, options = {}) {
    const root = document.getElementById('app');

    if (!root) {
        return;
    }

    resetSurfaceRuntime(surface);

    const bootstrapUrl = root.dataset.apiBootstrapUrl;
    const bootstrap = options?.bootstrap ?? await fetchJson(bootstrapUrl);
    appState.bootstrap = bootstrap;
    appState.activeSurface = surface;
    await ensureHelperUi();
    syncBootstrapSessionState(bootstrap);

    if (surface === 'public') {
        const { renderPublicSurface } = await import('./publicSurface.js');
        await renderPublicSurface(root, bootstrap, options);
        return;
    }

    if (surface === 'citizen' || surface === 'caller') {
        const { renderCitizenSurface } = await import('./citizenSurface.js');
        await renderCitizenSurface(root, bootstrap, options);
        return;
    }

    if (surface === 'operator') {
        const { renderOperatorSurface } = await import('./operatorSurface.js');
        await renderOperatorSurface(root, bootstrap, options);
        return;
    }

    if (surface === 'command') {
        const { renderCommandSurface } = await import('./commandSurface.js');
        await renderCommandSurface(root, bootstrap, options);
        return;
    }

    if (surface === 'admin') {
        const { renderAdminSurface } = await import('./adminSurface.js');
        await renderAdminSurface(root, bootstrap, options);
    }
}
