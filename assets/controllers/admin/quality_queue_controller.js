import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['search', 'status', 'commitment', 'incident', 'rows', 'noResults'];

    filter() {
        const query = this.searchTarget.value.trim().toLocaleLowerCase();
        const rows = this.rowsTarget.querySelectorAll('[data-quality-row]');
        let visibleRows = 0;

        rows.forEach((row) => {
            const matches = (!query || row.textContent.toLocaleLowerCase().includes(query))
                && (!this.statusTarget.value || row.dataset.status === this.statusTarget.value)
                && (!this.commitmentTarget.value || row.dataset.commitment === this.commitmentTarget.value)
                && (!this.incidentTarget.value || row.dataset.incident === this.incidentTarget.value);

            row.hidden = !matches;
            if (matches) visibleRows += 1;
        });

        this.noResultsTarget.hidden = visibleRows > 0 || rows.length === 0;
    }

    clear() {
        this.searchTarget.value = '';
        this.statusTarget.value = '';
        this.commitmentTarget.value = '';
        this.incidentTarget.value = '';
        this.filter();
        this.searchTarget.focus();
    }
}
