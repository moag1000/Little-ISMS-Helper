import { Controller } from '@hotwired/stimulus';
import { faToast } from '../js/fa-alerts.js';

/**
 * diff-select — Compliance-Wizard history: compare two snapshots.
 *
 * Navigates to the diff route /compliance-wizard/{wizard}/diff/{from}/{to}
 * once two different snapshots are chosen; otherwise shows a warning toast.
 * Bound to the form's submit (button click and Enter key alike).
 *
 * Usage:
 *   <form data-controller="diff-select"
 *         data-action="submit->diff-select#compare"
 *         data-diff-select-url-value="{{ path('app_compliance_wizard_diff', {wizard: w, fromId: 0, toId: 0}) }}"
 *         data-diff-select-message-value="{{ 'diff.select_different'|trans({}, 'compliance_wizard') }}">
 *     <select name="from">…</select> <select name="to">…</select>
 *     <button type="submit">Compare</button>
 *   </form>
 */
export default class extends Controller {
    static values = {
        // Diff URL rendered with the placeholder ids 0/0.
        url: String,
        message: String,
    };

    compare(event) {
        event.preventDefault();
        const from = this.element.querySelector('select[name="from"]')?.value;
        const to = this.element.querySelector('select[name="to"]')?.value;

        if (from && to && from !== to) {
            window.location.href = this.urlValue.replace(/\/0\/0(?=$|[?#])/, `/${from}/${to}`);
        } else {
            faToast(this.messageValue, 'warning');
        }
    }
}
