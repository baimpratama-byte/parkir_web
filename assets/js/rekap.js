'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const printRequested = new URLSearchParams(window.location.search).get('cetak') === '1';
    if (printRequested) {
        const url = new URL(window.location.href);
        url.searchParams.delete('cetak');
        window.history.replaceState({}, '', url);
        window.requestAnimationFrame(() => window.print());
    }

    const form = document.querySelector('.rekap-filter');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    const start = form.querySelector('#mulai');
    const end = form.querySelector('#akhir');
    if (!(start instanceof HTMLInputElement) || !(end instanceof HTMLInputElement)) {
        return;
    }
    start.addEventListener('change', () => {
        end.min = start.value;
    });
    end.addEventListener('change', () => {
        if (start.value) {
            end.min = start.value;
        }
    });
});
