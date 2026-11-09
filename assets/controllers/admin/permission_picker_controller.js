import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['controls', 'search', 'item', 'group', 'total', 'empty'];

    connect() {
        this.controlsTargets.forEach((control) => { control.hidden = false; });
        this.update();
    }

    normalize(value) {
        return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }

    filter() {
        const query = this.normalize(this.searchTarget.value);
        this.itemTargets.forEach((item) => { item.hidden = !this.normalize(item.dataset.search).includes(query); });
        this.groupTargets.forEach((group) => { group.hidden = ![...group.querySelectorAll('.pf-permission-item')].some((item) => !item.hidden); });
        this.emptyTarget.hidden = this.itemTargets.some((item) => !item.hidden);
        this.update();
    }

    update() {
        const selected = this.itemTargets.filter((item) => item.querySelector('input').checked).length;
        this.totalTarget.textContent = `${selected} de ${this.itemTargets.length} seleccionados`;
        this.groupTargets.forEach((group) => {
            const items = [...group.querySelectorAll('.pf-permission-item')];
            const count = items.filter((item) => item.querySelector('input').checked).length;
            group.querySelector('[data-count]').textContent = `${count} de ${items.length} seleccionados`;
        });
    }

    selectVisible(event) { this.setVisible(event, true); }
    clearVisible(event) { this.setVisible(event, false); }
    setVisible(event, checked) {
        event.currentTarget.closest('.pf-permission-group').querySelectorAll('.pf-permission-item').forEach((item) => {
            if (!item.hidden) item.querySelector('input').checked = checked;
        });
        this.update();
    }
}
