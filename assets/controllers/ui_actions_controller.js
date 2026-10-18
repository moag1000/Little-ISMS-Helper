import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';

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
 *     <form data-action="submit->ui-actions#promptSubmit" data-ui-actions-message-param="Reason?"
 *           data-ui-actions-field-param="reason">…<input type="hidden" name="reason"></form>
 *     <button data-action="ui-actions#copyToClipboard" data-ui-actions-text-param="text to copy">Copy</button>
 *     <button data-action="ui-actions#setFontSize" data-ui-actions-size-param="14px" data-ui-actions-target-param="#content">A</button>
 *     <button data-action="ui-actions#reload">Refresh</button>
 *     <button data-action="ui-actions#submitForm" data-ui-actions-form-param="#my-form">Submit</button>
 * </div>
 */
export default class extends Controller {
    static values = {
        // Empty default → falls back to the translated generic text (faT).
        confirmMessage: { type: String, default: '' }
    };

    /**
     * Print the current page
     */
    print(event) {
        event.preventDefault();
        window.print();
    }

    /**
     * Show Aurora confirmation dialog before proceeding.
     * Use with: data-action="click->ui-actions#confirm" data-ui-actions-message-param="Custom message"
     * For forms: data-action="submit->ui-actions#confirm" data-ui-actions-message-param="Custom message"
     *
     * Cancels the original event synchronously, then opens an Aurora
     * fa-confirm modal asynchronously. On confirm, re-fires the
     * underlying intent (submit the form / follow the link).
     */
    confirm(event) {
        // currentTarget = the element the action is bound to (the form for
        // submit->…, the button/link for click->…); event.target may be an
        // inner <i> icon of a button.
        const target = event.currentTarget instanceof Element ? event.currentTarget : event.target;
        // Bypass when this submission was already confirmed via #proceed.
        if (target instanceof HTMLFormElement && target.dataset.faConfirmBypass === '1') {
            delete target.dataset.faConfirmBypass;
            return true;
        }
        event.preventDefault();
        event.stopPropagation();
        const message = event.params?.message || this.confirmMessageValue || faT('are_you_sure');
        const tone = event.params?.tone || 'warn';

        if (typeof window.faConfirm !== 'function') {
            // Fallback if helper hasn't loaded yet
            if (window.confirm(message)) {
                this.#proceed(event, target);
            }
            return false;
        }

        window.faConfirm(message, { tone, confirmLabel: event.params?.confirmLabel })
            .then((ok) => {
                if (ok) {
                    this.#proceed(event, target);
                }
            });
        return false;
    }

    /**
     * Confirm before following a link (for buttons/links).
     * Use with: data-action="click->ui-actions#confirmLink" data-ui-actions-message-param="Are you sure?"
     */
    confirmLink(event) {
        return this.confirm(event);
    }

    /**
     * Ask for a free-text value (Aurora prompt) before submitting a form and
     * write it into one of the form's inputs — e.g. a rejection reason.
     * Use with: data-action="submit->ui-actions#promptSubmit"
     *           data-ui-actions-message-param="Reason for rejection"
     *           data-ui-actions-field-param="reason"          (input name)
     *           data-ui-actions-required-param="true"         (optional)
     * Cancelling the prompt aborts the submission.
     */
    promptSubmit(event) {
        const form = event.currentTarget instanceof HTMLFormElement ? event.currentTarget : event.target;
        if (!(form instanceof HTMLFormElement)) {
            return true;
        }
        if (form.dataset.faConfirmBypass === '1') {
            delete form.dataset.faConfirmBypass;
            return true;
        }
        event.preventDefault();
        event.stopPropagation();

        const message = event.params?.message || '';
        const required = event.params?.required === true || event.params?.required === 'true';
        const field = form.querySelector(`[name="${CSS.escape(event.params?.field || 'reason')}"]`);

        const apply = (value) => {
            if (value === null || (required && value.trim() === '')) {
                return;
            }
            if (field) {
                field.value = value.trim();
            }
            this.#proceed(event, form);
        };

        if (typeof window.faPrompt === 'function') {
            window.faPrompt(message, { required, allowEmpty: !required, maxlength: 1000 }).then(apply);
        } else {
            apply(window.prompt(message)); // fallback: helper not mounted yet
        }
        return false;
    }

    #proceed(event, target) {
        if (target instanceof HTMLFormElement) {
            // Re-submit the form bypassing the controller's confirm hook.
            target.dataset.faConfirmBypass = '1';
            if (typeof target.requestSubmit === 'function') {
                target.requestSubmit(event.submitter ?? null);
            } else {
                target.submit();
            }
        } else if (target instanceof HTMLAnchorElement && target.href) {
            window.location.href = target.href;
        } else if (target instanceof HTMLButtonElement && target.form) {
            target.form.dataset.faConfirmBypass = '1';
            if (typeof target.form.requestSubmit === 'function') {
                target.form.requestSubmit(target);
            } else {
                target.form.submit();
            }
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
                this.showToast(event.params?.successMessage || faT('copied'), 'success');
            } catch (err) {
                this.showToast(event.params?.errorMessage || faT('copy_failed'), 'danger');
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
     * Confirm and then make a fetch request
     * Use with: data-action="ui-actions#confirmAndFetch"
     *           data-ui-actions-confirm-param="Are you sure?"
     *           data-ui-actions-url-param="/api/action"
     *           data-ui-actions-method-param="POST"
     *           data-ui-actions-reload-param="true"
     */
    async confirmAndFetch(event) {
        event.preventDefault();

        const message = event.params?.confirm || this.confirmMessageValue || faT('are_you_sure');
        const tone = event.params?.tone || 'danger';
        const ok = typeof window.faConfirm === 'function'
            ? await window.faConfirm(message, { tone })
            : window.confirm(message);
        if (!ok) {
            return;
        }

        const url = event.params.url;
        const method = event.params.method || 'POST';
        const shouldReload = event.params.reload === 'true' || event.params.reload === true;

        if (!url) {
            return;
        }

        const button = event.currentTarget;
        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> ...';

        try {
            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (result.success) {
                this.showToast(result.message || faT('success'), 'success');
                if (shouldReload) {
                    setTimeout(() => window.location.reload(), 1000);
                }
            } else {
                this.#notifyError(result.error || result.message || faT('unknown_error'));
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        } catch (error) {
            this.#notifyError(`${faT('network_error')}: ${error.message}`);
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }

    #notifyError(message) {
        if (typeof window.faToast === 'function') {
            window.faToast(message, 'danger');
        } else {
            // Last-resort fallback in case the helper bundle is not ready yet.
            console.error(message);
        }
    }

    /**
     * Navigate to URL from select element value
     * Use with: data-action="change->ui-actions#navigateTo"
     * The select option values should be URLs
     */
    navigateTo(event) {
        const url = event.target.value;
        if (url) {
            window.location.href = url;
        }
    }

    /**
     * Update sibling display with range slider value
     * Use with: data-action="input->ui-actions#updateRangeDisplay"
     * Optionally set data-ui-actions-suffix-param="%" for suffix
     */
    updateRangeDisplay(event) {
        const suffix = event.params?.suffix || '%';
        const target = event.target.nextElementSibling;
        if (target) {
            target.textContent = event.target.value + suffix;
        }
    }

    /**
     * Show an Aurora toast (window.faToast). Also dispatches `ui-actions:toast`
     * so a page can react to it.
     */
    showToast(message, type = 'info') {
        this.dispatch('toast', { detail: { message, type } });

        if (typeof window.faToast === 'function') {
            window.faToast(message, type === 'error' ? 'danger' : type);
        }
    }
}
