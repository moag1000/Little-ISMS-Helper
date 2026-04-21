import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora Global-Banner Dismiss Controller
 *
 * Merkt sich pro banner-id im localStorage, dass User geschlossen hat.
 * Plan § 29 Global-Banner.
 */
export default class extends Controller {
    static STORAGE_KEY = 'fa-dismissed-banners';

    connect() {
        const id = this.element.dataset.bannerId;
        if (!id) return;
        if (this.isDismissed(id)) {
            this.element.remove();
            return;
        }
    }

    dismiss(event) {
        if (event) event.preventDefault();
        const id = this.element.dataset.bannerId;
        if (id) this.persistDismiss(id);
        this.element.classList.add('is-dismissing');
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) { this.element.remove(); return; }
        window.setTimeout(() => this.element.remove(), 240);
    }

    isDismissed(id) {
        try {
            const raw = localStorage.getItem(this.constructor.STORAGE_KEY);
            if (!raw) return false;
            const list = JSON.parse(raw);
            return Array.isArray(list) && list.includes(id);
        } catch (e) { return false; }
    }

    persistDismiss(id) {
        try {
            const raw = localStorage.getItem(this.constructor.STORAGE_KEY);
            const list = raw ? JSON.parse(raw) : [];
            if (!list.includes(id)) list.push(id);
            localStorage.setItem(this.constructor.STORAGE_KEY, JSON.stringify(list));
        } catch (e) { /* quota */ }
    }
}
