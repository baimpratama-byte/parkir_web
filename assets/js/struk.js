'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const printRequested = new URLSearchParams(window.location.search).get('cetak') === '1';
    if (!printRequested) {
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.delete('cetak');
    window.history.replaceState({}, '', url);
    window.requestAnimationFrame(() => window.print());
});
