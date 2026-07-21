import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'toggle', 'icon'];

    connect() {
        this.render();
    }

    toggle() {
        this.inputTarget.type = this.isVisible ? 'password' : 'text';

        this.render();
    }

    get isVisible() {
        return this.inputTarget.type === 'text';
    }

    render() {
        this.iconTarget.classList.toggle('bi-eye', !this.isVisible);
        this.iconTarget.classList.toggle('bi-eye-slash', this.isVisible);

        this.toggleTarget.setAttribute(
            'aria-label',
            this.isVisible ? 'Ocultar contraseña' : 'Mostrar contraseña',
        );

        this.toggleTarget.setAttribute('aria-pressed', String(this.isVisible));
    }
}