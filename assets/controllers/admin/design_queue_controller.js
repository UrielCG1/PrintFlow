import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['search', 'status', 'source', 'rows', 'noResults', 'count'];

    filter() {
        const query = this.normalize(this.searchTarget.value.trim());
        const rows = this.rowsTarget.querySelectorAll('[data-design-row]');
        let visibleRows = 0;

        rows.forEach((row) => {
            const matches = (!query || this.normalize(row.dataset.search).includes(query))
                && (!this.statusTarget.value || row.dataset.status === this.statusTarget.value)
                && (!this.sourceTarget.value || row.dataset.source === this.sourceTarget.value);

            row.hidden = !matches;
            if (matches) visibleRows += 1;
        });

        this.countTarget.textContent = String(visibleRows);
        this.noResultsTarget.hidden = visibleRows > 0 || rows.length === 0;
    }

    clear() {
        this.searchTarget.value = '';
        this.statusTarget.value = '';
        this.sourceTarget.value = '';
        this.filter();
        this.searchTarget.focus();
    }

    normalize(value) {
        return (value ?? '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLocaleLowerCase();
    }
}
