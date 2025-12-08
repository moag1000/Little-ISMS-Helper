import { Controller } from '@hotwired/stimulus';

/**
 * Quick View Controller
 * Keyboard Shortcut: Space (on list items)
 *
 * Features:
 * - Preview entity details without navigation
 * - Keyboard shortcut: Space to open, ESC to close
 * - Loading states
 * - Error handling
 */
export default class extends Controller {
    static targets = [
        'modal',
        'title',
        'body',
        'loading'
    ];

    static values = {
        url: String,
        type: String,
        // Translation strings
        loadingText: { type: String, default: 'Loading preview...' },
        errorTitle: { type: String, default: 'Error loading preview' },
        errorSubtitle: { type: String, default: 'Please try again' },
        closeText: { type: String, default: 'Close' }
    };

    connect() {
        // Register space key handler
        this.boundHandleKeydown = this.handleKeydown.bind(this);
        this.element.addEventListener('keydown', this.boundHandleKeydown);

        // Register ESC key handler for modal
        this.boundHandleModalKeydown = this.handleModalKeydown.bind(this);
        document.addEventListener('keydown', this.boundHandleModalKeydown);

        // Make element focusable
        if (!this.element.hasAttribute('tabindex')) {
            this.element.setAttribute('tabindex', '0');
        }
    }

    disconnect() {
        this.element.removeEventListener('keydown', this.boundHandleKeydown);
        document.removeEventListener('keydown', this.boundHandleModalKeydown);
    }

    handleKeydown(event) {
        // Space key to open quick view
        if (event.key === ' ' && !event.target.matches('input, textarea, button, a')) {
            event.preventDefault();
            this.open();
        }
    }

    async open(event) {
        if (event) {
            event.preventDefault();
        }

        // Remove inline style that modal manager might have set
        this.modalTarget.style.display = '';

        // Show modal
        this.modalTarget.classList.remove('d-none');
        this.modalTarget.classList.add('show');
        document.body.style.overflow = 'hidden';

        // Show loading
        this.showLoading();

        try {
            // Fetch data
            const response = await fetch(this.urlValue);

            if (!response.ok) {
                throw new Error('Failed to load preview');
            }

            const html = await response.text();
            this.displayContent(html);
        } catch (error) {
            this.displayError();
        }
    }

    close(event) {
        if (event) {
            event.preventDefault();
        }

        this.modalTarget.classList.add('d-none');
        this.modalTarget.classList.remove('show');
        document.body.style.overflow = '';
    }

    handleBackdropClick(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    handleModalKeydown(event) {
        if (event.key === 'Escape' && this.hasModalTarget && !this.modalTarget.classList.contains('d-none')) {
            event.preventDefault();
            this.close();
        }
    }

    showLoading() {
        this.bodyTarget.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">${this.loadingTextValue}</span>
                </div>
                <p class="mt-3 text-muted">${this.loadingTextValue}</p>
            </div>
        `;
    }

    displayContent(html) {
        this.bodyTarget.innerHTML = html;
    }

    displayError() {
        this.bodyTarget.innerHTML = `
            <div class="text-center py-5">
                <i class="bi-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                <p class="mt-3 mb-2">${this.errorTitleValue}</p>
                <p class="text-muted small">${this.errorSubtitleValue}</p>
                <button class="btn btn-sm btn-primary mt-3" data-action="click->quick-view#close">
                    ${this.closeTextValue}
                </button>
            </div>
        `;
    }
}
