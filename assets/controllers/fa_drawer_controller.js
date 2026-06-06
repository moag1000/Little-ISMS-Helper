import { Controller } from '@hotwired/stimulus';

/**
 * fa-drawer — generic slide-in side-sheet host driven by Turbo Frames.
 *
 * One persistent host lives in base.html.twig containing an empty
 * <turbo-frame id="fa-drawer">. Any link with data-turbo-frame="fa-drawer"
 * loads its target (detail / edit / new partial) into the frame; this
 * controller opens the panel, manages focus/ESC/backdrop/scroll-lock, guards
 * unsaved changes (window.faConfirm), integrates the Back button, shows a
 * skeleton while the frame fetches, scrolls to the first error on 422, and
 * closes the panel after a successful submit.
 *
 * Spec: docs/superpowers/specs/2026-06-06-form-drawer-modal-overhaul-design.md
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop', 'frame'];

    connect() {
        this.dirty = false;
        this.isOpen = false;
        this._onKeydown = this.#onKeydown.bind(this);
        this._onPopState = this.#onPopState.bind(this);

        // Open on a real click of a drawer trigger — NOT on turbo:before-fetch-request,
        // which also fires on Turbo's hover-prefetch and would blank the panel.
        this._onClick = this.#onClick.bind(this);
        this._onFrameLoad = this.#onFrameLoad.bind(this);
        this._onSubmitEnd = this.#onSubmitEnd.bind(this);
        document.addEventListener('click', this._onClick);
        this.frameTarget.addEventListener('turbo:frame-load', this._onFrameLoad);
        document.addEventListener('turbo:submit-end', this._onSubmitEnd);

        // Dirty-tracking inside the frame.
        this.frameTarget.addEventListener('input', () => { this.dirty = true; });
        this.frameTarget.addEventListener('change', () => { this.dirty = true; });
    }

    disconnect() {
        document.removeEventListener('click', this._onClick);
        document.removeEventListener('turbo:submit-end', this._onSubmitEnd);
        window.removeEventListener('popstate', this._onPopState);
        document.removeEventListener('keydown', this._onKeydown);
    }

    // ── open / close ────────────────────────────────────────────────────────

    open() {
        if (this.isOpen) return;
        this.isOpen = true;
        this.dirty = false;
        this.previouslyFocused = document.activeElement;

        this.panelTarget.classList.add('is-open');
        this.backdropTarget.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', this._onKeydown);

        // Back button closes the drawer instead of leaving the list.
        history.pushState({ faDrawer: true }, '');
        window.addEventListener('popstate', this._onPopState);

        // Background inert for a11y.
        this.#mainContent()?.setAttribute('inert', '');
        this.#mainContent()?.setAttribute('aria-hidden', 'true');
    }

    /** Close, honouring the unsaved-changes guard. fromHistory avoids double-pop. */
    async close(event, fromHistory = false) {
        if (!this.isOpen) return;
        if (this.dirty && !(await this.#confirmDiscard())) {
            // Re-push the state we were about to lose so Back stays consistent.
            if (fromHistory) history.pushState({ faDrawer: true }, '');
            return;
        }
        this.#finishClose(fromHistory);
    }

    #finishClose(fromHistory) {
        this.isOpen = false;
        this.dirty = false;
        this.panelTarget.classList.remove('is-open');
        this.backdropTarget.classList.remove('is-open');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', this._onKeydown);
        window.removeEventListener('popstate', this._onPopState);
        if (!fromHistory && history.state && history.state.faDrawer) history.back();

        this.#mainContent()?.removeAttribute('inert');
        this.#mainContent()?.removeAttribute('aria-hidden');

        // Empty the frame so stale content never flashes on next open.
        this.frameTarget.innerHTML = '';
        if (this.previouslyFocused && this.previouslyFocused.focus) {
            this.previouslyFocused.focus();
        }
    }

    backdropClose(event) {
        // Only when the backdrop itself is clicked — not a bubbled click from
        // inside the dialog/panel (the form-modal backdrop wraps its dialog).
        if (event && event.target !== event.currentTarget) return;
        this.close();
    }

    // ── Turbo lifecycle ──────────────────────────────────────────────────────

    #onClick(event) {
        // Only a plain left-click on a trigger for THIS host's frame opens it.
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest(`a[data-turbo-frame="${this.frameTarget.id}"]`);
        if (!link || link.target === '_blank') return;

        // Show a skeleton ONLY for triggers outside the frame (list rows) — for
        // an in-frame link (e.g. the detail "Edit" button) wiping the frame here
        // would destroy the link before Turbo handles it and fall back to a full
        // page navigation. Let Turbo perform the frame navigation either way.
        if (!this.frameTarget.contains(link)) {
            this.#showSkeleton();
        }
        this.open();
    }

    #onFrameLoad() {
        this.dirty = false; // fresh content
        this.#focusFirst();
        this.#scrollToFirstError();
    }

    #onSubmitEnd(event) {
        // Successful submit from inside the drawer → server returned a stream
        // that updated the row; close the panel.
        const form = event.target;
        if (!this.frameTarget.contains(form)) return;
        if (event.detail?.success) {
            this.dirty = false;
            this.#finishClose(false);
        } else {
            this.#scrollToFirstError();
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    #showSkeleton() {
        // Neutral skeleton — works for both the drawer and the form-modal host.
        this.frameTarget.innerHTML =
            '<div class="fa-overlay-skeleton" style="padding:20px">' +
            '<div class="fa-skeleton" style="height:1.4em;width:50%;margin:.2em 0 1em"></div>' +
            '<div class="fa-skeleton" style="height:1.2em;width:80%;margin:.6em 0"></div>' +
            '<div class="fa-skeleton" style="height:1.2em;width:95%;margin:.6em 0"></div>' +
            '<div class="fa-skeleton" style="height:1.2em;width:65%;margin:.6em 0"></div></div>';
        this.frameTarget.setAttribute('aria-busy', 'true');
    }

    #onKeydown(event) {
        if (event.key === 'Escape') { event.preventDefault(); this.close(); return; }
        if (event.key === 'Tab') this.#trapFocus(event);
    }

    #onPopState() {
        // Back button pressed while open → close (state already popped).
        if (this.isOpen) this.close(null, true);
    }

    #trapFocus(event) {
        const f = this.#focusables();
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault(); last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first.focus();
        }
    }

    #focusables() {
        return Array.from(this.panelTarget.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((el) => el.offsetParent !== null);
    }

    #focusFirst() {
        const f = this.#focusables();
        const firstField = this.panelTarget.querySelector(
            '.fa-drawer__body input:not([type=hidden]), .fa-drawer__body select, .fa-drawer__body textarea'
        );
        (firstField || f[0])?.focus();
    }

    #scrollToFirstError() {
        const err = this.panelTarget.querySelector(
            '.is-invalid, [aria-invalid="true"], .fa-cyber-input--error, .invalid-feedback'
        );
        if (err) {
            err.scrollIntoView({ block: 'center', behavior: 'smooth' });
            (err.matches('input,select,textarea') ? err : err.querySelector('input,select,textarea'))?.focus();
        }
    }

    #confirmDiscard() {
        const msg = this.element.dataset.faDrawerDiscardMessage
            || 'Ungespeicherte Änderungen verwerfen?';
        if (window.faConfirm) return window.faConfirm(msg);
        return Promise.resolve(window.confirm(msg)); // worst-case fallback
    }

    #mainContent() {
        return document.querySelector('main') || document.getElementById('main-content');
    }
}
