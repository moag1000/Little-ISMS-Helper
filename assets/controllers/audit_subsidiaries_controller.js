import { Controller } from '@hotwired/stimulus';

/**
 * audit-subsidiaries — shows the "audited subsidiaries" picker on the audit
 * form only for group audits (scopeType corporate_wide / corporate_subsidiaries).
 * "Konzernweites Audit" preselects every subsidiary; any other scope clears
 * the selection so no hidden subsidiaries are saved.
 *
 * Markup (audit/new + audit/edit):
 *   <form data-controller="audit-subsidiaries">
 *     <select data-corporate-scope>…</select>                 (scopeType, InternalAuditType)
 *     <div class="d-none" data-audit-subsidiaries-target="container">
 *       <select multiple data-corporate-subsidiaries>…</select>
 *     </div>
 *   </form>
 */
export default class extends Controller {
    static targets = ['container'];

    connect() {
        this.scopeSelect = this.element.querySelector('[data-corporate-scope]');
        this.subsidiaries = this.element.querySelector('[data-corporate-subsidiaries]');
        if (!this.scopeSelect) {
            return;
        }
        this.onChange = () => this.toggle();
        this.scopeSelect.addEventListener('change', this.onChange);
        this.toggle();
    }

    disconnect() {
        this.scopeSelect?.removeEventListener('change', this.onChange);
    }

    toggle() {
        const value = this.scopeSelect.value;
        const corporate = value === 'corporate_wide' || value === 'corporate_subsidiaries';
        if (this.hasContainerTarget) {
            this.containerTarget.classList.toggle('d-none', !corporate);
        }
        if (!this.subsidiaries) {
            return;
        }
        if (value === 'corporate_wide' || !corporate) {
            Array.from(this.subsidiaries.options).forEach((option) => {
                option.selected = corporate;
            });
        }
    }
}
