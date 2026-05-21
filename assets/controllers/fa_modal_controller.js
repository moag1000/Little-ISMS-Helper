import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora v4.0 — fa-modal shell controller
 *
 * Drives the .fa-modal shell (confirm / settings / wizard modes): open/close,
 * backdrop click, ESC key, focus-trap, body-scroll lock, return-focus.
 *
 * Domain-specific behaviors (type-to-confirm phrase + cooldown, wizard step
 * navigation, etc.) live in separate controllers that compose alongside
 * fa-modal via `data-controller="fa-modal fa-confirm"`.
 *
 * Open from another controller:
 *   this.dispatch('fa-modal:request-open', { detail: { id: 'delete-risk' } });
 *
 * Targets:
 *   dialog          — .fa-modal__container (focused on open)
 *   backdrop        — .fa-modal__backdrop (click closes)
 *   confirmButton   — primary submit button (used for opt-out of close-on-confirm)
 *   form            — inner <form> (for submit-listening)
 *
 * Values:
 *   open             Boolean (default: false) — open/closed state
 *   mode             String  (default: 'confirm') — confirm|settings|wizard
 *   tone             String  (default: 'neutral')
 *   closeOnConfirm   Boolean (default: false) — auto-close after form submit
 *   closeOnEsc       Boolean (default: true)
 *   closeOnBackdrop  Boolean (default: true)
 *
 * Events (dispatched, bubbling, prefix `fa-modal:`):
 *   opened           — after open transition starts
 *   closed           — after close transition starts
 *   confirmed        — after primary submit fires
 *   cancelled        — after cancel button / ESC / backdrop click
 */
export default class extends Controller {
    static targets = ['dialog', 'backdrop', 'confirmButton', 'form'];

    static values = {
        open:            { type: Boolean, default: false },
        mode:            { type: String,  default: 'confirm' },
        tone:            { type: String,  default: 'neutral' },
        closeOnConfirm:  { type: Boolean, default: false },
        closeOnEsc:      { type: Boolean, default: true },
        closeOnBackdrop: { type: Boolean, default: true },
    };

    #previouslyFocused = null;
    #boundHandleEscape = null;
    #boundHandleTab = null;
    #boundHandleRequestOpen = null;
    #boundHandleFormSubmit = null;

    connect() {
        this.#boundHandleEscape       = this.#handleEscape.bind(this);
        this.#boundHandleTab          = this.#handleTab.bind(this);
        this.#boundHandleRequestOpen  = this.#handleRequestOpen.bind(this);
        this.#boundHandleFormSubmit   = this.#handleFormSubmit.bind(this);

        document.addEventListener('keydown', this.#boundHandleEscape);
        document.addEventListener('fa-modal:request-open', this.#boundHandleRequestOpen);

        if (this.hasFormTarget) {
            this.formTarget.addEventListener('submit', this.#boundHandleFormSubmit);
        }

        this.#syncOpenState();
    }

    disconnect() {
        document.removeEventListener('keydown', this.#boundHandleEscape);
        document.removeEventListener('keydown', this.#boundHandleTab);
        document.removeEventListener('fa-modal:request-open', this.#boundHandleRequestOpen);

        if (this.hasFormTarget) {
            this.formTarget.removeEventListener('submit', this.#boundHandleFormSubmit);
        }

        // Restore body scroll if we leave while still open
        if (this.openValue) {
            document.body.style.overflow = '';
        }
    }

    // ── Public Actions ────────────────────────────────────────────────────

    open(event) {
        if (event && event.currentTarget) {
            this.#previouslyFocused = event.currentTarget;
        } else {
            this.#previouslyFocused = document.activeElement;
        }
        this.openValue = true;
    }

    close() {
        this.openValue = false;
    }

    cancel(event) {
        event?.preventDefault();
        this.dispatch('cancelled', { detail: { id: this.element.id } });
        this.close();
    }

    backdropClick(event) {
        if (!this.closeOnBackdropValue) return;
        if (event.target !== this.backdropTarget) return;
        this.dispatch('cancelled', { detail: { id: this.element.id, reason: 'backdrop' } });
        this.close();
    }

    // ── Value Change Callbacks ────────────────────────────────────────────

    openValueChanged() {
        this.#syncOpenState();
    }

    // ── Private ───────────────────────────────────────────────────────────

    #syncOpenState() {
        const isOpen = this.openValue;

        this.element.classList.toggle('is-open', isOpen);
        this.element.setAttribute('aria-hidden', isOpen ? 'false' : 'true');

        if (isOpen) {
            document.body.style.overflow = 'hidden';
            document.addEventListener('keydown', this.#boundHandleTab);
            requestAnimationFrame(() => this.#focusFirstElement());
            this.dispatch('opened', { detail: { id: this.element.id } });
        } else {
            document.body.style.overflow = '';
            document.removeEventListener('keydown', this.#boundHandleTab);

            // Return focus to trigger
            if (this.#previouslyFocused && typeof this.#previouslyFocused.focus === 'function') {
                this.#previouslyFocused.focus();
            }
            this.#previouslyFocused = null;

            this.dispatch('closed', { detail: { id: this.element.id } });
        }
    }

    #handleEscape(event) {
        if (event.key !== 'Escape') return;
        if (!this.openValue) return;
        if (!this.closeOnEscValue) return;
        event.preventDefault();
        this.dispatch('cancelled', { detail: { id: this.element.id, reason: 'escape' } });
        this.close();
    }

    #handleTab(event) {
        if (event.key !== 'Tab') return;
        if (!this.openValue) return;

        const focusable = this.#getFocusableElements();
        if (focusable.length === 0) {
            event.preventDefault();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    #handleRequestOpen(event) {
        const requestedId = event.detail?.id;
        if (!requestedId) return;
        if (requestedId !== this.element.id) return;
        // store trigger element from event source if available
        const trigger = event.detail?.trigger ?? null;
        if (trigger) this.#previouslyFocused = trigger;
        this.openValue = true;
    }

    #handleFormSubmit() {
        // Dispatch confirmed event for callers that want to react before navigation
        this.dispatch('confirmed', { detail: { id: this.element.id } });
        if (this.closeOnConfirmValue) {
            // Defer close to allow form to navigate / submit first
            requestAnimationFrame(() => this.close());
        }
    }

    #focusFirstElement() {
        if (!this.hasDialogTarget) return;
        const focusable = this.#getFocusableElements();
        if (focusable.length === 0) {
            this.dialogTarget.setAttribute('tabindex', '-1');
            this.dialogTarget.focus();
            return;
        }
        const autofocus = focusable.find((el) => el.hasAttribute('autofocus'));
        (autofocus ?? focusable[0]).focus();
    }

    #getFocusableElements() {
        if (!this.hasDialogTarget) return [];
        const selectors = [
            'a[href]',
            'area[href]',
            'input:not([disabled]):not([type="hidden"])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            'button:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
            '[contenteditable]',
        ].join(', ');

        return Array.from(this.dialogTarget.querySelectorAll(selectors)).filter((el) => {
            return (
                el.offsetWidth > 0 &&
                el.offsetHeight > 0 &&
                !el.hasAttribute('hidden') &&
                getComputedStyle(el).visibility !== 'hidden'
            );
        });
    }
}
