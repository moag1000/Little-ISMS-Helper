import { Controller } from '@hotwired/stimulus';

/**
 * Wizard Busy Controller
 *
 * Attached to a wizard <form> that triggers a long-running action
 * (restore, migration, seeding, import). On submit:
 *   1. Disables all interactive form elements (inputs, selects, buttons, .btn links).
 *   2. Sets aria-busy on the form for assistive tech.
 *   3. Switches the Alva avatar mood (default: 'working') so the user sees
 *      the assistant is actively handling the request.
 *   4. Shows a transparent overlay over the form with a spinner + message.
 *
 * Re-enables on turbo:submit-end (error case), otherwise the page reloads
 * and fresh DOM state applies.
 *
 * Usage:
 *   <form data-controller="wizard-busy"
 *         data-wizard-busy-message-value="Alva migriert die Datenbank..."
 *         data-wizard-busy-alva-mood-value="working">
 */
export default class extends Controller {
    static values = {
        message:  { type: String, default: 'Alva kümmert sich darum…' },
        alvaMood: { type: String, default: 'working' },
    };

    connect() {
        this._onSubmit       = this.handleSubmit.bind(this);
        this._onTurboEnd     = this.handleTurboEnd.bind(this);
        this._onPageHide     = this.reset.bind(this);
        this.element.addEventListener('submit', this._onSubmit);
        document.addEventListener('turbo:submit-end', this._onTurboEnd);
        window.addEventListener('pagehide', this._onPageHide);
    }

    disconnect() {
        this.element.removeEventListener('submit', this._onSubmit);
        document.removeEventListener('turbo:submit-end', this._onTurboEnd);
        window.removeEventListener('pagehide', this._onPageHide);
    }

    handleSubmit(event) {
        if (this.element.dataset.wizardBusyArmed === '1') {
            // second submit → swallow (prevents double-click)
            event.preventDefault();
            return;
        }
        this.element.dataset.wizardBusyArmed = '1';
        this.element.setAttribute('aria-busy', 'true');
        this.element.classList.add('wizard-busy');
        this._originalMood = this.captureAlvaMood();
        this.disableForm();
        this.setAlvaMood(this.alvaMoodValue);
        this.showOverlay(this.messageValue);
    }

    handleTurboEnd(event) {
        const form = event.detail?.formSubmission?.formElement;
        if (form === this.element) {
            // Always re-enable on submit-end. If the response was a redirect
            // and Turbo navigated, the DOM is replaced and reset() is a no-op
            // on the gone form. If the response was a 200 stream-replace (e.g.
            // DB-test result that re-renders the same page), the form stayed
            // → user must be able to click again. The previous "only on
            // failure" guard left forms locked after successful Turbo-render.
            this.reset();
        }
    }

    disableForm() {
        // CRITICAL: text/select/textarea/checkbox/radio must use READONLY (not
        // DISABLED) — disabled inputs don't get submitted in POST body, which
        // bites form validation with "field should not be empty" even though
        // the user filled it in. Disabled is only for buttons + links.
        const selector = 'input:not([type="hidden"]), select, textarea, button, a.btn';
        this._previouslyDisabled = new Set();
        this._previouslyReadonly = new Set();
        this.element.querySelectorAll(selector).forEach((el) => {
            if (el.disabled || el.classList.contains('disabled')) {
                this._previouslyDisabled.add(el);
                return;
            }
            if (el.tagName === 'A') {
                el.classList.add('disabled');
                el.setAttribute('aria-disabled', 'true');
                el.setAttribute('tabindex', '-1');
            } else if (el.tagName === 'BUTTON') {
                el.disabled = true;
            } else {
                // <input>, <select>, <textarea> — preserve POST value
                if (el.readOnly) {
                    this._previouslyReadonly.add(el);
                }
                el.readOnly = true;
                el.setAttribute('aria-readonly', 'true');
                // Visually hint the lock for select/checkbox/radio (which
                // don't natively grey out under readonly): add a class
                el.classList.add('wizard-busy-locked');
            }
        });
    }

    reEnableForm() {
        const selector = 'input:not([type="hidden"]), select, textarea, button, a.btn';
        this.element.querySelectorAll(selector).forEach((el) => {
            if (this._previouslyDisabled && this._previouslyDisabled.has(el)) return;
            if (el.tagName === 'A') {
                el.classList.remove('disabled');
                el.removeAttribute('aria-disabled');
                el.removeAttribute('tabindex');
            } else if (el.tagName === 'BUTTON') {
                el.disabled = false;
            } else {
                if (!this._previouslyReadonly || !this._previouslyReadonly.has(el)) {
                    el.readOnly = false;
                }
                el.removeAttribute('aria-readonly');
                el.classList.remove('wizard-busy-locked');
            }
        });
    }

    captureAlvaMood() {
        const alva = document.querySelector('.fa-alva');
        if (!alva) return null;
        const moodClass = Array.from(alva.classList).find((c) => c.startsWith('fa-alva--'));
        return moodClass || null;
    }

    setAlvaMood(mood) {
        const alva = document.querySelector('.fa-alva');
        if (!alva) return;
        Array.from(alva.classList).forEach((c) => {
            if (c.startsWith('fa-alva--')) alva.classList.remove(c);
        });
        alva.classList.add('fa-alva--' + mood);
    }

    restoreAlvaMood() {
        const alva = document.querySelector('.fa-alva');
        if (!alva) return;
        Array.from(alva.classList).forEach((c) => {
            if (c.startsWith('fa-alva--')) alva.classList.remove(c);
        });
        if (this._originalMood) {
            alva.classList.add(this._originalMood);
        } else {
            alva.classList.add('fa-alva--idle');
        }
    }

    showOverlay(message) {
        if (this.element.querySelector('.wizard-busy-overlay')) return;
        const overlay = document.createElement('div');
        overlay.className = 'wizard-busy-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML =
            '<div class="wizard-busy-overlay__inner">' +
                '<div class="spinner-border text-primary" role="status" aria-hidden="true"></div>' +
                '<div class="wizard-busy-overlay__text"></div>' +
            '</div>';
        overlay.querySelector('.wizard-busy-overlay__text').textContent = message;
        // Ensure form can host absolutely-positioned overlay.
        const cs = window.getComputedStyle(this.element);
        if (cs.position === 'static') {
            this.element.style.position = 'relative';
        }
        this.element.appendChild(overlay);
    }

    hideOverlay() {
        const overlay = this.element.querySelector('.wizard-busy-overlay');
        if (overlay) overlay.remove();
    }

    reset() {
        delete this.element.dataset.wizardBusyArmed;
        this.element.removeAttribute('aria-busy');
        this.element.classList.remove('wizard-busy');
        this.reEnableForm();
        this.restoreAlvaMood();
        this.hideOverlay();
    }
}
