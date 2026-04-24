/**
 * alva-bus.js — FairyAurora v4.0 Alva Companion Event Bus
 *
 * Singleton event bus that allows any part of the application to signal
 * Alva's mood changes. Import from app.js so it is globally available as
 * window.alvaBus.
 *
 * Event shape:
 *   { mood: 'celebrating'|'thinking'|'curious'|'alert'|'idle'|'happy'|'warning'|...,
 *     reason?: string,   // debugging label, e.g. 'upload-complete'
 *     ttlMs?: number     // auto-revert to idle after N ms (0 = no revert) }
 */

export const alvaBus = {
    /** @type {Array<(event: AlvaBusEvent) => void>} */
    _listeners: [],

    /**
     * Subscribe to Alva mood events.
     * @param {(event: AlvaBusEvent) => void} callback
     * @returns {() => void} unsubscribe function
     */
    on(callback) {
        this._listeners.push(callback);
        return () => this.off(callback);
    },

    /**
     * Unsubscribe a previously registered callback.
     * @param {(event: AlvaBusEvent) => void} callback
     */
    off(callback) {
        this._listeners = this._listeners.filter((l) => l !== callback);
    },

    /**
     * Emit a mood change event to all subscribers.
     * @param {{ mood: string, reason?: string, ttlMs?: number }} event
     */
    emit(event) {
        this._listeners.forEach((l) => {
            try {
                l(event);
            } catch (err) {
                // Never let a broken listener break the bus
                console.error('[AlvaBus] Listener error:', err);
            }
        });
    },
};

// Expose globally so Twig-inline scripts and third-party code can use it
// without importing the module.
window.alvaBus = alvaBus;
