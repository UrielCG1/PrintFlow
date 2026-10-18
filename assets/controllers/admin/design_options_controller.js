import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle(event) {
        const selectedOption = event.currentTarget;
        if (!selectedOption.open) {
            return;
        }

        this.element.querySelectorAll('details[open]').forEach((option) => {
            if (option !== selectedOption) {
                option.open = false;
            }
        });
    }
}
