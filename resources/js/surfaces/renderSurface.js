import { citizenCallStopIntent } from '../features/citizenCallStopIntent.js';
import { appState, ensureHelperUi, fetchJson, initAccountSessionSdk, openLoginModal, resetSurfaceRuntime, syncBootstrapSessionState } from './surfaceShared.js';

let surfaceRenderGeneration = 0;

const AUTH_REQUIRED_SURFACES = new Set(['public', 'citizen', 'operator', 'command', 'admin']);

export async function renderSurface(surface, options = {}) {
    const root = document.getElementById('app');

    if (!root) {
        return;
    }

    const renderGeneration = ++surfaceRenderGeneration;
    citizenCallStopIntent.beginTransition();
    const transitionGeneration = citizenCallStopIntent.scope().generation;
    resetSurfaceRuntime(surface);

    const bootstrapUrl = root.dataset.apiBootstrapUrl;
    const bootstrap = options?.bootstrap ?? await fetchJson(bootstrapUrl);
    if (renderGeneration !== surfaceRenderGeneration || citizenCallStopIntent.scope().generation !== transitionGeneration) return;
    citizenCallStopIntent.setCitizen(surface === 'citizen' && bootstrap?.authenticated ? bootstrap?.user?.id : null);
    const acceptedGeneration = citizenCallStopIntent.scope().generation;
    appState.bootstrap = bootstrap;
    appState.activeSurface = surface;
    await ensureHelperUi();
    if (renderGeneration !== surfaceRenderGeneration || citizenCallStopIntent.scope().generation !== acceptedGeneration) return;
    syncBootstrapSessionState(bootstrap);
    initAccountSessionSdk();

    if (AUTH_REQUIRED_SURFACES.has(surface) && !bootstrap?.authenticated) {
        root.replaceChildren();
        await openLoginModal({ blocking: true });
        return;
    }

    if (surface === 'public') {
        const { renderPublicSurface } = await import('./publicSurface.js');
        await renderPublicSurface(root, bootstrap, options);
        return;
    }

    if (surface === 'citizen') {
        const { renderCitizenSurface } = await import('./citizenSurface.js');
        if (renderGeneration !== surfaceRenderGeneration || citizenCallStopIntent.scope().generation !== acceptedGeneration) return;
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
