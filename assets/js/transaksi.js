'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const platInput = document.querySelector('#plat-nomor');
    const extraFields = document.querySelector('#vehicle-extra-fields');
    const typeInput = document.querySelector('#jenis-kendaraan');
    const colorInput = document.querySelector('#warna');
    const ownerInput = document.querySelector('#pemilik');
    const lookupStatus = document.querySelector('#plat-status');
    let lookupTimer = 0;
    let lookupController = null;
    let lastLookupPlate = '';

    const resetVehicleFields = () => {
        if (
            !(extraFields instanceof HTMLElement)
            || !(typeInput instanceof HTMLSelectElement)
            || !(colorInput instanceof HTMLInputElement)
            || !(ownerInput instanceof HTMLInputElement)
        ) {
            return;
        }

        extraFields.hidden = false;
        typeInput.disabled = false;
        colorInput.readOnly = false;
        ownerInput.readOnly = false;
        typeInput.value = '';
        colorInput.value = '';
        ownerInput.value = '';
        typeInput.required = true;
        colorInput.required = true;
        ownerInput.required = true;
        lastLookupPlate = '';
    };

    if (
        platInput instanceof HTMLInputElement
        && extraFields instanceof HTMLElement
        && typeInput instanceof HTMLSelectElement
        && colorInput instanceof HTMLInputElement
        && ownerInput instanceof HTMLInputElement
    ) {
        platInput.addEventListener('input', () => {
            const start = platInput.selectionStart;
            const end = platInput.selectionEnd;
            const normalized = platInput.value.trim().replace(/\s+/g, ' ').toUpperCase();
            if (platInput.value !== normalized) {
                platInput.value = normalized;
                if (start !== null && end !== null) {
                    platInput.setSelectionRange(start, end);
                }
            }

            window.clearTimeout(lookupTimer);
            if (lookupController) {
                lookupController.abort();
            }
            resetVehicleFields();
            const plate = platInput.value.trim();
            if (plate.length < 3) {
                if (lookupStatus instanceof HTMLElement) {
                    lookupStatus.textContent = 'Ketik plat nomor untuk mencari data kendaraan.';
                }
                return;
            }

            lookupTimer = window.setTimeout(async () => {
                lookupController = new AbortController();
                if (lookupStatus instanceof HTMLElement) {
                    lookupStatus.textContent = 'Mencari data kendaraan...';
                }
                try {
                    const url = new URL(platInput.form?.action || window.location.href);
                    url.searchParams.set('aksi', 'cari_kendaraan');
                    url.searchParams.set('plat', plate);
                    const response = await fetch(url, {
                        headers: { Accept: 'application/json' },
                        signal: lookupController.signal,
                    });
                    if (!response.ok) {
                        throw new Error('Data kendaraan tidak dapat dicari.');
                    }
                    const result = await response.json();
                    if (platInput.value.trim() !== plate) {
                        return;
                    }

                    lastLookupPlate = plate;
                    if (result.terdaftar === true) {
                        typeInput.value = result.kendaraan.jenis_kendaraan;
                        colorInput.value = result.kendaraan.warna;
                        ownerInput.value = result.kendaraan.pemilik;
                        extraFields.hidden = false;
                        typeInput.disabled = true;
                        colorInput.readOnly = true;
                        ownerInput.readOnly = true;
                        typeInput.required = false;
                        colorInput.required = false;
                        ownerInput.required = false;
                        if (lookupStatus instanceof HTMLElement) {
                            lookupStatus.textContent = 'Kendaraan terdaftar. Jenis dan warna sudah diisi.';
                        }
                    } else {
                        extraFields.hidden = false;
                        if (lookupStatus instanceof HTMLElement) {
                            lookupStatus.textContent = 'Kendaraan baru. Lengkapi jenis, warna, dan nama pemilik.';
                        }
                    }
                } catch (error) {
                    if (error instanceof DOMException && error.name === 'AbortError') {
                        return;
                    }
                    if (lookupStatus instanceof HTMLElement) {
                        lookupStatus.textContent = error instanceof Error
                            ? error.message
                            : 'Data kendaraan tidak dapat dicari.';
                    }
                }
            }, 250);
        });

        platInput.form?.addEventListener('submit', (event) => {
            if (lastLookupPlate !== platInput.value.trim() && platInput.value.trim().length >= 3) {
                event.preventDefault();
                if (lookupStatus instanceof HTMLElement) {
                    lookupStatus.textContent = 'Tunggu sampai pencarian plat selesai.';
                }
            }
        });
    }

    const formatDuration = (minutes) => {
        const hours = Math.floor(minutes / 60);
        const remainingMinutes = minutes % 60;
        if (hours === 0) {
            return `${remainingMinutes} menit`;
        }
        if (remainingMinutes === 0) {
            return `${hours} jam`;
        }
        return `${hours} jam ${remainingMinutes} menit`;
    };
    const formatMoney = (amount) => new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(amount);
    const getMinutesParked = (entryValue) => {
        const entryDate = new Date(entryValue);
        return Number.isNaN(entryDate.getTime())
            ? 0
            : Math.max(0, Math.floor((Date.now() - entryDate.getTime()) / 60000));
    };

    const updateDurations = () => {
        document.querySelectorAll('[data-parking-duration]').forEach((element) => {
            if (!(element instanceof HTMLElement) || !element.dataset.startTime) {
                return;
            }
            element.textContent = formatDuration(getMinutesParked(element.dataset.startTime));
        });
    };
    updateDurations();
    window.setInterval(updateDurations, 30000);

    const dialog = document.querySelector('#checkout-confirmation');
    const cancelButton = dialog?.querySelector('[data-checkout-cancel]');
    const acceptButton = dialog?.querySelector('[data-checkout-accept]');
    const plateOutput = dialog?.querySelector('[data-checkout-plate]');
    const entryOutput = dialog?.querySelector('[data-checkout-entry]');
    const durationOutput = dialog?.querySelector('[data-checkout-duration]');
    const rateOutput = dialog?.querySelector('[data-checkout-rate]');
    const totalOutput = dialog?.querySelector('[data-checkout-total]');

    if (
        !(dialog instanceof HTMLDialogElement)
        || !(cancelButton instanceof HTMLButtonElement)
        || !(acceptButton instanceof HTMLButtonElement)
        || !(plateOutput instanceof HTMLElement)
        || !(entryOutput instanceof HTMLElement)
        || !(durationOutput instanceof HTMLElement)
        || !(rateOutput instanceof HTMLElement)
        || !(totalOutput instanceof HTMLElement)
    ) {
        return;
    }

    let pendingForm = null;
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-confirm-checkout]')) {
            return;
        }
        if (form.dataset.confirmed === 'true') {
            delete form.dataset.confirmed;
            return;
        }

        event.preventDefault();
        pendingForm = form;
        const entryValue = form.dataset.waktuMasuk || '';
        const rate = Number(form.dataset.tarif || 0);
        const minutes = getMinutesParked(entryValue);
        const chargedHours = Math.max(1, Math.ceil(minutes / 60));
        const entryDate = new Date(entryValue);
        plateOutput.textContent = form.dataset.plat || '';
        entryOutput.textContent = Number.isNaN(entryDate.getTime())
            ? entryValue
            : new Intl.DateTimeFormat('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }).format(entryDate);
        durationOutput.textContent = formatDuration(minutes);
        rateOutput.textContent = `${formatMoney(rate)} / jam`;
        totalOutput.textContent = formatMoney(chargedHours * rate);
        dialog.showModal();
        cancelButton.focus();
    });

    cancelButton.addEventListener('click', () => {
        dialog.close();
        pendingForm = null;
    });

    acceptButton.addEventListener('click', () => {
        const form = pendingForm;
        pendingForm = null;
        dialog.close();
        if (!form) {
            return;
        }
        form.dataset.confirmed = 'true';
        form.requestSubmit();
    });

    dialog.addEventListener('close', () => {
        pendingForm = null;
    });
});
