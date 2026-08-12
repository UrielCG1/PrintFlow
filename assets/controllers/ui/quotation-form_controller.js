import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['prototype', 'items', 'emptyState', 'itemCount'];

    connect() {
        this.nextIndex = this.getNextIndex();
        this.refreshState();
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
            this.itemsTarget.querySelectorAll('[data-ui--quotation-form-item]'),
        );
    }
}
