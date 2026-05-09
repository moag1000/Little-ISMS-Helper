import { Controller } from '@hotwired/stimulus';

/**
 * Policy-Wizard W7-E — Bulk-Approval-Inbox mobile swipe controller.
 *
 * Adds gesture detection to each row of the per-user policy
 * acknowledgement inbox so the user can quickly indicate intent on a
 * touch device:
 *   - swipe right  → highlight the approve button (does NOT submit);
 *   - swipe left   → highlight the reject / view button (does NOT submit);
 *   - tap-to-confirm preserves the existing form-POST flow so a stray
 *     swipe never auto-submits an acknowledgement (audit defang —
 *     ISO 27001 A.6.3 acknowledgement evidence must be intentional).
 *
 * The controller is intentionally tap-to-confirm: highlighting the
 * button is the swipe payload, the user still has to press it. This
 * matches the persona-CISO requirement (Mobile-Sign-Off — explicit
 * confirmation required).
 *
 * Activation:
 *   <ul data-controller="bulk-approval-mobile">
 *     <li data-bulk-approval-mobile-target="row">
 *       …
 *       <a data-bulk-approval-mobile-target="rejectBtn">…</a>
 *       <button data-bulk-approval-mobile-target="approveBtn">…</button>
 *       <small data-bulk-approval-mobile-target="hint">…</small>
 *     </li>
 *   </ul>
 */
export default class extends Controller {
    static targets = ['row'];

    /**
     * Minimum horizontal distance (px) to count as a swipe. Below this
     * threshold the gesture is treated as a tap and ignored. 40px is the
     * default tap-vs-swipe boundary used by Material design guidelines.
     */
    static SWIPE_THRESHOLD_PX = 40;

    /**
     * Maximum vertical drift (px) before the gesture is considered a
     * scroll, not a swipe. Without this guard the controller would
     * highlight buttons whenever the user scrolls the list.
     */
    static MAX_VERTICAL_DRIFT_PX = 25;

    connect() {
        this._touchState = new WeakMap();
        this.rowTargets.forEach((row) => this._wireRow(row));
    }

    disconnect() {
        this.rowTargets.forEach((row) => this._unwireRow(row));
    }

    _wireRow(row) {
        const onStart = (event) => this._onTouchStart(row, event);
        const onMove  = (event) => this._onTouchMove(row, event);
        const onEnd   = (event) => this._onTouchEnd(row, event);
        const onCancel = (event) => this._onTouchCancel(row, event);

        row.addEventListener('touchstart',  onStart,  { passive: true });
        row.addEventListener('touchmove',   onMove,   { passive: true });
        row.addEventListener('touchend',    onEnd,    { passive: true });
        row.addEventListener('touchcancel', onCancel, { passive: true });

        this._touchState.set(row, {
            startX: 0,
            startY: 0,
            tracking: false,
            handlers: { onStart, onMove, onEnd, onCancel },
        });
    }

    _unwireRow(row) {
        const state = this._touchState.get(row);
        if (!state) {
            return;
        }
        const { onStart, onMove, onEnd, onCancel } = state.handlers;
        row.removeEventListener('touchstart',  onStart);
        row.removeEventListener('touchmove',   onMove);
        row.removeEventListener('touchend',    onEnd);
        row.removeEventListener('touchcancel', onCancel);
    }

    _onTouchStart(row, event) {
        const touch = event.touches[0];
        if (!touch) {
            return;
        }
        const state = this._touchState.get(row);
        state.startX = touch.clientX;
        state.startY = touch.clientY;
        state.tracking = true;
    }

    _onTouchMove(row, event) {
        const state = this._touchState.get(row);
        if (!state || !state.tracking) {
            return;
        }
        const touch = event.touches[0];
        if (!touch) {
            return;
        }
        const dx = touch.clientX - state.startX;
        const dy = touch.clientY - state.startY;

        if (Math.abs(dy) > this.constructor.MAX_VERTICAL_DRIFT_PX) {
            this._clearSwipeStyles(row);
            state.tracking = false;
            return;
        }

        if (dx > this.constructor.SWIPE_THRESHOLD_PX) {
            this._highlight(row, 'right');
        } else if (dx < -this.constructor.SWIPE_THRESHOLD_PX) {
            this._highlight(row, 'left');
        } else {
            this._clearSwipeStyles(row);
        }
    }

    _onTouchEnd(row, _event) {
        const state = this._touchState.get(row);
        if (state) {
            state.tracking = false;
        }
        // Hold the highlight for a short beat so the user sees the
        // affordance, but never auto-submit. Tap-to-confirm.
        window.setTimeout(() => this._clearSwipeStyles(row), 1200);
    }

    _onTouchCancel(row, _event) {
        this._clearSwipeStyles(row);
        const state = this._touchState.get(row);
        if (state) {
            state.tracking = false;
        }
    }

    _highlight(row, direction) {
        if (direction === 'right') {
            row.classList.add('is-swiping-right');
            row.classList.remove('is-swiping-left');
        } else {
            row.classList.add('is-swiping-left');
            row.classList.remove('is-swiping-right');
        }
        // Reveal the "tap to confirm" hint inside this row, if present.
        const hint = row.querySelector('[data-bulk-approval-mobile-target="hint"]');
        if (hint) {
            hint.classList.remove('d-none');
        }
    }

    _clearSwipeStyles(row) {
        row.classList.remove('is-swiping-right');
        row.classList.remove('is-swiping-left');
        const hint = row.querySelector('[data-bulk-approval-mobile-target="hint"]');
        if (hint) {
            hint.classList.add('d-none');
        }
    }
}
