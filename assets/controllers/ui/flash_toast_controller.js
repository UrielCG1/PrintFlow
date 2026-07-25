import { Controller } from '@hotwired/stimulus';
import { notify } from '../../js/ui/notifications.js';

export default class extends Controller {
    static values = {
        type: String,
        message: String,
    };

    connect() {
        const supportedTypes = ['success', 'error', 'warning', 'info'];
        const type = supportedTypes.includes(this.typeValue)
            ? this.typeValue
            : 'info';

        notify[type](this.messageValue);
        this.element.remove();
    }
}