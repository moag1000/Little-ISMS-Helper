import { Controller } from '@hotwired/stimulus';

/**
 * control-status controller
 *
 * Wires the Control implementation-status <select> to the implementation
 * percentage input so the two stay coherent (consultant finding C).
 *
 * Rules (only the deterministic ones force a value):
 *   - verified     → percentage = 100, input disabled (tooltip), because a
 *                    verified control is by definition fully implemented. The
 *                    server enforces this too (hard validation error).
 *   - not_started  → percentage = 0.
 *   - implemented  → if percentage < 100, offer (non-blocking) to set 100.
 *   - in_progress / planned → never forced.
 *
 * The disabled input would not POST its value, so we mirror it into a hidden
 * companion field and keep the original input's name on whichever element is
 * currently enabled.
 */
export default class extends Controller {
    static targets = ['status', 'percentage'];

    connect() {
        if (!this.hasPercentageTarget) {
            return;
        }
        this.percentageName = this.percentageTarget.getAttribute('name');
        // Apply once on load so a pre-verified control renders disabled.
        this.apply(false);
    }

    onChange() {
        this.apply(true);
    }

    apply(interactive) {
        const status = this.hasStatusTarget ? this.statusTarget.value : null;
        const input = this.percentageTarget;

        if (status === 'verified') {
            this.#setValue(100);
            this.#disable(true);
            return;
        }

        this.#disable(false);

        if (status === 'not_started') {
            this.#setValue(0);
            return;
        }

        if (status === 'implemented' && interactive) {
            const current = parseInt(input.value, 10);
            if (Number.isNaN(current) || current < 100) {
                // Aurora confirm dialog (window.faConfirm) instead of the native
                // browser confirm() — the native popup is jarring and off-brand.
                // faConfirm returns a Promise<boolean>; fall back to just applying
                // the value if the helper is not yet loaded.
                if (typeof window.faConfirm === 'function') {
                    window.faConfirm(this.#confirmMessage(), { tone: 'info' })
                        .then((ok) => { if (ok) { this.#setValue(100); } });
                } else {
                    this.#setValue(100);
                }
            }
        }
    }

    #setValue(value) {
        const input = this.percentageTarget;
        input.value = String(value);
        // Notify any sibling controller (e.g. progress-slider) of the change.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        if (this.hidden) {
            this.hidden.value = String(value);
        }
    }

    #disable(disabled) {
        const input = this.percentageTarget;
        if (disabled) {
            input.setAttribute('disabled', 'disabled');
            input.setAttribute('title', this.#disabledTooltip());
            // Disabled inputs are not submitted — mirror into a hidden field.
            if (!this.hidden && this.percentageName) {
                this.hidden = document.createElement('input');
                this.hidden.type = 'hidden';
                this.hidden.name = this.percentageName;
                this.hidden.value = input.value;
                input.parentNode.appendChild(this.hidden);
                input.removeAttribute('name');
            }
        } else {
            input.removeAttribute('disabled');
            input.removeAttribute('title');
            if (this.hidden) {
                this.hidden.remove();
                this.hidden = null;
                if (this.percentageName) {
                    input.setAttribute('name', this.percentageName);
                }
            }
        }
    }

    #disabledTooltip() {
        return this.element.getAttribute('data-control-status-verified-tooltip')
            || 'Verifiziert = vollständig umgesetzt';
    }

    #confirmMessage() {
        return this.element.getAttribute('data-control-status-implemented-confirm')
            || 'Als vollständig umgesetzt (100%) markieren?';
    }
}
