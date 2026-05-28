import { Controller } from '@hotwired/stimulus';

/**
 * Phase 4.4 — Live Badge Controller
 *
 * Polls GET /api/live-counts every 30 seconds and updates badge counts
 * from the JSON response. Automatically pauses when the browser tab is
 * hidden (document.visibilityState === 'hidden') and resumes when visible.
 *
 * Usage:
 *   <span data-controller="live-badge"
 *         data-live-badge-source-value="my_day"
 *         data-live-badge-target="count"
 *         aria-live="polite"
 *         aria-atomic="true">0</span>
 *
 * Values:
 *   source  — key from the /api/live-counts JSON response (e.g. "my_day")
 *   url     — optional override for poll endpoint (default: /api/live-counts)
 *   interval — poll interval in ms (default: 30000)
 *
 * Targets:
 *   count   — the element whose text content is updated with the new count
 *
 * The badge is hidden (display:none equivalent via data-live-badge-hidden attr)
 * when the count is 0, and shown when count > 0, via CSS class `is-visible`.
 * (CSS: .sb-prominent__badge { display: none } .sb-prominent__badge.is-visible { display: inline-flex })
 *
 * WCAG 2.2 SC 4.1.3 — Status Messages:
 *   The badge span has aria-live="polite" + aria-atomic="true" so screen readers
 *   announce count changes without disrupting the user's focus.
 */
export default class extends Controller {
    static targets = ['count'];
    static values = {
        source: String,
        url: { type: String, default: '/api/live-counts' },
        interval: { type: Number, default: 30000 },
    };

    connect() {
        // Fetch immediately on connect (initial state)
        this._fetchAndUpdate();

        // Schedule polling
        this._startPolling();

        // Pause/resume on tab visibility changes (battery + network courtesy)
        this._handleVisibilityChangeBound = this._handleVisibilityChange.bind(this);
        document.addEventListener('visibilitychange', this._handleVisibilityChangeBound);
    }

    disconnect() {
        this._stopPolling();
        if (this._handleVisibilityChangeBound) {
            document.removeEventListener('visibilitychange', this._handleVisibilityChangeBound);
        }
    }

    // ─────────────── Private helpers ───────────────

    _startPolling() {
        if (this._timer) return;
        this._timer = setInterval(
            () => this._fetchAndUpdate(),
            this.intervalValue
        );
    }

    _stopPolling() {
        if (this._timer) {
            clearInterval(this._timer);
            this._timer = null;
        }
    }

    _handleVisibilityChange() {
        if (document.hidden) {
            this._stopPolling();
        } else {
            // Fetch immediately on tab becoming visible, then resume polling
            this._fetchAndUpdate();
            this._startPolling();
        }
    }

    async _fetchAndUpdate() {
        try {
            const response = await fetch(this.urlValue, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;

            const data = await response.json();
            const key = this.sourceValue;

            if (key in data) {
                this._applyCount(data[key]);
            }
        } catch {
            // Network errors — silently skip; badge retains last known value
        }
    }

    /**
     * Update the badge count and toggle visibility.
     * @param {number} count
     */
    _applyCount(count) {
        const n = Math.max(0, Math.floor(count));
        const display = n > 99 ? '99+' : String(n);

        if (this.hasCountTarget) {
            this.countTarget.textContent = display;
        } else {
            this.element.textContent = display;
        }

        // Toggle is-visible CSS class: show badge only when count > 0
        if (n > 0) {
            this.element.classList.add('is-visible');
            this.element.removeAttribute('aria-hidden');
        } else {
            this.element.classList.remove('is-visible');
            this.element.setAttribute('aria-hidden', 'true');
        }
    }
}
