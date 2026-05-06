import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora v4.0 — API-Key Reveal Controller
 *
 * Toggles between masked and full token. Auto-hides after 30 s so the
 * full token does not linger on screen if the operator walks away.
 * Copy-to-clipboard is bound to the same toggle button via long-press
 * (handled by browser native context menu on the <code> element — we
 * do not implement explicit copy because the rendered token is selectable).
 */
export default class extends Controller {
    static targets = ['rest', 'toggle', 'icon'];
    static values = {
        full: String,
        masked: String,
        autoHideMs: { type: Number, default: 30000 },
    };

    #hideTimer = null;
    #revealed = false;

    disconnect() {
        this.#clearTimer();
    }

    toggle(event) {
        event?.preventDefault();
        if (!this.fullValue) {
            return;
        }

        if (this.#revealed) {
            this.#hide();
        } else {
            this.#reveal();
        }
    }

    #reveal() {
        if (!this.hasRestTarget) {
            return;
        }
        this.restTarget.textContent = this.fullValue;
        this.#revealed = true;
        if (this.hasIconTarget) {
            this.iconTarget.classList.replace('bi-eye', 'bi-eye-slash');
        }
        this.#clearTimer();
        this.#hideTimer = window.setTimeout(() => this.#hide(), this.autoHideMsValue);
    }

    #hide() {
        if (!this.hasRestTarget) {
            return;
        }
        this.restTarget.textContent = this.maskedValue;
        this.#revealed = false;
        if (this.hasIconTarget) {
            this.iconTarget.classList.replace('bi-eye-slash', 'bi-eye');
        }
        this.#clearTimer();
    }

    #clearTimer() {
        if (this.#hideTimer !== null) {
            window.clearTimeout(this.#hideTimer);
            this.#hideTimer = null;
        }
    }
}
