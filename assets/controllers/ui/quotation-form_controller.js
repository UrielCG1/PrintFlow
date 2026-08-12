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

        item.querySelector('select, input, textarea')?.focus();
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