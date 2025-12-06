import { Controller } from '@hotwired/stimulus';

/**
 * Report Regenerate Controller - Regenerate reports via AJAX
 *
 * Usage:
 * <button data-controller="report-regenerate"
 *         data-action="click->report-regenerate#regenerate"
 *         data-report-regenerate-url-value="/path/to/regenerate"
 *         data-report-regenerate-loading-text-value="Generating...">
 *     Regenerate
 * </button>
 */
export default class extends Controller {
    static values = {
        url: String,
        loadingText: { type: String, default: 'Generating...' },
        successText: { type: String, default: 'Report regenerated!' },
        errorText: { type: String, default: 'Error regenerating report' }
    };

    async regenerate(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const originalHtml = button.innerHTML;

        // Disable button and show loading state
        button.disabled = true;
        button.innerHTML = `<i class="bi bi-arrow-clockwise spinner-border spinner-border-sm me-1" aria-hidden="true"></i>${this.loadingTextValue}`;

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.success) {
                button.innerHTML = `<i class="bi bi-check-circle me-1"></i>${this.successTextValue}`;
                button.classList.remove('btn-outline-warning');
                button.classList.add('btn-success');

                // Reload after delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                this.showError(button, originalHtml, data.message || this.errorTextValue);
            }
        } catch (error) {
            this.showError(button, originalHtml, this.errorTextValue);
        }
    }

    showError(button, originalHtml, message) {
        button.innerHTML = `<i class="bi bi-exclamation-triangle me-1"></i>${message}`;
        button.classList.remove('btn-outline-warning');
        button.classList.add('btn-danger');

        setTimeout(() => {
            button.innerHTML = originalHtml;
            button.classList.remove('btn-danger');
            button.classList.add('btn-outline-warning');
            button.disabled = false;
        }, 3000);
    }
}
