import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['channel', 'screenshot'];

    connect() {
        this.update();
    }

    update() {
        const isWhatsapp = this.channelTarget.value === 'WHATSAPP';
        this.screenshotTarget.hidden = !isWhatsapp;
        if (!isWhatsapp) {
            const file = this.screenshotTarget.querySelector('input[type="file"]');
            if (file) file.value = '';
        }
    }
}
