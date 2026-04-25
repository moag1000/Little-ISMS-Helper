import { Controller } from '@hotwired/stimulus';

/**
 * Async-Job Controller — for long-running form submits that would otherwise
 * hit proxy/PHP timeouts. Pattern:
 *
 *   1. Intercept submit, show wizard-busy overlay (Alva working + spinner).
 *   2. POST the form via fetch (server returns immediately via
 *      fastcgi_finish_request and continues work in background).
 *   3. Poll a status endpoint every N ms until status === 'success' | 'failed'.
 *   4. On success: reload page (or navigate to redirect URL from response).
 *   5. On failure: show error message in overlay, re-enable form.
 *
 * Form-element data attributes:
 *   data-controller="async-job"
 *   data-async-job-status-url-value="/setup/step3/schema-status"
 *   data-async-job-poll-interval-value="1500"      (default: 1500ms)
 *   data-async-job-message-running-value="Alva ist gerade dabei…"
 *   data-async-job-message-success-value="Erfolgreich!"
 *   data-async-job-redirect-url-value="/setup/step4" (optional; default: location.reload())
 *   data-action="submit->async-job#start"
 *
 * Plays nicely with wizard_busy_controller — directly emits the same overlay
 * structure so styling stays consistent.
 */
export default class extends Controller {
    static values = {
        statusUrl: String,
        pollInterval: { type: Number, default: 1500 },
        messageRunning: { type: String, default: 'Wird ausgeführt…' },
        messageSuccess: { type: String, default: 'Fertig.' },
        redirectUrl: { type: String, default: '' },
    };

    connect() {
        this._polling = false;
    }

    disconnect() {
        this._polling = false;
    }

    async start(event) {
        event.preventDefault();
        if (this._polling) return;

        this._polling = true;
        this._showOverlay(this.messageRunningValue);
        this._lockForm();
        this._switchAlvaMood('working');

        // POST the form. Server returns immediately via fastcgi_finish_request.
        const form = this.element;
        const formData = new FormData(form);

        try {
            const resp = await fetch(form.action, {
                method: form.method || 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!resp.ok) {
                const text = await resp.text();
                throw new Error(`HTTP ${resp.status}: ${text.slice(0, 200)}`);
            }

            // Now poll status
            await this._pollUntilDone();
        } catch (err) {
            this._showError(err.message || String(err));
            this._unlockForm();
            this._switchAlvaMood('warning');
            this._polling = false;
        }
    }

    async _pollUntilDone() {
        while (this._polling) {
            await this._sleep(this.pollIntervalValue);
            try {
                const resp = await fetch(this.statusUrlValue, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!resp.ok) continue; // transient failure, keep polling

                const data = await resp.json();
                if (data.status === 'success') {
                    this._showSuccess(data.message || this.messageSuccessValue);
                    this._switchAlvaMood('happy');
                    this._polling = false;
                    setTimeout(() => {
                        if (this.redirectUrlValue) {
                            window.location.href = this.redirectUrlValue;
                        } else {
                            window.location.reload();
                        }
                    }, 600);
                    return;
                }
                if (data.status === 'failed') {
                    this._showError(data.message || 'Fehlgeschlagen');
                    this._unlockForm();
                    this._switchAlvaMood('warning');
                    this._polling = false;
                    return;
                }
                // status === 'running' or 'idle' → keep polling
            } catch (_e) {
                // transient — keep polling
            }
        }
    }

    _sleep(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }

    _showOverlay(message) {
        this._removeOverlay();
        const overlay = document.createElement('div');
        overlay.className = 'wizard-busy-overlay async-job-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML =
            '<div class="wizard-busy-overlay__inner">' +
                '<div class="spinner-border text-primary" role="status" aria-hidden="true"></div>' +
                '<div class="wizard-busy-overlay__text"></div>' +
            '</div>';
        overlay.querySelector('.wizard-busy-overlay__text').textContent = message;
        const cs = window.getComputedStyle(this.element);
        if (cs.position === 'static') this.element.style.position = 'relative';
        this.element.classList.add('wizard-busy');
        this.element.appendChild(overlay);
    }

    _showSuccess(message) {
        const text = this.element.querySelector('.wizard-busy-overlay__text');
        if (text) text.textContent = message;
        const spinner = this.element.querySelector('.spinner-border');
        if (spinner) spinner.classList.replace('text-primary', 'text-success');
    }

    _showError(message) {
        const text = this.element.querySelector('.wizard-busy-overlay__text');
        if (text) text.textContent = '⚠ ' + message;
        const spinner = this.element.querySelector('.spinner-border');
        if (spinner) {
            spinner.classList.remove('spinner-border');
            spinner.classList.add('text-danger');
            spinner.innerHTML = '<i class="bi bi-x-octagon-fill"></i>';
        }
    }

    _removeOverlay() {
        const o = this.element.querySelector('.wizard-busy-overlay');
        if (o) o.remove();
        this.element.classList.remove('wizard-busy');
    }

    _lockForm() {
        const elements = this.element.querySelectorAll('input:not([type="hidden"]), select, textarea, button, a.btn');
        elements.forEach((el) => {
            if (el.tagName === 'BUTTON') {
                el.disabled = true;
            } else if (el.tagName === 'A') {
                el.classList.add('disabled');
                el.setAttribute('aria-disabled', 'true');
            } else {
                el.readOnly = true;
                el.classList.add('wizard-busy-locked');
            }
        });
    }

    _unlockForm() {
        const elements = this.element.querySelectorAll('input:not([type="hidden"]), select, textarea, button, a.btn');
        elements.forEach((el) => {
            if (el.tagName === 'BUTTON') {
                el.disabled = false;
            } else if (el.tagName === 'A') {
                el.classList.remove('disabled');
                el.removeAttribute('aria-disabled');
            } else {
                el.readOnly = false;
                el.classList.remove('wizard-busy-locked');
            }
        });
    }

    _switchAlvaMood(mood) {
        // Setup-Wizard wraps Alva in two layers: outer .fa-onboarding-fairy
        // (orbit + aura wrapper, animated via .fa-onboarding-fairy--{mood})
        // and inner .fa-alva SVG (bob/wings/face animations via .fa-alva--{mood}).
        // Both need the mood swap for the full visible effect.
        document.querySelectorAll('.fa-alva, .fa-onboarding-fairy').forEach((el) => {
            const baseClass = el.classList.contains('fa-onboarding-fairy')
                ? 'fa-onboarding-fairy--'
                : 'fa-alva--';
            Array.from(el.classList).forEach((c) => {
                if (c.startsWith(baseClass)) el.classList.remove(c);
            });
            el.classList.add(baseClass + mood);
        });
    }
}
