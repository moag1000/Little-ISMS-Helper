import { Controller } from '@hotwired/stimulus';

/*
 * Generic "select all" master checkbox.
 *
 * Reads the target-selector from the plain data-attribute
 * `data-select-all-selector` (NOT a Stimulus value, to dodge a
 * `getAttributeNameForKey` error seen with longform value definitions
 * in this app's bundled Stimulus build).
 *
 * Usage:
 *   <input type="checkbox"
 *          data-controller="select-all"
 *          data-action="change->select-all#toggle"
 *          data-select-all-selector="input[name^='items['][type='checkbox']:not([disabled])">
 */
export default class extends Controller {
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
        const selector = this.element.dataset.selectAllSelector || 'input[type="checkbox"]:not(#' + this.element.id + ')';
        return Array.from(root.querySelectorAll(selector)).filter((cb) => cb !== this.element);
    }
}
