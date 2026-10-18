import { Controller } from '@hotwired/stimulus';
import { DayPilot } from '../../vendor/daypilot/daypilot-all.min.js';

const states = { reserved: 'Reservada', in_progress: 'En curso', paused: 'Pausada', blocked: 'Bloqueada', completed: 'Ejecutada' };
const colors = {
    reserved: ['#e8f2ff', '#165fbd', '#b8d4f7'],
    in_progress: ['#def5e6', '#08752f', '#91d6a6'],
    paused: ['#fff0d7', '#9a5a00', '#f1c87d'],
    blocked: ['#ffe9e7', '#b42318', '#f2aaa4'],
    completed: ['#edf1f6', '#51627a', '#d2dbe7'],
};

export default class extends Controller {
    static targets = ['date', 'summary', 'dayButton', 'weekButton', 'canvas', 'periodTitle', 'count', 'notice', 'empty', 'capacity', 'list', 'backlog', 'backlogCount', 'detailDialog', 'detail', 'detailMoveButton', 'manualMove', 'manualEquipment', 'manualStart', 'manualEnd', 'manualError', 'moveDialog', 'moveSummary', 'moveError', 'reason', 'confirmButton'];
    static values = { initial: Object, eventsUrl: String, previewUrl: String, confirmUrl: String, csrf: String, canManage: Boolean };

    connect() {
        this.board = this.initialValue;
        this.view = this.board.view;
        this.render();
    }

    disconnect() {
        this.loadController?.abort();
        this.calendar?.dispose?.();
    }

    previous() { this.shift(this.view === 'week' ? -7 : -1); }
    next() { this.shift(this.view === 'week' ? 7 : 1); }
    today() {
        this.dateTarget.value = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Mexico_City', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
        this.reload();
    }
    changeDate() { this.reload(); }
    showDay() { this.view = 'day'; this.reload(); }
    showWeek() { if (this.canManageValue) { this.view = 'week'; this.reload(); } }

    shift(days) {
        const date = new Date(`${this.dateTarget.value}T12:00:00`);
        date.setDate(date.getDate() + days);
        this.dateTarget.value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        this.reload();
    }

    applyFilters(event) {
        if (event.type === 'submit') event.preventDefault();
        if (event.type === 'change' && ['search', 'text'].includes(event.target.type)) return;
        this.reload();
    }

    clearFilters(event) {
        event.preventDefault();
        event.currentTarget.form.reset();
        this.reload();
    }

    async reload() {
        this.loadController?.abort();
        this.loadController = new AbortController();
        const params = new URLSearchParams({ date: this.dateTarget.value, view: this.view });
        if (this.canManageValue) {
            const form = this.element.querySelector('.pf-production-board__filters');
            for (const [key, value] of new FormData(form)) if (value) params.set(key, value);
        }
        this.setNotice('Cargando agenda…');
        try {
            const response = await fetch(`${this.eventsUrlValue}?${params}`, { headers: { Accept: 'application/json' }, signal: this.loadController.signal });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'No se pudo cargar el calendario.');
            this.board = data;
            this.render();
            this.setNotice('');
            window.history.replaceState(null, '', `${window.location.pathname}?date=${encodeURIComponent(data.date)}&view=${data.view}`);
        } catch (error) {
            if (error.name === 'AbortError') return;
            this.setNotice(error.message);
        }
    }

    render() {
        this.dateTarget.value = this.board.date;
        this.refreshFilterOptions();
        this.dayButtonTarget.classList.toggle('is-active', this.view === 'day');
        if (this.hasWeekButtonTarget) this.weekButtonTarget.classList.toggle('is-active', this.view === 'week');
        const dateLabel = new Intl.DateTimeFormat('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${this.board.range_start}T12:00:00Z`));
        this.periodTitleTarget.textContent = this.view === 'week' ? `Semana del ${dateLabel}` : `Reservas · ${dateLabel}`;
        this.countTarget.textContent = `${this.board.events.length} tramo${this.board.events.length === 1 ? '' : 's'}`;
        this.emptyTarget.hidden = this.board.events.length !== 0;
        this.renderSummary();
        this.renderCalendar();
        this.renderList();
        this.renderBacklog();
        this.renderCapacity();
    }

    refreshFilterOptions() {
        if (!this.canManageValue) return;
        const form = this.element.querySelector('.pf-production-board__filters');
        if (!form) return;
        const lists = {
            equipment: this.board.resources.map(item => [item.id, item.name]),
            area: Object.entries(this.board.options.area),
            operation: Object.entries(this.board.options.operation),
            responsible: Object.entries(this.board.options.responsible),
        };
        for (const [name, entries] of Object.entries(lists)) {
            const select = form.elements.namedItem(name);
            const selected = select.value;
            const currentLabel = select.selectedOptions[0]?.textContent || selected;
            const placeholder = select.options[0]?.textContent || 'Todas';
            select.replaceChildren(new Option(placeholder, ''));
            for (const [id, label] of entries) select.add(new Option(label, id));
            if (selected && !entries.some(([id]) => String(id) === selected)) select.add(new Option(currentLabel, selected));
            select.value = selected;
        }
    }

    renderSummary() {
        const metrics = [
            ['bi-calendar-check', 'Horas reservadas', this.hours(this.board.summary.reserved_minutes), 'blue'],
            ['bi-check-circle', 'Horas ejecutadas', this.hours(this.board.summary.executed_minutes), 'green'],
            ['bi-exclamation-triangle', 'Con riesgo en calendario', String(this.board.summary.risk_count), 'amber'],
            ['bi-pause-circle', 'Pausas o bloqueos en calendario', String(this.board.summary.blocked_count), 'red'],
        ];
        this.summaryTarget.replaceChildren(...metrics.map(([icon, label, value, tone]) => {
            const card = this.node('div', 'pf-production-board__metric');
            card.dataset.tone = tone;
            const symbol = this.node('i', `bi ${icon}`);
            symbol.setAttribute('aria-hidden', 'true');
            const copy = this.node('div');
            copy.append(this.node('span', '', label), this.node('strong', '', value));
            card.append(symbol, copy);
            return card;
        }));
    }

    renderCalendar() {
        this.calendar?.dispose?.();
        this.canvasTarget.replaceChildren();
        const host = document.createElement('div');
        host.id = `production-board-${this.view}`;
        this.canvasTarget.append(host);
        const resources = this.board.resources.map(row => ({ id: row.id, name: row.name }));
        if (resources.length === 0) {
            this.emptyTarget.textContent = 'No hay máquinas para la selección actual.';
            this.emptyTarget.hidden = false;
            return;
        }
        this.emptyTarget.textContent = 'No hay reservas para los filtros y la fecha seleccionados.';
        const events = this.board.events.map(row => {
            const [backColor, fontColor, borderColor] = colors[row.status] || colors.reserved;
            return { id: String(row.id), resource: row.resource, start: row.start, end: row.end,
                text: `${row.folio} · ${row.operation}\n${row.product}`, backColor, fontColor, borderColor,
                moveDisabled: !this.canManageValue || !row.movable };
        });
        const common = {
            locale: 'es-mx',
            events,
            eventMoveHandling: this.canManageValue ? 'Update' : 'Disabled',
            eventResizeHandling: 'Disabled',
            timeRangeSelectedHandling: 'Disabled',
            onEventClick: args => this.openEvent(Number(args.e.data.id)),
            onEventMove: args => {
                args.preventDefault();
                this.previewMove(args);
            },
        };
        try {
            if (this.view === 'week') {
                this.calendar = new DayPilot.Scheduler(host.id, {
                    ...common, startDate: this.board.range_start, days: 5, scale: 'Hour',
                    timeHeaders: [{ groupBy: 'Day', format: 'dddd d MMM' }, { groupBy: 'Hour', format: 'H' }],
                    resources, cellWidth: 34, rowHeaderWidth: 190, eventHeight: 44,
                    businessBeginsHour: 8, businessEndsHour: 18, heightSpec: 'Max', height: 720,
                });
            } else {
                this.calendar = new DayPilot.Calendar(host.id, {
                    ...common, viewType: 'Resources', startDate: this.board.range_start,
                    columns: resources, businessBeginsHour: 8, businessEndsHour: 18,
                    heightSpec: 'BusinessHours',
                    cellDuration: 30, cellHeight: 38, headerHeight: 42,
                });
            }
            this.calendar.init();
        } catch (error) {
            this.setNotice(`No fue posible dibujar el calendario: ${error.message}`);
        }
    }

    renderCapacity() {
        if (!this.hasCapacityTarget) return;
        this.capacityTarget.hidden = this.view !== 'week';
        if (this.view !== 'week') return;
        const title = this.node('h2', '', 'Capacidad de la semana por máquina');
        const grid = this.node('div', 'pf-production-board__capacity-grid');
        for (const row of this.board.resources) {
            const card = this.node('article', 'pf-production-board__capacity-card');
            const name = this.node('strong', '', row.name);
            const meta = this.node('small', '', `${row.area} · ${row.operation}`);
            const stats = this.node('div', '', `Libres ${this.hours(row.free_minutes)} · Reservadas ${this.hours(row.total_reserved_minutes)} · Ejecutadas en selección ${this.hours(row.executed_minutes)} · Alertas en selección ${row.alerts}`);
            const bar = this.node('div', 'pf-production-board__capacity-bar');
            const fill = this.node('span');
            const capacity = row.total_reserved_minutes + row.free_minutes;
            fill.style.width = `${capacity ? Math.min(100, Math.round(row.total_reserved_minutes / capacity * 100)) : 0}%`;
            bar.append(fill);
            card.append(name, meta, bar, stats);
            grid.append(card);
        }
        this.capacityTarget.replaceChildren(title, grid);
    }

    renderList() {
        this.listTarget.replaceChildren();
        for (const row of this.board.events) {
            const button = this.node('button', 'pf-production-board__list-row');
            button.type = 'button';
            button.append(this.node('strong', '', `${row.folio} · Partida ${row.partida}`),
                this.node('span', '', `${row.operation} · ${row.equipment}`),
                this.node('small', '', `${row.start.slice(11, 16)}–${row.end.slice(11, 16)} · ${states[row.status]} · ${row.priority_label}`));
            button.addEventListener('click', () => this.openEvent(row.id));
            this.listTarget.append(button);
        }
        if (this.board.events.length === 0) this.listTarget.append(this.node('p', '', 'Sin reservas para este periodo.'));
    }

    renderBacklog() {
        this.backlogCountTarget.textContent = `${this.board.unreserved.length} trabajo${this.board.unreserved.length === 1 ? '' : 's'}`;
        this.backlogTarget.replaceChildren();
        if (this.board.unreserved.length === 0) {
            this.backlogTarget.append(this.node('p', 'pf-production-board__backlog-empty', 'No hay trabajos pendientes sin horario para estos filtros.'));
            return;
        }
        for (const row of this.board.unreserved) {
            const item = this.node('article', 'pf-production-board__backlog-item');
            item.dataset.state = row.status;
            const main = this.node('div');
            main.append(this.node('strong', '', `${row.folio} · Partida ${row.partida} · ${row.operation}`),
                this.node('span', '', `${row.product} · ${row.equipment} · ${row.responsible}`));
            const side = this.node('div');
            side.append(this.node('b', '', row.priority_label), this.node('small', '', row.risk_label));
            item.append(main, side, this.node('p', '', row.reason));
            this.backlogTarget.append(item);
        }
    }

    openEvent(id) {
        const row = this.board.events.find(event => event.id === id);
        if (!row) return;
        const fields = [
            ['Orden / partida', `${row.folio} · ${row.partida}`], ['Producto', row.product],
            ['Operación', row.operation], ['Máquina', row.equipment], ['Área', row.area],
            ['Horario', `${row.start.replace('T', ' ')} – ${row.end.replace('T', ' ')}`],
            ['Duración reservada', this.hours(row.minutes)],
            ['Tiempo real', row.actual_minutes ? this.hours(row.actual_minutes) : 'Sin registro'],
            ['Estado', states[row.status]], ['Prioridad', row.priority_label],
            ['Riesgo', `${row.risk_label} · ${row.risk_cause}`],
            ['Responsable', row.responsible], ['Siguiente destino', row.next],
            ['Compromiso', row.commitment || 'Sin fecha'],
        ];
        const list = this.node('dl', 'pf-production-board__detail-list');
        for (const [label, value] of fields) list.append(this.node('dt', '', label), this.node('dd', '', value));
        this.detailTarget.replaceChildren(list);
        if (this.hasDetailMoveButtonTarget) {
            this.detailMoveButtonTarget.hidden = !row.movable;
            this.manualMoveTarget.hidden = true;
            this.manualRow = row;
        }
        this.detailDialogTarget.showModal();
    }

    closeDetail() { this.detailDialogTarget.close(); }

    showManualMove() {
        const row = this.manualRow;
        if (!row?.movable) return;
        this.manualEquipmentTarget.replaceChildren();
        for (const machine of this.board.resources.filter(resource => resource.operation_id === row.operation_id)) {
            this.manualEquipmentTarget.add(new Option(machine.name, machine.id));
        }
        this.manualEquipmentTarget.value = row.resource;
        this.manualStartTarget.value = row.start.slice(0, 16);
        this.manualEndTarget.value = row.end.slice(0, 16);
        this.manualErrorTarget.hidden = true;
        this.manualMoveTarget.hidden = false;
        this.manualStartTarget.focus();
    }

    previewManualMove(event) {
        event.preventDefault();
        if (!this.manualRow) return;
        const request = {
            equipment: Number(this.manualEquipmentTarget.value),
            start: `${this.manualStartTarget.value}:00`,
            end: `${this.manualEndTarget.value}:00`,
        };
        this.requestPreview(this.manualRow, request);
    }

    async previewMove(args) {
        const id = Number(args.e.data.id);
        const row = this.board.events.find(event => event.id === id);
        if (!row || !row.movable) { this.setNotice('Esta reserva ya no se puede mover.'); return; }
        const request = {
            equipment: Number(args.newResource || args.e.data.resource),
            start: args.newStart.toString('yyyy-MM-ddTHH:mm:ss'),
            end: args.newEnd.toString('yyyy-MM-ddTHH:mm:ss'),
        };
        this.requestPreview(row, request);
    }

    async requestPreview(row, request) {
        try {
            const preview = await this.post(this.previewUrlValue.replace('/0/', `/${row.id}/`), request);
            this.pendingMove = { id: row.id, request, fingerprint: preview.fingerprint };
            this.moveSummaryTarget.textContent = `${row.folio} · ${row.operation} → ${preview.equipment}, ${preview.start} a ${preview.end}. La duración se conserva.`;
            this.reasonTarget.value = '';
            this.moveErrorTarget.hidden = true;
            if (this.detailDialogTarget.open) this.detailDialogTarget.close();
            this.moveDialogTarget.showModal();
        } catch (error) {
            this.setNotice(error.message);
            if (this.detailDialogTarget.open && this.hasManualErrorTarget) {
                this.manualErrorTarget.textContent = error.message;
                this.manualErrorTarget.hidden = false;
            }
        }
    }

    async confirmMove(event) {
        event.preventDefault();
        if (!this.pendingMove) return;
        this.confirmButtonTarget.disabled = true;
        try {
            await this.post(this.confirmUrlValue.replace('/0/', `/${this.pendingMove.id}/`), {
                ...this.pendingMove.request, fingerprint: this.pendingMove.fingerprint, reason: this.reasonTarget.value,
            });
            this.closeMove();
            await this.reload();
            this.setNotice('Reserva reprogramada correctamente.');
        } catch (error) {
            this.moveErrorTarget.textContent = error.message;
            this.moveErrorTarget.hidden = false;
        } finally {
            this.confirmButtonTarget.disabled = false;
        }
    }

    closeMove() { this.moveDialogTarget.close(); this.pendingMove = null; }

    async post(url, data) {
        const response = await fetch(url, {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' },
            body: JSON.stringify(data),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'No se pudo actualizar la agenda.');
        return result;
    }

    setNotice(message) { this.noticeTarget.textContent = message; this.noticeTarget.hidden = !message; }
    hours(minutes) { return `${(minutes / 60).toLocaleString('es-MX', { maximumFractionDigits: 1 })} h`; }
    node(tag, className = '', value = '') {
        const element = document.createElement(tag);
        if (className) element.className = className;
        element.textContent = value;
        return element;
    }
}
