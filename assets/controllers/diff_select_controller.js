import { Controller } from '@hotwired/stimulus';

/**
 * diff-select — Compliance-Wizard history diff form.
 *
 * Auto-submits the GET form on select change so the URL reflects the
 * chosen "from" / "to" snapshot pair. Falls back to manual submit if the
 * form has a visible submit button (currently it does — keep both paths).
 */
export default class extends Controller {
    connect() {
        this.element
            .querySelectorAll('select')
            .forEach((sel) => sel.addEventListener('change', this.maybeSubmit));
    }

    disconnect() {
        this.element
            .querySelectorAll('select')
            .forEach((sel) => sel.removeEventListener('change', this.maybeSubmit));
    }

    maybeSubmit = (event) => {
        const form = event.target.closest('form');
        if (!form) return;
        const fromSel = form.querySelector('select[name="from"]');
        const toSel = form.querySelector('select[name="to"]');
        // Only auto-submit once BOTH dropdowns have a value AND they differ.
        if (fromSel && toSel && fromSel.value && toSel.value && fromSel.value !== toSel.value) {
            form.requestSubmit?.() ?? form.submit();
        }
    };
}
