import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['type', 'unit'];

    connect() {
        this.unitInput = this.unitTarget.querySelector('input');
        this.locked = this.unitInput.disabled;
        this.update();
    }

    update() {
        const isDecimal = this.typeTarget.querySelector('select').value === 'DECIMAL';
        this.unitTarget.hidden = !isDecimal;

        if (!this.locked) {
            if (!isDecimal) this.unitInput.value = '';
            this.unitInput.disabled = !isDecimal;
        }
    }
}
