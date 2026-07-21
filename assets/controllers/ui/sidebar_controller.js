import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['sidebar', 'backdrop', 'toggle'];

    connect() {
        this.handleKeydown = this.handleKeydown.bind(this);
        this.handleResize = this.handleResize.bind(this);

        document.addEventListener('keydown', this.handleKeydown);
        window.addEventListener('resize', this.handleResize);

        this.close();
    }

    disconnect() {
        document.removeEventListener('keydown', this.handleKeydown);
        window.removeEventListener('resize', this.handleResize);
    }

    toggle() {
        this.isOpen ? this.close() : this.open();
    }

    open() {
        this.sidebarTarget.classList.add('is-open');
        this.backdropTarget.classList.add('is-visible');
        this.sidebarTarget.setAttribute('aria-hidden', 'false');
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        document.body.classList.add('pf-sidebar-open');
    }

    close() {
        this.sidebarTarget.classList.remove('is-open');
        this.backdropTarget.classList.remove('is-visible');
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('pf-sidebar-open');

        this.sidebarTarget.setAttribute(
            'aria-hidden',
            this.isDesktop ? 'false' : 'true',
        );
    }

    handleKeydown(event) {
        if (event.key === 'Escape' && this.isOpen) {
            this.close();
        }
    }

    handleResize() {
        if (this.isDesktop) {
            this.close();
        }
    }

    get isOpen() {
        return this.sidebarTarget.classList.contains('is-open');
    }

    get isDesktop() {
        return window.matchMedia('(min-width: 992px)').matches;
    }
}