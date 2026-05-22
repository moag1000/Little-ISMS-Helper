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
    async loadEffectiveContext() {
        if (!this.hasEffectiveContextContainerTarget) return;

        try {
            const r = await fetch(`/api/corporate-structure/effective-context/${this.tenantIdValue}`);

            if (!r.ok) {
                const msg = r.status === 403 ? 'Keine Berechtigung' : `Fehler ${r.status}`;
                window.faToast(msg, 'danger');
                this.effectiveContextContainerTarget.innerHTML = `
                    <div class="alert alert-danger small mb-0">${this.escapeHtml(this.loadErrorTextValue)}</div>`;
                return;
            }

            const data = await r.json();

            if (data.error) {
                this.effectiveContextContainerTarget.innerHTML = `
                    <div class="alert alert-warning small mb-0">
                        <i class="fa-icon fa-icon--status-warning" aria-hidden="true"></i>
                        ${this.escapeHtml(this.noContextTextValue)}
                    </div>`;
                return;
            }

            let html = '<div class="card border-info">';
            html += '<div class="card-body p-3">';
            html += `<p class="mb-2"><strong>${this.escapeHtml(this.organizationLabelValue)}:</strong> ${this.escapeHtml(data.context.organizationName)}</p>`;

            if (data.context.isInherited) {
                html += '<div class="alert alert-info small mb-0">';
                html += '<i class="fa-icon fa-icon--util-arrow-up" aria-hidden="true"></i> ';
                html += `${this.escapeHtml(this.inheritedFromTextValue)}: `;
                html += `<a href="/admin/tenants/${data.context.inheritedFrom.id}" class="alert-link">${this.escapeHtml(data.context.inheritedFrom.name)}</a>`;
                html += '</div>';
            } else {
                html += '<div class="alert alert-success small mb-0">';
                html += `<i class="fa-icon fa-icon--ui-check" aria-hidden="true"></i> ${this.escapeHtml(this.ownContextTextValue)}`;
                html += '</div>';
            }

            html += '</div></div>';
            this.effectiveContextContainerTarget.innerHTML = html;
        } catch (err) {
            this.effectiveContextContainerTarget.innerHTML = `
                <div class="alert alert-danger small mb-0">${this.escapeHtml(this.loadErrorTextValue)}</div>`;
        }
    }

    /**
     * Propagate context changes to subsidiaries
     */
    async propagateContext(event) {
        event.preventDefault();

        if (!await window.faConfirm(this.confirmPropagateTextValue, { tone: 'warn' })) {
            return;
        }

        fetch(`/api/corporate-structure/propagate-context/${this.tenantIdValue}`, {
            method: 'POST'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.faToast(data.message, 'success');
                this.loadEffectiveContext();
            } else {
                window.faToast(this.propagateErrorTextValue, 'danger');
            }
        })
        .catch(err => {
            window.faToast(this.propagateErrorTextValue, 'danger');
        });
    }

    /**
     * Load granular governance rules via API
     */
    async loadGovernanceRules() {
        if (!this.hasGovernanceRulesContainerTarget) return;

        try {
            const r = await fetch(`/api/corporate-structure/${this.tenantIdValue}/governance`);

            if (!r.ok) {
                const msg = r.status === 403 ? 'Keine Berechtigung' : `Fehler ${r.status}`;
                window.faToast(msg, 'danger');
                this.governanceRulesContainerTarget.innerHTML = `
                    <div class="alert alert-danger small mb-0">${this.escapeHtml(this.saveErrorTextValue)}</div>`;
                return;
            }

            const data = await r.json();

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
                            <i class="fa-icon fa-icon--ui-trash" aria-hidden="true"></i>
                        </button>
                    </div>`;
            });
            html += '</div>';
            this.governanceRulesContainerTarget.innerHTML = html;
        } catch (err) {
            this.governanceRulesContainerTarget.innerHTML = `
                <div class="alert alert-danger small mb-0">${this.escapeHtml(this.saveErrorTextValue)}</div>`;
        }
    }

    /**
     * Show the Add Governance Rule modal
     */
    showAddRuleModal(event) {
        event.preventDefault();

        if (!this.hasRuleModalTarget) return;

        // Reset form fields
        if (this.hasRuleScopeTarget) this.ruleScopeTarget.value = 'control';
        if (this.hasRuleScopeIdTarget) this.ruleScopeIdTarget.value = '';
        if (this.hasRuleGovernanceTarget) this.ruleGovernanceTarget.value = 'hierarchical';

        document.dispatchEvent(new CustomEvent('fa-modal:request-open', {
            bubbles: true,
            detail: { id: this.ruleModalTarget.id },
        }));
    }

    /**
     * Save a new governance rule
     */
    async saveGovernanceRule(event) {
        event.preventDefault();

        const scope = this.ruleScopeTarget?.value;
        const scopeId = this.ruleScopeIdTarget?.value || null;
        const governanceModel = this.ruleGovernanceTarget?.value;

        if (!scope || !governanceModel) return;

        try {
            const r = await fetch(`/api/corporate-structure/${this.tenantIdValue}/governance/${scope}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ scopeId, governanceModel })
            });

            if (!r.ok) {
                const msg = r.status === 403 ? 'Keine Berechtigung' : `Fehler ${r.status}`;
                window.faToast(msg, 'danger');
                return;
            }

            const data = await r.json();

            if (data.success) {
                // Hide fa-modal shell
                const faModal = this.application.getControllerForElementAndIdentifier(this.ruleModalTarget, 'fa-modal');
                faModal?.close();
                this.loadGovernanceRules();
            } else {
                window.faToast(this.saveErrorTextValue, 'danger');
            }
        } catch (err) {
            window.faToast(this.saveErrorTextValue, 'danger');
        }
    }

    /**
     * Delete a governance rule
     */
    async deleteRule(event) {
        event.preventDefault();

        if (!await window.faConfirm(this.confirmDeleteTextValue, { tone: 'danger' })) {
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
                    window.faToast(this.deleteErrorTextValue, 'danger');
                }
            })
            .catch(err => {
                window.faToast(this.deleteErrorTextValue, 'danger');
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
