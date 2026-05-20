import { Controller } from '@hotwired/stimulus';

/**
 * confirm-typing — destructive-action keyword gate.
 *
 * Wired by `_components/_confirmation_dialog.html.twig` when the `typingConfirm`
 * option is set (e.g. user must type "LÖSCHEN" to enable the delete button).
 *
 * Contract:
 *   - Bound to an <input> inside a Bootstrap modal.
 *   - `data-confirm-typing-target-value` (or legacy `data-confirm-keyword`) holds
 *     the keyword.
 *   - Toggles `disabled` on the submit/danger button inside the modal-footer
 *     when the typed value matches the keyword (case-sensitive, trimmed).
 *
 * Defensive: if no submit button is found, this still updates `.is-valid` /
 * `.is-invalid` Bootstrap classes on the input so the user gets feedback.
 */
export default class extends Controller {
    static values = {
        target: { type: String, default: '' },
    };

    connect() {
        this.keyword =
            this.targetValue ||
            this.element.dataset.confirmKeyword ||
            '';
        this.modal = this.element.closest('.modal') || this.element.closest('form');
        this.submitBtn =
            this.modal?.querySelector('button[type="submit"], .btn-danger[type="submit"]') ?? null;
        // Lock the destructive button until the keyword matches.
        if (this.submitBtn) {
            this.submitBtn.disabled = true;
        }
        this.element.addEventListener('input', this.check);
        this.element.addEventListener('paste', this.check);
    }

    disconnect() {
        this.element.removeEventListener('input', this.check);
        this.element.removeEventListener('paste', this.check);
    }

    check = () => {
        const value = (this.element.value || '').trim();
        const match = this.keyword !== '' && value === this.keyword;
        this.element.classList.toggle('is-valid', match);
        this.element.classList.toggle('is-invalid', value !== '' && !match);
        if (this.submitBtn) {
            this.submitBtn.disabled = !match;
        }
    };
}
