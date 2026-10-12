import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['search', 'stage', 'status', 'priority', 'commitment', 'client', 'rows', 'empty'];

    connect() {
        this.activeTab = 'all';
    }

    filter() {
        const query = this.searchTarget.value.trim().toLocaleLowerCase();
        let visibleRows = 0;

        this.rowsTarget.querySelectorAll('[data-order-row]').forEach((row) => {
            const matches = (!query || row.dataset.search.includes(query))
                && (!this.stageTarget.value || row.dataset.stage === this.stageTarget.value)
                && (!this.statusTarget.value || row.dataset.status === this.statusTarget.value)
                && (!this.priorityTarget.value || row.dataset.priority === this.priorityTarget.value)
                && (!this.commitmentTarget.value || row.dataset.commitment === this.commitmentTarget.value)
                && (!this.clientTarget.value || row.dataset.client === this.clientTarget.value)
                && (this.activeTab === 'all' || row.dataset[`tab${this.activeTab[0].toUpperCase()}${this.activeTab.slice(1)}`] === '1');

            row.hidden = !matches;
            if (matches) visibleRows += 1;
        });

        this.emptyTarget.hidden = visibleRows > 0 || this.rowsTarget.querySelector('[data-order-row]') === null;
    }

    selectTab(event) {
        this.activeTab = event.currentTarget.dataset.filter;
        this.element.querySelectorAll('.service-orders-tabs [role="tab"]').forEach((tab) => {
            const selected = tab === event.currentTarget;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', String(selected));
        });
        this.filter();
    }

    clear() {
        this.searchTarget.value = '';
        this.stageTarget.value = '';
        this.statusTarget.value = '';
        this.priorityTarget.value = '';
        this.commitmentTarget.value = '';
        this.clientTarget.value = '';
        this.activeTab = 'all';
        const allTab = this.element.querySelector('[data-filter="all"]');
        this.element.querySelectorAll('.service-orders-tabs [role="tab"]').forEach((tab) => {
            const selected = tab === allTab;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', String(selected));
        });
        this.filter();
    }
}
