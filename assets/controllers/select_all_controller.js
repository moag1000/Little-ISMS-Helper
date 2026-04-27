import { Controller } from '@hotwired/stimulus';

/*
 * Generic "select all" checkbox.
 *
 * Usage:
 *   <input type="checkbox"
 *          data-controller="select-all"
 *          data-action="change->select-all#toggle"
 *          data-select-all-target-selector-value="input[name^='items['][type='checkbox']:not([disabled])">
 *
 * Behavior:
 *   - Master checkbox toggles all matched checkboxes within the closest <form>
 *     (or document if no form).
 *   - Listens to changes on matched checkboxes to update the master state
 *     (checked / unchecked / indeterminate) so the UI stays consistent when the
 *     user toggles individual rows.
 */
export default class extends Controller {
    static values = {
        targetSelector: { type: String, default: 'input[type="checkbox"]' },
    };

    connect() {
        this.boundUpdate = () => this.updateMasterState();
        this.scope().forEach((cb) => cb.addEventListener('change', this.boundUpdate));
        this.updateMasterState();
    }

    disconnect() {
        if (this.boundUpdate) {
            this.scope().forEach((cb) => cb.removeEventListener('change', this.boundUpdate));
        }
    }

    toggle(event) {
        const checked = event.target.checked;
        this.scope().forEach((cb) => {
            cb.checked = checked;
        });
    }

    updateMasterState() {
        const boxes = this.scope();
        if (boxes.length === 0) {
            this.element.checked = false;
            this.element.indeterminate = false;
            return;
        }
        const checkedCount = boxes.filter((cb) => cb.checked).length;
        if (checkedCount === 0) {
            this.element.checked = false;
            this.element.indeterminate = false;
        } else if (checkedCount === boxes.length) {
            this.element.checked = true;
            this.element.indeterminate = false;
        } else {
            this.element.checked = false;
            this.element.indeterminate = true;
        }
    }

    scope() {
        const root = this.element.closest('form') || document;
        const selector = this.targetSelectorValue || 'input[type="checkbox"]';
        return Array.from(root.querySelectorAll(selector));
    }
}
