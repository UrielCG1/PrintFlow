import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['search', 'status', 'priority', 'equipment', 'rows', 'empty', 'routePanel', 'following'];

    connect() {
        this.activeTab = 'queue';
        this.clockStartedAt = Date.now();
        this.updateClocks();
        this.clockInterval = window.setInterval(() => this.updateClocks(), 1000);
        this.filter();
    }

    disconnect() {
        window.clearInterval(this.clockInterval);
    }

    filter() {
        if (!this.hasRowsTarget) return;

        const query = this.hasSearchTarget ? this.searchTarget.value.trim().toLocaleLowerCase() : '';
        const status = this.hasStatusTarget ? this.statusTarget.value : '';
        const priority = this.hasPriorityTarget ? this.priorityTarget.value : '';
        const equipment = this.hasEquipmentTarget ? this.equipmentTarget.value : '';
        const rows = this.rowsTarget.querySelectorAll('[data-execution-row]');
        let visibleRows = 0;

        rows.forEach((row) => {
            const rowState = row.dataset.state;
            const matchesTab = this.activeTab === 'queue'
                ? rowState !== 'completed'
                : this.activeTab === 'paused_blocked'
                    ? ['paused', 'blocked'].includes(rowState)
                    : rowState === this.activeTab;
            const matches = matchesTab
                && (!query || row.dataset.search.includes(query))
                && (!status || rowState === status)
                && (!priority || row.dataset.priority === priority)
                && (!equipment || row.dataset.equipment === equipment);

            row.hidden = !matches;
            if (matches) visibleRows += 1;
        });

        const featured = this.rowsTarget.querySelector('.execution-task--featured');
        if (this.hasRoutePanelTarget) {
            this.routePanelTarget.hidden = !featured || featured.hidden;
            this.routePanelTarget.parentElement.classList.toggle('is-route-hidden', this.routePanelTarget.hidden);
        }
        if (this.hasFollowingTarget) {
            this.followingTarget.hidden = !this.followingTarget.querySelector('[data-execution-row]:not([hidden])');
            const blockedVisible = this.followingTarget.querySelector('.execution-task--blocked:not([hidden])');
            const notice = this.followingTarget.querySelector('.execution-following__notice');
            if (notice) notice.hidden = !blockedVisible;
        }

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visibleRows > 0 || rows.length === 0;
        }
    }

    selectTab(event) {
        if (this.hasStatusTarget) this.statusTarget.value = '';
        this.activateTab(event.currentTarget.dataset.filter);
        this.filter();
    }

    selectStatus() {
        const status = this.hasStatusTarget ? this.statusTarget.value : '';
        const tab = ['paused', 'blocked'].includes(status)
            ? 'paused_blocked'
            : (status || 'queue');
        this.activateTab(tab);
        this.filter();
    }

    activateTab(filter) {
        this.activeTab = filter;
        this.element.querySelectorAll('.execution-tabs button').forEach((tab) => {
            const selected = tab.dataset.filter === filter;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-pressed', String(selected));
        });
    }

    clear() {
        if (this.hasSearchTarget) this.searchTarget.value = '';
        if (this.hasStatusTarget) this.statusTarget.value = '';
        if (this.hasPriorityTarget) this.priorityTarget.value = '';
        if (this.hasEquipmentTarget) this.equipmentTarget.value = '';
        this.activateTab('queue');
        this.filter();
        if (this.hasSearchTarget) this.searchTarget.focus();
    }

    updateClocks() {
        const now = Date.now();
        this.element.querySelectorAll('[data-execution-clock]').forEach((clock) => {
            const baseSeconds = Number.parseInt(clock.dataset.seconds ?? '0', 10) || 0;
            const runningSeconds = clock.dataset.running === 'true'
                ? Math.max(0, Math.floor((now - this.clockStartedAt) / 1000))
                : 0;
            const totalSeconds = baseSeconds + runningSeconds;
            const hours = Math.floor(totalSeconds / 3600).toString().padStart(2, '0');
            const minutes = Math.floor((totalSeconds % 3600) / 60).toString().padStart(2, '0');
            const seconds = (totalSeconds % 60).toString().padStart(2, '0');
            clock.textContent = `${hours}:${minutes}:${seconds}`;
        });
    }
}
