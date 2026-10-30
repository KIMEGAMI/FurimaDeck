

const updateModalBodyLock = () => {
    document.body.classList.toggle('overflow-y-hidden', document.querySelector('dialog[data-modal][open]') !== null);
};

const findModal = (name) => [...document.querySelectorAll('dialog[data-modal]')]
    .find((dialog) => dialog.dataset.modal === name);

const openModal = (dialog) => {
    if (!dialog || dialog.open) {
        return;
    }

    dialog.showModal();
    updateModalBodyLock();

    if (dialog.hasAttribute('data-modal-autofocus')) {
        dialog.querySelector('input:not([type="hidden"]):not([disabled]), button:not([disabled]), [href], select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')?.focus();
    }
};

const initializeSecurityUi = () => {
    document.querySelectorAll('dialog[data-modal]').forEach((dialog) => {
        dialog.addEventListener('close', updateModalBodyLock);
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog && dialog.hasAttribute('data-modal-backdrop-close')) {
                dialog.close();
            }
        });

        if (dialog.hasAttribute('data-modal-initial-open')) {
            dialog.removeAttribute('data-modal-initial-open');
            openModal(dialog);
        }
    });

    document.querySelectorAll('[data-auto-dismiss]').forEach((element) => {
        const delay = Number(element.dataset.autoDismiss);
        if (Number.isFinite(delay) && delay > 0) {
            window.setTimeout(() => element.remove(), delay);
        }
    });

    document.querySelectorAll('[data-responsive-nav]').forEach((nav) => {
        const toggle = nav.querySelector('[data-nav-toggle]');
        const menu = nav.querySelector('[data-nav-menu]');
        const icon = nav.querySelector('[data-nav-icon]');
        const closeIcon = nav.querySelector('[data-nav-close-icon]');

        toggle?.addEventListener('click', () => {
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!isOpen));
            menu?.classList.toggle('hidden', isOpen);
            icon?.classList.toggle('hidden', !isOpen);
            closeIcon?.classList.toggle('hidden', isOpen);
            toggle.setAttribute('aria-label', isOpen ? 'メニューを開く' : 'メニューを閉じる');
        });

        nav.querySelectorAll('details').forEach((details) => {
            details.addEventListener('mouseleave', () => { details.open = false; });
            details.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    details.open = false;
                    details.querySelector('summary')?.focus();
                }
            });
        });
    });

    document.querySelectorAll('[data-dropdown]').forEach((dropdown) => {
        const toggle = dropdown.querySelector('[data-dropdown-toggle]');
        const menu = dropdown.querySelector('[data-dropdown-menu]');

        const close = () => {
            if (menu) {
                menu.hidden = true;
            }
            toggle?.setAttribute('aria-expanded', 'false');
        };

        toggle?.addEventListener('click', () => {
            const opening = menu?.hidden ?? false;
            if (menu) {
                menu.hidden = !opening;
            }
            toggle.setAttribute('aria-expanded', String(opening));
        });

        dropdown.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('[data-dropdown-menu]')) {
                close();
            }
        });

        document.addEventListener('click', (event) => {
            if (event.target instanceof Node && !dropdown.contains(event.target)) {
                close();
            }
        });

        dropdown.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                close();
                toggle?.focus();
            }
        });
    });

    document.addEventListener('click', (event) => {
        const action = event.target instanceof Element ? event.target.closest('[data-dialog-action], [data-modal-open], [data-modal-close], [data-action="print"]') : null;
        if (!action) {
            return;
        }

        if (action.dataset.action === 'print') {
            window.print();
            return;
        }

        if (action.hasAttribute('data-modal-open')) {
            openModal(findModal(action.dataset.modalOpen));
            return;
        }

        if (action.hasAttribute('data-modal-close')) {
            action.closest('dialog[data-modal]')?.close();
            return;
        }

        const dialog = document.getElementById(action.dataset.dialogTarget);
        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }

        if (action.dataset.dialogAction === 'open' && !dialog.open) {
            dialog.showModal();
        } else if (action.dataset.dialogAction === 'close' && dialog.open) {
            dialog.close();
        }
    });

    document.addEventListener('submit', (event) => {
        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        const message = submitter?.dataset.confirm ?? event.target.dataset.confirm;
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    window.addEventListener('open-modal', (event) => openModal(findModal(String(event.detail ?? ''))));
    window.addEventListener('close-modal', (event) => findModal(String(event.detail ?? ''))?.close());
    window.addEventListener('close', () => document.querySelectorAll('dialog[data-modal][open]').forEach((dialog) => dialog.close()));
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSecurityUi, { once: true });
} else {
    initializeSecurityUi();
}

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
