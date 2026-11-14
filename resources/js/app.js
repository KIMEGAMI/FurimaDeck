

const closeDropdown = (dropdown) => {
    const menu = dropdown.querySelector('[data-dropdown-menu]');
    const toggle = dropdown.querySelector('[data-dropdown-toggle] button');

    if (menu) {
        menu.hidden = true;
    }
    toggle?.setAttribute('aria-expanded', 'false');
};

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const mobileToggle = target?.closest('[data-mobile-nav-toggle]');

    if (mobileToggle) {
        const menu = document.getElementById('app-mobile-navigation');
        const isOpen = mobileToggle.getAttribute('aria-expanded') === 'true';

        mobileToggle.setAttribute('aria-expanded', String(! isOpen));
        mobileToggle.setAttribute('aria-label', isOpen ? 'メニューを開く' : 'メニューを閉じる');
        menu?.classList.toggle('hidden', isOpen);
        menu?.classList.toggle('block', ! isOpen);
        document.querySelector('[data-mobile-nav-open-icon]')?.classList.toggle('hidden', ! isOpen);
        document.querySelector('[data-mobile-nav-close-icon]')?.classList.toggle('hidden', isOpen);
        return;
    }

    const dropdownToggle = target?.closest('[data-dropdown-toggle]');
    if (dropdownToggle) {
        const dropdown = dropdownToggle.closest('[data-dropdown]');
        const menu = dropdown?.querySelector('[data-dropdown-menu]');
        const isOpen = menu ? ! menu.hidden : false;

        document.querySelectorAll('[data-dropdown]').forEach((item) => {
            if (item !== dropdown) {
                closeDropdown(item);
            }
        });

        if (menu) {
            menu.hidden = isOpen;
            dropdownToggle.querySelector('button')?.setAttribute('aria-expanded', String(! isOpen));
        }
        return;
    }

    const dropdownMenu = target?.closest('[data-dropdown-menu]');
    if (dropdownMenu && target?.closest('a, button')) {
        const dropdown = dropdownMenu.closest('[data-dropdown]');
        if (dropdown) {
            closeDropdown(dropdown);
        }
        return;
    }

    const openModalButton = target?.closest('[data-open-modal]');
    if (openModalButton) {
        event.preventDefault();
        showModal(openModalButton.dataset.openModal, openModalButton);
        return;
    }

    const closeModalButton = target?.closest('[data-close-modal], [data-modal-backdrop]');
    if (closeModalButton) {
        hideModal(closeModalButton.closest('[data-modal]'));
        return;
    }

    const dialogOpenButton = target?.closest('[data-dialog-open]');
    if (dialogOpenButton) {
        document.getElementById(dialogOpenButton.dataset.dialogOpen)?.showModal();
        return;
    }

    const dialogCloseButton = target?.closest('[data-dialog-close]');
    if (dialogCloseButton) {
        document.getElementById(dialogCloseButton.dataset.dialogClose)?.close();
        return;
    }

    const printButton = target?.closest('[data-print]');
    if (printButton) {
        window.print();
        return;
    }

    const logoutLink = target?.closest('[data-logout-submit]');
    if (logoutLink) {
        event.preventDefault();
        logoutLink.closest('form')?.requestSubmit();
        return;
    }

    document.querySelectorAll('[data-dropdown]').forEach((dropdown) => {
        if (! dropdown.contains(target)) {
            closeDropdown(dropdown);
        }
    });
});

const focusableElements = (container) => [...container.querySelectorAll(
    'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled]), details, [tabindex]:not([tabindex="-1"])'
)];

const modalReturnFocus = new WeakMap();

function showModal(name, trigger) {
    const modal = [...document.querySelectorAll('[data-modal]')]
        .find((item) => item.dataset.modal === name);

    if (! modal) {
        return;
    }

    modalReturnFocus.set(modal, trigger);
    modal.hidden = false;
    document.body.classList.add('overflow-y-hidden');

    window.requestAnimationFrame(() => {
        (focusableElements(modal)[0] ?? modal).focus();
    });
}

function hideModal(modal) {
    if (! modal) {
        return;
    }

    modal.hidden = true;
    document.body.classList.remove('overflow-y-hidden');
    modalReturnFocus.get(modal)?.focus();
}

document.querySelectorAll('[data-modal]:not([hidden])').forEach((modal) => {
    document.body.classList.add('overflow-y-hidden');
});

document.addEventListener('keydown', (event) => {
    const activeModal = [...document.querySelectorAll('[data-modal]:not([hidden])')].at(-1);

    if (activeModal && event.key === 'Escape') {
        event.preventDefault();
        hideModal(activeModal);
        return;
    }

    if (activeModal && event.key === 'Tab') {
        const focusable = focusableElements(activeModal);
        if (focusable.length === 0) {
            event.preventDefault();
            activeModal.focus();
        } else if (event.shiftKey && document.activeElement === focusable[0]) {
            event.preventDefault();
            focusable.at(-1)?.focus();
        } else if (! event.shiftKey && document.activeElement === focusable.at(-1)) {
            event.preventDefault();
            focusable[0].focus();
        }
    }

    if (event.key === 'Escape') {
        document.querySelectorAll('[data-dropdown]').forEach(closeDropdown);
        document.querySelectorAll('.furimadeck-app-navigation details[open]').forEach((details) => {
            details.open = false;
        });
    }
});

document.querySelectorAll('.furimadeck-app-navigation details').forEach((details) => {
    details.addEventListener('mouseleave', () => {
        details.open = false;
    });
});

document.querySelectorAll('[data-flash-dismiss]').forEach((message) => {
    window.setTimeout(() => {
        message.hidden = true;
    }, 2000);
});

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const confirmButton = target?.closest('[data-confirm]');

    if (confirmButton && ! window.confirm(confirmButton.dataset.confirm ?? '実行しますか？')) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm && ! window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
}, true);

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
