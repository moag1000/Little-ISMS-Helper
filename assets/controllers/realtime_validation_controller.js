import { Controller } from '@hotwired/stimulus';

/**
 * Real-time Form Validation Controller
 *
 * Validates form fields as the user types, providing instant feedback
 * with visual indicators (checkmarks for valid, X for invalid).
 *
 * Features:
 * - Debounced validation (300ms) to avoid excessive checks while typing
 * - Visual feedback icons (✓ valid, ✗ invalid)
 * - HTML5 + custom validation rules
 * - ARIA announcements for screen readers
 * - Smooth animations and transitions
 *
 * Usage:
 * <input type="email"
 *        data-controller="realtime-validation"
 *        data-action="input->realtime-validation#validate blur->realtime-validation#validateImmediate"
 *        data-realtime-validation-rules-value='{"required": true, "minLength": 5}'>
 */
export default class extends Controller {
    static values = {
        debounceDelay: { type: Number, default: 300 },
        rules: { type: Object, default: {} }
    }

    connect() {
        this.validationTimeout = null;
        this.isValidated = false;

        // Add validation wrapper for feedback icon
        this.wrapField();

        // Validate on page load if field has value (server-side validation)
        if (this.element.value && this.element.classList.contains('is-invalid')) {
            this.showInvalid(this.getErrorMessage());
        }
    }

    disconnect() {
        if (this.validationTimeout) {
            clearTimeout(this.validationTimeout);
        }
    }

    /**
     * Wrap field with feedback container for icon positioning
     */
    wrapField() {
        // Skip if already wrapped
        if (this.element.parentElement?.classList.contains('field-with-feedback')) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'field-with-feedback position-relative';
        this.element.parentNode.insertBefore(wrapper, this.element);
        wrapper.appendChild(this.element);
    }

    /**
     * Debounced validation on input
     */
    validate(event) {
        // Clear previous timeout
        if (this.validationTimeout) {
            clearTimeout(this.validationTimeout);
        }

        // Only validate after user has started typing
        this.validationTimeout = setTimeout(() => {
            this.performValidation();
        }, this.debounceDelayValue);
    }

    /**
     * Immediate validation on blur (no debounce)
     */
    validateImmediate(event) {
        if (this.validationTimeout) {
            clearTimeout(this.validationTimeout);
        }
        this.performValidation();
    }

    /**
     * Perform actual validation
     */
    performValidation() {
        const value = this.element.value.trim();
        const fieldType = this.element.type;

        // Skip validation if field is empty and not required
        if (!value && !this.element.required && !this.rulesValue.required) {
            this.clearFeedback();
            return;
        }

        // Check HTML5 validity first
        if (!this.element.checkValidity()) {
            this.showInvalid(this.element.validationMessage);
            return;
        }

        // Custom validation rules
        const customValidation = this.validateCustomRules(value, fieldType);
        if (!customValidation.valid) {
            this.showInvalid(customValidation.message);
            return;
        }

        // Field is valid
        this.showValid();
    }

    /**
     * Validate custom rules defined via data attribute
     */
    validateCustomRules(value, fieldType) {
        const rules = this.rulesValue;

        // Min length check
        if (rules.minLength && value.length < rules.minLength) {
            return {
                valid: false,
                message: `Mindestens ${rules.minLength} Zeichen erforderlich`
            };
        }

        // Max length check
        if (rules.maxLength && value.length > rules.maxLength) {
            return {
                valid: false,
                message: `Maximal ${rules.maxLength} Zeichen erlaubt`
            };
        }

        // Pattern matching
        if (rules.pattern) {
            const regex = new RegExp(rules.pattern);
            if (!regex.test(value)) {
                return {
                    valid: false,
                    message: rules.patternMessage || 'Ungültiges Format'
                };
            }
        }

        // Email validation (additional check beyond HTML5)
        if (fieldType === 'email' && !this.isValidEmail(value)) {
            return {
                valid: false,
                message: 'Bitte geben Sie eine gültige E-Mail-Adresse ein'
            };
        }

        // URL validation
        if (fieldType === 'url' && !this.isValidUrl(value)) {
            return {
                valid: false,
                message: 'Bitte geben Sie eine gültige URL ein'
            };
        }

        return { valid: true };
    }

    /**
     * Show valid state with checkmark
     */
    showValid() {
        this.element.classList.remove('is-invalid');
        this.element.classList.add('is-valid');
        this.element.setAttribute('aria-invalid', 'false');

        this.updateFeedbackIcon('valid');
        this.clearErrorMessage();

        this.isValidated = true;
    }

    /**
     * Show invalid state with X mark
     */
    showInvalid(message) {
        this.element.classList.remove('is-valid');
        this.element.classList.add('is-invalid');
        this.element.setAttribute('aria-invalid', 'true');

        this.updateFeedbackIcon('invalid');
        this.showErrorMessage(message);

        this.isValidated = true;
    }

    /**
     * Clear validation feedback
     */
    clearFeedback() {
        this.element.classList.remove('is-valid', 'is-invalid');
        this.element.removeAttribute('aria-invalid');
        this.updateFeedbackIcon('none');
        this.clearErrorMessage();

        this.isValidated = false;
    }

    /**
     * Update feedback icon (✓ or ✗)
     */
    updateFeedbackIcon(state) {
        const wrapper = this.element.closest('.field-with-feedback');
        if (!wrapper) return;

        // Remove existing icon
        const existingIcon = wrapper.querySelector('.validation-feedback-icon');
        if (existingIcon) {
            existingIcon.remove();
        }

        if (state === 'none') return;

        // Create new icon
        const icon = document.createElement('span');
        icon.className = `validation-feedback-icon validation-${state}`;
        icon.setAttribute('aria-hidden', 'true');

        if (state === 'valid') {
            icon.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
        } else if (state === 'invalid') {
            icon.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>';
        }

        wrapper.appendChild(icon);
    }

    /**
     * Show error message
     */
    showErrorMessage(message) {
        const fieldId = this.element.id || this.element.name;
        let errorDiv = document.getElementById(`${fieldId}-realtime-error`);

        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.id = `${fieldId}-realtime-error`;
            errorDiv.className = 'invalid-feedback';
            errorDiv.setAttribute('role', 'alert');
            errorDiv.setAttribute('aria-live', 'polite');

            // Insert after field or wrapper
            const wrapper = this.element.closest('.field-with-feedback');
            const insertAfter = wrapper || this.element;
            insertAfter.parentNode.insertBefore(errorDiv, insertAfter.nextSibling);
        }

        errorDiv.textContent = message;
        errorDiv.style.display = 'block';

        // Update aria-describedby
        const describedby = this.element.getAttribute('aria-describedby') || '';
        if (!describedby.includes(errorDiv.id)) {
            this.element.setAttribute('aria-describedby', `${describedby} ${errorDiv.id}`.trim());
        }
    }

    /**
     * Clear error message
     */
    clearErrorMessage() {
        const fieldId = this.element.id || this.element.name;
        const errorDiv = document.getElementById(`${fieldId}-realtime-error`);

        if (errorDiv) {
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
        }
    }

    /**
     * Get appropriate error message from HTML5 validation
     */
    getErrorMessage() {
        if (this.element.validity.valueMissing) {
            return 'Dieses Feld ist erforderlich';
        }
        if (this.element.validity.typeMismatch) {
            return 'Bitte geben Sie einen gültigen Wert ein';
        }
        if (this.element.validity.tooShort) {
            return `Mindestens ${this.element.minLength} Zeichen erforderlich`;
        }
        if (this.element.validity.tooLong) {
            return `Maximal ${this.element.maxLength} Zeichen erlaubt`;
        }
        if (this.element.validity.patternMismatch) {
            return 'Ungültiges Format';
        }

        return this.element.validationMessage || 'Ungültiger Wert';
    }

    /**
     * Validate email format (stricter than HTML5)
     */
    isValidEmail(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }

    /**
     * Validate URL format
     */
    isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }
}
