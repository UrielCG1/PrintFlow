import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY_PREFIX = 'pf-service-order-scroll-position:';
const MAX_STATE_AGE_MS = 120_000;

export default class extends Controller {
    connect() {
        this.storageKey = `${STORAGE_KEY_PREFIX}${window.location.pathname}`;
        this.restore();
    }

    remember(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') {
            return;
        }

        // Confirmation dialogs submit twice. Ignore the first, cancelled attempt.
        const controllers = form.dataset.controller?.split(/\s+/) ?? [];
        if (controllers.includes('ui--confirm-action') && form.dataset.confirmed !== 'true') {
            return;
        }

        const action = new URL(form.action, window.location.href);
        const orderPath = window.location.pathname.replace(/\/+$/, '');
        if (action.origin !== window.location.origin
            || !action.pathname.startsWith(`${orderPath}/`)
            || action.pathname.endsWith('/prioridad/vista-previa')) {
            return;
        }

        const details = Array.from(this.element.querySelectorAll('details'), (element) => element.open);
        try {
            window.sessionStorage.setItem(this.storageKey, JSON.stringify({
                scrollY: window.scrollY,
                details,
                savedAt: Date.now(),
            }));
        } catch {
            // Keep form submissions working when browser storage is unavailable.
        }
    }

    restore() {
        let state;
        try {
            const stored = window.sessionStorage.getItem(this.storageKey);
            if (stored === null) {
                return;
            }
            state = JSON.parse(stored);
        } catch {
            this.removeSavedState();
            return;
        }

        if (!Number.isFinite(state?.scrollY)
            || !Array.isArray(state.details)
            || !Number.isFinite(state.savedAt)
            || Date.now() - state.savedAt > MAX_STATE_AGE_MS) {
            this.removeSavedState();
            return;
        }

        const details = this.element.querySelectorAll('details');
        state.details.forEach((wasOpen, index) => {
            if (details[index]) {
                details[index].open = Boolean(wasOpen);
            }
        });

        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                const root = document.documentElement;
                const previousScrollBehavior = root.style.scrollBehavior;
                root.style.scrollBehavior = 'auto';
                window.scrollTo(0, state.scrollY);
                window.requestAnimationFrame(() => {
                    root.style.scrollBehavior = previousScrollBehavior;
                });
                this.removeSavedState();
            });
        });
    }

    removeSavedState() {
        try {
            window.sessionStorage.removeItem(this.storageKey);
        } catch {
            // Ignore storage restrictions; the short expiration limits stale state.
        }
    }
}
