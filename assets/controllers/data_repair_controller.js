import { Controller } from '@hotwired/stimulus';

/**
 * Data Repair Controller
 *
 * Handles data repair operations like fixing orphaned entities.
 *
 * Usage:
 * <div data-controller="data-repair"
 *      data-data-repair-base-url-value="/admin/data-repair/fix/0"
 *      data-data-repair-confirm-text-value="Are you sure?">
 *     <select data-data-repair-target="tenantSelect">...</select>
 *     <form data-data-repair-target="form">...</form>
 *     <button data-action="data-repair#fixAllOrphans">Fix</button>
 * </div>
 */
export default class extends Controller {
    static targets = ['tenantSelect', 'form'];

    static values = {
        baseUrl: String,
        confirmText: { type: String, default: 'Are you sure?' },
        selectTenantText: { type: String, default: 'Please select a tenant first' }
    };

    /**
     * Fix all orphaned entities
     */
    fixAllOrphans(event) {
        event.preventDefault();

        const tenantId = this.tenantSelectTarget.value;

        if (!tenantId) {
            alert(this.selectTenantTextValue);
            return;
        }

        const tenantName = this.tenantSelectTarget.options[this.tenantSelectTarget.selectedIndex].text;
        const confirmMessage = `${this.confirmTextValue} "${tenantName}"?`;

        if (confirm(confirmMessage)) {
            // Update form action with selected tenant ID
            const url = this.baseUrlValue.replace('/0', '/' + tenantId);
            this.formTarget.action = url;
            this.formTarget.submit();
        }
    }
}
