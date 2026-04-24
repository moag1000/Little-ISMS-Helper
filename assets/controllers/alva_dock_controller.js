import { Controller } from '@hotwired/stimulus';

/**
 * AlvaDockController — FairyAurora v4.0 Alva Companion Dock
 *
 * Mounts the site-wide Alva fairy companion. Listens to window.alvaBus events
 * and swaps the mood class on the inner .fa-alva element accordingly.
 *
 * Stimulus values (read from data-* attributes on the dock element):
 *   data-alva-dock-size-value        — "sm"|"md"|"lg"  (default: "md")
 *   data-alva-dock-enabled-value     — boolean          (default: true)
 *   data-alva-dock-default-ttl-value — number (ms)      (default: 3000)
 *
 * Dock position is managed purely by CSS class fa-alva-dock--pos-<position>
 * rendered server-side in base.html.twig.
 */
export default class extends Controller {
    static values = {
        size: { type: String, default: 'md' },
        enabled: { type: Boolean, default: true },
        defaultTtl: { type: Number, default: 3000 },
    };

    /**
     * Moods that auto-revert to idle after ttlMs (transient feedback).
     * Moods NOT listed here stay until the next bus event (sticky).
     */
    static TRANSIENT_MOODS = new Set([
        'celebrating', 'alert', 'happy', 'warning', 'curious',
    ]);

    connect() {
        this._busUnsubscribe = null;
        this._ttlTimer = null;

        // Apply initial size class
        this._applySize(this.sizeValue);

        // Honour the enabled flag
        if (!this.enabledValue) {
            this.element.style.display = 'none';
            return;
        }

        // Subscribe to alvaBus (may not be defined yet on very first tick;
        // requestAnimationFrame gives app.js time to run if needed).
        const attach = () => {
            if (window.alvaBus) {
                this._busUnsubscribe = window.alvaBus.on((event) => this._handleEvent(event));
            } else {
                requestAnimationFrame(attach);
            }
        };
        attach();
    }

    disconnect() {
        if (this._busUnsubscribe) {
            this._busUnsubscribe();
            this._busUnsubscribe = null;
        }
        if (this._ttlTimer) {
            clearTimeout(this._ttlTimer);
            this._ttlTimer = null;
        }
    }

    // ── Stimulus value-change callbacks ────────────────────────────────────

    sizeValueChanged(value, previousValue) {
        if (previousValue) this.element.classList.remove(`fa-alva-dock--size-${previousValue}`);
        this._applySize(value);
    }

    enabledValueChanged(value) {
        this.element.style.display = value ? '' : 'none';
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * React to an AlvaBus event: swap the mood class and schedule auto-revert.
     * @param {{ mood: string, reason?: string, ttlMs?: number }} event
     */
    _handleEvent(event) {
        if (!this.enabledValue) return;

        const mood = event.mood ?? 'idle';
        this._setMood(mood);

        // Determine TTL: explicit value in event > mood-type default > 0 (no revert)
        let ttl = event.ttlMs;
        if (ttl === undefined) {
            ttl = this.constructor.TRANSIENT_MOODS.has(mood)
                ? this.defaultTtlValue
                : 0;
        }

        // Cancel any pending revert before scheduling a new one
        if (this._ttlTimer) {
            clearTimeout(this._ttlTimer);
            this._ttlTimer = null;
        }

        if (ttl > 0) {
            this._ttlTimer = setTimeout(() => {
                this._setMood('idle');
                this._ttlTimer = null;
            }, ttl);
        }
    }

    /**
     * Swap the .fa-alva--<mood> class on the inner Alva element.
     * Only touches mood classes (prefix fa-alva--), not size or other classes.
     * @param {string} mood
     */
    _setMood(mood) {
        const alvaEl = this.element.querySelector('.fa-alva');
        if (!alvaEl) return;

        // Remove existing mood classes (keep non-mood classes untouched)
        const toRemove = [];
        alvaEl.classList.forEach((cls) => {
            if (
                cls.startsWith('fa-alva--') &&
                !['fa-alva--size-sm', 'fa-alva--size-md', 'fa-alva--size-lg'].includes(cls)
            ) {
                toRemove.push(cls);
            }
        });
        toRemove.forEach((cls) => alvaEl.classList.remove(cls));

        alvaEl.classList.add(`fa-alva--${mood}`);
    }

    /**
     * Apply a fa-alva-dock--size-<s> class to the dock root element.
     * @param {string} size — "sm" | "md" | "lg"
     */
    _applySize(size) {
        ['sm', 'md', 'lg'].forEach((s) => {
            this.element.classList.toggle(`fa-alva-dock--size-${s}`, s === size);
        });
    }
}
