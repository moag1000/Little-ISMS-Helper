import { Controller } from '@hotwired/stimulus';

/**
 * Audit Subsidiaries Controller
 *
 * Shows/hides the subsidiaries container based on the scopeType select value.
 * When "corporate_wide" is selected all subsidiary options are auto-selected.
 * When a non-corporate scope is selected the container is hidden and all
 * subsidiary options are deselected.
 *
 * The controller locates its key elements via the data attributes already
 * emitted by InternalAuditType rather than Stimulus targets:
 *   - scopeType select:           [data-corporate-scope]
 *   - subsidiaries container:     #subsidiaries-container  (or data-audit-subsidiaries-target="container")
 *   - subsidiaries multi-select:  [data-corporate-subsidiaries]
 *
 * Values:
 *   - corporateScopes (Array) — scope values that show the container
 *                               (defaults to ['corporate_wide','corporate_subsidiaries'])
 */
export default class extends Controller {
    static targets = ['container'];
    static values = {
        corporateScopes: { type: Array, default: ['corporate_wide', 'corporate_subsidiaries'] }
    };

    connect() {
        this._scopeSelect = this.element.querySelector('[data-corporate-scope]');
        this._subsidiariesSelect = this.element.querySelector('[data-corporate-subsidiaries]');

        if (!this._scopeSelect) {
            return;
        }

        this._scopeSelect.addEventListener('change', () => this.toggle());
        this.toggle();
    }

    toggle() {
        const selected = this._scopeSelect ? this._scopeSelect.value : '';
        const isCorporate = this.corporateScopesValue.includes(selected);
        const container = this.hasContainerTarget
            ? this.containerTarget
            : this.element.querySelector('#subsidiaries-container');

        if (!container) {
            return;
        }

        if (isCorporate) {
            container.style.display = 'block';

            // Auto-select all options for corporate_wide
            if (selected === 'corporate_wide' && this._subsidiariesSelect) {
                Array.from(this._subsidiariesSelect.options).forEach(opt => {
                    opt.selected = true;
                });
            }
        } else {
            container.style.display = 'none';

            // Deselect all when hiding
            if (this._subsidiariesSelect) {
                Array.from(this._subsidiariesSelect.options).forEach(opt => {
                    opt.selected = false;
                });
            }
        }
    }
}
