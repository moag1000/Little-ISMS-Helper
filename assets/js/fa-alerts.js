/**
 * FairyAurora v4 — Toast + Confirm helpers
 *
 * Replaces `alert()` and `confirm()` with Aurora-styled equivalents so
 * messages stay on-brand and themable via the design tokens.
 *
 * Exposed globals:
 *   window.faToast(message, variant?, options?)
 *     Shows a non-blocking toast in the top-right corner. Returns the
 *     toast element. Auto-dismisses after `options.timeout` ms (default
 *     5000). variant: 'info' | 'success' | 'warning' | 'danger'.
 *
 *   window.faConfirm(message, options?) -> Promise<boolean>
 *     Shows a modal confirm dialog. Resolves to true when the user
 *     confirms, false on cancel / backdrop / Escape. Options:
 *       title      — bold headline, optional
 *       confirmLabel — primary button label (default 'Bestätigen')
 *       cancelLabel  — secondary button label (default 'Abbrechen')
 *       tone       — 'warn' | 'danger' (default 'warn')
 *       icon       — Bootstrap-Icons name (without bi- prefix);
 *                    defaults: warn -> exclamation-triangle-fill,
 *                    danger -> shield-x
 */

(function () {
    'use strict';

    const TOAST_CONTAINER_ID = 'fa-toast-container';

    function ensureToastContainer() {
        let container = document.getElementById(TOAST_CONTAINER_ID);
        if (!container) {
            container = document.createElement('div');
            container.id = TOAST_CONTAINER_ID;
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'true');
            container.style.cssText = [
                'position:fixed',
                'top:24px',
                'right:24px',
                'z-index:1080',
                'display:flex',
                'flex-direction:column',
                'gap:12px',
                'max-width:min(420px, calc(100vw - 48px))',
                'pointer-events:none',
            ].join(';');
            document.body.appendChild(container);
        }
        return container;
    }

    const ICONS = {
        info: 'status-info',
        success: 'status-ok',
        warning: 'status-warning',
        danger: 'status-critical',
    };

    function makeToast(message, variant, options) {
        const v = ['info', 'success', 'warning', 'danger'].includes(variant) ? variant : 'info';
        const opts = options || {};
        const toast = document.createElement('div');
        toast.className = 'fa-alert fa-alert--' + v;
        toast.setAttribute('role', v === 'danger' || v === 'warning' ? 'alert' : 'status');
        toast.style.cssText = [
            'pointer-events:auto',
            'box-shadow:0 6px 24px rgba(0,0,0,.18)',
            'animation:faToastIn .18s ease-out',
        ].join(';');

        const icon = opts.icon || ICONS[v];
        const iconEl = document.createElement('i');
        if (typeof icon === 'string' && icon.indexOf('bi-') === 0) {
            iconEl.className = 'bi ' + icon + ' fa-alert__icon';
        } else {
            iconEl.className = 'fa-icon fa-icon--' + icon + ' fa-alert__icon';
        }
        iconEl.setAttribute('aria-hidden', 'true');
        toast.appendChild(iconEl);

        const body = document.createElement('div');
        body.className = 'fa-alert__body';
        if (opts.title) {
            const title = document.createElement('div');
            title.className = 'fa-alert__title';
            title.textContent = opts.title;
            body.appendChild(title);
        }
        const msg = document.createElement('p');
        msg.className = 'fa-alert__message';
        msg.style.margin = '0';
        msg.textContent = message;
        body.appendChild(msg);
        toast.appendChild(body);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'fa-alert__close';
        close.setAttribute('aria-label', 'Close');
        close.innerHTML = '<i class="fa-icon fa-icon--ui-close" aria-hidden="true"></i>';
        close.addEventListener('click', () => removeToast(toast));
        toast.appendChild(close);

        return toast;
    }

    function removeToast(toast) {
        if (!toast || !toast.parentElement) {
            return;
        }
        toast.style.animation = 'faToastOut .15s ease-in forwards';
        toast.addEventListener('animationend', () => toast.remove(), { once: true });
    }

    function injectKeyframes() {
        if (document.getElementById('fa-toast-keyframes')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'fa-toast-keyframes';
        style.textContent = `
            @keyframes faToastIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes faToastOut { from { opacity: 1; transform: translateY(0); } to { opacity: 0; transform: translateY(-8px); } }
        `;
        document.head.appendChild(style);
    }

    window.faToast = function (message, variant, options) {
        injectKeyframes();
        const container = ensureToastContainer();
        const toast = makeToast(String(message ?? ''), variant || 'info', options || {});
        container.appendChild(toast);
        const timeout = (options && options.timeout) || 5000;
        if (timeout > 0) {
            window.setTimeout(() => removeToast(toast), timeout);
        }
        return toast;
    };

    window.faConfirm = function (message, options) {
        const opts = options || {};
        const tone = ['warn', 'danger', 'nuclear'].includes(opts.tone) ? opts.tone : 'warn';

        return new Promise((resolve) => {
            const backdrop = document.createElement('div');
            backdrop.className = 'fa-confirm-backdrop';
            backdrop.style.cssText = [
                'position:fixed',
                'inset:0',
                'z-index:1090',
                'background:rgba(15,23,42,.55)',
                'backdrop-filter:blur(4px)',
                'display:flex',
                'align-items:center',
                'justify-content:center',
                'padding:24px',
                'animation:faConfirmFadeIn .15s ease-out',
            ].join(';');

            const dialog = document.createElement('div');
            dialog.className = 'fa-confirm';
            dialog.setAttribute('data-tone', tone);
            dialog.setAttribute('role', 'alertdialog');
            dialog.setAttribute('aria-labelledby', 'fa-confirm-title');
            dialog.style.cssText = [
                'max-width:520px',
                'width:100%',
                'animation:faConfirmZoomIn .18s ease-out',
            ].join(';');

            const iconName = opts.icon
                || (tone === 'warn' ? 'exclamation-triangle-fill' : 'shield-x');
            dialog.innerHTML = `
                <header class="fa-confirm__header">
                    <span class="fa-confirm__icon"><i class="bi bi-${iconName}" aria-hidden="true"></i></span>
                    <div>
                        ${opts.title ? `<div class="fa-confirm__title" id="fa-confirm-title">${escapeHtml(opts.title)}</div>` : ''}
                        <p class="fa-confirm__sub" ${opts.title ? '' : 'id="fa-confirm-title"'}>${escapeHtml(message)}</p>
                    </div>
                </header>
                <footer class="fa-confirm__footer">
                    <button type="button" class="fa-cyber-btn fa-cyber-btn--ghost" data-fa-confirm-cancel>${escapeHtml(opts.cancelLabel || 'Abbrechen')}</button>
                    <button type="button" class="fa-cyber-btn fa-cyber-btn--${tone === 'warn' ? 'warning' : 'danger'}" data-fa-confirm-ok>${escapeHtml(opts.confirmLabel || 'Bestätigen')}</button>
                </footer>
            `;

            injectConfirmKeyframes();

            backdrop.appendChild(dialog);
            document.body.appendChild(backdrop);

            const previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';

            const okBtn = dialog.querySelector('[data-fa-confirm-ok]');
            const cancelBtn = dialog.querySelector('[data-fa-confirm-cancel]');
            const previousFocus = document.activeElement;
            okBtn.focus();

            function close(value) {
                document.body.style.overflow = previousOverflow;
                document.removeEventListener('keydown', onKey);
                backdrop.remove();
                if (previousFocus instanceof HTMLElement) {
                    previousFocus.focus();
                }
                resolve(value);
            }

            function onKey(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close(false);
                } else if (event.key === 'Enter' && document.activeElement !== cancelBtn) {
                    event.preventDefault();
                    close(true);
                }
            }

            okBtn.addEventListener('click', () => close(true));
            cancelBtn.addEventListener('click', () => close(false));
            backdrop.addEventListener('click', (event) => {
                if (event.target === backdrop) {
                    close(false);
                }
            });
            document.addEventListener('keydown', onKey);
        });
    };

    function injectConfirmKeyframes() {
        if (document.getElementById('fa-confirm-keyframes')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'fa-confirm-keyframes';
        style.textContent = `
            @keyframes faConfirmFadeIn { from { opacity: 0; } to { opacity: 1; } }
            @keyframes faConfirmZoomIn { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: scale(1); } }
        `;
        document.head.appendChild(style);
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }
})();
