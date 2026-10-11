import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['items', 'item', 'template'];

    connect() {
        const indexes = this.itemTargets.map((item) => {
            const field = item.querySelector('[name*="[options]["]');
            return Number(field?.name.match(/\[options\]\[(\d+)\]/)?.[1] ?? -1);
        });
        this.nextIndex = Math.max(-1, ...indexes) + 1;
        this.updateRows();
    }

    add() {
        const html = this.templateTarget.innerHTML
            .replaceAll('__name__', String(this.nextIndex++))
            .replaceAll('__number__', String(this.itemTargets.length + 1));
        this.itemsTarget.insertAdjacentHTML('beforeend', html);
        this.updateRows();
        this.itemTargets.at(-1)?.querySelector('input[name$="[name]"]')?.focus();
    }

    remove(event) {
        if (this.itemTargets.length <= 1) return;
        event.currentTarget.closest('[data-ui--characteristic-options-batch-target="item"]')?.remove();
        this.updateRows();
    }

    updateRows() {
        this.itemTargets.forEach((item, index) => {
            const number = String(index + 1);
            item.querySelector('[data-option-number]').textContent = number;
            item.querySelector('[data-option-title]').textContent = number;
            const remove = item.querySelector('[data-option-remove]');
            remove.hidden = this.itemTargets.length === 1;
            remove.setAttribute('aria-label', `Quitar opción ${number}`);
        });
    }
}
