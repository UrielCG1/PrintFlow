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
        'commercialContext',
        'commercialContact',
        'fiscalAddress',
        'deliveryAddress',
        'commercialContactId',
        'fiscalAddressId',
        'deliveryAddressId',
    ];

    static values = {
        clientContextUrl: String,
        productContextUrl: String,
        pricePreviewUrl: String,
    };

    connect() {
        this.nextIndex = this.getNextIndex();
        this.discountOrigin = this.discountPercentTarget.value.trim() === ''
            ? 'CLIENT_DEFAULT'
            : 'MANUAL';
        this.clientContextRequest = 0;
        this.productContextRequest = 0;
        this.productContextActive = true;
        this.pricePreviewRequest = 0;
        this.pricePreviewActive = true;
        this.pricePreviewTimers = new WeakMap();
        this.currentClientContext = null;

        this.refreshState();
        this.itemElements.forEach((item) => {
            this.filterProductsForCategory(item);
            this.configureItemSpecifications(item, { loadCharacteristics: true });
        });
        this.loadSelectedClientContext({
            applyClientDefault: true,
            applyCommercialDefaults: false,
        });
    }

    disconnect() {
        this.clientContextRequest += 1;
        this.productContextRequest += 1;
        this.pricePreviewRequest += 1;
        this.productContextActive = false;
        this.pricePreviewActive = false;
    }

    async changeClient() {
        const previousDiscountOrigin = this.discountOrigin;

        const context = await this.loadSelectedClientContext({
            applyClientDefault: false,
        });

        if (!context) {
            return;
        }

        this.applyCommercialDefaults(context);

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

    changeCommercialContact() {
        this.commercialContactIdTarget.value = this.commercialContactTarget.value;
    }

    changeFiscalAddress() {
        this.fiscalAddressIdTarget.value = this.fiscalAddressTarget.value;
    }

    changeDeliveryAddress() {
        this.deliveryAddressIdTarget.value = this.deliveryAddressTarget.value;
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
        this.filterProductsForCategory(item);
        this.configureItemSpecifications(item, { loadCharacteristics: true });

        item.querySelector('select, input, textarea')?.focus();
    }

    async changeCommercialCategory(event) {
        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (!item) {
            return;
        }

        const category = event.currentTarget;
        const product = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );
        const previousCategoryId = item.dataset.previousCommercialCategoryId ?? '';
        const currentProductCategoryId = product?.selectedOptions[0]?.dataset.quotationCategoryId ?? '';

        if (
            previousCategoryId !== ''
            && previousCategoryId !== category.value
            && product?.value !== ''
            && currentProductCategoryId !== category.value
            && this.hasSpecificationValues(item)
            && !this.confirmSpecificationReplacement()
        ) {
            category.value = previousCategoryId;

            return;
        }

        this.filterProductsForCategory(item);

        if (product && product.value !== '' && currentProductCategoryId !== category.value) {
            product.value = '';
            this.clearSpecifications(item);
            this.clearPricePreview(item);
        }

        item.dataset.previousCommercialCategoryId = category.value;
        await this.configureItemSpecifications(item, { loadCharacteristics: true });
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
            this.clearPricePreview(item);
        }

        await this.configureItemSpecifications(item, { loadCharacteristics: true });
    }

    changeLargeFormatDimension(event) {
        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (item) {
            this.updateCalculatedArea(item);
            this.schedulePricePreview(item);
        }
    }

    markQuantityAsManual(event) {
        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (!item) {
            return;
        }

        if (this.isLargeFormatAreaItem(item)) {
            const quantityMode = this.quantityModeInput(item);
            if (quantityMode) {
                quantityMode.value = 'MANUAL';
            }

            this.updateBillingHelp(item);
        }

        this.schedulePricePreview(item);
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

    async configureItemSpecifications(item, { loadCharacteristics = false } = {}) {
        const select = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );

        if (!select) {
            return;
        }

        const isLargeFormat = this.selectedProfile(select) === 'LARGE_FORMAT';
        item.dataset.usesCommercialCharacteristics = '0';
        this.toggleLegacySpecificationPanel(item, isLargeFormat);

        item.dataset.previousCommercialItemId = select.value;

        const category = item.querySelector(
            '[data-ui--quotation-form-commercial-category]',
        );
        if (category) {
            item.dataset.previousCommercialCategoryId = category.value;
        }

        if (isLargeFormat) {
            this.updateCalculatedArea(item);
        }

        if (loadCharacteristics) {
            await this.loadProductCharacteristics(item);
        }

        this.schedulePricePreview(item);
    }

    toggleLegacySpecificationPanel(item, visible) {
        const panel = item.querySelector(
            '[data-ui--quotation-form-specification-panel]',
        );

        if (!panel) {
            return;
        }

        panel.classList.toggle('d-none', !visible);
        panel.querySelectorAll('[data-ui--quotation-form-specification]').forEach(
            (input) => {
                input.required = visible;
            },
        );
    }

    filterProductsForCategory(item) {
        const category = item.querySelector(
            '[data-ui--quotation-form-commercial-category]',
        );
        const product = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );

        if (!category || !product) {
            return;
        }

        Array.from(product.options).forEach((option) => {
            const belongsToCategory = option.value === ''
                || option.dataset.quotationCategoryId === category.value;

            option.hidden = !belongsToCategory;
            option.disabled = !belongsToCategory;
        });
    }

    async loadProductCharacteristics(item) {
        const product = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );
        const category = item.querySelector(
            '[data-ui--quotation-form-commercial-category]',
        );

        if (!product) {
            return;
        }

        if (product.value === '') {
            this.renderProductCharacteristics(item, []);
            this.toggleLegacySpecificationPanel(
                item,
                this.selectedProfile(product) === 'LARGE_FORMAT',
            );

            return;
        }

        const request = ++this.productContextRequest;
        item.dataset.productContextRequest = String(request);

        try {
            const response = await fetch(
                this.productContextUrlValue.replace(
                    /0$/,
                    encodeURIComponent(product.value),
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
                    `No se pudo recuperar el Producto (${response.status}).`,
                );
            }

            const context = await response.json();
            if (
                !this.productContextActive
                || item.dataset.productContextRequest !== String(request)
                || product.value !== String(context.id)
            ) {
                return;
            }

            if (category?.value !== String(context.category?.id ?? '')) {
                this.renderProductCharacteristics(item, []);
                this.toggleLegacySpecificationPanel(item, false);

                return;
            }

            const characteristics = Array.isArray(context.characteristics)
                ? context.characteristics
                : [];
            this.renderProductCharacteristics(item, characteristics);
            item.dataset.usesCommercialCharacteristics = characteristics.length > 0 ? '1' : '0';
            this.toggleLegacySpecificationPanel(
                item,
                this.selectedProfile(product) === 'LARGE_FORMAT',
            );
        } catch (error) {
            if (
                this.productContextActive
                && item.dataset.productContextRequest === String(request)
            ) {
                this.renderProductCharacteristics(item, []);
            }
        }
    }

    renderProductCharacteristics(item, characteristics) {
        const panel = item.querySelector(
            '[data-ui--quotation-form-characteristics-panel]',
        );
        const fields = item.querySelector(
            '[data-ui--quotation-form-characteristics-fields]',
        );

        if (!panel || !fields) {
            return;
        }

        const capturedValues = this.specificationValues(fields);
        const initialValues = this.initialSpecificationValues(fields);
        const values = { ...initialValues, ...capturedValues };

        const isLargeFormat = this.selectedProfile(
            item.querySelector('[data-ui--quotation-form-commercial-item]'),
        ) === 'LARGE_FORMAT';
        const displayedCharacteristics = isLargeFormat
            ? characteristics.filter((characteristic) => ![
                'FINISHED_WIDTH_CM',
                'FINISHED_HEIGHT_CM',
            ].includes(characteristic.code))
            : characteristics;

        if (displayedCharacteristics.length === 0) {
            fields.innerHTML = '';
            panel.classList.add('d-none');

            return;
        }

        const specificationsName = fields.dataset.specificationsName;
        if (!specificationsName) {
            return;
        }

        fields.innerHTML = displayedCharacteristics.map((characteristic) => this.renderCharacteristicField(
            characteristic,
            specificationsName,
            values[characteristic.key] ?? '',
        )).join('');
        panel.classList.remove('d-none');
    }

    renderCharacteristicField(characteristic, specificationsName, value) {
        const fieldName = `${specificationsName}[${characteristic.key}]`;
        const fieldId = `quotation-characteristic-${fieldName.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
        const label = `${this.escapeHtml(characteristic.name)}${characteristic.unitLabel ? ` (${this.escapeHtml(characteristic.unitLabel)})` : ''}${characteristic.required ? ' <span class="text-danger">*</span>' : ''}`;
        const sharedAttributes = `id="${fieldId}" class="form-control" name="${this.escapeHtml(fieldName)}" data-ui--quotation-form-specification="${this.escapeHtml(characteristic.key)}"${characteristic.required ? ' required' : ''}`;
        const escapedValue = this.escapeHtml(value);
        let input;

        switch (characteristic.inputType) {
            case 'SELECT':
                input = `<select ${sharedAttributes}><option value="">Selecciona una opción</option>${(characteristic.options ?? []).map((option) => `<option value="${this.escapeHtml(option.value)}"${option.value === value ? ' selected' : ''}>${this.escapeHtml(option.label)}</option>`).join('')}</select>`;
                break;
            case 'DECIMAL':
                input = `<input ${sharedAttributes} type="text" value="${escapedValue}" inputmode="decimal" maxlength="15" autocomplete="off">`;
                break;
            case 'BOOLEAN':
                input = `<select ${sharedAttributes}><option value="">Selecciona una opción</option><option value="1"${String(value) === '1' ? ' selected' : ''}>Sí</option><option value="0"${String(value) === '0' ? ' selected' : ''}>No</option></select>`;
                break;
            default:
                input = `<input ${sharedAttributes} type="text" value="${escapedValue}" maxlength="255" autocomplete="off">`;
        }

        return `<div class="col-12 col-md-6 pf-form-field"><label class="form-label" for="${fieldId}">${label}</label>${input}</div>`;
    }

    specificationValues(container) {
        return Array.from(
            container.querySelectorAll('[data-ui--quotation-form-specification]'),
        ).reduce((values, input) => {
            const key = input.getAttribute('data-ui--quotation-form-specification');

            return key ? { ...values, [key]: input.value } : values;
        }, {});
    }

    initialSpecificationValues(container) {
        try {
            const values = JSON.parse(container.dataset.initialSpecifications ?? '{}');

            return values && typeof values === 'object' ? values : {};
        } catch (error) {
            return {};
        }
    }

    escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    schedulePricePreview(item) {
        if (!item || !this.pricePreviewActive || !this.hasPricePreviewUrlValue) {
            return;
        }

        const currentTimer = this.pricePreviewTimers.get(item);
        if (currentTimer) {
            window.clearTimeout(currentTimer);
        }

        const timer = window.setTimeout(() => {
            this.pricePreviewTimers.delete(item);
            this.refreshPricePreview(item);
        }, 250);

        this.pricePreviewTimers.set(item, timer);
    }

    async refreshPricePreview(item) {
        if (!this.pricePreviewActive || !this.hasPricePreviewUrlValue) {
            return;
        }

        const product = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );
        const quantity = item.querySelector(
            '[data-ui--quotation-form-quantity]',
        );

        if (!product?.value) {
            this.clearPricePreview(
                item,
                'Selecciona un Producto y captura una cantidad válida para calcular.',
            );

            return;
        }

        const normalizedQuantity = this.normalizedQuantity(quantity?.value ?? '');
        if (normalizedQuantity === null) {
            this.clearPricePreview(
                item,
                'Captura una cantidad mayor que cero con máximo cuatro decimales.',
            );

            return;
        }

        const request = ++this.pricePreviewRequest;
        item.dataset.pricePreviewRequest = String(request);
        this.setPricePreviewLoading(item);

        try {
            const url = new URL(
                this.pricePreviewUrlValue.replace(
                    /0$/,
                    encodeURIComponent(product.value),
                ),
                window.location.origin,
            );
            url.searchParams.set('quantity', normalizedQuantity);

            const response = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            let context = {};
            try {
                context = await response.json();
            } catch (error) {
                context = {};
            }

            if (
                !this.pricePreviewActive
                || item.dataset.pricePreviewRequest !== String(request)
                || product.value !== String(context.itemId ?? product.value)
            ) {
                return;
            }

            if (!response.ok) {
                throw new Error(
                    context.message
                    ?? `No se pudo calcular el precio (${response.status}).`,
                );
            }

            this.renderPricePreview(item, context);
        } catch (error) {
            if (
                this.pricePreviewActive
                && item.dataset.pricePreviewRequest === String(request)
            ) {
                this.clearPricePreview(
                    item,
                    error.message || 'No se pudo calcular el precio de esta partida.',
                    { invalidate: false },
                );
            }
        }
    }

    setPricePreviewLoading(item) {
        this.pricePreviewField(item, 'unit-price').textContent = '…';
        this.pricePreviewField(item, 'line-subtotal').textContent = '…';
        this.pricePreviewField(item, 'price-help').textContent = 'Calculando precio en servidor…';

        const unit = this.pricePreviewField(item, 'price-unit');
        if (unit.textContent.trim() === '') {
            unit.textContent = '—';
        }
    }

    renderPricePreview(item, context) {
        const measurementUnit = context.measurementUnit ?? {};
        const unitLabel = measurementUnit.name
            ? `${measurementUnit.name}${measurementUnit.code ? ` (${measurementUnit.code})` : ''}`
            : (measurementUnit.code || '—');

        this.pricePreviewField(item, 'price-unit').textContent = unitLabel;
        this.pricePreviewField(item, 'unit-price').textContent = this.formatMoney(
            context.unitPrice,
        );
        this.pricePreviewField(item, 'line-subtotal').textContent = this.formatMoney(
            context.lineSubtotal,
        );

        const priceRule = context.priceRule;
        this.pricePreviewField(item, 'price-help').textContent = context.priceSource === 'QUANTITY_TIER'
            ? `Precio por rango aplicado desde ${priceRule?.minQuantity ?? context.quantity} ${measurementUnit.code ?? ''}. El servidor lo validará nuevamente al guardar.`
            : 'Precio base configurado. El servidor lo validará nuevamente al guardar.';
    }

    clearPricePreview(
        item,
        help = 'Selecciona un Producto y captura una cantidad válida para calcular.',
        { invalidate = true } = {},
    ) {
        const timer = this.pricePreviewTimers?.get(item);
        if (timer) {
            window.clearTimeout(timer);
            this.pricePreviewTimers.delete(item);
        }

        if (invalidate) {
            item.dataset.pricePreviewRequest = String(++this.pricePreviewRequest);
        }

        this.pricePreviewField(item, 'price-unit').textContent = '—';
        this.pricePreviewField(item, 'unit-price').textContent = '—';
        this.pricePreviewField(item, 'line-subtotal').textContent = '—';
        this.pricePreviewField(item, 'price-help').textContent = help;
    }

    pricePreviewField(item, name) {
        return item.querySelector(
            `[data-ui--quotation-form-${name}]`,
        );
    }

    normalizedQuantity(value) {
        const normalized = String(value).trim().replace(',', '.');

        if (normalized.match(/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/) === null) {
            return null;
        }

        if (Number.parseFloat(normalized) <= 0) {
            return null;
        }

        return normalized;
    }

    formatMoney(value) {
        const amount = Number.parseFloat(String(value));

        if (!Number.isFinite(amount)) {
            return '—';
        }

        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(amount);
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

        const characteristicFields = item.querySelector(
            '[data-ui--quotation-form-characteristics-fields]',
        );
        if (characteristicFields) {
            characteristicFields.innerHTML = '';
            characteristicFields.dataset.initialSpecifications = '{}';
        }
    }

    hasSpecificationValues(item) {
        return Array.from(
            item.querySelectorAll('[data-ui--quotation-form-specification]'),
        ).some((input) => input.value.trim() !== '');
    }

    confirmSpecificationReplacement() {
        return window.confirm(
            'Al cambiar la Categoría o el Producto se eliminarán las características capturadas. ¿Deseas continuar?',
        );
    }

    selectedProfile(select) {
        return select?.selectedOptions[0]?.dataset.quotationProfile ?? 'NONE';
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

    async loadSelectedClientContext({
        applyClientDefault,
        applyCommercialDefaults = false,
    }) {
        const clientId = this.clientTarget.value;

        if (clientId === '') {
            this.currentClientContext = null;
            this.clientContextTarget.classList.add('d-none');
            this.clearCommercialContext();

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
            this.renderCommercialContext(context, { applyCommercialDefaults });

            if (applyClientDefault && this.discountOrigin === 'CLIENT_DEFAULT') {
                this.applyClientDefaultDiscount(context);
            }

            return context;
        } catch (error) {
            if (request === this.clientContextRequest) {
                this.currentClientContext = null;
                this.clientContextTarget.classList.add('d-none');
                this.clearCommercialContext();
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

    renderCommercialContext(context, { applyCommercialDefaults }) {
        this.setCommercialSelectOptions(
            this.commercialContactTarget,
            context.commercialContacts,
            (contact) => contact.label,
        );
        this.setCommercialSelectOptions(
            this.fiscalAddressTarget,
            context.fiscalAddresses,
            (address) => this.addressOptionLabel(address),
        );
        this.setCommercialSelectOptions(
            this.deliveryAddressTarget,
            context.deliveryAddresses,
            (address) => this.addressOptionLabel(address),
        );

        this.restoreCommercialSelection(
            this.commercialContactTarget,
            this.commercialContactIdTarget,
            context.defaults?.commercialContactId,
            applyCommercialDefaults,
        );
        this.restoreCommercialSelection(
            this.fiscalAddressTarget,
            this.fiscalAddressIdTarget,
            context.defaults?.fiscalAddressId,
            applyCommercialDefaults,
        );
        this.restoreCommercialSelection(
            this.deliveryAddressTarget,
            this.deliveryAddressIdTarget,
            context.defaults?.deliveryAddressId,
            applyCommercialDefaults,
        );

        this.commercialContextTarget.classList.remove('d-none');
    }

    clearCommercialContext() {
        this.commercialContactIdTarget.value = '';
        this.fiscalAddressIdTarget.value = '';
        this.deliveryAddressIdTarget.value = '';
        this.commercialContactTarget.replaceChildren();
        this.fiscalAddressTarget.replaceChildren();
        this.deliveryAddressTarget.replaceChildren();
        this.commercialContextTarget.classList.add('d-none');
    }

    applyCommercialDefaults(context) {
        this.renderCommercialContext(context, { applyCommercialDefaults: true });
    }

    setCommercialSelectOptions(select, entries, labelFor) {
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = 'Sin seleccionar';

        const options = [emptyOption];
        (Array.isArray(entries) ? entries : []).forEach((entry) => {
            const option = document.createElement('option');
            option.value = String(entry.id);
            option.textContent = labelFor(entry);
            options.push(option);
        });

        select.replaceChildren(...options);
    }

    restoreCommercialSelection(select, input, defaultId, applyDefaults) {
        const selectedId = input.value.trim();
        const isAvailable = Array.from(select.options).some(
            (option) => option.value === selectedId,
        );
        const useDefault = applyDefaults && selectedId === '';
        const nextId = useDefault && defaultId !== null && defaultId !== undefined
            ? String(defaultId)
            : (isAvailable ? selectedId : '');

        select.value = nextId;
        input.value = nextId;
    }

    addressOptionLabel(address) {
        return address.summary
            ? `${address.label} · ${address.summary}`
            : address.label;
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
