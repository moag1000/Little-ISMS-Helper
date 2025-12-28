import { Controller } from '@hotwired/stimulus';

/**
 * AutoFix Controller - System health auto-fix actions
 *
 * Usage:
 * <button data-controller="autofix"
 *         data-action="click->autofix#fix"
 *         data-autofix-action-param="clear-cache"
 *         data-autofix-url-value="/monitoring/health">
 *     Fix Issue
 * </button>
 * <div data-autofix-target="result"></div>
 */
export default class extends Controller {
    static targets = ['result'];
    static values = {
        url: { type: String, default: '' },
        processingText: { type: String, default: 'Processing...' }
    };

    async fix(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const action = event.params.action;

        if (!action) {
            return;
        }

        // Disable button and show loading state
        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = `<i class="bi bi-hourglass-split" aria-hidden="true"></i> ${this.processingTextValue}`;

        // Find result container
        const resultContainer = this.hasResultTarget
            ? this.resultTarget
            : document.getElementById('fix-result-' + action);

        if (resultContainer) {
            resultContainer.innerHTML = '';
        }

        try {
            const baseUrl = this.urlValue || window.location.pathname;
            const response = await fetch(`${baseUrl}/fix/${action}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (result.success) {
                if (resultContainer) {
                    let detailsHtml = '';
                    if (result.details && result.details.length > 0) {
                        detailsHtml = '<hr class="my-2"><ul class="mb-0 small">' +
                            result.details.map(d => `<li>${d}</li>`).join('') +
                            '</ul>';
                    }
                    resultContainer.innerHTML = `
                        <div class="alert alert-success fix-result fairy-magic-glow">
                            <i class="bi bi-stars fairy-icon-sparkle" aria-hidden="true"></i> ${result.message}
                            ${detailsHtml}
                            <div class="mt-1 small text-muted opacity-75">Die Cyberpunk Fee hat das für dich erledigt!</div>
                        </div>
                    `;
                }

                // Reload page after 2 seconds for permission fixes
                if (['cache', 'logs', 'var-permissions', 'uploads-permissions', 'session-permissions'].includes(action)) {
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                }
            } else {
                if (resultContainer) {
                    resultContainer.innerHTML = `
                        <div class="alert alert-danger fix-result">
                            <i class="bi bi-x-circle" aria-hidden="true"></i> ${result.message}
                        </div>
                    `;
                }
            }
        } catch (error) {
            if (resultContainer) {
                resultContainer.innerHTML = `
                    <div class="alert alert-danger fix-result">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Error: ${error.message}
                    </div>
                `;
            }
        } finally {
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }
}
