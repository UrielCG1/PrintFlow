import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['search', 'status', 'commitment', 'date', 'methodTab', 'rows', 'noResults'];

    connect() {
        this.method = 'all';
    }

    selectMethod(event) {
        this.method = event.currentTarget.dataset.method || 'all';
        this.methodTabTargets.forEach((tab) => {
            const isActive = tab === event.currentTarget;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-pressed', String(isActive));
        });
        this.filter();
    }

    filter() {
        const query = this.searchTarget.value.trim().toLocaleLowerCase();
        const rows = this.rowsTarget.querySelectorAll('[data-delivery-row]');
        let visibleRows = 0;

        rows.forEach((row) => {
            const matches = (!query || row.textContent.toLocaleLowerCase().includes(query))
                && (this.method === 'all' || row.dataset.method === this.method)
                && (!this.statusTarget.value || row.dataset.status === this.statusTarget.value)
                && (!this.commitmentTarget.value || row.dataset.commitment === this.commitmentTarget.value)
                && (!this.dateTarget.value || row.dataset.commitmentDate === this.dateTarget.value);

            row.hidden = !matches;
            if (matches) visibleRows += 1;
        });

        this.noResultsTarget.hidden = visibleRows > 0 || rows.length === 0;
    }

    clear() {
        this.searchTarget.value = '';
        this.statusTarget.value = '';
        this.commitmentTarget.value = '';
        this.dateTarget.value = '';
        this.method = 'all';
        this.methodTabTargets.forEach((tab) => {
            const isActive = tab.dataset.method === 'all';
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-pressed', String(isActive));
        });
        this.filter();
        this.searchTarget.focus();
    }
}
