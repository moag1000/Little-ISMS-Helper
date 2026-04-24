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
        expectedTotal: Number,
        confirmText: { type: String, default: 'Are you sure?' },
        selectTenantText: { type: String, default: 'Please select a tenant first' }
    };

    /**
     * Fix all orphaned entities
     */
    async fixAllOrphans(event) {
        event.preventDefault();

        const tenantId = this.tenantSelectTarget.value;

        if (!tenantId) {
            alert(this.selectTenantTextValue);
            return;
        }

        const tenantName = this.tenantSelectTarget.options[this.tenantSelectTarget.selectedIndex].text;
        const confirmMessage = `${this.confirmTextValue} "${tenantName}"?`;

        if (!confirm(confirmMessage)) {
            return;
        }

        // Backend-Guard: sha256(expectedTotal + '|' + tenantId) muss der bei
        // Render-Zeit gesehenen Orphan-Anzahl entsprechen (Drift-Schutz gegen
        // Double-Submit). Dieselbe Formel wie in fixAllOrphans() server-seitig.
        const total = this.hasExpectedTotalValue ? this.expectedTotalValue : 0;
        const hash = await this.sha256Hex(`${total}|${tenantId}`);
        let hashInput = this.formTarget.querySelector('input[name="confirm_hash"]');
        if (!hashInput) {
            hashInput = document.createElement('input');
            hashInput.type = 'hidden';
            hashInput.name = 'confirm_hash';
            this.formTarget.appendChild(hashInput);
        }
        hashInput.value = hash;

        const url = this.baseUrlValue.replace('/0', '/' + tenantId);
        this.formTarget.action = url;
        this.formTarget.submit();
    }

    async sha256Hex(input) {
        const bytes = new TextEncoder().encode(input);
        const buf = await crypto.subtle.digest('SHA-256', bytes);
        return Array.from(new Uint8Array(buf))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }
}
