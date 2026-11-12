'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const menuToggle = document.querySelector('.app-menu-toggle');
    const navigation = document.querySelector('.app-nav');

    if (menuToggle instanceof HTMLButtonElement && navigation instanceof HTMLElement) {
        const mobileLayout = window.matchMedia('(max-width: 767px)');

        const updateNavigation = () => {
            const expanded = menuToggle.getAttribute('aria-expanded') === 'true';
            navigation.hidden = mobileLayout.matches && !expanded;
        };

        menuToggle.addEventListener('click', () => {
            const expanded = menuToggle.getAttribute('aria-expanded') === 'true';
            menuToggle.setAttribute('aria-expanded', String(!expanded));
            updateNavigation();
        });

        mobileLayout.addEventListener('change', () => {
            menuToggle.setAttribute('aria-expanded', 'false');
            updateNavigation();
        });

        updateNavigation();
    }

    const dialog = document.querySelector('#delete-confirmation');
    const confirmationMessage = dialog?.querySelector('[data-confirmation-message]');
    const acceptButton = dialog?.querySelector('[data-confirm-accept]');
    const cancelButton = dialog?.querySelector('[data-confirm-cancel]');

    if (
        !(dialog instanceof HTMLDialogElement)
        || !(confirmationMessage instanceof HTMLElement)
        || !(acceptButton instanceof HTMLButtonElement)
        || !(cancelButton instanceof HTMLButtonElement)
    ) {
        return;
    }

    let pendingForm = null;
    let pendingSubmitter = null;

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-confirm-delete]')) {
            return;
        }

        if (form.dataset.confirmed === 'true') {
            delete form.dataset.confirmed;
            return;
        }

        event.preventDefault();
        pendingForm = form;
        pendingSubmitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        confirmationMessage.textContent = form.dataset.confirmMessage
            || 'Data yang dihapus tidak dapat dipulihkan.';
        dialog.showModal();
        cancelButton.focus();
    });

    cancelButton.addEventListener('click', () => {
        dialog.close();
        pendingForm = null;
        pendingSubmitter = null;
    });

    acceptButton.addEventListener('click', () => {
        const form = pendingForm;
        const submitter = pendingSubmitter;
        pendingForm = null;
        pendingSubmitter = null;
        dialog.close();

        if (!form) {
            return;
        }

        form.dataset.confirmed = 'true';
        if (submitter && submitter.form === form) {
            form.requestSubmit(submitter);
            return;
        }

        form.requestSubmit();
    });

    dialog.addEventListener('close', () => {
        pendingForm = null;
        pendingSubmitter = null;
    });
});
