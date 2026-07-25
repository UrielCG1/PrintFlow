import { Controller } from '@hotwired/stimulus';
import { confirmAction } from '../../js/ui/confirmations.js';

export default class extends Controller {
    static values = {
        title: String,
        text: String,
        confirmButtonText: String,
        cancelButtonText: String,
        icon: String,
    };

    async submit(event) {
        if (this.element.dataset.confirmed === 'true') {
            return;
        }

        event.preventDefault();

        const result = await confirmAction({
            title: this.titleValue || '¿Deseas continuar?',
            text: this.textValue || '',
            confirmButtonText: this.confirmButtonTextValue || 'Sí, continuar',
            cancelButtonText: this.cancelButtonTextValue || 'Cancelar',
            icon: this.iconValue || 'warning',
        });

        if (!result.isConfirmed) {
            return;
        }

        this.element.dataset.confirmed = 'true';
        this.element.requestSubmit();
    }
}