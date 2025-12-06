import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for corporate structure modals
 * Handles showSetParentModal and showUpdateGovernanceModal
 */
export default class extends Controller {
    static values = {
        tenantId: Number,
        tenantName: String,
        governanceModel: String
    }

    /**
     * Show the Set Parent modal
     */
    showSetParent(event) {
        event.preventDefault();

        const tenantId = this.tenantIdValue;
        const tenantName = this.tenantNameValue;

        document.getElementById('subsidiaryId').value = tenantId;
        document.getElementById('subsidiaryName').value = tenantName;
        document.getElementById('setParentForm').action = `/admin/tenants/${tenantId}/set-parent`;

        // Remove the subsidiary from parent options
        const parentSelect = document.getElementById('parentId');
        Array.from(parentSelect.options).forEach(option => {
            option.disabled = option.value == tenantId;
        });

        const modalEl = document.getElementById('setParentModal');
        if (modalEl && window.bootstrap) {
            const modal = new window.bootstrap.Modal(modalEl);
            modal.show();
        }
    }

    /**
     * Show the Update Governance modal
     */
    showUpdateGovernance(event) {
        event.preventDefault();

        const tenantId = this.tenantIdValue;
        const tenantName = this.tenantNameValue;
        const currentGovernance = this.governanceModelValue;

        document.getElementById('governanceTenantId').value = tenantId;
        document.getElementById('governanceTenantName').value = tenantName;
        document.getElementById('updateGovernanceForm').action = `/admin/tenants/${tenantId}/update-governance`;

        // Set current governance as selected
        const select = document.getElementById('updateGovernanceModel');
        select.value = currentGovernance || '';

        // Update help text
        const helpText = document.getElementById('updateGovernanceHelp');
        if (window.governanceDescriptions) {
            helpText.textContent = window.governanceDescriptions[currentGovernance] || '';
        }

        const modalEl = document.getElementById('updateGovernanceModal');
        if (modalEl && window.bootstrap) {
            const modal = new window.bootstrap.Modal(modalEl);
            modal.show();
        }
    }
}
