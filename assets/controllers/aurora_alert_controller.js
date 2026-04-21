import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora Alert/Toast Dismiss Controller
 *
 * Handles the close-button on Aurora alerts + auto-hide for tone=success.
 * Attached via data-controller="aurora-alert" in _alert.html.twig.
 */
export default class extends Controller {
    static values = {
        autohide: { type: Number, default: 0 } // 0 = never, > 0 = ms
    };

    connect() {
        if (this.autohideValue > 0) {
            this.autohideTimeout = window.setTimeout(() => this.dismiss(), this.autohideValue);
        }
    }

    disconnect() {
        if (this.autohideTimeout) {
            window.clearTimeout(this.autohideTimeout);
        }
    }

    dismiss(event) {
        if (event) event.preventDefault();
        const el = this.element;
        el.classList.add('is-dismissing');
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) {
            el.remove();
            return;
        }
        window.setTimeout(() => el.remove(), 240);
    }
}
