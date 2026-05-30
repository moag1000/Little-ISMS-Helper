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
        // The controller is mounted either on the <form> itself (e.g. login)
        // or on a wrapper such as `.fa-form-layout` that lives *inside* the
        // form. Resolve the real form element for both cases.
        this.form = this.element.closest('form')
            || this.element.querySelector('form')
            || (this.element.tagName === 'FORM' ? this.element : null);

        if (this.form) {
            // Disable native HTML5 validation. A `required` field inside a
            // collapsed fa-form-section is not focusable, so the browser aborts
            // submit silently ("An invalid form control … is not focusable")
            // before our submit handler can reveal the section. We run the
            // validation ourselves on submit — see handleSubmit().
            this.form.noValidate = true;
            this._onSubmit = this.handleSubmit.bind(this);
            this.form.addEventListener('submit', this._onSubmit);
        }

        // Check for errors on page load (server-side validation)
        this.checkForErrors();
    }

    disconnect() {
        if (this.form && this._onSubmit) {
            this.form.removeEventListener('submit', this._onSubmit);
        }
    }

    /**
     * Handle form submission. With native validation disabled (see connect),
     * the submit event always fires — so we can reveal the collapsed section /
     * inactive tab that hides the first invalid field *before* the browser
     * reports the constraint, instead of being silently blocked.
     */
    handleSubmit(event) {
        if (!this.form || typeof this.form.checkValidity !== 'function') {
            return;
        }
        if (!this.form.checkValidity()) {
            event.preventDefault();
            const firstInvalid = this.form.querySelector(
                'input:invalid, select:invalid, textarea:invalid'
            );
            if (firstInvalid) {
                // Reveal the collapsed section / inactive tab first, then let
                // the now-visible field receive focus + the native bubble.
                this.revealHiddenAncestors(firstInvalid);
                requestAnimationFrame(() => {
                    this.scrollToError(firstInvalid);
                    try { firstInvalid.focus({ preventScroll: true }); } catch (e) { /* not focusable yet */ }
                    this.form.reportValidity();
                });
            }
            this.announceErrors();
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
            // Reveal collapsed section / inactive tab BEFORE scroll+focus,
            // otherwise the field is hidden and scroll-into-view jumps to a
            // collapsed container (user sees nothing actionable).
            this.revealHiddenAncestors(firstError);
            // Give the reveal animation a frame to start, then scroll+focus.
            requestAnimationFrame(() => {
                this.scrollToError(firstError);
                this.focusErrorField(firstError);
            });
            this.announceErrors();
        }
    }

    /**
     * Walk up the DOM from the first error and open any closed container that
     * hides it:
     *   - fa-form-layout sections (`.fa-form-section--collapsed`) — click head
     *     to delegate to `form-layout#toggleSection`
     *   - fa-tabs panels (`[role="tabpanel"]` not `.is-active`) — find the
     *     matching nav-item button and click it (delegates to `tabs#switchTo`)
     *   - Bootstrap-style `[hidden]` / `.d-none` ancestors — clear so the field
     *     becomes layout-visible (last resort; safest before scrolling)
     */
    revealHiddenAncestors(errorElement) {
        let node = errorElement;
        let safety = 20;
        while (node && node !== this.element && safety-- > 0) {
            // 1. fa-form-section collapsed → click head to toggle open
            if (node.classList && node.classList.contains('fa-form-section--collapsed')) {
                const head = node.querySelector('.fa-form-section__head');
                if (head) head.click();
            }

            // 2. Inactive tab panel → find nav-item with matching tab-id and click
            if (node.matches && node.matches('[role="tabpanel"]') && !node.classList.contains('is-active')) {
                const tabId = node.dataset.tabId;
                if (tabId) {
                    const navItem = document.querySelector(
                        `[role="tab"][data-tab-id="${tabId}"]`
                    );
                    if (navItem) navItem.click();
                }
            }

            // 3. Bootstrap accordion: collapsed `.collapse` without `.show`
            if (node.classList && node.classList.contains('collapse') && !node.classList.contains('show')) {
                // Find the toggle button targeting this collapse, click it
                const id = node.id;
                if (id) {
                    const toggle = document.querySelector(
                        `[data-bs-toggle="collapse"][data-bs-target="#${id}"], [aria-controls="${id}"]`
                    );
                    if (toggle) toggle.click();
                }
            }

            node = node.parentElement;
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
