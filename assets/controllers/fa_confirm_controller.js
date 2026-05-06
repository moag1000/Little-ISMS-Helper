import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora v4.0 — fa-confirm Stimulus Controller
 *
 * Drives the fa-confirm dialog: type-to-confirm phrase matching plus
 * optional cooldown before the submit button becomes clickable.
 *
 * Targets:
 *   form         - the inner <form>; cancel dispatches `fa-confirm:cancel`
 *                  on the dialog so a containing modal can close itself
 *   phraseInput  - <input> the user types into for type-to-confirm
 *   submit       - the dangerous submit button (disabled until ready)
 *   submitLabel  - <span> inside submit button, used for cooldown countdown
 *
 * Values:
 *   phrase   - exact string the user must type (case-sensitive)
 *   cooldown - seconds before submit becomes clickable. Counts down in
 *              the submit-button label.
 */
export default class extends Controller {
    static targets = ['form', 'phraseInput', 'submit', 'submitLabel'];
    static values = {
        phrase: String,
        cooldown: { type: Number, default: 0 },
    };

    #cooldownRemaining = 0;
    #cooldownTimer = null;
    #cooldownLabelOriginal = null;

    connect() {
        this.#cooldownRemaining = this.cooldownValue;
        if (this.#cooldownRemaining > 0) {
            this.#startCooldown();
        }
        this.#evaluateState();
    }

    disconnect() {
        this.#clearCooldown();
    }

    evaluate() {
        this.#evaluateState();
    }

    cancel(event) {
        event?.preventDefault();
        this.dispatch('cancel');
    }

    #evaluateState() {
        if (!this.hasSubmitTarget) {
            return;
        }

        const phraseOk = this.phraseValue
            ? this.hasPhraseInputTarget && this.phraseInputTarget.value === this.phraseValue
            : true;
        const cooldownOk = this.#cooldownRemaining <= 0;

        this.submitTarget.disabled = !(phraseOk && cooldownOk);
    }

    #startCooldown() {
        if (this.hasSubmitLabelTarget) {
            this.#cooldownLabelOriginal = this.submitLabelTarget.textContent;
        }
        this.#renderCooldownLabel();
        this.#cooldownTimer = window.setInterval(() => {
            this.#cooldownRemaining -= 1;
            if (this.#cooldownRemaining <= 0) {
                this.#clearCooldown();
                this.#restoreLabel();
            } else {
                this.#renderCooldownLabel();
            }
            this.#evaluateState();
        }, 1000);
    }

    #renderCooldownLabel() {
        if (!this.hasSubmitLabelTarget) {
            return;
        }
        this.submitLabelTarget.textContent = `${this.#cooldownLabelOriginal ?? ''} (${this.#cooldownRemaining}s)`;
    }

    #restoreLabel() {
        if (this.hasSubmitLabelTarget && this.#cooldownLabelOriginal !== null) {
            this.submitLabelTarget.textContent = this.#cooldownLabelOriginal;
        }
    }

    #clearCooldown() {
        if (this.#cooldownTimer !== null) {
            window.clearInterval(this.#cooldownTimer);
            this.#cooldownTimer = null;
        }
    }
}
