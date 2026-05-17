import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora — Lifecycle Transition Dropdown Controller (lifecycle X.3)
 *
 * Fetches allowed transitions from the JSON API and renders them as
 * fa-menu items. Handles CSRF token injection, reason prompts, and
 * page reload on success or 409 conflict.
 *
 * Data values (set via Twig template):
 *   allowed-url   GET  /lifecycle/{type}/{id}/allowed-transitions
 *   transition-url POST /lifecycle/{type}/{id}/transition
 *   csrf          CSRF token string (lifecycle_transition intention)
 *   reason-label  Prompt text for reason_required transitions
 *   workflow      Workflow name (informational)
 */
export default class extends Controller {
    static targets = ['trigger', 'menu'];
    static values = {
        allowedUrl:    String,
        transitionUrl: String,
        csrf:          String,
        reasonLabel:   String,
        workflow:      String,
    };

    connect() {
        this.isOpen = false;
        this._lockVersion = null;
        this._boundOutside = this._handleOutside.bind(this);
        this._boundKeydown  = this._handleKeydown.bind(this);
    }

    disconnect() {
        this._removeListeners();
    }

    // ── Toggle ────────────────────────────────────────────────────────────────

    toggle(event) {
        if (event) event.stopPropagation();
        this.isOpen ? this._close() : this._open();
    }

    // ── Open / Close ──────────────────────────────────────────────────────────

    async _open() {
        if (!this.hasMenuTarget) return;
        this.triggerTarget.setAttribute('aria-expanded', 'true');
        this.menuTarget.innerHTML = `<div class="fa-menu__item fa-menu__item--loading" aria-disabled="true" role="menuitem"><span class="spinner-border spinner-border-sm me-2" role="status"></span>${this._t('loading')}</div>`;
        this.menuTarget.hidden = false;
        this.isOpen = true;
        document.addEventListener('click', this._boundOutside);
        document.addEventListener('keydown', this._boundKeydown);

        try {
            const res = await fetch(this.allowedUrlValue, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (!res.ok) {
                this._renderError(data.message ?? 'Error loading transitions.');
                return;
            }
            this._lockVersion = data.lock_version ?? null;
            this._renderMenu(data.allowed_transitions ?? []);
        } catch (err) {
            this._renderError(err.message ?? 'Network error.');
        }
    }

    _close() {
        if (!this.hasMenuTarget) return;
        this.menuTarget.hidden = true;
        this.isOpen = false;
        this.triggerTarget.setAttribute('aria-expanded', 'false');
        this._removeListeners();
    }

    // ── Menu rendering ────────────────────────────────────────────────────────

    _renderMenu(transitions) {
        if (!this.hasMenuTarget) return;
        if (transitions.length === 0) {
            this.menuTarget.innerHTML = `<div class="fa-menu__item fa-menu__item--muted" aria-disabled="true" role="menuitem">${this._t('no_transitions')}</div>`;
            return;
        }
        this.menuTarget.innerHTML = transitions
            .map(t => {
                const label = this._transitionLabel(t.name);
                const toLabel = this._statusLabel(t.to ?? '');
                const hint = toLabel ? ` <small class="text-muted ms-auto">→ ${toLabel}</small>` : '';
                return `<button type="button"
                            class="fa-menu__item"
                            role="menuitem"
                            data-transition="${this._esc(t.name)}"
                            data-reason-required="${t.reason_required ? '1' : '0'}"
                            data-action="click->lifecycle-dropdown#applyTransition">
                        ${this._esc(label)}${hint}
                    </button>`;
            })
            .join('');
        // Focus first item
        const first = this.menuTarget.querySelector('button');
        if (first) window.setTimeout(() => first.focus(), 10);
    }

    _renderError(msg) {
        if (!this.hasMenuTarget) return;
        this.menuTarget.innerHTML = `<div class="fa-menu__item fa-menu__item--danger" aria-disabled="true" role="menuitem">${this._esc(msg)}</div>`;
    }

    // ── Transition ────────────────────────────────────────────────────────────

    async applyTransition(event) {
        const btn = event.currentTarget;
        const transitionName = btn.dataset.transition;
        const needsReason = btn.dataset.reasonRequired === '1';
        let reason = null;

        if (needsReason) {
            reason = window.prompt(this.reasonLabelValue);
            if (reason === null || reason.trim() === '') {
                // User cancelled or empty — abort silently
                this._close();
                return;
            }
        }

        this._close();

        const body = { transition: transitionName };
        if (reason !== null) body.reason = reason;
        if (this._lockVersion !== null) body.lock_version = this._lockVersion;

        try {
            const res = await fetch(this.transitionUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': this.csrfValue,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });

            const data = await res.json();

            if (res.status === 409) {
                window.alert(data.message ?? 'Conflict — please reload.');
                window.location.reload();
                return;
            }
            if (!res.ok) {
                window.alert(data.message ?? `Error (${res.status})`);
                return;
            }
            // Success — reload to reflect new status
            window.location.reload();
        } catch (err) {
            window.alert(err.message ?? 'Network error.');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    _transitionLabel(name) {
        // Try to find pre-rendered label via a hidden data store on the page,
        // or fall back to humanised key (camelCase → sentence).
        const storeEl = document.querySelector(`[data-lifecycle-label-${name}]`);
        if (storeEl) return storeEl.dataset[`lifecycleLabel${this._camelize(name)}`] ?? name;
        return name.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    _statusLabel(status) {
        const storeEl = document.querySelector(`[data-lifecycle-status-label-${status}]`);
        if (storeEl) return storeEl.dataset[`lifecycleStatusLabel${this._camelize(status)}`] ?? status;
        return status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    _camelize(str) {
        return str.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
    }

    _t(key) {
        const map = {
            loading: 'Loading…',
            no_transitions: 'No transitions available.',
        };
        return map[key] ?? key;
    }

    _esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    _removeListeners() {
        document.removeEventListener('click', this._boundOutside);
        document.removeEventListener('keydown', this._boundKeydown);
    }

    _handleOutside(event) {
        if (!this.element.contains(event.target)) this._close();
    }

    _handleKeydown(event) {
        if (event.key === 'Escape') {
            this._close();
            this.triggerTarget.focus();
        }
        // Arrow keys for menu navigation
        if (this.isOpen && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            const items = Array.from(this.menuTarget.querySelectorAll('button[role="menuitem"]:not([aria-disabled])'));
            const idx = items.indexOf(document.activeElement);
            if (event.key === 'ArrowDown') items[(idx + 1) % items.length]?.focus();
            else items[(idx - 1 + items.length) % items.length]?.focus();
        }
    }
}
