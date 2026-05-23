import { Controller } from '@hotwired/stimulus';

/**
 * P-19 — Form-Step-Inline-Hint controller.
 *
 * Listens to input/change events on form fields, debounces, and asks
 * /api/alva-hint/form/{entityType} which AlvaFormHints currently apply
 * to the live payload. The server-side AlvaHintFormEvaluator returns
 * a list of already-translated hints; this controller mounts each one
 * as an Aurora-styled inline alert right below the form field whose
 * name matches `hint.field`.
 *
 * Wiring (FormType template, opt-in):
 *   <form data-controller="alva-hint-in-form"
 *         data-alva-hint-in-form-entity-type-value="incident"
 *         data-alva-hint-in-form-endpoint-value="{{ path('api_alva_hint_form_evaluate', {entityType: 'incident'}) }}"
 *         data-alva-hint-in-form-csrf-token-value="{{ csrf_token('alva_hint_form') }}"
 *         data-alva-hint-in-form-form-name-value="incident"
 *         data-action="input->alva-hint-in-form#scheduleEvaluate change->alva-hint-in-form#scheduleEvaluate">
 *     {{ form_widget(form) }}
 *   </form>
 *
 * The controller is intentionally tolerant: a 4xx/5xx response, a network
 * error, or a malformed payload just leaves the form alone. Inline hints
 * are non-essential UX — the regulatory show-page hint still fires after
 * save.
 */
export default class extends Controller {
    static values = {
        entityType: String,
        endpoint: String,
        csrfToken: String,
        // Symfony form name used to build the bracketed input-name prefix
        // ("incident" → "incident[dataBreachOccurred]"). Falls back to
        // entityType if not provided.
        formName: { type: String, default: '' },
        // Debounce delay so we don't pound the endpoint on every keystroke.
        debounceMs: { type: Number, default: 250 },
    };

    connect() {
        this.timer = null;
        this.activeHints = new Map(); // field → DOM node
        this.formName = this.formNameValue || this.entityTypeValue;
        // First evaluation on mount, so server-rendered fields with
        // critical values surface a hint without requiring a keystroke.
        this.scheduleEvaluate();
    }

    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
        this.activeHints.forEach((node) => node.remove());
        this.activeHints.clear();
    }

    scheduleEvaluate() {
        if (this.timer) {
            clearTimeout(this.timer);
        }
        this.timer = setTimeout(() => this.evaluate(), this.debounceMsValue);
    }

    async evaluate() {
        const payload = this.collectPayload();

        let response;
        try {
            response = await fetch(this.endpointValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    _token: this.csrfTokenValue,
                    payload,
                }),
            });
        } catch (err) {
            return;
        }

        if (!response.ok) {
            return;
        }

        let data;
        try {
            data = await response.json();
        } catch (err) {
            return;
        }

        if (!data || data.ok !== true || !Array.isArray(data.hints)) {
            return;
        }

        this.renderHints(data.hints);
    }

    /**
     * Build a plain-object payload from the form's inputs. Bracketed names
     * (`incident[dataBreachOccurred]`) are stripped down to the bare
     * property name so the server-side rule sees `dataBreachOccurred`.
     * Radios / checkboxes that are not checked are omitted (mirrors how
     * Symfony Forms POST-serializes).
     */
    collectPayload() {
        const formEl = this.element.matches('form') ? this.element : this.element.querySelector('form') || this.element;
        const payload = {};
        const inputs = formEl.querySelectorAll('input, select, textarea');

        inputs.forEach((input) => {
            const fullName = input.name;
            if (!fullName) {
                return;
            }
            const propName = this.extractPropertyName(fullName);
            if (!propName) {
                return;
            }
            if (input.type === 'checkbox' || input.type === 'radio') {
                if (!input.checked) {
                    return;
                }
                payload[propName] = input.value;
                return;
            }
            if (input.type === 'number' || input.type === 'range') {
                if (input.value === '') {
                    return;
                }
                payload[propName] = input.value;
                return;
            }
            payload[propName] = input.value;
        });

        return payload;
    }

    /**
     * `incident[dataBreachOccurred]` → `dataBreachOccurred`
     * `incident[severity]`           → `severity`
     * `_token` / unrelated inputs    → null
     */
    extractPropertyName(fullName) {
        const prefix = `${this.formName}[`;
        if (!fullName.startsWith(prefix)) {
            return null;
        }
        const inner = fullName.slice(prefix.length, fullName.indexOf(']', prefix.length));
        return inner || null;
    }

    /**
     * Reconcile rendered hint nodes with the new hint list. Hints keyed
     * by `field` so re-evaluation with the same set of fields just
     * updates the title/body in place — no flicker.
     */
    renderHints(hints) {
        const newFields = new Set();
        hints.forEach((hint) => {
            newFields.add(hint.field);
            this.upsertHint(hint);
        });
        // Remove hints whose field no longer triggers a match.
        Array.from(this.activeHints.keys()).forEach((field) => {
            if (!newFields.has(field)) {
                this.activeHints.get(field).remove();
                this.activeHints.delete(field);
            }
        });
    }

    upsertHint(hint) {
        let node = this.activeHints.get(hint.field);
        if (!node) {
            const anchor = this.findAnchorFor(hint.field);
            if (!anchor) {
                return;
            }
            node = document.createElement('div');
            node.className = `fa-alert fa-alert--${hint.tier} mt-2`;
            node.setAttribute('role', 'status');
            node.setAttribute('aria-live', 'polite');
            node.dataset.alvaInlineHintField = hint.field;
            anchor.appendChild(node);
            this.activeHints.set(hint.field, node);
        }

        const actionHtml = hint.action
            ? `<a href="${this.escapeAttr(hint.action.url)}" class="fa-cyber-btn fa-cyber-btn--${hint.tier} fa-cyber-btn--sm mt-2"><i class="fa-icon fa-icon--util-arrow-right" aria-hidden="true"></i>${this.escapeHtml(hint.action.label)}</a>`
            : '';

        node.innerHTML = `
            <i class="fa-icon fa-icon--ui-stars fa-alert__icon" aria-hidden="true"></i>
            <div class="fa-alert__body">
                <div class="fa-alert__title">${this.escapeHtml(hint.title)}</div>
                <p class="fa-alert__message mb-0">${this.escapeHtml(hint.body)}</p>
                ${actionHtml}
            </div>
        `;

        // Best-effort mood broadcast (optional Alva mascot integration —
        // mirrors alva_hint_controller.js).
        if (hint.mood && window.alvaBus && typeof window.alvaBus.emit === 'function') {
            window.alvaBus.emit({
                mood: hint.mood,
                reason: `alva-hint-in-form:${hint.key}`,
                ttlMs: 4000,
            });
        }
    }

    findAnchorFor(propName) {
        const formEl = this.element.matches('form') ? this.element : this.element.querySelector('form') || this.element;
        // Prefer the `.form-group` / `.mb-3` wrapper around the first
        // input matching the field name — append the alert inside the
        // wrapper so it visually belongs to the field.
        const fullName = `${this.formName}[${propName}]`;
        const input = formEl.querySelector(`[name="${fullName}"], [name="${fullName}[]"]`);
        if (!input) {
            return null;
        }
        return input.closest('.mb-3, .form-group, .fa-form-section, fieldset') || input.parentElement;
    }

    escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    escapeAttr(value) {
        return this.escapeHtml(value).replace(/"/g, '&quot;');
    }
}
