

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    });
}

const isIosDevice = /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isStandaloneApp = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

if (isIosDevice && !isStandaloneApp) {
    document.querySelectorAll('[data-pwa-ios-install-help]').forEach((help) => {
        help.hidden = false;
    });
}

let deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;

    document.querySelectorAll('[data-pwa-install]').forEach((button) => {
        button.hidden = false;
        button.disabled = false;
    });
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;

    document.querySelectorAll('[data-pwa-install]').forEach((button) => {
        button.hidden = true;
    });
    document.querySelectorAll('[data-pwa-ios-install-help]').forEach((help) => {
        help.hidden = true;
    });
});

window.addEventListener('click', async (event) => {
    const installButton = event.target instanceof Element ? event.target.closest('[data-pwa-install]') : null;

    if (! installButton || ! deferredInstallPrompt) {
        return;
    }

    installButton.disabled = true;
    deferredInstallPrompt.prompt();

    await deferredInstallPrompt.userChoice;
    deferredInstallPrompt = null;
    installButton.hidden = true;
});
