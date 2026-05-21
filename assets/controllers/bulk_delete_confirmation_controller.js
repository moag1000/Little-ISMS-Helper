import { Controller } from '@hotwired/stimulus';

/**
 * Bulk Delete Confirmation Controller
 * Manages the bulk delete confirmation modal with accessibility features
 *
 * Features:
 * - WCAG 2.1 AA compliant
 * - Shows dependencies and impact analysis
 * - Keyboard navigable
 * - Promise-based API for easy integration
 *
 * Usage:
 * const confirmed = await this.application.getControllerForElementAndIdentifier(
 *     document.getElementById('bulkDeleteModal'),
 *     'bulk-delete-confirmation'
 * ).show({
 *     count: 5,
 *     entityLabel: 'Assets',
 *     endpoint: '/assets/bulk-delete-check',
 *     ids: [1, 2, 3, 4, 5]
 * });
 */
export default class extends Controller {
    static targets = [
        'modal',
        'message',
        'count',
        'entityLabel',
        'dependencies',
        'dependencyList',
        'loading',
        'error',
        'errorMessage',
        'confirmButton',
        'cancelButton'
    ];

    connect() {
        this.resolvePromise = null;
        this.rejectPromise = null;
        this.previousFocus = null;

        this.boundOnFaModalClosed = this.onFaModalClosed.bind(this);
        this.element.addEventListener('fa-modal:closed', this.boundOnFaModalClosed);
    }

    disconnect() {
        this.element.removeEventListener('fa-modal:closed', this.boundOnFaModalClosed);
    }

    onFaModalClosed() {
        if (this.resolvePromise) {
            this.resolvePromise(false);
            this.cleanup();
        }
    }

    /**
     * Show the confirmation modal
     * @param {Object} options Configuration options
     * @param {number} options.count Number of items to delete
     * @param {string} options.entityLabel Label for the entity type (e.g., 'Assets', 'Risks')
     * @param {string} options.endpoint API endpoint to check dependencies
     * @param {Array} options.ids Array of IDs to delete
     * @param {string} [options.message] Custom confirmation message
     * @returns {Promise<boolean>} Resolves to true if confirmed, false if cancelled
     */
    async show(options) {
        const {
            count,
            entityLabel,
            endpoint,
            ids,
            message
        } = options;

        // Store current focus to restore later
        this.previousFocus = document.activeElement;

        // Update modal content
        this.countTarget.textContent = count;
        this.entityLabelTarget.textContent = entityLabel;

        if (message) {
            this.messageTarget.textContent = message;
        } else {
            this.messageTarget.textContent = this.getDefaultMessage(count, entityLabel);
        }

        // Reset state
        this.hideError();
        this.hideDependencies();

        // Open fa-modal shell via dispatch
        document.dispatchEvent(new CustomEvent('fa-modal:request-open', {
            bubbles: true,
            detail: { id: this.modalTarget.id },
        }));

        // Check dependencies if endpoint provided
        if (endpoint && ids && ids.length > 0) {
            this.showLoading();
            try {
                const dependencies = await this.checkDependencies(endpoint, ids);
                this.hideLoading();

                if (dependencies && dependencies.length > 0) {
                    this.showDependencies(dependencies);
                }
            } catch (error) {
                this.hideLoading();
                this.showError('Fehler beim Laden der Abhängigkeiten: ' + error.message);
            }
        }

        // Focus confirm button for keyboard navigation
        setTimeout(() => {
            this.confirmButtonTarget.focus();
        }, 100);

        // Cancel-resolution happens via the fa-modal:closed listener bound in connect().
        return new Promise((resolve, reject) => {
            this.resolvePromise = resolve;
            this.rejectPromise = reject;
        });
    }

    /**
     * Check dependencies via API
     */
    async checkDependencies(endpoint, ids) {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ ids })
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();
        return data.dependencies || [];
    }

    /**
     * Show dependencies list
     */
    showDependencies(dependencies) {
        this.dependenciesTarget.classList.remove('d-none');

        // Clear existing list
        this.dependencyListTarget.innerHTML = '';

        // Add dependencies
        dependencies.forEach(dep => {
            const li = document.createElement('li');
            li.className = 'd-flex align-items-center gap-2 mb-1';

            const icon = document.createElement('i');
            icon.className = `bi bi-${dep.icon || 'link-45deg'} text-warning`;
            icon.setAttribute('aria-hidden', 'true');

            const text = document.createElement('span');
            text.textContent = dep.message;

            li.appendChild(icon);
            li.appendChild(text);
            this.dependencyListTarget.appendChild(li);
        });
    }

    hideDependencies() {
        if (this.hasDependenciesTarget) {
            this.dependenciesTarget.classList.add('d-none');
        }
    }

    showLoading() {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.remove('d-none');
        }
    }

    hideLoading() {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.add('d-none');
        }
    }

    showError(message) {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.remove('d-none');
            this.errorMessageTarget.textContent = message;
        }
    }

    hideError() {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.add('d-none');
        }
    }

    confirm() {
        if (this.resolvePromise) {
            this.resolvePromise(true);
            this.cleanup();
        }
        this.closeModal();
    }

    cancel() {
        if (this.resolvePromise) {
            this.resolvePromise(false);
            this.cleanup();
        }
        this.closeModal();
    }

    closeModal() {
        const faModal = this.application.getControllerForElementAndIdentifier(
            this.modalTarget,
            'fa-modal',
        );
        faModal?.close();
    }

    cleanup() {
        this.resolvePromise = null;
        this.rejectPromise = null;

        // Restore focus
        if (this.previousFocus && this.previousFocus.focus) {
            this.previousFocus.focus();
        }
    }

    getDefaultMessage(count, entityLabel) {
        const label = entityLabel.toLowerCase();
        return `Möchten Sie wirklich ${count} ${label} löschen?`;
    }

    // Keyboard accessibility
    handleKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.confirm();
        }
    }
}
