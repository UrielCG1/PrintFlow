import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'prototype',
        'items',
        'emptyState',
        'itemCount',
        'client',
        'discountPercent',
        'clientContext',
        'clientBusinessName',
        'clientLegalName',
        'clientEmail',
        'clientPhone',
        'clientDefaultDiscount',
        'discountOrigin',
    ];

    static values = {
        clientContextUrl: String,
    };

    connect() {
        this.nextIndex = this.getNextIndex();
        this.discountOrigin = this.discountPercentTarget.value.trim() === ''
            ? 'CLIENT_DEFAULT'
            : 'MANUAL';
        this.clientContextRequest = 0;
        this.currentClientContext = null;

        this.refreshState();
        this.itemElements.forEach((item) => this.configureItemSpecifications(item));
        this.loadSelectedClientContext({ applyClientDefault: true });
    }

    disconnect() {
        this.clientContextRequest += 1;
    }

    async changeClient() {
        const previousDiscountOrigin = this.discountOrigin;

        const context = await this.loadSelectedClientContext({
            applyClientDefault: false,
        });

        if (!context) {
            return;
        }

        if (previousDiscountOrigin === 'MANUAL') {
            const shouldApplyClientDefault = await this.confirmDiscountReplacement(
                context,
            );

            if (!shouldApplyClientDefault) {
                this.discountOrigin = 'MANUAL';
                this.refreshDiscountOrigin();

                return;
            }
        }

        this.applyClientDefaultDiscount(context);
    }

    markDiscountAsManual() {
        if (this.discountPercentTarget.value.trim() === '') {
            if (this.currentClientContext) {
                this.applyClientDefaultDiscount(this.currentClientContext);
            }

            return;
        }

        this.discountOrigin = 'MANUAL';
        this.refreshDiscountOrigin();
    }

    addItem(event) {
        event.preventDefault();

        const item = document.createElement('article');

        item.className = 'pf-card p-4';
        item.setAttribute('data-ui--quotation-form-item', '');
        item.innerHTML = `
            <header class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 mb-4 pb-3 border-bottom">
                <div>
                    <span class="text-muted small">Partida</span>
                    <strong class="ms-1" data-ui--quotation-form-line-number></strong>
                </div>

                <button
                    type="button"
                    class="btn pf-button pf-button--danger pf-button--sm"
                    data-action="ui--quotation-form#removeItem"
                    aria-label="Eliminar partida"
                >
                    <i class="bi bi-trash3" aria-hidden="true"></i>
                    Eliminar
                </button>
            </header>

            ${this.prototypeTarget.innerHTML.replace(/__name__/g, String(this.nextIndex))}
        `;

        this.itemsTarget.append(item);
        this.nextIndex += 1;

        this.refreshState();
        this.configureItemSpecifications(item);

        item.querySelector('select, input, textarea')?.focus();
    }

    async changeCommercialItem(event) {
        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (!item) {
            return;
        }

        const previousCommercialItemId = item.dataset.previousCommercialItemId ?? '';
        const nextCommercialItemId = event.currentTarget.value;

        if (
            previousCommercialItemId !== ''
            && previousCommercialItemId !== nextCommercialItemId
            && this.hasSpecificationValues(item)
            && !this.confirmSpecificationReplacement()
        ) {
            event.currentTarget.value = previousCommercialItemId;

            return;
        }

        if (previousCommercialItemId !== nextCommercialItemId) {
            this.clearSpecifications(item);
        }

        this.configureItemSpecifications(item);
    }

    changeLargeFormatDimension(event) {
        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (item) {
            this.updateCalculatedArea(item);
        }
    }

    markQuantityAsManual(event) {
        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (!item || !this.isLargeFormatAreaItem(item)) {
            return;
        }

        const quantityMode = this.quantityModeInput(item);
        if (quantityMode) {
            quantityMode.value = 'MANUAL';
        }

        this.updateBillingHelp(item);
    }

    removeItem(event) {
        event.preventDefault();

        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (!item) {
            return;
        }

        item.remove();
        this.refreshState();
    }

    refreshState() {
        this.itemElements.forEach((item, index) => {
            const lineNumberValue = index + 1;
            const lineNumber = item.querySelector(
                '[data-ui--quotation-form-line-number]',
            );
            const removeButton = item.querySelector(
                '[data-action="ui--quotation-form#removeItem"]',
            );

            if (lineNumber) {
                lineNumber.textContent = String(lineNumberValue);
            }

            if (removeButton) {
                removeButton.setAttribute(
                    'aria-label',
                    `Eliminar partida ${lineNumberValue}`,
                );
            }
        });

        const totalItems = this.itemElements.length;

        this.emptyStateTarget.classList.toggle(
            'd-none',
            totalItems > 0,
        );

        this.itemCountTarget.textContent = totalItems === 0
            ? 'Sin partidas'
            : `${totalItems} ${totalItems === 1 ? 'partida' : 'partidas'}`;
    }

    getNextIndex() {
        const indexes = Array.from(
            this.itemsTarget.querySelectorAll('[name*="[items]"]'),
        ).map((field) => {
            const match = field.name.match(/\[items\]\[(\d+)\]/);

            return match ? Number(match[1]) : -1;
        });

        return Math.max(-1, ...indexes) + 1;
    }

    get itemElements() {
        return Array.from(
            this.itemsTarget.querySelectorAll(
                '[data-ui--quotation-form-item]',
            ),
        );
    }

    configureItemSpecifications(item) {
        const select = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );
        const panel = item.querySelector(
            '[data-ui--quotation-form-specification-panel]',
        );

        if (!select || !panel) {
            return;
        }

        const isLargeFormat = this.selectedProfile(select) === 'LARGE_FORMAT';

        panel.classList.toggle('d-none', !isLargeFormat);
        panel.querySelectorAll('[data-ui--quotation-form-specification]').forEach(
            (input) => {
                input.required = isLargeFormat;
            },
        );

        item.dataset.previousCommercialItemId = select.value;

        if (isLargeFormat) {
            this.updateCalculatedArea(item);
        }
    }

    updateCalculatedArea(item) {
        const areaTarget = item.querySelector(
            '[data-ui--quotation-form-calculated-area]',
        );
        const width = this.specificationInput(item, 'finished_width_cm');
        const height = this.specificationInput(item, 'finished_height_cm');

        if (!areaTarget || !width || !height) {
            return;
        }

        const widthValue = this.parseDecimal(width.value);
        const heightValue = this.parseDecimal(height.value);

        if (!Number.isFinite(widthValue) || !Number.isFinite(heightValue) || widthValue <= 0 || heightValue <= 0) {
            areaTarget.textContent = 'Captura ambas medidas.';
            this.updateBillingHelp(item);

            return;
        }

        const area = (widthValue * heightValue) / 10000;
        const formattedArea = area.toFixed(4);
        areaTarget.textContent = `${formattedArea} m²`;

        const quantityMode = this.quantityModeInput(item);
        const quantity = item.querySelector('[data-ui--quotation-form-quantity]');

        if (
            this.isLargeFormatAreaItem(item)
            && quantityMode
            && quantity
            && quantityMode.value !== 'MANUAL'
        ) {
            quantity.value = formattedArea;
            quantityMode.value = 'AUTO';
        }

        this.updateBillingHelp(item);
    }

    updateBillingHelp(item) {
        const help = item.querySelector(
            '[data-ui--quotation-form-billing-help]',
        );

        if (!help) {
            return;
        }

        if (!this.isLargeFormatAreaItem(item)) {
            help.textContent = 'La cantidad facturable se captura en piezas; las medidas se conservan como especificación.';

            return;
        }

        const quantityMode = this.quantityModeInput(item);
        help.textContent = quantityMode?.value === 'MANUAL'
            ? 'Cantidad facturable ajustada manualmente. Al modificar las medidas no se reemplazará.'
            : 'La cantidad facturable se toma del área calculada. Puedes modificarla manualmente si es necesario.';
    }

    clearSpecifications(item) {
        item.querySelectorAll('[data-ui--quotation-form-specification]').forEach(
            (input) => {
                input.value = '';
            },
        );

        const areaTarget = item.querySelector(
            '[data-ui--quotation-form-calculated-area]',
        );
        if (areaTarget) {
            areaTarget.textContent = 'Captura ambas medidas.';
        }

        const quantityMode = this.quantityModeInput(item);
        if (quantityMode) {
            quantityMode.value = 'AUTO';
        }
    }

    hasSpecificationValues(item) {
        return Array.from(
            item.querySelectorAll('[data-ui--quotation-form-specification]'),
        ).some((input) => input.value.trim() !== '');
    }

    confirmSpecificationReplacement() {
        return window.confirm(
            'Al cambiar el concepto se eliminarán las medidas terminadas capturadas. ¿Deseas continuar?',
        );
    }

    selectedProfile(select) {
        return select.selectedOptions[0]?.dataset.quotationProfile ?? 'NONE';
    }

    selectedMeasurementUnitCode(item) {
        const select = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );

        return select?.selectedOptions[0]?.dataset.quotationMeasurementUnitCode ?? '';
    }

    isLargeFormatAreaItem(item) {
        const select = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );

        return select
            && this.selectedProfile(select) === 'LARGE_FORMAT'
            && this.selectedMeasurementUnitCode(item).toUpperCase() === 'M2';
    }

    specificationInput(item, name) {
        return item.querySelector(
            `[data-ui--quotation-form-specification="${name}"]`,
        );
    }

    quantityModeInput(item) {
        return item.querySelector('[data-ui--quotation-form-quantity-mode]');
    }

    parseDecimal(value) {
        const normalized = String(value).trim().replace(',', '.');

        if (!/^(?:0|[1-9]\d*)(?:\.\d+)?$/.test(normalized)) {
            return Number.NaN;
        }

        return Number.parseFloat(normalized);
    }

    async loadSelectedClientContext({ applyClientDefault }) {
        const clientId = this.clientTarget.value;

        if (clientId === '') {
            this.currentClientContext = null;
            this.clientContextTarget.classList.add('d-none');

            return null;
        }

        const request = ++this.clientContextRequest;

        try {
            const response = await fetch(
                this.clientContextUrlValue.replace(
                    /0$/,
                    encodeURIComponent(clientId),
                ),
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            if (!response.ok) {
                throw new Error(
                    `No se pudo recuperar el cliente (${response.status}).`,
                );
            }

            const context = await response.json();

            if (request !== this.clientContextRequest) {
                return null;
            }

            this.currentClientContext = context;
            this.renderClientContext(context);

            if (applyClientDefault && this.discountOrigin === 'CLIENT_DEFAULT') {
                this.applyClientDefaultDiscount(context);
            }

            return context;
        } catch (error) {
            if (request === this.clientContextRequest) {
                this.currentClientContext = null;
                this.clientContextTarget.classList.add('d-none');
            }

            return null;
        }
    }

    renderClientContext(context) {
        this.clientBusinessNameTarget.textContent = context.businessName;
        this.clientLegalNameTarget.textContent = context.legalName
            ? `Razón social: ${context.legalName}`
            : 'Sin razón social registrada.';
        this.clientEmailTarget.textContent = context.email
            ? `Correo: ${context.email}`
            : 'Sin correo registrado.';
        this.clientPhoneTarget.textContent = context.phone
            ? `Teléfono: ${context.phone}`
            : 'Sin teléfono registrado.';
        this.clientDefaultDiscountTarget.textContent = `${this.formatPercent(
            context.defaultDiscountPercent,
        )}%`;

        this.clientContextTarget.classList.remove('d-none');
        this.refreshDiscountOrigin();
    }

    applyClientDefaultDiscount(context) {
        const discount = this.formatPercent(context.defaultDiscountPercent);

        this.discountPercentTarget.value = discount;
        this.discountOrigin = 'CLIENT_DEFAULT';
        this.refreshDiscountOrigin();
    }

    refreshDiscountOrigin() {
        if (!this.hasDiscountOriginTarget) {
            return;
        }

        this.discountOriginTarget.textContent = this.discountOrigin === 'MANUAL'
            ? 'Ajuste manual: se conservará al cambiar de cliente.'
            : 'Aplicado desde la condición comercial del cliente.';
    }

    async confirmDiscountReplacement(context) {
        const title = 'El descuento fue ajustado manualmente';
        const text = `El cliente seleccionado tiene un descuento predeterminado de ${this.formatPercent(context.defaultDiscountPercent)}%. ¿Deseas aplicarlo?`;

        if (window.Swal) {
            const result = await window.Swal.fire({
                title,
                text,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Aplicar descuento del cliente',
                cancelButtonText: 'Conservar ajuste manual',
                reverseButtons: true,
            });

            return result.isConfirmed;
        }

        return window.confirm(`${title}. ${text}`);
    }

    formatPercent(value) {
        const percent = Number.parseFloat(String(value));

        return Number.isFinite(percent) ? percent.toFixed(2) : '0.00';
    }
}
