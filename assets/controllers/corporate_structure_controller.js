import { Controller } from '@hotwired/stimulus';

/**
 * Corporate Structure Controller
 *
 * Handles corporate structure modal operations for set parent and update governance.
 *
 * Usage:
 * <div data-controller="corporate-structure">
 *     <button data-action="corporate-structure#showSetParentModal"
 *             data-tenant-id="123"
 *             data-tenant-name="My Tenant">Set Parent</button>
 * </div>
 */
export default class extends Controller {
    static targets = [
        'setParentModal',
        'subsidiaryId',
        'subsidiaryName',
        'parentId',
        'governanceModel',
        'governanceHelp',
        'setParentForm',
        'updateGovernanceModal',
        'governanceTenantId',
        'governanceTenantName',
        'updateGovernanceModel',
        'updateGovernanceHelp',
        'updateGovernanceForm'
    ];

    static values = {
        governanceDescriptions: Object,
        setParentUrlTemplate: { type: String, default: '' }
    };

    /**
     * Show set parent modal
     */
    showSetParentModal(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const tenantId = button.dataset.tenantId;
        const tenantName = button.dataset.tenantName;

        if (this.hasSubsidiaryIdTarget) {
            this.subsidiaryIdTarget.value = tenantId;
        }
        if (this.hasSubsidiaryNameTarget) {
            this.subsidiaryNameTarget.value = tenantName;
        }

        // Reset form
        if (this.hasParentIdTarget) {
            this.parentIdTarget.value = '';
        }
        if (this.hasGovernanceModelTarget) {
            this.governanceModelTarget.value = '';
        }
        if (this.hasGovernanceHelpTarget) {
            this.governanceHelpTarget.textContent = '';
        }

        // Update form action
        if (this.hasSetParentFormTarget && this.setParentUrlTemplateValue) {
            this.setParentFormTarget.action = this.setParentUrlTemplateValue.replace('__ID__', tenantId);
        }

        // Show modal
        if (window.bootstrap && this.hasSetParentModalTarget) {
            const modal = new window.bootstrap.Modal(this.setParentModalTarget);
            modal.show();
        }
    }

    /**
     * Show update governance modal
     */
    showUpdateGovernanceModal(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const tenantId = button.dataset.tenantId;
        const tenantName = button.dataset.tenantName;
        const currentGovernance = button.dataset.governanceModel;

        if (this.hasGovernanceTenantIdTarget) {
            this.governanceTenantIdTarget.value = tenantId;
        }
        if (this.hasGovernanceTenantNameTarget) {
            this.governanceTenantNameTarget.value = tenantName;
        }
        if (this.hasUpdateGovernanceModelTarget) {
            this.updateGovernanceModelTarget.value = currentGovernance || '';
            this.updateGovernanceDescription();
        }

        // Update form action
        if (this.hasUpdateGovernanceFormTarget && this.setParentUrlTemplateValue) {
            this.updateGovernanceFormTarget.action = this.setParentUrlTemplateValue.replace('__ID__', tenantId);
        }

        // Show modal
        if (window.bootstrap && this.hasUpdateGovernanceModalTarget) {
            const modal = new window.bootstrap.Modal(this.updateGovernanceModalTarget);
            modal.show();
        }
    }

    /**
     * Update governance description when model changes
     */
    updateGovernanceDescription(event) {
        const model = event ? event.target.value : (this.hasUpdateGovernanceModelTarget ? this.updateGovernanceModelTarget.value : '');
        const target = event?.target?.id === 'governanceModel' ? this.governanceHelpTarget : this.updateGovernanceHelpTarget;

        if (target && this.hasGovernanceDescriptionsValue) {
            target.textContent = this.governanceDescriptionsValue[model] || '';
        }
    }

    /**
     * Update set parent governance description
     */
    updateSetParentGovernanceDescription(event) {
        if (this.hasGovernanceHelpTarget && this.hasGovernanceDescriptionsValue) {
            const model = event.target.value;
            this.governanceHelpTarget.textContent = this.governanceDescriptionsValue[model] || '';
        }
    }
}
