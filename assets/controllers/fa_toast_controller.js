import { Controller } from '@hotwired/stimulus';

/**
 * fa-toast — Aurora Toast Notification Stack-Manager (V3 W3-UX-Toast)
 *
 * Stack-managed toast container. Renders dynamically created toasts and
 * handles auto-dismiss, persistence, animations, and a11y.
 *
 * Mount once on a `.fa-toast-stack` container (e.g. in base.html.twig):
 *   <div class="fa-toast-stack" data-controller="fa-toast" data-fa-toast-target="stack"></div>
 *
 * Triggering toasts from anywhere:
 *   1) DOM event (recommended — decoupled):
 *      window.dispatchEvent(new CustomEvent('fa-toast:show', {
 *          detail: { tone: 'success', title: 'Saved.', message: '…',
 *                    duration: 5000, persistent: false, actions: [...] }
 *      }));
 *
 *   2) Direct controller invocation (testing):
 *      const el = document.querySelector('[data-controller~="fa-toast"]');
 *      const ctrl = app.getControllerForElementAndIdentifier(el, 'fa-toast');
 *      ctrl.success('Saved!');
 *
 * Tones: 'info' (default) | 'success' | 'warning' | 'danger'
 * Persistence: pass `persistent: true` to disable auto-dismiss; user must
 * click the close-button. The progress bar is hidden in that case.
 *
 * Backwards-compat: also listens for the legacy `toast:show` event used by
 * the older `toast` controller, so existing dispatchers keep working.
 */
export default class extends Controller {
    static targets = ['stack'];
    static values = {
        defaultDuration: { type: Number, default: 5000 },
        maxStackSize:    { type: Number, default: 5 },
    };

    connect() {
        this._handler = this._handleEvent.bind(this);
        window.addEventListener('fa-toast:show', this._handler);
        window.addEventListener('toast:show',    this._handler);   // legacy

        this._convertFlashMessages();
    }

    disconnect() {
        window.removeEventListener('fa-toast:show', this._handler);
        window.removeEventListener('toast:show',    this._handler);
    }

    _handleEvent(event) {
        const d = event.detail || {};
        // Map legacy `type` ('error'|'success'|'warning'|'info') to `tone`.
        const tone = d.tone || this._mapLegacyType(d.type) || 'info';
        this.show({
            tone,
            title:      d.title      || '',
            message:    d.message    || '',
            duration:   d.duration   ?? this.defaultDurationValue,
            persistent: d.persistent || false,
            actions:    d.actions    || [],
        });
    }

    _mapLegacyType(type) {
        if (!type) return null;
        if (type === 'error') return 'danger';
        return type;
    }

    _convertFlashMessages() {
        const flashContainer = document.querySelector('#flash-messages');
        if (!flashContainer) return;
        if (flashContainer.hasAttribute('data-skip-toast')) return;

        const flashes = flashContainer.querySelectorAll('.alert');
        flashes.forEach(flash => {
            const tone = this._toneFromAlertClass(flash.className);
            const message = flash.textContent.trim();
            if (message) {
                this.show({
                    tone,
                    message,
                    duration: message.startsWith('DEBUG:') ? 30000 : 5000,
                });
            }
            flash.remove();
        });

        if (flashContainer.children.length === 0) {
            flashContainer.style.display = 'none';
        }
    }

    _toneFromAlertClass(className) {
        if (className.includes('alert-success')) return 'success';
        if (className.includes('alert-danger') || className.includes('alert-error')) return 'danger';
        if (className.includes('alert-warning')) return 'warning';
        return 'info';
    }

    /* ------------------------------------------------------------------ Public API */
    /**
     * Show a toast.
     * @param {Object} opts
     * @param {string} opts.tone        info|success|warning|danger
     * @param {string} [opts.title]
     * @param {string} opts.message
     * @param {number} [opts.duration]  ms; 0 / persistent → no auto-dismiss
     * @param {boolean}[opts.persistent]
     * @param {Array}  [opts.actions]   [{label,href,target}]
     * @returns {HTMLElement}
     */
    show(opts) {
        if (!this.hasStackTarget) return null;

        const stack = this.stackTarget;
        // Enforce max stack size — drop oldest.
        while (stack.children.length >= this.maxStackSizeValue) {
            const oldest = stack.firstElementChild;
            if (!oldest) break;
            this._dismiss(oldest);
        }

        const tone       = opts.tone || 'info';
        const persistent = opts.persistent || opts.duration === 0;
        const duration   = persistent ? 0 : (opts.duration ?? this.defaultDurationValue);

        const toast = this._createToast({ ...opts, tone, persistent });
        stack.appendChild(toast);

        // Set progress duration
        const prog = toast.querySelector('.fa-toast__progress');
        if (prog && duration > 0) {
            prog.style.animationDuration = `${duration}ms`;
        }

        if (duration > 0) {
            setTimeout(() => this._dismiss(toast), duration);
        }
        return toast;
    }

    success(message, opts = {}) { return this.show({ ...opts, tone: 'success', message }); }
    info   (message, opts = {}) { return this.show({ ...opts, tone: 'info',    message }); }
    warning(message, opts = {}) { return this.show({ ...opts, tone: 'warning', message, duration: opts.duration ?? 6000 }); }
    danger (message, opts = {}) { return this.show({ ...opts, tone: 'danger',  message, duration: opts.duration ?? 7000 }); }
    error  (message, opts = {}) { return this.danger(message, opts); }   // alias

    dismiss(event) {
        const toast = event.currentTarget?.closest('.fa-toast') || event.target?.closest('.fa-toast');
        this._dismiss(toast);
    }

    _dismiss(toast) {
        if (!toast || toast.classList.contains('is-dismissing')) return;
        toast.classList.add('is-dismissing');
        const remove = () => { if (toast.parentNode) toast.remove(); };
        // 240ms matches --t-base; fallback in case animationend doesn't fire.
        toast.addEventListener('animationend', remove, { once: true });
        setTimeout(remove, 320);
    }

    _createToast(opts) {
        const toneIcons = {
            info:    'bi-info-circle-fill',
            success: 'bi-check-circle-fill',
            warning: 'bi-exclamation-triangle-fill',
            danger:  'bi-exclamation-octagon-fill',
        };
        const isCritical = opts.tone === 'warning' || opts.tone === 'danger';
        const toast = document.createElement('div');
        toast.className = `fa-toast fa-toast--${opts.tone}${opts.persistent ? ' fa-toast--persistent' : ''}`;
        toast.setAttribute('role', isCritical ? 'alert' : 'status');
        toast.setAttribute('aria-live', isCritical ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.dataset.faToastTarget = 'toast';

        const icon  = opts.icon || toneIcons[opts.tone] || toneIcons.info;
        const title = opts.title  ? `<div class="fa-toast__title">${this._escape(opts.title)}</div>`     : '';
        const msg   = opts.message ? `<div class="fa-toast__message">${this._escape(opts.message)}</div>` : '';
        const actions = (opts.actions && opts.actions.length > 0)
            ? `<div class="fa-toast__actions">${opts.actions.map(a => `<a class="fa-cyber-btn fa-cyber-btn--ghost fa-cyber-btn--sm" href="${this._escape(a.href || '#')}"${a.target ? ` target="${this._escape(a.target)}"` : ''}>${this._escape(a.label)}</a>`).join('')}</div>`
            : '';

        toast.innerHTML = `
            <i class="fa-toast__icon bi ${icon}" aria-hidden="true"></i>
            <div class="fa-toast__body">${title}${msg}${actions}</div>
            <button type="button" class="fa-toast__close" aria-label="Close" data-action="click->fa-toast#dismiss">&times;</button>
            ${opts.persistent ? '' : '<span class="fa-toast__progress" aria-hidden="true"></span>'}
        `;
        return toast;
    }

    _escape(s) {
        const div = document.createElement('div');
        div.textContent = s ?? '';
        return div.innerHTML;
    }
}
