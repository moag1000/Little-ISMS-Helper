import { Controller } from '@hotwired/stimulus';

/**
 * Form Validation Controller
 * Enhances form validation with consistent error positioning and user feedback
 *
 * Features:
 * - Auto-scrolls to first error on form submission
 * - Auto-focuses first invalid field
 * - Smooth scroll with offset for fixed headers
 * - ARIA live region announcements for screen readers
 * - Works with both server-side and client-side validation
 *
 * Usage:
 * <form data-controller="form-validation"
 *       data-action="submit->form-validation#handleSubmit turbo:submit-end->form-validation#handleResponse">
 *     <!-- form fields -->
 * </form>
 */
export default class extends Controller {
    static values = {
        scrollOffset: { type: Number, default: 100 }  // Offset for fixed headers
    }

    connect() {
        // Check for errors on page load (server-side validation)
        this.checkForErrors();
    }

    /**
     * Handle form submission
     * Checks for client-side validation errors before submit
     */
    handleSubmit(event) {
        // Let browser's native validation run first
        if (!this.element.checkValidity()) {
            event.preventDefault();
            this.scrollToFirstError();
            return false;
        }
    }

    /**
     * Handle Turbo form submission response
     * Checks for server-side validation errors after response
     */
    handleResponse(event) {
        // Small delay to let DOM update with server errors
        setTimeout(() => {
            this.checkForErrors();
        }, 100);
    }

    /**
     * Check for validation errors and handle them
     */
    checkForErrors() {
        const firstError = this.findFirstError();

        if (firstError) {
            this.scrollToError(firstError);
            this.focusErrorField(firstError);
            this.announceErrors();
        }
    }

    /**
     * Find the first error element in the form
     * Supports multiple error markup patterns
     */
    findFirstError() {
        // Try multiple selectors for different error patterns
        const selectors = [
            '.is-invalid',                    // Bootstrap/custom invalid field
            '.form-control:invalid',          // HTML5 validation
            '.invalid-feedback:not(.d-none)', // Visible error message
            '[aria-invalid="true"]',          // ARIA invalid
            '.form-error',                    // Custom error class
            '.has-error'                      // Legacy error class
        ];

        for (const selector of selectors) {
            const element = this.element.querySelector(selector);
            if (element) {
                return element;
            }
        }

        return null;
    }

    /**
     * Scroll to error with smooth animation and offset
     */
    scrollToError(errorElement) {
        // Find the form group or parent container
        const formGroup = errorElement.closest('.form-group, .mb-3, .form-floating');
        const targetElement = formGroup || errorElement;

        // Calculate scroll position with offset
        const elementTop = targetElement.getBoundingClientRect().top + window.pageYOffset;
        const offsetPosition = elementTop - this.scrollOffsetValue;

        // Smooth scroll to position
        window.scrollTo({
            top: offsetPosition,
            behavior: 'smooth'
        });
    }

    /**
     * Focus the error field for keyboard accessibility
     */
    focusErrorField(errorElement) {
        // If error element is a message, find the associated input
        let inputElement = errorElement;

        if (!errorElement.matches('input, select, textarea')) {
            // Look for input in same form group
            const formGroup = errorElement.closest('.form-group, .mb-3, .form-floating');
            if (formGroup) {
                inputElement = formGroup.querySelector('input, select, textarea');
            }

            // Or use aria-describedby to find associated input
            if (!inputElement && errorElement.id) {
                inputElement = this.element.querySelector(`[aria-describedby*="${errorElement.id}"]`);
            }
        }

        if (inputElement && inputElement.matches('input, select, textarea')) {
            // Small delay to ensure scroll completes
            setTimeout(() => {
                inputElement.focus({ preventScroll: true });

                // Select text in input for easy correction
                if (inputElement.select && inputElement.type !== 'checkbox' && inputElement.type !== 'radio') {
                    inputElement.select();
                }
            }, 300);
        }
    }

    /**
     * Announce errors to screen readers
     */
    announceErrors() {
        const errorMessages = this.element.querySelectorAll('.invalid-feedback, .form-error, [role="alert"]');

        if (errorMessages.length === 0) return;

        // Count errors
        const errorCount = errorMessages.length;

        // Create or update ARIA live region
        let liveRegion = document.getElementById('form-errors-announcement');

        if (!liveRegion) {
            liveRegion = document.createElement('div');
            liveRegion.id = 'form-errors-announcement';
            liveRegion.setAttribute('role', 'status');
            liveRegion.setAttribute('aria-live', 'polite');
            liveRegion.className = 'sr-only';
            document.body.appendChild(liveRegion);
        }

        // Announce error count in German
        const message = errorCount === 1
            ? 'Das Formular enthält einen Fehler. Bitte korrigieren Sie das markierte Feld.'
            : `Das Formular enthält ${errorCount} Fehler. Bitte korrigieren Sie die markierten Felder.`;

        liveRegion.textContent = message;

        // Clear after announcement
        setTimeout(() => {
            liveRegion.textContent = '';
        }, 5000);
    }

    /**
     * Utility: Programmatically trigger error check
     * Can be called from other controllers
     */
    validate() {
        this.checkForErrors();
    }
}
