import { Controller } from '@hotwired/stimulus';

/**
 * Tenant ISMS Context Controller
 *
 * Handles lazy loading of ISMS context on the tenant show page.
 *
 * Usage:
 * <div data-controller="tenant-isms-context"
 *      data-tenant-isms-context-api-url-value="/api/tenant/1/isms-context"
 *      data-tenant-isms-context-edit-url-value="/context/edit">
 *     <div data-tenant-isms-context-target="container">Loading...</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['container', 'tab'];

    static values = {
        apiUrl: String,
        editUrl: String,
        contextViewUrl: { type: String, default: '/context' },
        // Translation strings
        notConfiguredTitle: { type: String, default: 'No ISMS context configured' },
        notConfiguredText: { type: String, default: 'Configure the ISMS context to define your security management scope.' },
        configureNowText: { type: String, default: 'Configure Now' },
        completenessLabel: { type: String, default: 'Completeness' },
        editText: { type: String, default: 'Edit' },
        inheritedAlertTitle: { type: String, default: 'ISMS context inherited from corporate parent' },
        inheritedFromText: { type: String, default: 'From' },
        inheritedEditNote: { type: String, default: 'Edit at parent tenant' },
        cannotEditText: { type: String, default: 'Cannot edit inherited context' },
        viewFullText: { type: String, default: 'View Full Context' },
        loadErrorText: { type: String, default: 'Error loading ISMS context' },
        retryText: { type: String, default: 'Retry' },
        loadingText: { type: String, default: 'Loading...' },
        // Field labels
        fieldOrganizationName: { type: String, default: 'Organization Name' },
        fieldIsmsScope: { type: String, default: 'ISMS Scope' },
        fieldScopeExclusions: { type: String, default: 'Scope Exclusions' },
        fieldExternalIssues: { type: String, default: 'External Issues' },
        fieldInternalIssues: { type: String, default: 'Internal Issues' },
        fieldInterestedParties: { type: String, default: 'Interested Parties' },
        fieldLegalRequirements: { type: String, default: 'Legal Requirements' },
        fieldIsmsPolicy: { type: String, default: 'ISMS Policy' },
        fieldRolesAndResponsibilities: { type: String, default: 'Roles and Responsibilities' }
    };

    connect() {
        this.loaded = false;

        // Listen for tab activation if we have a tab target
        if (this.hasTabTarget) {
            this.tabTarget.addEventListener('shown.bs.tab', () => {
                if (!this.loaded) {
                    this.load();
                }
            });
        }
    }

    /**
     * Load the ISMS context
     */
    async load() {
        if (!this.hasContainerTarget) return;

        this.containerTarget.innerHTML = `
            <div class="text-center text-muted py-5">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">${this.loadingTextValue}</span>
                </div>
                <p class="mt-2">${this.loadingTextValue}</p>
            </div>
        `;

        try {
            const response = await fetch(this.apiUrlValue);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const data = await response.json();

            if (data.error) {
                this.showNotConfigured(data.editUrl);
                return;
            }

            this.renderContext(data);
            this.loaded = true;
        } catch (error) {
            this.showError(error.message);
        }
    }

    /**
     * Show not configured state
     */
    showNotConfigured(editUrl) {
        const url = editUrl || this.editUrlValue;
        this.containerTarget.innerHTML = `
            <div class="alert alert-info">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <strong>${this.notConfiguredTitleValue}</strong><br>
                <p class="mb-0 mt-2">${this.notConfiguredTextValue}</p>
                <a href="${this.escapeHtml(url)}" class="btn btn-primary mt-3">
                    <i class="bi bi-plus-circle" aria-hidden="true"></i> ${this.configureNowTextValue}
                </a>
            </div>
        `;
    }

    /**
     * Show error state
     */
    showError(message) {
        let errorHtml = this.loadErrorTextValue;
        if (message) {
            errorHtml += `<br><small class="text-muted">${this.escapeHtml(message)}</small>`;
        }

        this.containerTarget.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                ${errorHtml}
                <br><br>
                <button class="btn btn-sm btn-outline-danger" data-action="tenant-isms-context#load">
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> ${this.retryTextValue}
                </button>
            </div>
        `;
    }

    /**
     * Render the context data
     */
    renderContext(data) {
        const context = data.context;
        const inheritanceInfo = data.inheritanceInfo || {};
        const completeness = parseInt(data.completeness) || 0;
        const canEdit = data.canEdit !== false;

        let html = '';

        // Corporate Inheritance Alert
        if (inheritanceInfo.isInherited) {
            const parentId = parseInt(inheritanceInfo.inheritedFrom?.id) || 0;
            const parentName = this.escapeHtml(inheritanceInfo.inheritedFrom?.name || 'Parent');
            html += `
                <div class="alert alert-warning mb-4">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-diagram-3 me-2 mt-1" aria-hidden="true"></i>
                        <div>
                            <strong>${this.inheritedAlertTitleValue}</strong><br>
                            <span class="small">
                                ${this.inheritedFromTextValue}:
                                <a href="/admin/tenants/${parentId}" class="alert-link">${parentName}</a>
                            </span><br>
                            <span class="small text-muted">${this.inheritedEditNoteValue}</span>
                        </div>
                    </div>
                </div>
            `;
        }

        // Completeness Card
        const progressClass = completeness >= 80 ? 'bg-success' :
            completeness >= 50 ? 'bg-warning' : 'bg-danger';

        html += `
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-2">${this.completenessLabelValue}</h6>
                            <h2 class="mb-0">${completeness}%</h2>
                        </div>
                        <div>
                            ${canEdit ?
                                `<a href="${this.escapeHtml(data.editUrl || this.editUrlValue)}" class="btn btn-primary">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> ${this.editTextValue}
                                </a>` :
                                `<button class="btn btn-secondary" disabled title="${this.cannotEditTextValue}">
                                    <i class="bi bi-lock" aria-hidden="true"></i> ${this.editTextValue}
                                </button>`
                            }
                        </div>
                    </div>
                    <div class="progress mt-2" style="height: 8px;">
                        <div class="progress-bar ${progressClass}" style="width: ${completeness}%"></div>
                    </div>
                </div>
            </div>
        `;

        // Context fields
        const fields = [
            { key: 'organizationName', label: this.fieldOrganizationNameValue, icon: 'building' },
            { key: 'ismsScope', label: this.fieldIsmsScopeValue, icon: 'bullseye', multiline: true },
            { key: 'scopeExclusions', label: this.fieldScopeExclusionsValue, icon: 'x-circle', multiline: true },
            { key: 'externalIssues', label: this.fieldExternalIssuesValue, icon: 'globe', multiline: true },
            { key: 'internalIssues', label: this.fieldInternalIssuesValue, icon: 'house', multiline: true },
            { key: 'interestedParties', label: this.fieldInterestedPartiesValue, icon: 'people', multiline: true },
            { key: 'legalRequirements', label: this.fieldLegalRequirementsValue, icon: 'file-text', multiline: true },
            { key: 'ismsPolicy', label: this.fieldIsmsPolicyValue, icon: 'shield-check', multiline: true },
            { key: 'rolesAndResponsibilities', label: this.fieldRolesAndResponsibilitiesValue, icon: 'person-badge', multiline: true }
        ];

        html += '<div class="row">';
        fields.forEach(field => {
            const value = context[field.key];
            if (value) {
                const displayValue = field.multiline && value.length > 200 ?
                    value.substring(0, 200) + '...' : value;

                html += `
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="text-muted mb-2">
                                    <i class="bi bi-${field.icon}" aria-hidden="true"></i> ${field.label}
                                </h6>
                                <p class="mb-0">${this.nl2br(displayValue)}</p>
                            </div>
                        </div>
                    </div>
                `;
            }
        });
        html += '</div>';

        // Link to full context view
        html += `
            <div class="text-center mt-4">
                <a href="${this.contextViewUrlValue}" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i> ${this.viewFullTextValue}
                </a>
            </div>
        `;

        this.containerTarget.innerHTML = html;
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Convert newlines to <br> after escaping
     */
    nl2br(text) {
        return this.escapeHtml(text).replace(/\n/g, '<br>');
    }
}
