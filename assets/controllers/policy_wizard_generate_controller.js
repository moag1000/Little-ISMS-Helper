import { Controller } from '@hotwired/stimulus';

/**
 * Policy-Wizard Step-7 Generate-button gate (Auditor Observation
 * 2026-05-10).
 *
 * Disables the Generate button until BOTH the approver-confirmation
 * checkbox AND (if rendered) the warning-acknowledgement checkbox are
 * ticked. The server-side controller enforces the same contract — this
 * is pure UX so the user does not click Generate, see a flash error
 * and have to scroll back up.
 *
 * Usage:
 *   <form data-controller="policy-wizard-generate">
 *     <input type="checkbox" data-policy-wizard-generate-target="confirm"
 *            data-action="change->policy-wizard-generate#toggle">
 *     <input type="checkbox" data-policy-wizard-generate-target="acknowledge"
 *            data-action="change->policy-wizard-generate#toggle">
 *     <button data-policy-wizard-generate-target="button" disabled>...</button>
 *   </form>
 */
export default class extends Controller {
    static targets = ['confirm', 'acknowledge', 'button'];

    connect() {
        this.toggle();
    }

    toggle() {
        if (!this.hasButtonTarget) {
            return;
        }
        const confirmOk = !this.hasConfirmTarget || this.confirmTarget.checked;
        const ackOk = !this.hasAcknowledgeTarget || this.acknowledgeTarget.checked;
        this.buttonTarget.disabled = !(confirmOk && ackOk);
    }
}
