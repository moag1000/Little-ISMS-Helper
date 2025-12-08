import { Controller } from '@hotwired/stimulus';

/**
 * Button Loading State Controller
 * Prevents double-clicks and shows visual feedback during async operations
 *
 * Usage:
 * <button type="submit"
 *         class="btn btn-primary"
 *         data-controller="button-loading"
 *         data-action="button-loading#start">
 *     Save
 * </button>
 *
 * Or for non-submit buttons:
 * <button class="btn btn-primary"
 *         data-controller="button-loading"
 *         data-action="click->button-loading#start ajax:complete->button-loading#stop">
 *     Process
 * </button>
 *
 * Features:
 * - Automatically shows spinner on button click
 * - Disables button to prevent double-clicks
 * - Auto-restores on Turbo navigation
 * - Works with forms and AJAX requests
 */
export default class extends Controller {
    static values = {
        text: String,  // Optional custom loading text
        // Translation strings for loading states
        saving: { type: String, default: 'Saving...' },
        deleting: { type: String, default: 'Deleting...' },
        sending: { type: String, default: 'Sending...' },
        loading: { type: String, default: 'Loading...' },
        creating: { type: String, default: 'Creating...' },
        updating: { type: String, default: 'Updating...' },
        importing: { type: String, default: 'Importing...' },
        exporting: { type: String, default: 'Exporting...' },
        processing: { type: String, default: 'Processing...' }
    }

    connect() {
        // Store original button content
        this.originalContent = this.element.innerHTML;
        this.originalText = this.element.textContent.trim();

        // Listen for Turbo events to auto-restore button
        document.addEventListener('turbo:submit-end', this.handleTurboSubmitEnd.bind(this));
        document.addEventListener('turbo:before-cache', this.handleBeforeCache.bind(this));
    }

    disconnect() {
        document.removeEventListener('turbo:submit-end', this.handleTurboSubmitEnd.bind(this));
        document.removeEventListener('turbo:before-cache', this.handleBeforeCache.bind(this));
    }

    start(event) {
        // Don't show loading state if button is already disabled
        if (this.element.disabled) {
            event.preventDefault();
            return;
        }

        // Add loading state
        this.element.disabled = true;
        this.element.classList.add('btn-loading');

        // Determine loading text
        const loadingText = this.textValue || this.getLoadingText();

        // Add spinner and update text
        this.element.innerHTML = `
            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
            ${loadingText}
        `;

        // Set ARIA attributes for accessibility
        this.element.setAttribute('aria-busy', 'true');
        this.element.setAttribute('aria-live', 'polite');
    }

    stop() {
        this.restore();
    }

    restore() {
        this.element.disabled = false;
        this.element.classList.remove('btn-loading');
        this.element.innerHTML = this.originalContent;
        this.element.removeAttribute('aria-busy');
        this.element.removeAttribute('aria-live');
    }

    handleTurboSubmitEnd(event) {
        // Restore button after form submission completes
        // Check if the button belongs to the form that was submitted
        const form = event.detail.formSubmission?.formElement;
        if (form && form.contains(this.element)) {
            this.restore();
        }
    }

    handleBeforeCache() {
        // Always restore before Turbo caches the page
        this.restore();
    }

    getLoadingText() {
        // Determine appropriate loading text based on original button text
        const text = this.originalText.toLowerCase();

        if (text.includes('speichern') || text.includes('save')) {
            return this.savingValue;
        } else if (text.includes('löschen') || text.includes('delete')) {
            return this.deletingValue;
        } else if (text.includes('senden') || text.includes('submit') || text.includes('absenden')) {
            return this.sendingValue;
        } else if (text.includes('laden') || text.includes('load')) {
            return this.loadingValue;
        } else if (text.includes('erstellen') || text.includes('create')) {
            return this.creatingValue;
        } else if (text.includes('aktualisieren') || text.includes('update') || text.includes('refresh')) {
            return this.updatingValue;
        } else if (text.includes('importieren') || text.includes('import')) {
            return this.importingValue;
        } else if (text.includes('exportieren') || text.includes('export')) {
            return this.exportingValue;
        }

        return this.processingValue;
    }
}
