import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for tenant governance and corporate structure management.
 * Handles ISMS context inheritance and granular governance rules.
 */
export default class extends Controller {
    static values = {
        tenantId: Number,
        hasParent: Boolean,
        noContextText: String,
        inheritedFromText: String,
        ownContextText: String,
        loadErrorText: String,
        confirmPropagateText: String,
        propagateErrorText: String,
        noRulesText: String,
        saveErrorText: String,
        confirmDeleteText: String,
        deleteErrorText: String,
        organizationLabel: String
    }

    static targets = [
        'effectiveContextContainer',
        'governanceRulesContainer',
        'ruleModal',
        'ruleScope',
        'ruleScopeId',
        'ruleGovernance'
    ]

    connect() {
        this.loadEffectiveContext();
        if (this.hasParentValue) {
            this.loadGovernanceRules();
        }
    }

    /**
     * Load effective ISMS context via API
     */
    loadEffectiveContext() {
        if (!this.hasEffectiveContextContainerTarget) return;

        fetch(`/api/corporate-structure/effective-context/${this.tenantIdValue}`)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    this.effectiveContextContainerTarget.innerHTML = `
                        <div class="alert alert-warning small mb-0">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            ${this.escapeHtml(this.noContextTextValue)}
                        </div>`;
                    return;
                }

                let html = '<div class="card border-info">';
                html += '<div class="card-body p-3">';
                html += `<p class="mb-2"><strong>${this.escapeHtml(this.organizationLabelValue)}:</strong> ${this.escapeHtml(data.context.organizationName)}</p>`;

                if (data.context.isInherited) {
                    html += '<div class="alert alert-info small mb-0">';
                    html += '<i class="bi bi-arrow-up-right" aria-hidden="true"></i> ';
                    html += `${this.escapeHtml(this.inheritedFromTextValue)}: `;
                    html += `<a href="/admin/tenants/${data.context.inheritedFrom.id}" class="alert-link">${this.escapeHtml(data.context.inheritedFrom.name)}</a>`;
                    html += '</div>';
                } else {
                    html += '<div class="alert alert-success small mb-0">';
                    html += `<i class="bi bi-check-circle" aria-hidden="true"></i> ${this.escapeHtml(this.ownContextTextValue)}`;
                    html += '</div>';
                }

                html += '</div></div>';
                this.effectiveContextContainerTarget.innerHTML = html;
            })
            .catch(err => {
                this.effectiveContextContainerTarget.innerHTML = `
                    <div class="alert alert-danger small mb-0">${this.escapeHtml(this.loadErrorTextValue)}</div>`;
            });
    }

    /**
     * Propagate context changes to subsidiaries
     */
    propagateContext(event) {
        event.preventDefault();

        if (!confirm(this.confirmPropagateTextValue)) {
            return;
        }

        fetch(`/api/corporate-structure/propagate-context/${this.tenantIdValue}`, {
            method: 'POST'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                this.loadEffectiveContext();
            } else {
                alert(this.propagateErrorTextValue);
            }
        })
        .catch(err => {
            alert(this.propagateErrorTextValue);
        });
    }

    /**
     * Load granular governance rules via API
     */
    loadGovernanceRules() {
        if (!this.hasGovernanceRulesContainerTarget) return;

        fetch(`/api/corporate-structure/${this.tenantIdValue}/governance`)
            .then(r => r.json())
            .then(data => {
                if (data.rules.length === 0) {
                    this.governanceRulesContainerTarget.innerHTML = `
                        <p class="text-muted small">${this.escapeHtml(this.noRulesTextValue)}</p>`;
                    return;
                }

                let html = '<div class="list-group list-group-flush">';
                data.rules.forEach(rule => {
                    const badgeColor = rule.governanceModel === 'hierarchical' ? 'primary' :
                                      (rule.governanceModel === 'shared' ? 'warning' : 'secondary');

                    html += `
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${this.escapeHtml(rule.scope)}</strong>${rule.scopeId ? ': ' + this.escapeHtml(rule.scopeId) : ''}
                                <br><span class="badge bg-${badgeColor}">${this.escapeHtml(rule.governanceLabel)}</span>
                            </div>
                            <button class="btn btn-sm btn-danger"
                                    data-action="tenant-governance#deleteRule"
                                    data-rule-id="${rule.id}"
                                    data-rule-scope="${this.escapeHtml(rule.scope)}"
                                    data-rule-scope-id="${rule.scopeId ? this.escapeHtml(rule.scopeId) : ''}">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        </div>`;
                });
                html += '</div>';
                this.governanceRulesContainerTarget.innerHTML = html;
            })
            .catch(err => {
                this.governanceRulesContainerTarget.innerHTML = `
                    <div class="alert alert-danger small mb-0">${this.escapeHtml(this.saveErrorTextValue)}</div>`;
            });
    }

    /**
     * Show the Add Governance Rule modal
     */
    showAddRuleModal(event) {
        event.preventDefault();

        if (!this.hasRuleModalTarget || !window.bootstrap) return;

        // Reset form fields
        if (this.hasRuleScopeTarget) this.ruleScopeTarget.value = 'control';
        if (this.hasRuleScopeIdTarget) this.ruleScopeIdTarget.value = '';
        if (this.hasRuleGovernanceTarget) this.ruleGovernanceTarget.value = 'hierarchical';

        const modal = new window.bootstrap.Modal(this.ruleModalTarget);
        modal.show();
    }

    /**
     * Save a new governance rule
     */
    saveGovernanceRule(event) {
        event.preventDefault();

        const scope = this.ruleScopeTarget?.value;
        const scopeId = this.ruleScopeIdTarget?.value || null;
        const governanceModel = this.ruleGovernanceTarget?.value;

        if (!scope || !governanceModel) return;

        fetch(`/api/corporate-structure/${this.tenantIdValue}/governance/${scope}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ scopeId, governanceModel })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Hide modal
                const modalInstance = window.bootstrap.Modal.getInstance(this.ruleModalTarget);
                if (modalInstance) modalInstance.hide();
                this.loadGovernanceRules();
            } else {
                alert(this.saveErrorTextValue);
            }
        })
        .catch(err => {
            alert(this.saveErrorTextValue);
        });
    }

    /**
     * Delete a governance rule
     */
    deleteRule(event) {
        event.preventDefault();

        if (!confirm(this.confirmDeleteTextValue)) {
            return;
        }

        const button = event.currentTarget;
        const scope = button.dataset.ruleScope;
        const scopeId = button.dataset.ruleScopeId || 'null';

        const url = `/api/corporate-structure/${this.tenantIdValue}/governance/${scope}/${scopeId}`;

        fetch(url, { method: 'DELETE' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    this.loadGovernanceRules();
                } else {
                    alert(this.deleteErrorTextValue);
                }
            })
            .catch(err => {
                alert(this.deleteErrorTextValue);
            });
    }

    /**
     * Helper: Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
