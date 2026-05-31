/**
 * Persona Quick-Switcher Controller
 *
 * Powers the "Persona-Vorschau" radiogroup in the sidebar mega-menu
 * (_mega_menu.html.twig, ROLE_COMPLIANCE_MANAGER + expert density). Lets a
 * compliance manager preview the app through a persona's eyes (CISO / Risk /
 * DPO / ISB / BCM).
 *
 * Interface (wired in the template):
 *   data-controller="persona-switcher"
 *   data-persona-switcher-endpoint-value="{{ path('app_preferences_persona_switch') }}"
 *   data-persona-switcher-csrf-value="{{ csrf_token('persona_switch_' ~ app.user.id) }}"
 *   data-persona-switcher-current-value="{{ session acting-as persona or '' }}"
 *   <button data-persona-switcher-target="option"
 *           data-persona="PERSONA_CISO"
 *           data-action="click->persona-switcher#switch">CISO</button>
 *
 * Effect: POSTs the chosen persona (CSRF-protected) to the backend, which
 * stores it in the session; the acting-as persona re-shapes the whole UI
 * (dashboards + voters), so on success we reload. Clicking the active persona
 * reverts to the user's own view.
 *
 * NOTE: this controller previously did not exist — the buttons were wired in
 * the template but the Stimulus action resolved to nothing, so clicking them
 * silently did nothing.
 */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['option']
    static values  = { endpoint: String, csrf: String, current: String }

    connect() {
        this._busy = false;
        this._highlight(this.currentValue);
    }

    /**
     * @param {Event} event
     */
    switch(event) {
        const persona = event.currentTarget.dataset.persona;
        if (!persona || this._busy) return;
        // Clicking the already-active persona reverts to the user's own view.
        const target = persona === this.currentValue ? 'revert' : persona;
        this._post(target);
    }

    /** Explicit "back to my own (Compliance) view" action. */
    revert() {
        if (this._busy) return;
        this._post('revert');
    }

    async _post(persona) {
        if (!this.endpointValue) return;

        this._busy = true;
        this.optionTargets.forEach(opt => { opt.disabled = true; });

        try {
            const res = await fetch(this.endpointValue, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: new URLSearchParams({ _token: this.csrfValue, persona }),
            });
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            // The acting-as persona lives in the session and re-shapes the whole
            // UI; navigate straight into the target cockpit (or back to the
            // Compliance dashboard on revert). Falls back to a reload.
            const data = await res.json().catch(() => ({}));
            if (data && typeof data.redirect === 'string' && data.redirect) {
                window.location.assign(data.redirect);
            } else {
                window.location.reload();
            }
        } catch {
            this._busy = false;
            this.optionTargets.forEach(opt => { opt.disabled = false; });
            window.dispatchEvent(new CustomEvent('show-toast', {
                detail: { message: 'Persona-Wechsel fehlgeschlagen.', type: 'error' },
            }));
        }
    }

    _highlight(persona) {
        if (!this.hasOptionTarget) return;
        this.optionTargets.forEach(opt => {
            const active = persona !== '' && opt.dataset.persona === persona;
            opt.classList.toggle('is-active', active);
            opt.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }
}
