import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';

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
        loadingText: { type: String, default: '' },
        errorTitle: { type: String, default: '' },
        errorSubtitle: { type: String, default: '' },
        closeText: { type: String, default: '' }
    };

    previouslyFocusedElement = null;

    /** Template-passed label value, else the translated faT() fallback. */
    label(name) {
        const fallback = {
            loadingText: () => faT('quick_view.loading'),
            errorTitle: () => faT('quick_view.error_title'),
            errorSubtitle: () => faT('quick_view.error_subtitle'),
            closeText: () => faT('quick_view.close'),
        };
        return this[`${name}Value`] || fallback[name]();
    }

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

        // Save currently focused element to restore on close
        this.previouslyFocusedElement = document.activeElement;

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

            // Focus first focusable element after content loads
            requestAnimationFrame(() => {
                const focusable = this.getFocusableElements();
                if (focusable.length > 0) {
                    focusable[0].focus();
                }
            });
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

        // Restore focus to previously focused element
        if (this.previouslyFocusedElement && typeof this.previouslyFocusedElement.focus === 'function') {
            this.previouslyFocusedElement.focus();
        }
        this.previouslyFocusedElement = null;
    }

    handleBackdropClick(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    handleModalKeydown(event) {
        if (!this.hasModalTarget || this.modalTarget.classList.contains('d-none')) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
        } else if (event.key === 'Tab') {
            // Focus trap
            this.handleTabKey(event);
        }
    }

    handleTabKey(event) {
        const focusableElements = this.getFocusableElements();
        if (focusableElements.length === 0) return;

        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (event.shiftKey) {
            if (document.activeElement === firstElement) {
                event.preventDefault();
                lastElement.focus();
            }
        } else {
            if (document.activeElement === lastElement) {
                event.preventDefault();
                firstElement.focus();
            }
        }
    }

    getFocusableElements() {
        const selector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
        return Array.from(this.modalTarget.querySelectorAll(selector)).filter(
            el => !el.disabled && el.offsetParent !== null
        );
    }

    showLoading() {
        this.bodyTarget.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">${this.label('loadingText')}</span>
                </div>
                <p class="mt-3 text-muted">${this.label('loadingText')}</p>
            </div>
        `;
    }

    displayContent(html) {
        this.bodyTarget.innerHTML = html;
    }

    displayError() {
        this.bodyTarget.innerHTML = `
            <div class="text-center py-5">
                <i class="fa-icon fa-icon--status-warning text-danger" style="font-size: 3rem;" aria-hidden="true"></i>
                <p class="mt-3 mb-2">${this.label('errorTitle')}</p>
                <p class="text-muted small">${this.label('errorSubtitle')}</p>
                <button class="btn btn-sm btn-primary mt-3" data-action="click->quick-view#close">
                    ${this.label('closeText')}
                </button>
            </div>
        `;
    }
}
