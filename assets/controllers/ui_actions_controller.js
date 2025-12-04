import { Controller } from '@hotwired/stimulus';

/**
 * UI Actions Controller - Common UI interactions
 *
 * Replaces inline onclick handlers with Stimulus data-actions.
 * Provides reusable actions for common UI patterns.
 *
 * Usage:
 * <div data-controller="ui-actions">
 *     <button data-action="ui-actions#print">Print</button>
 *     <button data-action="ui-actions#confirm" data-ui-actions-message-param="Are you sure?">Delete</button>
 *     <button data-action="ui-actions#copyToClipboard" data-ui-actions-text-param="text to copy">Copy</button>
 *     <button data-action="ui-actions#setFontSize" data-ui-actions-size-param="14px" data-ui-actions-target-param="#content">A</button>
 *     <button data-action="ui-actions#reload">Refresh</button>
 *     <button data-action="ui-actions#submitForm" data-ui-actions-form-param="#my-form">Submit</button>
 * </div>
 */
export default class extends Controller {
    static values = {
        confirmMessage: { type: String, default: 'Are you sure?' }
    };

    /**
     * Print the current page
     */
    print(event) {
        event.preventDefault();
        window.print();
    }

    /**
     * Show confirmation dialog before proceeding
     * Use with: data-action="click->ui-actions#confirm" data-ui-actions-message-param="Custom message"
     * For forms: data-action="submit->ui-actions#confirm" data-ui-actions-message-param="Custom message"
     * Returns false to cancel form submission if user cancels
     */
    confirm(event) {
        const message = event.params?.message || this.confirmMessageValue;
        if (!window.confirm(message)) {
            event.preventDefault();
            event.stopPropagation();
            return false;
        }
        return true;
    }

    /**
     * Confirm before following a link (for buttons/links)
     * Use with: data-action="click->ui-actions#confirmLink" data-ui-actions-message-param="Are you sure?"
     */
    confirmLink(event) {
        const message = event.params?.message || this.confirmMessageValue;
        if (!window.confirm(message)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    /**
     * Copy text to clipboard
     * Use with: data-action="ui-actions#copyToClipboard" data-ui-actions-text-param="text"
     */
    async copyToClipboard(event) {
        event.preventDefault();
        const text = event.params.text;

        if (text && navigator.clipboard) {
            try {
                await navigator.clipboard.writeText(text);
                this.showToast('Copied to clipboard', 'success');
            } catch (err) {
                this.showToast('Failed to copy', 'error');
            }
        }
    }

    /**
     * Set font size on target element
     * Use with: data-action="ui-actions#setFontSize"
     *           data-ui-actions-size-param="14px"
     *           data-ui-actions-target-param="#element-id"
     */
    setFontSize(event) {
        event.preventDefault();
        const size = event.params.size;
        const targetSelector = event.params.target;

        if (size && targetSelector) {
            const target = document.querySelector(targetSelector);
            if (target) {
                target.style.fontSize = size;
            }
        }
    }

    /**
     * Reload the current page
     */
    reload(event) {
        event.preventDefault();
        window.location.reload();
    }

    /**
     * Submit a specific form
     * Use with: data-action="ui-actions#submitForm" data-ui-actions-form-param="#form-id"
     * Or: data-action="ui-actions#submitForm" data-ui-actions-form-id-param="form-id"
     */
    submitForm(event) {
        event.preventDefault();
        const formSelector = event.params.form;
        const formId = event.params.formId;

        let form = null;
        if (formId) {
            form = document.getElementById(formId);
        } else if (formSelector) {
            form = document.querySelector(formSelector);
        }

        if (form) {
            form.submit();
        }
    }

    /**
     * Select all checkboxes within a container
     * Use with: data-action="ui-actions#selectAll" data-ui-actions-container-param="#container"
     */
    selectAll(event) {
        event.preventDefault();
        const containerSelector = event.params.container || this.element;
        const container = typeof containerSelector === 'string'
            ? document.querySelector(containerSelector)
            : containerSelector;

        if (container) {
            container.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                cb.checked = true;
                cb.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
    }

    /**
     * Deselect all checkboxes within a container
     * Use with: data-action="ui-actions#deselectAll" data-ui-actions-container-param="#container"
     */
    deselectAll(event) {
        event.preventDefault();
        const containerSelector = event.params.container || this.element;
        const container = typeof containerSelector === 'string'
            ? document.querySelector(containerSelector)
            : containerSelector;

        if (container) {
            container.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
                cb.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
    }

    /**
     * Toggle visibility of an element
     * Use with: data-action="ui-actions#toggle" data-ui-actions-target-param="#element-id"
     */
    toggle(event) {
        event.preventDefault();
        const targetSelector = event.params.target;

        if (targetSelector) {
            const target = document.querySelector(targetSelector);
            if (target) {
                target.classList.toggle('d-none');
            }
        }
    }

    /**
     * Scroll to element
     * Use with: data-action="ui-actions#scrollTo" data-ui-actions-target-param="#element-id"
     */
    scrollTo(event) {
        event.preventDefault();
        const targetSelector = event.params.target;

        if (targetSelector) {
            const target = document.querySelector(targetSelector);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    /**
     * Auto-submit form when select changes
     * Use with: data-action="change->ui-actions#autoSubmit"
     */
    autoSubmit(event) {
        const form = event.target.closest('form');
        if (form) {
            form.submit();
        }
    }

    /**
     * Show a toast notification (if toast system available)
     */
    showToast(message, type = 'info') {
        // Dispatch event for toast system to handle
        this.dispatch('toast', { detail: { message, type } });

        // Fallback: check for global toast function
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
        }
    }
}
