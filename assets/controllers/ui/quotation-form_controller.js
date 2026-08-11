import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['items', 'emptyState', 'prototype', 'itemCount'];

    static values = {
        index: Number,
    };

    connect() {
        this.indexValue = this.nextItemIndex;
        this.refreshState();
    }

    addItem() {
        if (!this.hasPrototypeTarget) {
            return;
        }

        const prototype = this.prototypeTarget.innerHTML.trim();

        if (!prototype) {
            return;
        }

        const item = document.createElement('article');

        item.className = 'pf-card p-4';

        // Debe conservar los DOS guiones para coincidir con el selector Stimulus.
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

            ${prototype.replace(/__name__/g, String(this.indexValue))}
        `;

        this.itemsTarget.append(item);
        this.indexValue += 1;

        this.refreshState();
        item.querySelector('select, input, textarea')?.focus();
    }

    removeItem(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const item = button.closest('[data-ui--quotation-form-item]');

        if (!item) {
            return;
        }

        item.remove();
        this.refreshState();
    }

    refreshState() {
        const items = this.itemElements;

        items.forEach((item, index) => {
            const lineNumber = item.querySelector(
                '[data-ui--quotation-form-line-number]',
            );

            const removeButton = item.querySelector(
                '[data-action="ui--quotation-form#removeItem"]',
            );

            if (lineNumber) {
                lineNumber.textContent = String(index + 1);
            }

            if (removeButton) {
                removeButton.setAttribute(
                    'aria-label',
                    `Eliminar partida ${index + 1}`,
                );
            }
        });

        if (this.hasEmptyStateTarget) {
            this.emptyStateTarget.classList.toggle('d-none', items.length > 0);
        }

        if (this.hasItemCountTarget) {
            this.itemCountTarget.textContent = items.length === 0
                ? 'Sin partidas'
                : `${items.length} ${items.length === 1 ? 'partida' : 'partidas'}`;
        }
    }

    get itemElements() {
        return Array.from(
            this.itemsTarget.querySelectorAll(
                '[data-ui--quotation-form-item]',
            ),
        );
    }

    get nextItemIndex() {
        return this.itemElements.reduce((nextIndex, item) => {
            const field = item.querySelector('[name*="[items]"]');
            const match = field?.name.match(/\[items\]\[(\d+)]/);

            return match
                ? Math.max(nextIndex, Number(match[1]) + 1)
                : nextIndex;
        }, 0);
    }
}