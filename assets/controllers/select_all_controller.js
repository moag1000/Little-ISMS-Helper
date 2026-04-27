import { Controller } from '@hotwired/stimulus';

/*
 * Generic "select all" master checkbox.
 *
 * Reads the target-selector from the plain data-attribute
 * `data-select-all-selector` and resolves the master element from the
 * action event's currentTarget rather than `this.element` to dodge a
 * Stimulus base-class binding glitch we hit on this app's bundled build.
 *
 * Usage:
 *   <input type="checkbox"
 *          data-controller="select-all"
 *          data-action="change->select-all#toggle"
 *          data-select-all-selector="input[name^='items['][type='checkbox']:not([disabled])">
 */
export default class extends Controller {
    static targets = ['__placeholder__'];

    connect() {
        // Defer the wire-up: in some builds `this.element` resolves to
        // undefined inside connect(); a microtask gives the runtime a
        // chance to finalise the controller instance.
        queueMicrotask(() => this.wireUp());
    }

    wireUp() {
        const master = this.masterElement();
        if (!master) {
            return;
        }
        this._master = master;
        this._boundUpdate = () => this.updateMasterState();
        this.scope().forEach((cb) => cb.addEventListener('change', this._boundUpdate));
        this.updateMasterState();
    }

    disconnect() {
        if (this._boundUpdate) {
            this.scope().forEach((cb) => cb.removeEventListener('change', this._boundUpdate));
        }
    }

    toggle(event) {
        const master = event.currentTarget;
        const checked = master.checked;
        this.scope(master).forEach((cb) => {
            cb.checked = checked;
        });
    }

    updateMasterState() {
        const master = this._master;
        if (!master) return;
        const boxes = this.scope(master);
        if (boxes.length === 0) {
            master.checked = false;
            master.indeterminate = false;
            return;
        }
        const checkedCount = boxes.filter((cb) => cb.checked).length;
        if (checkedCount === 0) {
            master.checked = false;
            master.indeterminate = false;
        } else if (checkedCount === boxes.length) {
            master.checked = true;
            master.indeterminate = false;
        } else {
            master.checked = false;
            master.indeterminate = true;
        }
    }

    masterElement() {
        try {
            if (this.element) return this.element;
        } catch (_e) { /* getter throws in some builds */ }
        if (this.context && this.context.element) return this.context.element;
        // Last resort: find the only element with our controller marker.
        return document.querySelector('[data-controller~="select-all"]');
    }

    scope(masterArg) {
        const master = masterArg || this._master || this.masterElement();
        if (!master) return [];
        const root = master.closest('form') || document;
        const selector = master.dataset.selectAllSelector || 'input[type="checkbox"]';
        return Array.from(root.querySelectorAll(selector)).filter((cb) => cb !== master);
    }
}
