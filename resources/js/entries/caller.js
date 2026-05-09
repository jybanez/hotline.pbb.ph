import { renderSurface } from '../surfaces/renderSurface.js';
import { appState, ensureHelperUi, fetchJson } from '../surfaces/surfaceShared.js';

let lastStandaloneSessionPingAt = 0;
let deferredInstallPrompt = null;
let installOfferOpening = false;

renderSurface('caller').then(() => {
    void pingStandaloneCallerSession();
});

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/caller-sw.js', { scope: '/' }).catch((error) => {
        console.warn('Caller service worker registration failed.', error);
    });
}

function isStandaloneCallerPwa() {
    return Boolean(
        window.matchMedia?.('(display-mode: standalone)')?.matches
        || window.navigator.standalone,
    );
}

function shouldOfferPwaInstall() {
    return !isStandaloneCallerPwa();
}

function isAndroidBrowser() {
    const ua = window.navigator.userAgent || '';

    return /Android/i.test(ua);
}

function isChromeAndroid() {
    const ua = window.navigator.userAgent || '';

    return /Android/i.test(ua)
        && /Chrome\//i.test(ua)
        && !/SamsungBrowser|EdgA|OPR\//i.test(ua);
}

async function offerCallerPwaInstall() {
    if (!shouldOfferPwaInstall() || installOfferOpening) {
        return;
    }

    installOfferOpening = true;
    const canPrompt = Boolean(deferredInstallPrompt);
    const fallbackText = isChromeAndroid()
        ? 'Chrome has not made native install available yet. Reload this page once, then use Install App again. If the browser menu only says Add to Home screen, Chrome is still treating it as a shortcut.'
        : isAndroidBrowser()
            ? 'Native PWA install is not available in this browser. Open this page in Chrome, then use Install App again.'
            : 'Native PWA install is not available in this browser.';

    try {
        const helper = await ensureHelperUi();

        if (canPrompt) {
            const accepted = await helper.uiConfirm('Install PBB Hotline on this device?', {
                title: 'Install PBB Hotline',
                description: 'Open the caller side from your home screen for faster access.',
                confirmText: 'Install',
                cancelText: 'Not Now',
                variant: 'info',
                allowBackdropClose: true,
                allowEscClose: true,
            });

            if (!accepted) {
                return;
            }

            const promptEvent = deferredInstallPrompt;
            deferredInstallPrompt = null;

            promptEvent?.prompt?.();
            await promptEvent.userChoice;
            return;
        }

        await helper.uiAlert('Install PBB Hotline from this browser.', {
            title: 'Install PBB Hotline',
            description: fallbackText,
            okText: 'Got It',
            variant: 'info',
            allowBackdropClose: true,
            allowEscClose: true,
        });
    } catch (error) {
        console.warn('Caller PWA install offer failed.', error);
    } finally {
        installOfferOpening = false;
    }
}

window.HotlineCallerPwa = {
    isStandalone: isStandaloneCallerPwa,
    offerInstall: offerCallerPwaInstall,
};

async function pingStandaloneCallerSession() {
    if (!isStandaloneCallerPwa()) {
        return;
    }

    if (!appState.bootstrap?.authenticated || appState.bootstrap?.user?.role !== 'caller') {
        return;
    }

    const now = Date.now();

    if ((now - lastStandaloneSessionPingAt) < 15000) {
        return;
    }

    lastStandaloneSessionPingAt = now;

    try {
        await fetchJson('/api/session/ping?surface=caller');
    } catch (error) {
        console.warn('Caller PWA session refresh failed.', error);
    }
}

window.addEventListener('focus', () => {
    void pingStandaloneCallerSession();
});

window.addEventListener('pageshow', () => {
    void pingStandaloneCallerSession();
});

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        void pingStandaloneCallerSession();
    }
});

window.addEventListener('beforeinstallprompt', (event) => {
    if (isStandaloneCallerPwa()) {
        return;
    }

    event.preventDefault();
    deferredInstallPrompt = event;
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
});
