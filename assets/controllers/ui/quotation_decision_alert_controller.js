import { Controller } from '@hotwired/stimulus';
import { showError } from '../../js/ui/confirmations.js';

export default class extends Controller {
    static values = { message: String, panel: String };

    async connect() {
        await showError({
            title: this.panelValue === '.quotation-action--accept'
                ? 'No se registró la aceptación'
                : 'No se registró el rechazo',
            text: this.messageValue,
        });
        const panel = document.querySelector(this.panelValue);
        panel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        this.element.remove();
    }
}
