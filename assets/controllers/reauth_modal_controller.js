import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora v4.0 — reauth-modal controller
 *
 * Handles the re-authentication modal challenge for RememberMe sessions.
 * Works in two modes:
 *
 *  1. Modal mode (default): wired alongside `fa-modal` on `#reauth-modal`.
 *     Listens for `fa-reauth:open` events (dispatched by the global fetch
 *     interceptor when a 403+reauth JSON response is detected).
 *     Updates `return_to` from the event detail, then programmatically opens
 *     the fa-modal via the `fa-modal:request-open` event.
 *
 *  2. Inline mode (data-reauth-modal-inline-value="true"): used on the
 *     full-page reauth_page.html.twig where the form is already rendered
 *     directly without a modal shell.
 *
 * Values:
 *   provider   String — 'password' | 'azure_oauth' | 'azure_saml' | 'oidc'
 *   returnTo   String — relative path to navigate to after success
 *   ssoSlug    String — IdP slug for OIDC providers
 *   inline     Boolean — inline / full-page mode (no modal shell)
 *
 * Targets:
 *   form          — password <form> element
 *   passwordInput — <input type="password">
 *   submitButton  — primary confirm button
 *   errorMessage  — error display element
 *
 * Events consumed:
 *   fa-reauth:open  — opens the modal and updates return_to from event detail
 *
 * Events dispatched:
 *   reauth-modal:success  — after successful reauth (bubbles, detail: {return_to})
 */
export default class extends Controller {
    static targets = ['form', 'passwordInput', 'submitButton', 'errorMessage'];

    static values = {
        provider: { type: String, default: 'password' },
        returnTo: { type: String, default: '/' },
        ssoSlug:  { type: String, default: '' },
        inline:   { type: Boolean, default: false },
    };

    #boundHandleReauthOpen = null;

    connect() {
        this.#boundHandleReauthOpen = this.#handleReauthOpen.bind(this);
        document.addEventListener('fa-reauth:open', this.#boundHandleReauthOpen);
    }

    disconnect() {
        document.removeEventListener('fa-reauth:open', this.#boundHandleReauthOpen);
    }

    // ── Public actions ────────────────────────────────────────────────────

    /**
     * Action: submit->reauth-modal#submitPassword
     * Sends POST /{locale}/reauth/password with the entered password.
     */
    async submitPassword(event) {
        event.preventDefault();

        if (!this.hasPasswordInputTarget) {
            return;
        }

        const password = this.passwordInputTarget.value;
        if (!password) {
            this.#showError(this.#t('reauth.error.password_required', 'Bitte Passwort eingeben.'));
            return;
        }

        this.#setLoading(true);
        this.#clearError();

        const locale = window.location.pathname.match(/^\/([a-z]{2})\//)?.[1] || 'de';

        try {
            const response = await fetch(`/${locale}/reauth/password`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    password: password,
                    return_to: this.returnToValue,
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok && data.success) {
                this.#onSuccess(data.return_to || this.returnToValue);
            } else {
                const msg = data.error
                    ? this.#t(data.error, this.#fallbackErrorMsg(data.error))
                    : this.#t('reauth.error.generic', 'Authentifizierung fehlgeschlagen.');
                this.#showError(msg);
            }
        } catch (_err) {
            this.#showError(this.#t('reauth.error.network', 'Verbindungsfehler. Bitte erneut versuchen.'));
        } finally {
            this.#setLoading(false);
        }
    }

    /**
     * Action: click->reauth-modal#redirectSso
     * Redirects to the SSO re-auth start endpoint.
     */
    redirectSso() {
        const provider = this.providerValue;
        const returnTo = encodeURIComponent(this.returnToValue);
        const locale = window.location.pathname.match(/^\/([a-z]{2})\//)?.[1] || 'de';
        let url;

        if (provider === 'oidc') {
            const slug = this.ssoSlugValue;
            url = `/${locale}/reauth/sso/oidc?return_to=${returnTo}${slug ? '&slug=' + encodeURIComponent(slug) : ''}`;
        } else {
            url = `/${locale}/reauth/sso/${encodeURIComponent(provider)}?return_to=${returnTo}`;
        }

        window.location.href = url;
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Handles the `fa-reauth:open` custom event dispatched by the fetch interceptor.
     * detail: { provider, user_identifier, return_to, sso_slug }
     */
    #handleReauthOpen(event) {
        const detail = event.detail || {};

        // Update values from the event payload
        if (detail.provider)  this.providerValue  = detail.provider;
        if (detail.return_to) this.returnToValue   = detail.return_to;
        if (detail.sso_slug)  this.ssoSlugValue    = detail.sso_slug;

        // Clear any previous error / password
        this.#clearError();
        if (this.hasPasswordInputTarget) {
            this.passwordInputTarget.value = '';
        }

        if (this.inlineValue) {
            // Inline mode: nothing to open, form is already visible.
            return;
        }

        // Modal mode: forward to fa-modal shell via its canonical open event.
        document.dispatchEvent(new CustomEvent('fa-modal:request-open', {
            bubbles: true,
            detail: { id: 'reauth-modal' },
        }));
    }

    #onSuccess(returnTo) {
        // Dispatch success event so other controllers can react
        this.dispatch('reauth-modal:success', { detail: { return_to: returnTo }, bubbles: true });

        if (this.inlineValue) {
            // Full-page: navigate to original target
            window.location.href = returnTo;
            return;
        }

        // Modal: close the modal first, then reload the original page / retry.
        // Close via fa-modal cancel action (which dispatches fa-modal:closed)
        const modal = document.getElementById('reauth-modal');
        if (modal && modal._stimulus_controller) {
            // If fa-modal controller is accessible, use its open value.
        }
        // Fallback: dispatch close request
        document.dispatchEvent(new CustomEvent('fa-modal:request-close', {
            bubbles: true,
            detail: { id: 'reauth-modal' },
        }));

        // Short delay so the modal closes before navigation
        setTimeout(() => {
            window.location.href = returnTo;
        }, 200);
    }

    #setLoading(loading) {
        if (this.hasSubmitButtonTarget) {
            this.submitButtonTarget.disabled = loading;
            if (loading) {
                this.submitButtonTarget.classList.add('is-loading');
            } else {
                this.submitButtonTarget.classList.remove('is-loading');
            }
        }
    }

    #showError(message) {
        if (this.hasErrorMessageTarget) {
            this.errorMessageTarget.textContent = message;
            this.errorMessageTarget.hidden = false;
        }
    }

    #clearError() {
        if (this.hasErrorMessageTarget) {
            this.errorMessageTarget.textContent = '';
            this.errorMessageTarget.hidden = true;
        }
    }

    /**
     * Simple translation lookup from window.translations.security.
     * Falls back to the provided default string.
     */
    #t(key, fallback) {
        try {
            const parts = key.split('.');
            // key format: "reauth.error.invalid_password" → translations.security.reauth.error.invalid_password
            let node = window.translations?.security;
            for (const part of parts.slice(0)) {
                node = node?.[part];
            }
            return (typeof node === 'string') ? node : fallback;
        } catch (_) {
            return fallback;
        }
    }

    #fallbackErrorMsg(errorKey) {
        const map = {
            'reauth.error.invalid_password': 'Passwort ist falsch.',
            'reauth.error.password_required': 'Bitte Passwort eingeben.',
            'reauth.error.generic': 'Authentifizierung fehlgeschlagen.',
            'reauth.error.network': 'Verbindungsfehler.',
        };
        return map[errorKey] ?? 'Authentifizierung fehlgeschlagen.';
    }
}
