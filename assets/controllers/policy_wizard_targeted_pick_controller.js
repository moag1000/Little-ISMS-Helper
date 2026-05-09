import { Controller } from '@hotwired/stimulus';

/**
 * Policy-Wizard W3-J — Targeted-pick max-cap enforcer.
 *
 * Locks remaining unchecked checkboxes once `max` topics are selected.
 * Re-enables them as soon as the count drops below the cap. Pure UX —
 * the server-side TargetedPickTopicsStep also enforces `MAX_TOPICS`.
 *
 * Usage:
 *   <table data-controller="policy-wizard-targeted-pick"
 *          data-policy-wizard-targeted-pick-max-value="10">
 *     <input type="checkbox" name="topics[]"
 *            data-policy-wizard-targeted-pick-target="checkbox"
 *            data-action="change->policy-wizard-targeted-pick#enforceMax">
 *   </table>
 */
export default class extends Controller {
    static targets = ['checkbox'];
    static values = { max: { type: Number, default: 10 } };

    connect() {
        this.enforceMax();
    }

    enforceMax() {
        const checkedCount = this.checkboxTargets.filter((cb) => cb.checked).length;
        const cap = this.maxValue;
        this.checkboxTargets.forEach((cb) => {
            if (!cb.checked) {
                cb.disabled = checkedCount >= cap;
            }
        });
    }
}
