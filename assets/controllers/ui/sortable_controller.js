import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';
import Swal from 'sweetalert2';

export default class extends Controller {
    static values = {
        url: String,
        token: String,
    };

    connect() {
        this.originalOrder = [];

        this.sortable = Sortable.create(this.element, {
            animation: 150,
            handle: '.pf-sortable__handle',
            draggable: 'tr[data-sortable-id]',
            ghostClass: 'pf-sortable__row--ghost',
            chosenClass: 'pf-sortable__row--chosen',
            onStart: () => {
                this.originalOrder = this.currentOrder();
            },
            onEnd: (event) => {
                if (event.oldIndex !== event.newIndex) {
                    this.persistOrder(event);
                }
            },
        });
    }

    disconnect() {
        this.sortable?.destroy();
    }

    async persistOrder(event) {
        this.sortable.option('disabled', true);

        const movedId = this.rowId(event.item);
        const beforeId = this.rowId(event.item.nextElementSibling);
        const afterId = this.rowId(event.item.previousElementSibling);

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    _token: this.tokenValue,
                    movedId,
                    beforeId,
                    afterId,
                }),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || 'No fue posible guardar el nuevo orden.');
            }
        } catch (error) {
            this.sortable.sort(this.originalOrder);

            await Swal.fire({
                icon: 'error',
                title: 'No fue posible reordenar',
                text: error.message,
                confirmButtonText: 'Entendido',
            });
        } finally {
            this.sortable?.option('disabled', false);
        }
    }

    currentOrder() {
        return Array.from(this.element.querySelectorAll('tr[data-sortable-id]'))
            .map((row) => row.dataset.id);
    }

    rowId(row) {
        if (!row) {
            return null;
        }

        const id = Number(row.dataset.sortableId);

        return Number.isSafeInteger(id) && id > 0 ? id : null;
    }
}