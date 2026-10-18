import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';
import Sortable from 'sortablejs';

/**
 * Workflow Builder Controller
 * Enables drag & drop workflow step management
 *
 * Usage:
 * <div data-controller="workflow-builder"
 *      data-workflow-builder-workflow-id-value="123"
 *      data-workflow-builder-api-url-value="/api/workflow">
 *   <div data-workflow-builder-target="stepsList"></div>
 *   <div data-workflow-builder-target="stepForm"></div>
 * </div>
 */
export default class extends Controller {
    static targets = ['stepsList', 'stepForm', 'stepTemplate', 'notification', 'loadingSpinner'];
    static values = {
        workflowId: Number,
        apiUrl: String,
        csrfToken: String,
        // Translation strings
        editTitle: { type: String, default: '' },
        duplicateTitle: { type: String, default: '' },
        deleteTitle: { type: String, default: '' }
    };

    /** Template-passed label value, else the translated faT() fallback. */
    label(name) {
        const fallback = {
            editTitle: () => faT('workflow_builder.edit'),
            duplicateTitle: () => faT('workflow_builder.duplicate'),
            deleteTitle: () => faT('workflow_builder.delete'),
        };
        return this[`${name}Value`] || fallback[name]();
    }

    connect() {
        this.reorderInProgress = false;
        this.loadSteps();
        this.initializeSortable();
    }

    getHeaders() {
        return {
            'Content-Type': 'application/json',
            'X-CSRF-Token': this.csrfTokenValue
        };
    }

    initializeSortable() {
        if (Sortable && this.hasStepsListTarget) {
            this.sortable = Sortable.create(this.stepsListTarget, {
                animation: 150,
                handle: '.drag-handle',
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass: 'sortable-drag',
                onEnd: (evt) => this.onReorder(evt)
            });
        }
    }

    async loadSteps() {
        this.showLoading();
        try {
            const response = await fetch(`${this.apiUrlValue}/${this.workflowIdValue}/steps`);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            let data;
            try {
                data = await response.json();
            } catch (jsonError) {
                this.showNotification(faT('workflow_builder.invalid_response'), 'error');
                return;
            }

            if (data && data.success) {
                this.renderSteps(data.steps || []);
            } else {
                this.showNotification(data?.error || faT('workflow_builder.load_failed'), 'error');
            }
        } catch (error) {
            this.showNotification(faT('workflow_builder.load_failed'), 'error');
        } finally {
            this.hideLoading();
        }
    }

    renderSteps(steps) {
        if (!this.hasStepsListTarget) return;

        if (steps.length === 0) {
            this.stepsListTarget.innerHTML = `
                <div class="text-center py-5 text-muted">
                    <i class="fa-icon fa-icon--nav-workflow fs-1 d-block mb-3"></i>
                    <p>${this.escapeHtml(faT('workflow_builder.empty'))}</p>
                </div>
            `;
            return;
        }

        this.stepsListTarget.innerHTML = steps.map((step, index) => this.renderStepCard(step, index)).join('');
    }

    renderStepCard(step, index) {
        const stepTypeIcon = this.getStepTypeIcon(step.stepType);
        const stepTypeBadge = this.getStepTypeBadge(step.stepType);
        const approverInfo = this.getApproverInfo(step);

        // Validate numeric values to prevent injection
        const stepId = parseInt(step.id, 10);
        const stepOrder = parseInt(step.stepOrder, 10);
        const daysToComplete = step.daysToComplete ? parseInt(step.daysToComplete, 10) : null;

        // Skip rendering if critical values are invalid
        if (isNaN(stepId) || isNaN(stepOrder)) {
            return '';
        }

        return `
            <div class="card mb-3 step-card" data-step-id="${stepId}" data-step-order="${stepOrder}">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="drag-handle me-3 cursor-grab text-muted">
                            <i class="fa-icon fa-icon--util-drag-handle fs-4"></i>
                        </div>
                        <div class="step-number me-3">
                            <span class="badge bg-primary rounded-pill fs-6">${index + 1}</span>
                        </div>
                        <div class="step-icon me-3">
                            <i class="fa-icon fa-icon--${stepTypeIcon} fs-4 text-${this.getStepTypeColor(step.stepType)}" aria-hidden="true"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="card-title mb-1">${this.escapeHtml(step.name || faT('workflow_builder.unnamed'))}</h5>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                ${stepTypeBadge}
                                ${step.isRequired ? `<span class="badge bg-warning text-dark">${this.escapeHtml(faT('workflow_builder.required'))}</span>` : `<span class="badge bg-secondary">${this.escapeHtml(faT('workflow_builder.optional'))}</span>`}
                                ${daysToComplete && !isNaN(daysToComplete) ? `<span class="badge bg-info"><i class="fa-icon fa-icon--ui-clock me-1"></i>${this.escapeHtml(faT('workflow_builder.sla_days', { '%days%': daysToComplete }))}</span>` : ''}
                            </div>
                            ${approverInfo}
                            ${step.description ? `<p class="card-text text-muted small mt-2">${this.escapeHtml(step.description)}</p>` : ''}
                        </div>
                        <div class="step-actions ms-3">
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary"
                                        data-action="click->workflow-builder#editStep"
                                        data-step-id="${stepId}"
                                        title="${this.label('editTitle')}">
                                    <i class="fa-icon fa-icon--ui-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-secondary"
                                        data-action="click->workflow-builder#duplicateStep"
                                        data-step-id="${stepId}"
                                        title="${this.label('duplicateTitle')}">
                                    <i class="fa-icon fa-icon--ui-copy"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger"
                                        data-action="click->workflow-builder#deleteStep"
                                        data-step-id="${stepId}"
                                        title="${this.label('deleteTitle')}">
                                    <i class="fa-icon fa-icon--ui-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    getStepTypeIcon(stepType) {
        // Aurora-namespaced icon names (see assets/styles/fairy-aurora-icons.css)
        const icons = {
            'approval': 'approve',
            'notification': 'bell',
            'auto_action': 'nav-gear'
        };
        return icons[stepType] || 'ui-circle';
    }

    getStepTypeColor(stepType) {
        const colors = {
            'approval': 'success',
            'notification': 'info',
            'auto_action': 'warning'
        };
        return colors[stepType] || 'secondary';
    }

    getStepTypeBadge(stepType) {
        const labels = {
            'approval': faT('workflow_builder.type.approval'),
            'notification': faT('workflow_builder.type.notification'),
            'auto_action': faT('workflow_builder.type.auto_action')
        };
        const color = this.getStepTypeColor(stepType);
        // Escape stepType if not in predefined labels to prevent XSS
        const label = labels[stepType] || this.escapeHtml(String(stepType));
        return `<span class="badge bg-${color}">${label}</span>`;
    }

    getApproverInfo(step) {
        let info = [];

        if (step.approverRole && typeof step.approverRole === 'string') {
            // Sanitize role name by only allowing alphanumeric characters and spaces
            const roleName = step.approverRole
                .replace('ROLE_', '')
                .replace(/_/g, ' ')
                .replace(/[^a-zA-Z0-9\s]/g, ''); // Remove any non-alphanumeric characters
            const escapedRoleName = this.escapeHtml(roleName);
            info.push(`<span class="badge bg-light text-dark"><i class="fa-icon fa-icon--role me-1"></i>${escapedRoleName}</span>`);
        }

        if (step.approverUsers && Array.isArray(step.approverUsers) && step.approverUsers.length > 0) {
            // Number is safe, but ensure it's actually a number
            const userCount = parseInt(step.approverUsers.length, 10);
            if (!isNaN(userCount)) {
                info.push(`<span class="badge bg-light text-dark"><i class="fa-icon fa-icon--nav-people me-1"></i>${this.escapeHtml(faT('workflow_builder.users', { '%count%': userCount }))}</span>`);
            }
        }

        return info.length > 0 ? `<div class="approver-info">${info.join(' ')}</div>` : '';
    }

    async onReorder(evt) {
        if (this.reorderInProgress) {
            return;
        }

        const stepCards = this.stepsListTarget.querySelectorAll('.step-card');
        const stepIds = Array.from(stepCards)
            .map(card => {
                const id = parseInt(card.dataset.stepId, 10);
                if (isNaN(id)) {
                    return null;
                }
                return id;
            })
            .filter(id => id !== null);

        if (stepIds.length !== stepCards.length) {
            this.showNotification(faT('workflow_builder.invalid_steps'), 'error');
            this.loadSteps();
            return;
        }

        this.reorderInProgress = true;
        try {
            const response = await fetch(`${this.apiUrlValue}/${this.workflowIdValue}/steps/reorder`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify({ stepIds })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            let data;
            try {
                data = await response.json();
            } catch (jsonError) {
                this.showNotification(faT('workflow_builder.invalid_response'), 'error');
                this.loadSteps();
                return;
            }

            if (data && data.success) {
                this.showNotification(faT('workflow_builder.reordered'), 'success');
                // Update step numbers in UI
                this.updateStepNumbers();
            } else {
                this.showNotification(data?.error || faT('workflow_builder.reorder_failed'), 'error');
                this.loadSteps(); // Reload to revert
            }
        } catch (error) {
            this.showNotification(faT('workflow_builder.reorder_failed'), 'error');
            this.loadSteps(); // Reload to revert
        } finally {
            this.reorderInProgress = false;
        }
    }

    updateStepNumbers() {
        const stepCards = this.stepsListTarget.querySelectorAll('.step-card');
        stepCards.forEach((card, index) => {
            const numberBadge = card.querySelector('.step-number .badge');
            if (numberBadge) {
                numberBadge.textContent = index + 1;
            }
        });
    }

    async addStep(event) {
        event.preventDefault();
        const form = event.target;
        const submitBtn = form.querySelector('[type="submit"]');

        // Disable button to prevent multiple submissions
        if (submitBtn) submitBtn.disabled = true;

        const formData = new FormData(form);
        const stepData = Object.fromEntries(formData);

        // Convert checkbox to boolean
        stepData.isRequired = formData.has('isRequired');

        // Convert approverUsers to array with NaN protection
        const userIds = formData.getAll('approverUsers')
            .map(id => parseInt(id, 10))
            .filter(id => !isNaN(id));
        stepData.approverUsers = userIds.length > 0 ? userIds : null;

        try {
            const response = await fetch(`${this.apiUrlValue}/${this.workflowIdValue}/steps`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify(stepData)
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            let data;
            try {
                data = await response.json();
            } catch (jsonError) {
                this.showNotification(faT('workflow_builder.invalid_response'), 'error');
                return;
            }

            if (data && data.success) {
                this.showNotification(faT('workflow_builder.added'), 'success');
                form.reset();
                this.loadSteps();
            } else {
                const errorMsg = data?.errors ? data.errors.join(', ') : (data?.error || faT('workflow_builder.add_failed'));
                this.showNotification(errorMsg, 'error');
            }
        } catch (error) {
            this.showNotification(faT('workflow_builder.add_failed'), 'error');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    async editStep(event) {
        const stepId = event.currentTarget.dataset.stepId;
        // Open edit modal or inline form
        this.showNotification(faT('workflow_builder.edit_unavailable', { '%id%': stepId }), 'info');
    }

    async duplicateStep(event) {
        const stepId = event.currentTarget.dataset.stepId;

        try {
            const response = await fetch(`${this.apiUrlValue}/step/${stepId}/duplicate`, {
                method: 'POST',
                headers: this.getHeaders()
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                this.showNotification(faT('workflow_builder.duplicated'), 'success');
                this.loadSteps();
            } else {
                this.showNotification(data.error || faT('workflow_builder.duplicate_failed'), 'error');
            }
        } catch (error) {
            this.showNotification(faT('workflow_builder.duplicate_failed'), 'error');
        }
    }

    async deleteStep(event) {
        const stepId = event.currentTarget.dataset.stepId;
        const button = event.currentTarget;

        if (!await window.faConfirm(faT('workflow_builder.confirm_delete'), { tone: 'danger' })) {
            return;
        }

        button.disabled = true;
        try {
            const response = await fetch(`${this.apiUrlValue}/step/${stepId}`, {
                method: 'DELETE',
                headers: this.getHeaders()
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            let data;
            try {
                data = await response.json();
            } catch (jsonError) {
                this.showNotification(faT('workflow_builder.invalid_response'), 'error');
                return;
            }

            if (data && data.success) {
                this.showNotification(faT('workflow_builder.deleted'), 'success');
                this.loadSteps();
            } else {
                this.showNotification(data?.error || faT('workflow_builder.delete_failed'), 'error');
            }
        } catch (error) {
            this.showNotification(faT('workflow_builder.delete_failed'), 'error');
        } finally {
            button.disabled = false;
        }
    }

    async applyTemplate(event) {
        const templateKey = event.currentTarget.dataset.templateKey;
        const clearExisting = await window.faConfirm(faT('workflow_builder.confirm_replace'), { tone: 'warn' });

        try {
            const response = await fetch(`${this.apiUrlValue}/${this.workflowIdValue}/apply-template`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify({ templateKey, clearExisting })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                this.showNotification(faT('workflow_builder.template_applied', { '%count%': data.stepsAdded }), 'success');
                this.loadSteps();
            } else {
                this.showNotification(data.error || faT('workflow_builder.template_failed'), 'error');
            }
        } catch (error) {
            this.showNotification(faT('workflow_builder.template_failed'), 'error');
        }
    }

    showNotification(message, type = 'info') {
        if (this.hasNotificationTarget) {
            const alertClass = {
                'success': 'alert-success',
                'error': 'alert-danger',
                'warning': 'alert-warning',
                'info': 'alert-info'
            }[type] || 'alert-info';

            this.notificationTarget.innerHTML = `
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="${this.escapeHtml(faT('close'))}"></button>
                </div>
            `;

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                const alert = this.notificationTarget.querySelector('.alert');
                if (alert) {
                    alert.remove();
                }
            }, 5000);
        }
    }

    showLoading() {
        if (this.hasLoadingSpinnerTarget) {
            this.loadingSpinnerTarget.classList.remove('d-none');
        }
    }

    hideLoading() {
        if (this.hasLoadingSpinnerTarget) {
            this.loadingSpinnerTarget.classList.add('d-none');
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
