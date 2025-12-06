import { Controller } from '@hotwired/stimulus';

/**
 * MFA Setup Controller
 *
 * Handles TOTP verification and secret key copy during MFA setup.
 *
 * Usage:
 * <div data-controller="mfa-setup"
 *      data-mfa-setup-verify-url-value="/verify"
 *      data-mfa-setup-success-redirect-value="/mfa/show/1">
 *     <form data-action="submit->mfa-setup#verify">
 *         <input data-mfa-setup-target="codeInput" type="text">
 *         <button data-mfa-setup-target="submitButton">Verify</button>
 *     </form>
 *     <div data-mfa-setup-target="feedback"></div>
 * </div>
 */
export default class extends Controller {
    static targets = ['codeInput', 'submitButton', 'feedback'];

    static values = {
        verifyUrl: String,
        successRedirect: String,
        // Translation strings
        verifyingText: { type: String, default: 'Verifying...' },
        verifyButtonText: { type: String, default: 'Verify & Activate' },
        errorText: { type: String, default: 'Error' },
        copiedText: { type: String, default: 'Copied!' },
        copyText: { type: String, default: 'Copy' }
    };

    connect() {
        // Auto-submit when 6 digits are entered
        if (this.hasCodeInputTarget) {
            this.codeInputTarget.addEventListener('input', this.handleCodeInput.bind(this));
        }
    }

    /**
     * Handle code input - auto-submit when 6 digits entered
     */
    handleCodeInput(event) {
        const value = event.target.value;
        if (value.length === 6 && /^\d{6}$/.test(value)) {
            setTimeout(() => {
                this.element.querySelector('form').dispatchEvent(
                    new Event('submit', { cancelable: true })
                );
            }, 500);
        }
    }

    /**
     * Verify the TOTP code
     */
    async verify(event) {
        event.preventDefault();

        const code = this.codeInputTarget.value;
        const button = this.submitButtonTarget;
        const originalHTML = button.innerHTML;

        // Disable button during verification
        button.disabled = true;
        button.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${this.verifyingTextValue}`;

        try {
            const response = await fetch(this.verifyUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'code=' + encodeURIComponent(code)
            });

            const data = await response.json();

            if (data.success) {
                this.feedbackTarget.innerHTML = `
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle" aria-hidden="true"></i> ${data.message}
                    </div>
                `;

                // Redirect after delay
                setTimeout(() => {
                    window.location.href = this.successRedirectValue;
                }, 2000);
            } else {
                this.showError(data.message);
                button.disabled = false;
                button.innerHTML = originalHTML;

                // Clear and refocus
                this.codeInputTarget.value = '';
                this.codeInputTarget.focus();
            }
        } catch (error) {
            this.showError(`${this.errorTextValue}: ${error.message}`);
            button.disabled = false;
            button.innerHTML = originalHTML;
        }
    }

    /**
     * Copy secret key to clipboard
     */
    async copySecret(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const inputId = button.dataset.mfaSetupCopyTargetParam || 'secret-key';
        const input = document.getElementById(inputId);

        if (!input) return;

        try {
            // Select and copy
            input.select();

            if (navigator.clipboard) {
                await navigator.clipboard.writeText(input.value);
            } else {
                document.execCommand('copy');
            }

            // Show feedback
            const originalHTML = button.innerHTML;
            button.innerHTML = `<i class="bi bi-check" aria-hidden="true"></i> ${this.copiedTextValue}`;
            button.classList.add('btn-success');
            button.classList.remove('btn-outline-secondary');

            setTimeout(() => {
                button.innerHTML = originalHTML;
                button.classList.remove('btn-success');
                button.classList.add('btn-outline-secondary');
            }, 2000);
        } catch (err) {
        }
    }

    /**
     * Show error message in feedback area
     */
    showError(message) {
        if (this.hasFeedbackTarget) {
            this.feedbackTarget.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-x-circle" aria-hidden="true"></i> ${message}
                </div>
            `;
        }
    }
}
