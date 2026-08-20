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
        'clientContextStatus',
        'clientContextStatusText',
        'clientContextRetry',
        'submitButton',
        'submitStatus',
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
        this.focusFirstError();
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
        this.refreshWorkflow();

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
        this.renderCommercialSelectionPreviews();
    }

    changeFiscalAddress() {
        this.fiscalAddressIdTarget.value = this.fiscalAddressTarget.value;
        this.renderCommercialSelectionPreviews();
    }

    changeDeliveryAddress() {
        this.deliveryAddressIdTarget.value = this.deliveryAddressTarget.value;
        this.renderCommercialSelectionPreviews();
    }

    addItem(event) {
        event.preventDefault();

        const template = this.prototypeTarget.innerHTML.replace(
            /__name__/g,
            String(this.nextIndex),
        );
        const holder = document.createElement('div');
        holder.innerHTML = template.trim();

        const item = holder.firstElementChild;
        if (!(item instanceof HTMLElement)) {
            return;
        }

        this.itemsTarget.append(item);
        this.nextIndex += 1;

        this.refreshState();
        this.filterProductsForCategory(item);
        this.configureItemSpecifications(item, { loadCharacteristics: true });

        window.requestAnimationFrame(() => {
            item.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            item.querySelector('select:not(:disabled), input:not(:disabled), textarea:not(:disabled)')?.focus();
        });
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
        this.refreshItemIdentity(item);
        this.refreshWorkflow();
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

        this.refreshItemIdentity(item);
        this.refreshWorkflow();
        await this.configureItemSpecifications(item, { loadCharacteristics: true });
    }

    changeLargeFormatDimension(event) {
        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (item) {
            this.syncLargeFormatDimensionMirrors(item);
            this.updateCalculatedArea(item);
            this.schedulePricePreview(item);
            this.refreshWorkflow();
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
        this.refreshWorkflow();
    }

    async removeItem(event) {
        event.preventDefault();

        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (!item) {
            return;
        }

        if (this.itemHasUserData(item)) {
            const confirmed = await this.confirmItemRemoval(item);
            if (!confirmed) {
                return;
            }
        }

        const nextFocus = item.nextElementSibling?.querySelector(
            'select:not(:disabled), input:not(:disabled), textarea:not(:disabled)',
        ) ?? item.previousElementSibling?.querySelector(
            'select:not(:disabled), input:not(:disabled), textarea:not(:disabled)',
        );

        item.remove();
        this.refreshState();

        if (nextFocus instanceof HTMLElement) {
            window.requestAnimationFrame(() => nextFocus.focus());
        }
    }

    refreshState() {
        this.itemElements.forEach((item, index) => {
            const lineNumberValue = index + 1;
            const lineNumbers = item.querySelectorAll(
                '[data-ui--quotation-form-line-number]',
            );
            const removeButton = item.querySelector(
                '[data-action="ui--quotation-form#removeItem"]',
            );

            lineNumbers.forEach((lineNumber) => {
                lineNumber.textContent = String(lineNumberValue);
            });

            if (removeButton) {
                removeButton.setAttribute(
                    'aria-label',
                    `Eliminar partida ${lineNumberValue}`,
                );
            }

            this.refreshItemIdentity(item);
        });

        const totalItems = this.itemElements.length;

        this.emptyStateTarget.classList.toggle(
            'd-none',
            totalItems > 0,
        );

        const countLabel = totalItems === 0
            ? 'Sin partidas'
            : `${totalItems} ${totalItems === 1 ? 'partida' : 'partidas'}`;

        this.itemCountTargets.forEach((target) => {
            target.textContent = countLabel;
        });

        this.refreshWorkflow();
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

        this.updateBillingHelp(item);
        this.refreshItemIdentity(item);
        this.refreshWorkflow();
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

        const hasCategory = category.value !== '';

        Array.from(product.options).forEach((option, index) => {
            const belongsToCategory = option.value === ''
                || option.dataset.quotationCategoryId === category.value;

            option.hidden = !belongsToCategory;
            option.disabled = !belongsToCategory;

            if (index === 0 && option.value === '') {
                option.textContent = hasCategory
                    ? 'Selecciona un producto'
                    : 'Primero selecciona una categoría';
            }
        });

        product.disabled = !hasCategory;
        product.setAttribute('aria-disabled', hasCategory ? 'false' : 'true');

        this.refreshItemIdentity(item);
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
            this.setProductContextState(item, 'idle');
            this.toggleLegacySpecificationPanel(
                item,
                this.selectedProfile(product) === 'LARGE_FORMAT',
            );

            return;
        }

        const request = ++this.productContextRequest;
        item.dataset.productContextRequest = String(request);
        this.setProductContextState(
            item,
            'loading',
            'Cargando configuración y características del Producto…',
        );

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

            let context = {};
            try {
                context = await response.json();
            } catch (error) {
                context = {};
            }

            if (
                !this.productContextActive
                || item.dataset.productContextRequest !== String(request)
                || product.value !== String(context.id ?? product.value)
            ) {
                return;
            }

            if (!response.ok) {
                throw new Error(
                    context.message
                    ?? `No se pudo recuperar el Producto (${response.status}).`,
                );
            }

            if (category?.value !== String(context.category?.id ?? '')) {
                this.renderProductCharacteristics(item, []);
                this.toggleLegacySpecificationPanel(item, false);
                this.setProductContextState(
                    item,
                    'error',
                    'El Producto ya no pertenece a la categoría seleccionada. Vuelve a seleccionarlo.',
                );

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

            if (characteristics.length > 0) {
                const visibleCount = this.visibleCharacteristicCount(item, characteristics);
                const message = visibleCount > 0
                    ? `${visibleCount} ${visibleCount === 1 ? 'característica disponible' : 'características disponibles'} para este Producto.`
                    : 'La configuración de este Producto se completa con las medidas terminadas.';

                this.setProductContextState(item, 'success', message);
            } else if (this.selectedProfile(product) === 'LARGE_FORMAT') {
                this.setProductContextState(
                    item,
                    'success',
                    'Este Producto utiliza únicamente las medidas terminadas como especificación.',
                );
            } else {
                this.setProductContextState(
                    item,
                    'empty',
                    'Este Producto no requiere características adicionales.',
                );
            }
        } catch (error) {
            if (
                this.productContextActive
                && item.dataset.productContextRequest === String(request)
            ) {
                this.renderProductCharacteristics(item, []);
                this.setProductContextState(
                    item,
                    'error',
                    error.message || 'No fue posible cargar la configuración del Producto.',
                );
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
        const dimensionCharacteristics = isLargeFormat
            ? characteristics.filter(
                (characteristic) => this.largeFormatDimensionKind(characteristic) !== null,
            )
            : [];
        const displayedCharacteristics = isLargeFormat
            ? characteristics.filter(
                (characteristic) => this.largeFormatDimensionKind(characteristic) === null,
            )
            : characteristics;

        const specificationsName = fields.dataset.specificationsName;
        if (!specificationsName) {
            return;
        }

        const dimensionMirrors = dimensionCharacteristics.map((characteristic) => {
            const dimension = this.largeFormatDimensionKind(characteristic);
            const dedicatedInput = dimension
                ? this.specificationInput(item, dimension)
                : null;
            const value = dedicatedInput?.value
                ?? values[characteristic.key]
                ?? '';

            return this.renderDimensionMirror(
                characteristic,
                specificationsName,
                dimension,
                value,
            );
        }).join('');

        const visibleFields = displayedCharacteristics.map((characteristic) => this.renderCharacteristicField(
            characteristic,
            specificationsName,
            values[characteristic.key] ?? '',
        )).join('');

        fields.innerHTML = `${dimensionMirrors}${visibleFields}`;
        panel.classList.toggle('d-none', displayedCharacteristics.length === 0);
        this.syncLargeFormatDimensionMirrors(item);
    }

    renderCharacteristicField(characteristic, specificationsName, value) {
        const fieldName = `${specificationsName}[${characteristic.key}]`;
        const fieldId = `quotation-characteristic-${fieldName.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
        const label = `${this.escapeHtml(characteristic.name)}${characteristic.unitLabel ? ` (${this.escapeHtml(characteristic.unitLabel)})` : ''}${characteristic.required ? ' <span class="text-danger">*</span>' : ''}`;
        const sharedAttributes = `id="${fieldId}" name="${this.escapeHtml(fieldName)}" data-ui--quotation-form-specification="${this.escapeHtml(characteristic.key)}"${characteristic.required ? ' required' : ''}`;
        const escapedValue = this.escapeHtml(value);
        let input;

        switch (characteristic.inputType) {
            case 'SELECT':
                input = `<select class="form-select" ${sharedAttributes}><option value="">Selecciona una opción</option>${(characteristic.options ?? []).map((option) => `<option value="${this.escapeHtml(option.value)}"${option.value === value ? ' selected' : ''}>${this.escapeHtml(option.label)}</option>`).join('')}</select>`;
                break;
            case 'DECIMAL':
                input = `<input class="form-control" ${sharedAttributes} type="text" value="${escapedValue}" inputmode="decimal" maxlength="15" autocomplete="off">`;
                break;
            case 'BOOLEAN':
                input = `<select class="form-select" ${sharedAttributes}><option value="">Selecciona una opción</option><option value="1"${String(value) === '1' ? ' selected' : ''}>Sí</option><option value="0"${String(value) === '0' ? ' selected' : ''}>No</option></select>`;
                break;
            default:
                input = `<input class="form-control" ${sharedAttributes} type="text" value="${escapedValue}" maxlength="255" autocomplete="off">`;
        }

        return `<div class="col-12 col-md-6 col-xl-4 pf-form-field quotation-characteristic-field"><label class="form-label" for="${fieldId}">${label}</label>${input}</div>`;
    }

    renderDimensionMirror(characteristic, specificationsName, dimension, value) {
        const fieldName = `${specificationsName}[${characteristic.key}]`;

        return `<input type="hidden" name="${this.escapeHtml(fieldName)}" value="${this.escapeHtml(value)}" data-ui--quotation-form-specification="${this.escapeHtml(characteristic.key)}" data-ui--quotation-form-dimension-mirror="${this.escapeHtml(dimension ?? '')}">`;
    }

    largeFormatDimensionKind(characteristic) {
        const code = String(characteristic?.code ?? '').trim().toUpperCase();
        const key = String(characteristic?.key ?? '').trim().toLowerCase();
        const name = String(characteristic?.name ?? '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toUpperCase();

        if (
            code === 'FINISHED_WIDTH_CM'
            || key === 'characteristic_finished_width_cm'
            || name === 'ANCHO TERMINADO'
        ) {
            return 'finished_width_cm';
        }

        if (
            code === 'FINISHED_HEIGHT_CM'
            || key === 'characteristic_finished_height_cm'
            || name === 'ALTO TERMINADO'
        ) {
            return 'finished_height_cm';
        }

        return null;
    }

    syncLargeFormatDimensionMirrors(item) {
        item.querySelectorAll('[data-ui--quotation-form-dimension-mirror]').forEach(
            (mirror) => {
                const dimension = mirror.getAttribute(
                    'data-ui--quotation-form-dimension-mirror',
                );
                const source = dimension
                    ? this.specificationInput(item, dimension)
                    : null;

                mirror.value = source?.value ?? '';
            },
        );
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
                { state: 'idle' },
            );

            return;
        }

        const normalizedQuantity = this.normalizedQuantity(quantity?.value ?? '');
        if (normalizedQuantity === null) {
            this.clearPricePreview(
                item,
                'Captura una cantidad mayor que cero con máximo cuatro decimales.',
                { state: 'warning' },
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
                    { invalidate: false, state: 'error' },
                );
            }
        }
    }

    setPricePreviewLoading(item) {
        this.setPricePreviewState(item, 'loading', 'Calculando');
        this.pricePreviewField(item, 'unit-price').textContent = '…';
        this.pricePreviewField(item, 'line-subtotal').textContent = '…';
        this.pricePreviewField(item, 'price-help').textContent = 'Consultando el precio vigente en el servidor…';

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
        const usesTier = context.priceSource === 'QUANTITY_TIER';
        this.setPricePreviewState(
            item,
            'success',
            usesTier ? 'Rango aplicado' : 'Precio base',
        );
        this.pricePreviewField(item, 'price-help').textContent = usesTier
            ? `Precio por rango aplicado desde ${priceRule?.minQuantity ?? context.quantity} ${measurementUnit.code ?? ''}. El servidor lo validará nuevamente al guardar.`
            : 'Precio base configurado. El servidor lo validará nuevamente al guardar.';
    }

    clearPricePreview(
        item,
        help = 'Selecciona un Producto y captura una cantidad válida para calcular.',
        { invalidate = true, state = 'idle' } = {},
    ) {
        const timer = this.pricePreviewTimers?.get(item);
        if (timer) {
            window.clearTimeout(timer);
            this.pricePreviewTimers.delete(item);
        }

        if (invalidate) {
            item.dataset.pricePreviewRequest = String(++this.pricePreviewRequest);
        }

        this.setPricePreviewState(
            item,
            state,
            state === 'error'
                ? 'No disponible'
                : (state === 'warning' ? 'Revisa cantidad' : 'En espera'),
        );
        this.pricePreviewField(item, 'price-unit').textContent = '—';
        this.pricePreviewField(item, 'unit-price').textContent = '—';
        this.pricePreviewField(item, 'line-subtotal').textContent = '—';
        this.pricePreviewField(item, 'price-help').textContent = help;
    }

    setPricePreviewState(item, state, label) {
        const preview = item.querySelector(
            '[data-ui--quotation-form-price-preview]',
        );
        const status = this.pricePreviewField(item, 'price-status');

        if (preview) {
            preview.dataset.state = state;
            preview.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
        }

        if (status) {
            status.textContent = label;
        }
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
        const badge = item.querySelector(
            '[data-ui--quotation-form-quantity-mode-badge]',
        );

        if (!this.isLargeFormatAreaItem(item)) {
            if (help) {
                help.textContent = 'Las medidas se conservan como especificación; la cantidad se captura de forma independiente.';
            }
            if (badge) {
                badge.textContent = this.selectedMeasurementUnitCode(item).toUpperCase() === 'PZA'
                    ? 'Cobro por pieza'
                    : 'Captura manual';
            }

            return;
        }

        const quantityMode = this.quantityModeInput(item);
        const isManual = quantityMode?.value === 'MANUAL';

        if (help) {
            help.textContent = isManual
                ? 'Cantidad ajustada manualmente. Cambiar las medidas ya no reemplazará este valor.'
                : 'La cantidad facturable se toma del área calculada. Si escribes otra cantidad, quedará como ajuste manual.';
        }

        if (badge) {
            badge.textContent = isManual
                ? 'Ajuste manual'
                : 'Automática por área';
        }
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
            this.setClientContextState('idle');
            this.clearCommercialContext();
            this.refreshWorkflow();

            return null;
        }

        const request = ++this.clientContextRequest;
        this.clientContextTarget.classList.add('d-none');
        this.commercialContextTarget.classList.add('d-none');
        this.setCommercialContextLoading();
        this.setClientContextState(
            'loading',
            'Cargando datos comerciales del cliente…',
        );

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

            let context = {};
            try {
                context = await response.json();
            } catch (error) {
                context = {};
            }

            if (request !== this.clientContextRequest) {
                return null;
            }

            if (!response.ok) {
                throw new Error(
                    context.message
                    ?? `No se pudo recuperar el cliente (${response.status}).`,
                );
            }

            this.currentClientContext = context;
            this.renderClientContext(context);
            this.renderCommercialContext(context, { applyCommercialDefaults });
            this.setClientContextState('success');
            this.refreshWorkflow();

            if (applyClientDefault && this.discountOrigin === 'CLIENT_DEFAULT') {
                this.applyClientDefaultDiscount(context);
            }

            return context;
        } catch (error) {
            if (request === this.clientContextRequest) {
                this.currentClientContext = null;
                this.clientContextTarget.classList.add('d-none');
                this.clearCommercialContext();
                this.setClientContextState(
                    'error',
                    error.message || 'No fue posible cargar los datos comerciales del cliente.',
                );
                this.refreshWorkflow();
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
        this.commercialContactTarget.disabled = false;
        this.fiscalAddressTarget.disabled = false;
        this.deliveryAddressTarget.disabled = false;

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
        this.renderCommercialSelectionPreviews();
    }

    clearCommercialContext() {
        this.commercialContactIdTarget.value = '';
        this.fiscalAddressIdTarget.value = '';
        this.deliveryAddressIdTarget.value = '';
        this.commercialContactTarget.replaceChildren();
        this.fiscalAddressTarget.replaceChildren();
        this.deliveryAddressTarget.replaceChildren();
        this.commercialContactTarget.disabled = true;
        this.fiscalAddressTarget.disabled = true;
        this.deliveryAddressTarget.disabled = true;
        this.clearCommercialSelectionPreviews();
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
        const suffix = address.isDefault ? ' · Predeterminado' : '';

        return `${address.label}${suffix}`;
    }

    renderCommercialSelectionPreviews() {
        if (!this.currentClientContext) {
            this.clearCommercialSelectionPreviews();

            return;
        }

        this.renderCommercialSelectionPreview(
            'contact',
            this.findCommercialEntry(
                this.currentClientContext.commercialContacts,
                this.commercialContactTarget.value,
            ),
        );
        this.renderCommercialSelectionPreview(
            'fiscal',
            this.findCommercialEntry(
                this.currentClientContext.fiscalAddresses,
                this.fiscalAddressTarget.value,
            ),
        );
        this.renderCommercialSelectionPreview(
            'delivery',
            this.findCommercialEntry(
                this.currentClientContext.deliveryAddresses,
                this.deliveryAddressTarget.value,
            ),
        );
    }

    findCommercialEntry(entries, id) {
        const selectedId = String(id ?? '');

        return (Array.isArray(entries) ? entries : []).find(
            (entry) => String(entry.id) === selectedId,
        ) ?? null;
    }

    renderCommercialSelectionPreview(type, entry) {
        const container = this.element.querySelector(
            `[data-ui--quotation-form-commercial-preview="${type}"]`,
        );

        if (!container) {
            return;
        }

        if (!entry) {
            container.replaceChildren();
            container.classList.add('d-none');

            return;
        }

        const title = document.createElement('strong');
        title.className = 'quotation-commercial-selection__title';

        const meta = document.createElement('span');
        meta.className = 'quotation-commercial-selection__meta';

        if (type === 'contact') {
            title.textContent = entry.label || 'Contacto seleccionado';

            const details = [
                entry.email ? `Correo: ${entry.email}` : null,
                entry.phone ? `Teléfono: ${entry.phone}` : null,
            ].filter(Boolean);

            meta.textContent = details.length > 0
                ? details.join(' · ')
                : 'Sin correo o teléfono registrado.';
        } else {
            title.textContent = entry.recipientName
                ? `${entry.label} · ${entry.recipientName}`
                : entry.label;
            meta.textContent = entry.summary || 'Sin dirección resumida disponible.';
        }

        container.replaceChildren(title, meta);
        container.classList.remove('d-none');
    }

    clearCommercialSelectionPreviews() {
        this.element.querySelectorAll(
            '[data-ui--quotation-form-commercial-preview]',
        ).forEach((container) => {
            container.replaceChildren();
            container.classList.add('d-none');
        });
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

    async retryClientContext(event) {
        event.preventDefault();

        await this.loadSelectedClientContext({
            applyClientDefault: this.discountOrigin === 'CLIENT_DEFAULT',
            applyCommercialDefaults: false,
        });
    }

    async retryProductContext(event) {
        event.preventDefault();

        const item = event.currentTarget.closest(
            '[data-ui--quotation-form-item]',
        );

        if (!item) {
            return;
        }

        await this.loadProductCharacteristics(item);
        this.schedulePricePreview(item);
    }

    setClientContextState(state, message = '') {
        if (!this.hasClientContextStatusTarget) {
            return;
        }

        const visible = state === 'loading' || state === 'error';
        this.clientContextStatusTarget.classList.toggle('d-none', !visible);
        this.clientContextStatusTarget.dataset.state = state;

        if (this.hasClientContextStatusTextTarget) {
            this.clientContextStatusTextTarget.textContent = message;
        }

        if (this.hasClientContextRetryTarget) {
            this.clientContextRetryTarget.classList.toggle(
                'd-none',
                state !== 'error',
            );
        }

        const icon = this.clientContextStatusTarget.querySelector(
            '.quotation-async-status__icon',
        );
        if (icon) {
            icon.innerHTML = state === 'loading'
                ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>'
                : '<i class="bi bi-exclamation-circle" aria-hidden="true"></i>';
        }
    }

    setCommercialContextLoading() {
        [
            this.commercialContactTarget,
            this.fiscalAddressTarget,
            this.deliveryAddressTarget,
        ].forEach((select) => {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Cargando…';
            select.replaceChildren(option);
            select.disabled = true;
        });
    }

    setProductContextState(item, state, message = '') {
        const status = item.querySelector(
            '[data-ui--quotation-form-product-status]',
        );

        if (!status) {
            return;
        }

        if (state === 'idle') {
            status.classList.add('d-none');

            return;
        }

        status.classList.remove('d-none');
        status.dataset.state = state;

        const text = status.querySelector(
            '[data-ui--quotation-form-product-status-text]',
        );
        const icon = status.querySelector(
            '[data-ui--quotation-form-product-status-icon]',
        );
        const retry = status.querySelector(
            '[data-ui--quotation-form-product-retry]',
        );

        if (text) {
            text.textContent = message;
        }

        if (icon) {
            const iconClass = {
                loading: null,
                success: 'bi-check-circle',
                empty: 'bi-info-circle',
                error: 'bi-exclamation-circle',
            }[state];

            icon.innerHTML = state === 'loading'
                ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>'
                : `<i class="bi ${iconClass ?? 'bi-info-circle'}" aria-hidden="true"></i>`;
        }

        if (retry) {
            retry.classList.toggle('d-none', state !== 'error');
        }
    }

    visibleCharacteristicCount(item, characteristics) {
        const product = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );

        if (this.selectedProfile(product) !== 'LARGE_FORMAT') {
            return characteristics.length;
        }

        return characteristics.filter(
            (characteristic) => this.largeFormatDimensionKind(characteristic) === null,
        ).length;
    }

    refreshItemIdentity(item) {
        const category = item.querySelector(
            '[data-ui--quotation-form-commercial-category]',
        );
        const product = item.querySelector(
            '[data-ui--quotation-form-commercial-item]',
        );
        const title = item.querySelector(
            '[data-ui--quotation-form-item-title]',
        );
        const meta = item.querySelector(
            '[data-ui--quotation-form-item-meta]',
        );

        if (!title || !meta) {
            return;
        }

        const selectedProduct = product?.selectedOptions[0];
        const productName = selectedProduct?.dataset.quotationProductName ?? '';
        const productCode = selectedProduct?.dataset.quotationProductCode ?? '';
        const unitName = selectedProduct?.dataset.quotationMeasurementUnitName ?? '';
        const categoryName = category?.selectedOptions[0]?.value
            ? category.selectedOptions[0].textContent.trim()
            : '';

        if (product?.value && productName) {
            title.textContent = productName;
            meta.textContent = [
                productCode,
                categoryName,
                unitName,
            ].filter(Boolean).join(' · ');

            return;
        }

        title.textContent = category?.value
            ? 'Selecciona un Producto'
            : 'Nueva partida';
        meta.textContent = category?.value
            ? `${categoryName} · Falta elegir el Producto`
            : 'Elige una categoría para comenzar.';
    }

    refreshWorkflow() {
        const clientComplete = this.clientTarget.value !== '';
        const items = this.itemElements;
        const itemsComplete = items.length > 0 && items.every((item) => {
            const product = item.querySelector(
                '[data-ui--quotation-form-commercial-item]',
            );
            const quantity = item.querySelector(
                '[data-ui--quotation-form-quantity]',
            );

            return Boolean(product?.value)
                && this.normalizedQuantity(quantity?.value ?? '') !== null;
        });

        const currentStep = !clientComplete
            ? 'client'
            : (!itemsComplete ? 'items' : 'save');

        this.element.querySelectorAll(
            '[data-ui--quotation-form-workflow-step]',
        ).forEach((step) => {
            const name = step.getAttribute(
                'data-ui--quotation-form-workflow-step',
            );
            const complete = name === 'client'
                ? clientComplete
                : (name === 'items' ? itemsComplete : false);
            const current = name === currentStep;

            step.classList.toggle('is-complete', complete);
            step.classList.toggle('is-current', current);

            if (current) {
                step.setAttribute('aria-current', 'step');
            } else {
                step.removeAttribute('aria-current');
            }
        });
    }

    itemHasUserData(item) {
        const fields = Array.from(
            item.querySelectorAll('select, input:not([type="hidden"]), textarea'),
        );

        return fields.some((field) => field.value.trim() !== '');
    }

    async confirmItemRemoval(item) {
        const lineNumber = item.querySelector(
            '[data-ui--quotation-form-line-number]',
        )?.textContent?.trim() ?? '';
        const productName = item.querySelector(
            '[data-ui--quotation-form-item-title]',
        )?.textContent?.trim() ?? 'esta partida';

        const title = lineNumber
            ? `¿Eliminar la partida ${lineNumber}?`
            : '¿Eliminar esta partida?';
        const text = `${productName}. Se descartarán los datos capturados en esta partida.`;

        if (window.Swal) {
            const result = await window.Swal.fire({
                title,
                text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Conservar partida',
                reverseButtons: true,
                focusCancel: true,
            });

            return result.isConfirmed;
        }

        return window.confirm(`${title} ${text}`);
    }

    submitForm() {
        if (!this.element.checkValidity()) {
            return;
        }

        this.element.setAttribute('aria-busy', 'true');

        if (this.hasSubmitButtonTarget) {
            this.submitButtonTarget.disabled = true;
            this.submitButtonTarget.setAttribute('aria-disabled', 'true');

            const label = this.submitButtonTarget.querySelector(
                '[data-ui--quotation-form-submit-label]',
            );
            if (label) {
                label.textContent = 'Guardando…';
            }

            const icon = this.submitButtonTarget.querySelector('i');
            if (icon) {
                icon.className = 'spinner-border spinner-border-sm';
            }
        }

        if (this.hasSubmitStatusTarget) {
            this.submitStatusTarget.textContent = 'Validando y guardando en el servidor…';
        }
    }

    focusFirstError() {
        const summary = this.element.querySelector(
            '[data-ui--quotation-form-error-summary]',
        );

        if (summary instanceof HTMLElement) {
            window.requestAnimationFrame(() => summary.focus());
        }
    }

    formatPercent(value) {
        const percent = Number.parseFloat(String(value));

        return Number.isFinite(percent) ? percent.toFixed(2) : '0.00';
    }
}
