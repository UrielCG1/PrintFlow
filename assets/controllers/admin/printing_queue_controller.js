import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['search', 'status', 'machine', 'commitment', 'rows', 'empty'];

    connect() {
        this.activeTab = 'all';
    }

    filter() {
        const query = this.searchTarget.value.trim().toLocaleLowerCase();
        let visibleRows = 0;

        this.rowsTarget.querySelectorAll('[data-print-row]').forEach((row) => {
            const matches = (!query || row.dataset.search.includes(query))
                && (!this.statusTarget.value || row.dataset.status === this.statusTarget.value)
                && (!this.machineTarget.value || row.dataset.machine === this.machineTarget.value)
                && (!this.commitmentTarget.value || row.dataset.commitment === this.commitmentTarget.value)
                && (this.activeTab === 'all' || row.dataset[`tab${this.activeTab[0].toUpperCase()}${this.activeTab.slice(1)}`] === '1');

            row.hidden = !matches;
            if (matches) visibleRows += 1;
        });

        this.emptyTarget.hidden = visibleRows > 0 || this.rowsTarget.querySelector('[data-print-row]') === null;
    }

    selectTab(event) {
        this.activeTab = event.currentTarget.dataset.filter;
        this.element.querySelectorAll('.printing-tabs [role="tab"]').forEach((tab) => {
            const selected = tab === event.currentTarget;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', String(selected));
        });
        this.filter();
    }

    clear() {
        this.searchTarget.value = '';
        this.statusTarget.value = '';
        this.machineTarget.value = '';
        this.commitmentTarget.value = '';
        this.activeTab = 'all';
        const allTab = this.element.querySelector('[data-filter="all"]');
        this.element.querySelectorAll('.printing-tabs [role="tab"]').forEach((tab) => {
            const selected = tab === allTab;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', String(selected));
        });
        this.filter();
    }
}
