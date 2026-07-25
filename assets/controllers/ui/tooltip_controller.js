import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        placement: {
            type: String,
            default: 'top',
        },
    };

    connect() {
        if (!window.bootstrap?.Tooltip) {
            return;
        }

        this.tooltip = new window.bootstrap.Tooltip(this.element, {
            placement: this.placementValue,
            trigger: 'hover focus',
        });
    }

    disconnect() {
        this.tooltip?.dispose();
    }
}