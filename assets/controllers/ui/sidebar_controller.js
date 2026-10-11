import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['sidebar', 'backdrop', 'toggle'];

    static scrollStorageKey = 'pf-app-sidebar-scroll-top';

    connect() {
        this.handleKeydown = this.handleKeydown.bind(this);
        this.handleResize = this.handleResize.bind(this);
        this.handleNavigationScroll = this.handleNavigationScroll.bind(this);

        document.addEventListener('keydown', this.handleKeydown);
        window.addEventListener('resize', this.handleResize);

        this.navigationElement = this.sidebarTarget.querySelector('.pf-sidebar__nav');
        this.navigationElement?.addEventListener('scroll', this.handleNavigationScroll, { passive: true });

        this.close();
        this.restoreNavigationScroll();
    }

    disconnect() {
        document.removeEventListener('keydown', this.handleKeydown);
        window.removeEventListener('resize', this.handleResize);
        this.navigationElement?.removeEventListener('scroll', this.handleNavigationScroll);
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

    handleNavigationScroll() {
        window.sessionStorage.setItem(
            this.constructor.scrollStorageKey,
            String(this.navigationElement.scrollTop),
        );
    }

    restoreNavigationScroll() {
        const savedPosition = window.sessionStorage.getItem(this.constructor.scrollStorageKey);

        if (savedPosition === null || !this.navigationElement) {
            return;
        }

        const savedScrollTop = Number(savedPosition);

        if (!Number.isFinite(savedScrollTop)) {
            return;
        }

        window.requestAnimationFrame(() => {
            this.navigationElement.scrollTop = savedScrollTop;
        });
    }

    get isOpen() {
        return this.sidebarTarget.classList.contains('is-open');
    }

    get isDesktop() {
        return window.matchMedia('(min-width: 992px)').matches;
    }
}
