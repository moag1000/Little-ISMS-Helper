import { Controller } from '@hotwired/stimulus';

/**
 * S14 Cluster A — Quick-Create Modal Controller
 *
 * Lightweight wrapper around the canonical fa-modal shell that:
 *   1. Opens a Quick-Create form modal when the trigger button is clicked
 *   2. Submits the form as JSON to /api/quick-create/{entityType}
 *   3. On success, appends the new option to the parent select (TomSelect-aware)
 *      and pre-selects it
 *   4. Closes the modal and shows a fa-toast confirmation
 *
 * Usage (Twig):
 *   <div data-controller="quick-create-modal"
 *        data-quick-create-modal-entity-type-value="asset"
 *        data-quick-create-modal-target-select-value="business_process_supportingAssets"
 *        data-quick-create-modal-modal-id-value="quickCreateAssetModal"
 *        data-quick-create-modal-csrf-value="{{ csrf_token('quick_create') }}">
 *
 *     <button type="button"
 *             class="fa-cyber-btn fa-cyber-btn--ghost fa-cyber-btn--sm"
 *             data-action="quick-create-modal#openModal">
 *       + Schnell anlegen
 *     </button>
 *
 *     {{ _fa_modal.settings({ id: 'quickCreateAssetModal', ... }) }}
 *   </div>
 *
 * The target select can be:
 *   - A native <select> — option appended via Option() constructor
 *   - A TomSelect-enhanced select — option added via TomSelect API (sync via DOM event)
 */
export default class extends Controller {
    static values = {
        entityType:   String,
        targetSelect: String,   // id of the parent <select>
        modalId:      String,   // id of the fa-modal-settings to open
        csrf:         String,   // CSRF token for `quick_create` token id
        endpoint:     { type: String, default: '/api/quick-create' },
    };

    static targets = ['nameInput', 'errorBox', 'submitButton'];

    openModal(event) {
        event?.preventDefault();
        if (!this.modalIdValue) {
            console.warn('[quick-create-modal] modalId value missing');
            return;
        }
        document.dispatchEvent(
            new CustomEvent('fa-modal:request-open', {
                bubbles: true,
                detail: { id: this.modalIdValue },
            }),
        );
        // Defer focus until the modal opens
        setTimeout(() => {
            if (this.hasNameInputTarget) {
                this.nameInputTarget.focus();
                this.nameInputTarget.select();
            }
        }, 80);
    }

    async submit(event) {
        event?.preventDefault();

        const name = this.hasNameInputTarget ? this.nameInputTarget.value.trim() : '';
        if (!name) {
            this.#showError(this.#t('quick_create.error.name_required'));
            return;
        }

        this.#setBusy(true);
        this.#clearError();

        let response;
        try {
            response = await fetch(`${this.endpointValue}/${this.entityTypeValue}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name,
                    _token: this.csrfValue,
                }),
            });
        } catch (err) {
            this.#showError(this.#t('quick_create.error.network'));
            this.#setBusy(false);
            return;
        }

        let data;
        try {
            data = await response.json();
        } catch (_) {
            data = { ok: false, error: `HTTP ${response.status}` };
        }

        if (!response.ok || !data?.ok) {
            const errs = Array.isArray(data?.errors)
                ? data.errors.join(', ')
                : (data?.error || `HTTP ${response.status}`);
            this.#showError(errs);
            this.#setBusy(false);
            return;
        }

        // Success path — inject the new option into the parent <select>
        this.#injectOption(data.id, data.label);

        // Reset modal state
        if (this.hasNameInputTarget) this.nameInputTarget.value = '';
        this.#setBusy(false);

        // Toast (fa-toast bus listens on window.faToast if present)
        if (window.faToast?.success) {
            window.faToast.success(this.#t('quick_create.toast.success', { label: data.label }));
        }

        // Close the modal
        document.dispatchEvent(
            new CustomEvent('fa-modal:request-close', {
                bubbles: true,
                detail: { id: this.modalIdValue },
            }),
        );
        // Also dispatch a generic close-by-cancel since fa-modal closes via
        // its internal openValue. The cleanest cross-controller signal:
        const modalEl = document.getElementById(this.modalIdValue);
        if (modalEl) {
            // The fa-modal shell exposes an `open` Stimulus value; setting
            // data-fa-modal-open-value="false" triggers the close transition.
            modalEl.setAttribute('data-fa-modal-open-value', 'false');
        }
    }

    #injectOption(id, label) {
        if (!this.targetSelectValue) return;
        const select = document.getElementById(this.targetSelectValue)
            || document.querySelector(`select[name="${this.targetSelectValue}"]`);
        if (!select) {
            console.warn(`[quick-create-modal] target select not found: ${this.targetSelectValue}`);
            return;
        }

        // TomSelect path — the wrapped TomSelect instance is attached at
        // .tomselect on the original select element.
        if (select.tomselect) {
            select.tomselect.addOption({ value: String(id), text: label });
            const current = select.tomselect.getValue();
            const next = Array.isArray(current) ? [...current, String(id)] : String(id);
            select.tomselect.setValue(next, /* silent */ false);
            return;
        }

        // Native select fallback
        const opt = new Option(label, String(id), true, true);
        select.add(opt);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    #showError(msg) {
        if (this.hasErrorBoxTarget) {
            this.errorBoxTarget.textContent = msg;
            this.errorBoxTarget.hidden = false;
        } else {
            console.error('[quick-create-modal]', msg);
        }
    }

    #clearError() {
        if (this.hasErrorBoxTarget) {
            this.errorBoxTarget.textContent = '';
            this.errorBoxTarget.hidden = true;
        }
    }

    #setBusy(busy) {
        if (this.hasSubmitButtonTarget) {
            this.submitButtonTarget.disabled = busy;
            this.submitButtonTarget.classList.toggle('is-loading', busy);
        }
        if (this.hasNameInputTarget) {
            this.nameInputTarget.disabled = busy;
        }
    }

    /**
     * Minimal translation fallback — looks up data attrs first, falls back
     * to a sensible English string. Templates can override via
     * data-quick-create-modal-i18n-* attributes on the controller root.
     */
    #t(key, params = {}) {
        const slug = key.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
        const attr = `data-quick-create-modal-i18n-${slug}`;
        const fromDom = this.element.getAttribute(attr);
        let str = fromDom
            || {
                'quick-create-error-name-required': 'Name is required.',
                'quick-create-error-network':       'Network error. Please retry.',
                'quick-create-toast-success':       'Created: %label%',
            }[slug]
            || key;
        for (const [k, v] of Object.entries(params)) {
            str = str.replace(`%${k}%`, String(v));
        }
        return str;
    }
}
