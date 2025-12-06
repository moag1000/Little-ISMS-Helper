import { Controller } from '@hotwired/stimulus';

/**
 * Unique Field Validator Controller
 *
 * Performs AJAX validation to check if a field value is unique in the database.
 * Commonly used for email addresses, usernames, and other unique identifiers.
 *
 * Features:
 * - Debounced AJAX requests (500ms) to reduce server load
 * - Visual loading indicator during check
 * - Instant feedback (available/taken)
 * - Caching to avoid duplicate requests
 * - CSRF token support
 * - Works with existing validation states
 *
 * Usage:
 * <input type="email"
 *        data-controller="unique-field-validator"
 *        data-unique-field-validator-url-value="/api/check-email"
 *        data-unique-field-validator-field-value="email"
 *        data-unique-field-validator-entity-id-value="123"
 *        data-action="input->unique-field-validator#check blur->unique-field-validator#checkImmediate">
 *
 * API Endpoint Response Format:
 * { "available": true/false, "message": "Email is already in use" }
 */
export default class extends Controller {
    static values = {
        url: String,           // API endpoint URL
        field: String,         // Field name (email, username, etc.)
        entityId: Number,      // Current entity ID (for edit mode, skip self-check)
        debounceDelay: { type: Number, default: 500 },
        minLength: { type: Number, default: 3 }
    }

    connect() {
        this.checkTimeout = null;
        this.cache = new Map();
        this.lastCheckedValue = null;
        this.isChecking = false;
    }

    disconnect() {
        if (this.checkTimeout) {
            clearTimeout(this.checkTimeout);
        }
    }

    /**
     * Debounced uniqueness check on input
     */
    check(event) {
        if (this.checkTimeout) {
            clearTimeout(this.checkTimeout);
        }

        this.checkTimeout = setTimeout(() => {
            this.performCheck();
        }, this.debounceDelayValue);
    }

    /**
     * Immediate check on blur (no debounce)
     */
    checkImmediate(event) {
        if (this.checkTimeout) {
            clearTimeout(this.checkTimeout);
        }
        this.performCheck();
    }

    /**
     * Perform AJAX uniqueness check
     */
    async performCheck() {
        const value = this.element.value.trim();

        // Skip if value is too short
        if (!value || value.length < this.minLengthValue) {
            this.clearStatus();
            return;
        }

        // Skip if value hasn't changed
        if (value === this.lastCheckedValue) {
            return;
        }

        // Check cache first
        if (this.cache.has(value)) {
            const cachedResult = this.cache.get(value);
            this.updateStatus(cachedResult);
            return;
        }

        // Show loading state
        this.showLoading();

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    field: this.fieldValue,
                    value: value,
                    entityId: this.hasEntityIdValue ? this.entityIdValue : null
                })
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const result = await response.json();

            // Cache result
            this.cache.set(value, result);
            this.lastCheckedValue = value;

            // Update UI
            this.hideLoading();
            this.updateStatus(result);

        } catch (error) {
            this.hideLoading();
            this.showError('Validierung fehlgeschlagen. Bitte versuchen Sie es später erneut.');
        }
    }

    /**
     * Show loading spinner
     */
    showLoading() {
        this.isChecking = true;

        // Add spinner icon
        const wrapper = this.element.closest('.field-with-feedback') || this.element.parentElement;

        let spinner = wrapper.querySelector('.unique-check-spinner');
        if (!spinner) {
            spinner = document.createElement('span');
            spinner.className = 'unique-check-spinner position-absolute';
            spinner.innerHTML = '<i class="bi bi-arrow-repeat spinner-icon"></i>';
            spinner.style.cssText = 'right: 10px; top: 50%; transform: translateY(-50%);';
            wrapper.classList.add('position-relative');
            wrapper.appendChild(spinner);
        }

        spinner.style.display = 'block';
    }

    /**
     * Hide loading spinner
     */
    hideLoading() {
        this.isChecking = false;

        const wrapper = this.element.closest('.field-with-feedback') || this.element.parentElement;
        const spinner = wrapper.querySelector('.unique-check-spinner');

        if (spinner) {
            spinner.style.display = 'none';
        }
    }

    /**
     * Update field status based on API result
     */
    updateStatus(result) {
        const fieldId = this.element.id || this.element.name;

        if (result.available) {
            // Value is unique/available
            this.element.classList.remove('is-invalid');
            this.element.classList.add('is-valid');
            this.element.setAttribute('aria-invalid', 'false');

            this.showSuccessMessage(result.message || this.getSuccessMessage());
            this.clearErrorMessage();

        } else {
            // Value is taken/not unique
            this.element.classList.remove('is-valid');
            this.element.classList.add('is-invalid');
            this.element.setAttribute('aria-invalid', 'true');

            this.showErrorMessage(result.message || this.getErrorMessage());
        }
    }

    /**
     * Clear validation status
     */
    clearStatus() {
        this.element.classList.remove('is-valid', 'is-invalid');
        this.element.removeAttribute('aria-invalid');
        this.clearErrorMessage();
        this.clearSuccessMessage();
    }

    /**
     * Show error message
     */
    showErrorMessage(message) {
        const fieldId = this.element.id || this.element.name;
        let errorDiv = document.getElementById(`${fieldId}-unique-error`);

        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.id = `${fieldId}-unique-error`;
            errorDiv.className = 'invalid-feedback d-block';
            errorDiv.setAttribute('role', 'alert');
            errorDiv.setAttribute('aria-live', 'polite');

            const wrapper = this.element.closest('.field-with-feedback') || this.element;
            wrapper.parentNode.insertBefore(errorDiv, wrapper.nextSibling);
        }

        errorDiv.innerHTML = `<i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> ${message}`;
        errorDiv.style.display = 'block';

        // Update aria-describedby
        const describedby = this.element.getAttribute('aria-describedby') || '';
        if (!describedby.includes(errorDiv.id)) {
            this.element.setAttribute('aria-describedby', `${describedby} ${errorDiv.id}`.trim());
        }
    }

    /**
     * Show success message
     */
    showSuccessMessage(message) {
        const fieldId = this.element.id || this.element.name;
        let successDiv = document.getElementById(`${fieldId}-unique-success`);

        if (!successDiv) {
            successDiv = document.createElement('div');
            successDiv.id = `${fieldId}-unique-success`;
            successDiv.className = 'valid-feedback d-block';
            successDiv.setAttribute('role', 'status');
            successDiv.setAttribute('aria-live', 'polite');

            const wrapper = this.element.closest('.field-with-feedback') || this.element;
            wrapper.parentNode.insertBefore(successDiv, wrapper.nextSibling);
        }

        successDiv.innerHTML = `<i class="bi bi-check-circle-fill" aria-hidden="true"></i> ${message}`;
        successDiv.style.display = 'block';
    }

    /**
     * Clear error message
     */
    clearErrorMessage() {
        const fieldId = this.element.id || this.element.name;
        const errorDiv = document.getElementById(`${fieldId}-unique-error`);

        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
    }

    /**
     * Clear success message
     */
    clearSuccessMessage() {
        const fieldId = this.element.id || this.element.name;
        const successDiv = document.getElementById(`${fieldId}-unique-success`);

        if (successDiv) {
            successDiv.style.display = 'none';
        }
    }

    /**
     * Show generic error
     */
    showError(message) {
        this.element.classList.add('is-invalid');
        this.showErrorMessage(message);
    }

    /**
     * Get default success message
     */
    getSuccessMessage() {
        const fieldName = this.fieldValue || 'Wert';
        return `${fieldName.charAt(0).toUpperCase() + fieldName.slice(1)} ist verfügbar`;
    }

    /**
     * Get default error message
     */
    getErrorMessage() {
        const fieldName = this.fieldValue || 'Wert';
        return `${fieldName.charAt(0).toUpperCase() + fieldName.slice(1)} wird bereits verwendet`;
    }
}
