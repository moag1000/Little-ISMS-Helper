import { Controller } from '@hotwired/stimulus';

/**
 * Incident Escalation Preview Controller
 *
 * Shows users what will happen BEFORE they create/update an incident,
 * so they understand workflow implications.
 *
 * Features:
 * - Real-time preview on severity/data breach changes
 * - Debounced AJAX calls (300ms)
 * - Loading states
 * - Error handling
 * - i18n via translations value
 *
 * Usage:
 * <div data-controller="incident-escalation-preview"
 *      data-incident-escalation-preview-preview-url-value="{{ path('app_incident_escalation_preview') }}"
 *      data-incident-escalation-preview-translations-value="{{ translations_json }}">
 *     <select data-incident-escalation-preview-target="severitySelect"></select>
 *     <input type="checkbox" data-incident-escalation-preview-target="breachCheckbox">
 *     <div data-incident-escalation-preview-target="previewPanel"></div>
 * </div>
 */
export default class extends Controller {
    static targets = [
        'severitySelect',
        'breachCheckbox',
        'previewPanel',
        'previewContent'
    ];

    static values = {
        previewUrl: String,
        translations: { type: Object, default: {} }
    };

    connect() {
        this.debounceTimer = null;
        this.isLoading = false;
    }

    disconnect() {
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }
    }

    /**
     * Get translated string with fallback
     */
    t(key, fallback) {
        return this.translationsValue[key] || fallback || key;
    }

    /**
     * Update preview when severity or breach status changes
     */
    updatePreview() {
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        this.debounceTimer = setTimeout(() => {
            this.fetchPreview();
        }, 300);
    }

    /**
     * Fetch preview data from API
     */
    async fetchPreview() {
        const severity = this.severitySelectTarget.value;

        // Handle data breach checkbox (can be radio buttons or checkbox)
        let dataBreachOccurred = false;
        if (this.hasBreachCheckboxTarget) {
            const breachRadios = document.querySelectorAll('input[name="incident[dataBreachOccurred]"]');
            if (breachRadios.length > 1) {
                const checkedRadio = Array.from(breachRadios).find(radio => radio.checked);
                dataBreachOccurred = checkedRadio && checkedRadio.value === '1';
            } else {
                dataBreachOccurred = this.breachCheckboxTarget.checked;
            }
        }

        // Hide preview for low severity without data breach
        if (severity === 'low' && !dataBreachOccurred) {
            this.hidePreview();
            return;
        }

        this.showLoading();

        try {
            const response = await fetch(this.previewUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    severity: severity,
                    dataBreachOccurred: dataBreachOccurred
                })
            });

            if (!response.ok) {
                throw new Error('Preview request failed');
            }

            const preview = await response.json();
            this.displayPreview(preview);
        } catch (error) {
            this.showError();
        }
    }

    /**
     * Display preview panel with data
     */
    displayPreview(preview) {
        if (!preview.will_escalate) {
            this.hidePreview();
            return;
        }

        this.previewPanelTarget.style.display = 'block';
        this.previewContentTarget.innerHTML = this.buildPreviewHtml(preview);
        this.isLoading = false;
    }

    /**
     * Build preview HTML
     */
    buildPreviewHtml(preview) {
        const levelBadgeClass = this.getEscalationLevelBadgeClass(preview.escalation_level);
        const levelText = this.getEscalationLevelText(preview.escalation_level);

        let html = `
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h3 class="h6 mb-0">
                        <i class="bi bi-clipboard-check" aria-hidden="true"></i>
                        ${this.escapeHtml(this.t('title', 'Escalation Preview'))}
                    </h3>
                </div>
                <div class="card-body">
        `;

        // Workflow Info
        if (preview.workflow_name) {
            html += `
                <div class="mb-3">
                    <h4 class="h6 mb-2">
                        <i class="bi bi-diagram-3" aria-hidden="true"></i>
                        ${this.escapeHtml(this.t('workflow_name', 'Workflow'))}
                    </h4>
                    <div class="d-flex align-items-center gap-2">
                        <strong>${this.escapeHtml(preview.workflow_name)}</strong>
                        <span class="badge ${levelBadgeClass}">${this.escapeHtml(levelText)}</span>
                        <span class="badge bg-primary">
                            <i class="bi bi-lightning-fill" aria-hidden="true"></i>
                            ${this.escapeHtml(this.t('automatic', 'Automatic'))}
                        </span>
                    </div>
                </div>
            `;
        }

        // Notification List
        if (preview.notified_users && preview.notified_users.length > 0) {
            html += `
                <div class="mb-3">
                    <h4 class="h6 mb-2">
                        <i class="bi bi-people" aria-hidden="true"></i>
                        ${this.escapeHtml(this.t('notified_users', 'Who will be notified:'))}
                    </h4>
                    <ul class="list-unstyled mb-0">
            `;

            preview.notified_users.forEach(user => {
                html += `
                    <li class="mb-2">
                        <div class="d-flex align-items-center gap-2">
                            ${user.avatar ? `<img src="${user.avatar}" alt="${this.escapeHtml(user.name)}" class="rounded-circle" width="32" height="32">` : '<i class="bi bi-person-circle" style="font-size: 32px;" aria-hidden="true"></i>'}
                            <div>
                                <strong>${this.escapeHtml(user.name)}</strong>
                                <br>
                                <small class="text-muted">${this.escapeHtml(user.email)}</small>
                            </div>
                        </div>
                    </li>
                `;
            });

            html += `
                    </ul>
                    <p class="mb-0 mt-2">
                        <span class="badge bg-info">
                            ${preview.notified_users.length} ${this.escapeHtml(this.t('emails', 'emails will be sent'))}
                        </span>
                    </p>
                </div>
            `;
        }

        // SLA Info
        html += `
            <div class="mb-3">
                <h4 class="h6 mb-2">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    ${this.escapeHtml(this.t('sla_requirement', 'SLA Requirement'))}
                </h4>
                <div class="alert alert-info mb-0" role="alert">
                    <strong>${this.escapeHtml(preview.sla_description)}</strong>
                </div>
            </div>
        `;

        // GDPR Section (if breach)
        if (preview.is_gdpr_breach && preview.gdpr_deadline) {
            html += `
                <div class="mb-3">
                    <div class="alert alert-danger" role="alert">
                        <h4 class="alert-heading h6">
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                            ${this.escapeHtml(this.t('gdpr_warning', 'GDPR Data Breach - 72h Deadline'))}
                        </h4>
                        <p class="mb-2">
                            <strong>${this.escapeHtml(this.t('gdpr_deadline', 'Deadline:'))}</strong>
                            ${this.formatDateTime(preview.gdpr_deadline)}
                        </p>
                        <p class="mb-0 small">
                            ${this.escapeHtml(this.t('gdpr_reference', 'GDPR Art. 33 requires notification to supervisory authority within 72 hours of becoming aware of the breach.'))}
                        </p>
                    </div>
                </div>
            `;
        }

        // Approval Workflow (if applicable)
        if (preview.requires_approval && preview.approval_steps && preview.approval_steps.length > 0) {
            html += `
                <div class="mb-3">
                    <h4 class="h6 mb-2">
                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                        ${this.escapeHtml(this.t('approval_required', 'Approval Required'))}
                    </h4>
                    <ol class="mb-2">
            `;

            preview.approval_steps.forEach(step => {
                html += `
                    <li>
                        <strong>${this.escapeHtml(step.name)}</strong>
                        <br>
                        <small class="text-muted">${this.escapeHtml(step.role)}</small>
                    </li>
                `;
            });

            html += `
                    </ol>
                    <p class="mb-0">
                        <small>
                            <strong>${this.escapeHtml(this.t('estimated_time', 'Estimated Time:'))}</strong>
                            ${this.escapeHtml(preview.estimated_completion_time)}
                        </small>
                    </p>
                </div>
            `;
        }

        // Summary Box
        html += `
                    <div class="border-top pt-3">
                        <h4 class="h6 mb-2">
                            <i class="bi bi-check-circle" aria-hidden="true"></i>
                            ${this.escapeHtml(this.t('summary_title', 'What will happen:'))}
                        </h4>
                        <ul class="mb-0">
                            <li>
                                <i class="bi bi-check text-success" aria-hidden="true"></i>
                                ${this.escapeHtml(this.t('action_workflow_started', 'Workflow will start automatically'))}
                            </li>
                            <li>
                                <i class="bi bi-check text-success" aria-hidden="true"></i>
                                ${this.escapeHtml(this.t('action_notifications_sent', 'Email notifications will be sent to stakeholders'))}
                            </li>
                            ${preview.requires_approval ? `
                            <li>
                                <i class="bi bi-check text-success" aria-hidden="true"></i>
                                ${this.escapeHtml(this.t('action_approval_required', 'Approval steps will be initiated'))}
                            </li>
                            ` : ''}
                            <li>
                                <i class="bi bi-check text-success" aria-hidden="true"></i>
                                ${this.escapeHtml(this.t('action_sla_tracked', 'SLA tracking will begin'))} (${preview.sla_hours}h)
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        `;

        return html;
    }

    /**
     * Show loading skeleton
     */
    showLoading() {
        this.isLoading = true;
        this.previewPanelTarget.style.display = 'block';

        this.previewContentTarget.innerHTML = `
            <div class="card border-secondary">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                            <span class="visually-hidden">${this.escapeHtml(this.t('loading', 'Loading...'))}</span>
                        </div>
                        <span>${this.escapeHtml(this.t('loading_preview', 'Loading escalation preview...'))}</span>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Show error message
     */
    showError() {
        this.isLoading = false;
        this.previewContentTarget.innerHTML = `
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                ${this.escapeHtml(this.t('error', 'Failed to load escalation preview. Please try again.'))}
            </div>
        `;
    }

    /**
     * Hide preview panel
     */
    hidePreview() {
        this.previewPanelTarget.style.display = 'none';
        this.isLoading = false;
    }

    /**
     * Get badge class for escalation level
     */
    getEscalationLevelBadgeClass(level) {
        const classes = {
            'data_breach': 'bg-danger',
            'critical': 'bg-dark',
            'high': 'bg-danger',
            'medium': 'bg-warning text-dark',
            'low': 'bg-info'
        };
        return classes[level] || 'bg-secondary';
    }

    /**
     * Get text for escalation level
     */
    getEscalationLevelText(level) {
        const key = 'level_' + level;
        const fallbacks = {
            'data_breach': 'Data Breach',
            'critical': 'Critical',
            'high': 'High',
            'medium': 'Medium',
            'low': 'Low'
        };
        return this.t(key, fallbacks[level] || level);
    }

    /**
     * Format datetime
     */
    formatDateTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString();
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
