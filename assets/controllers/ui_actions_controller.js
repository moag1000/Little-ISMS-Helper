import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';
import { faConfirm, faPrompt, faToast } from '../js/fa-alerts.js';

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
 *     <form data-action="submit->ui-actions#submitOnce">…</form>
 *     <select data-action="change->ui-actions#autoSubmit">…</select>
 * </div>
 *
 * Inline on*="…" handlers and javascript: URLs are blocked by the CSP
 * (script-src without 'unsafe-inline') and by Gate 67 — use these actions.
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

        faConfirm(message, { tone, confirmLabel: event.params?.confirmLabel })
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

        faPrompt(message, { required, allowEmpty: !required, maxlength: 1000 }).then(apply);
        return false;
    }

    /**
     * Re-submit a form past this controller's confirm/prompt hook. The submit
     * event is dispatched synchronously inside requestSubmit(), so the hook
     * consumes the flag there; when native validation blocks the submission no
     * event fires and the flag would linger and skip the NEXT confirm — hence
     * it is always cleared once requestSubmit() returns.
     */
    #submitBypassing(form, submitter) {
        form.dataset.faConfirmBypass = '1';
        try {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter ?? null);
            } else {
                form.submit();
            }
        } finally {
            delete form.dataset.faConfirmBypass;
        }
    }

    #proceed(event, target) {
        if (target instanceof HTMLFormElement) {
            this.#submitBypassing(target, event.submitter ?? null);
        } else if (target instanceof HTMLAnchorElement && target.href) {
            window.location.href = target.href;
        } else if (target instanceof HTMLButtonElement && target.form) {
            this.#submitBypassing(target.form, target);
        }
    }

    /**
     * Copy text to clipboard
     * Use with: data-action="ui-actions#copyToClipboard" data-ui-actions-text-param="text"
     * Or copy a field's value: data-ui-actions-source-param="#input-id"
     */
    async copyToClipboard(event) {
        event.preventDefault();
        const text = event.params.text ?? document.querySelector(event.params.source)?.value;

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
     * Toggle a class (default: d-none) on every element matching a selector,
     * or on the trigger's next sibling when no target is given.
     * Use with: data-action="ui-actions#toggle" data-ui-actions-target-param="#element-id"
     * Optional: data-ui-actions-class-param="is-open"
     *           data-ui-actions-active-class-param="fa-cyber-btn--primary"
     *           data-ui-actions-inactive-class-param="fa-cyber-btn--ghost"
     *           → the trigger swaps these classes and flips aria-pressed.
     */
    toggle(event) {
        event.preventDefault();
        const trigger = event.currentTarget;
        const cssClass = event.params.class || 'd-none';
        const targets = event.params.target
            ? document.querySelectorAll(event.params.target)
            : [trigger.nextElementSibling].filter(Boolean);
        targets.forEach((target) => target.classList.toggle(cssClass));

        if (event.params.activeClass || event.params.inactiveClass) {
            const active = trigger.getAttribute('aria-pressed') !== 'true';
            trigger.setAttribute('aria-pressed', active ? 'true' : 'false');
            if (event.params.activeClass) trigger.classList.toggle(event.params.activeClass, active);
            if (event.params.inactiveClass) trigger.classList.toggle(event.params.inactiveClass, !active);
        }
    }

    /**
     * Disclosure button: flips aria-expanded, toggles `hidden` on the element
     * named by aria-controls and rotates a `.chevron-icon` inside the button.
     * Use with: <button aria-expanded="false" aria-controls="detail-1"
     *                  data-action="ui-actions#disclose">
     */
    disclose(event) {
        const trigger = event.currentTarget;
        const expanded = trigger.getAttribute('aria-expanded') !== 'true';
        trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        const panel = document.getElementById(trigger.getAttribute('aria-controls'));
        if (panel) {
            panel.hidden = !expanded;
        }
        const chevron = trigger.querySelector('.chevron-icon');
        if (chevron) {
            chevron.style.transform = expanded ? 'rotate(90deg)' : '';
        }
    }

    /**
     * Chip/tab switcher: show one panel of a group, hide the others (d-none)
     * and move the active class to the clicked trigger.
     * Use with: data-action="ui-actions#showPanel"
     *           data-ui-actions-panel-param="#tab-high"          panel to show
     *           data-ui-actions-panels-param=".notification-tab"  all panels
     *           data-ui-actions-triggers-param="[data-tab]"       all triggers
     *           data-ui-actions-active-class-param="fa-filter-chip--active"
     */
    showPanel(event) {
        event.preventDefault();
        const { panel, panels, triggers, activeClass } = event.params;
        const shown = document.querySelector(panel);
        document.querySelectorAll(panels).forEach((el) => el.classList.toggle('d-none', el !== shown));
        if (triggers) {
            document.querySelectorAll(triggers).forEach((el) => {
                const isActive = el === event.currentTarget;
                if (activeClass) el.classList.toggle(activeClass, isActive);
                el.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        }
    }

    /**
     * Copy the trigger's value into another field (e.g. a select outside a
     * form feeding a hidden input inside it).
     * Use with: data-action="change->ui-actions#copyValue" data-ui-actions-target-param="#hidden-input"
     */
    copyValue(event) {
        const target = document.querySelector(event.params.target);
        if (target) {
            target.value = event.currentTarget.value;
        }
    }

    /**
     * Before a form submits, split a free-text list ("12, 34 56") into one
     * hidden input per entry so the server receives an array.
     * Use with: data-action="submit->ui-actions#splitToHidden"
     *           data-ui-actions-source-param="control_ids_raw"   (input name)
     *           data-ui-actions-name-param="control_ids[]"       (hidden input name)
     */
    splitToHidden(event) {
        const form = event.currentTarget;
        const { source, name } = event.params;
        form.querySelectorAll('input[data-ui-actions-split]').forEach((el) => el.remove());
        const raw = form.querySelector(`[name="${CSS.escape(source)}"]`)?.value || '';
        raw.split(/[,\s]+/).filter(Boolean).forEach((value) => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = name;
            hidden.value = value;
            hidden.dataset.uiActionsSplit = '';
            form.appendChild(hidden);
        });
    }

    /**
     * Double-submit guard: disables the submitting button (or the form's first
     * button) once the browser accepted the submission.
     * Use with: <form data-action="submit->ui-actions#submitOnce">
     */
    submitOnce(event) {
        const form = event.currentTarget;
        const button = event.submitter || form.querySelector('button');
        // Disable after the submission's form data set has been built, so a
        // named submit button still sends its value.
        setTimeout(() => { if (button) button.disabled = true; }, 0);
    }

    /**
     * Go back in history (error pages' "Back" button).
     * Use with: data-action="ui-actions#historyBack"
     */
    historyBack(event) {
        event.preventDefault();
        window.history.back();
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
     * Optional: data-ui-actions-fields-param='{"document_id": 5}' posts these
     *           fields as form data (the CSRF token as `_token`) instead of JSON.
     */
    async confirmAndFetch(event) {
        event.preventDefault();
        // Capture before the first await: the browser resets currentTarget
        // once the event finished dispatching.
        const button = event.currentTarget;

        const message = event.params?.confirm || this.confirmMessageValue || faT('are_you_sure');
        const tone = event.params?.tone || 'danger';
        const ok = await faConfirm(message, { tone });
        if (!ok) {
            return;
        }

        const url = event.params.url;
        const method = event.params.method || 'POST';
        const shouldReload = event.params.reload === 'true' || event.params.reload === true;

        if (!url) {
            return;
        }

        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> ...';

        try {
            // Optional CSRF token: data-ui-actions-csrf-param="{{ csrf_token('…') }}".
            const csrfToken = event.params.csrf;
            const fields = event.params.fields;
            let request;
            if (fields && typeof fields === 'object') {
                const body = new FormData();
                Object.entries(fields).forEach(([key, value]) => body.append(key, value));
                if (csrfToken) {
                    body.append('_token', csrfToken);
                }
                request = { method, body, headers: { 'X-Requested-With': 'XMLHttpRequest' } };
            } else {
                request = {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
                    },
                };
            }
            const response = await fetch(url, request);

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
        faToast(message, 'danger');
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

        faToast(message, type === 'error' ? 'danger' : type);
    }
}
