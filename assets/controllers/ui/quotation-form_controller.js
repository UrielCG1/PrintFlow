import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['items', 'emptyState'];

    static values = {
        prototype: String,
        index: Number,
    };

    connect() {
        this.indexValue = this.itemElements.length;
        this.refreshState();
    }

    addItem() {
        const item = document.createElement('article');

        item.className = 'quotation-item';
        item.dataset.uiQuotationFormItem = '';
        item.innerHTML = `
            <div class="quotation-item__header">
                <span class="quotation-item__number">
                    Partida
                    <strong data-ui--quotation-form-line-number></strong>
                </span>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    data-action="ui--quotation-form#removeItem"
                >
                    <i class="bi bi-trash3 me-1"></i>
                    Eliminar
                </button>
            </div>

            <div class="row g-3">
                <div class="col-12 col-lg-8">
                    ${this.prototypeValue.replace(/__name__/g, this.indexValue)}
                </div>
            </div>
        `;

        const fields = item.querySelectorAll('.form-group, .mb-3');

        if (fields.length >= 2) {
            const row = item.querySelector('.row');

            row.innerHTML = '';
            fields[0].classList.add('col-12', 'col-lg-8');
            fields[1].classList.add('col-12', 'col-lg-4');

            row.append(fields[0], fields[1]);
        }

        this.itemsTarget.append(item);
        this.indexValue += 1;

        this.refreshState();
    }

    removeItem(event) {
        const item = event.target.closest('[data-ui--quotation-form-item]');

        if (!item) {
            return;
        }

        item.remove();
        this.refreshState();
    }

    refreshState() {
        this.itemElements.forEach((item, index) => {
            const lineNumber = item.querySelector(
                '[data-ui--quotation-form-line-number]',
            );

            if (lineNumber) {
                lineNumber.textContent = String(index + 1);
            }
        });

        this.emptyStateTarget.classList.toggle(
            'd-none',
            this.itemElements.length > 0,
        );
    }

    get itemElements() {
        return Array.from(
            this.itemsTarget.querySelectorAll('[data-ui--quotation-form-item]'),
        );
    }
}