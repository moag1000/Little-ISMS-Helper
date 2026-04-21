import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora Mode-Switch Controller — 3-State (Light / Dark / System)
 *
 * Persists to localStorage['fa-theme']. Init-Script in base.html.twig sets
 * html[data-theme] before first render (no flash-of-wrong-theme).
 *
 * Plan § 17 + § 28.7: Default 'light' for new users, System-Opt-in explicit.
 */
export default class extends Controller {
    static values = {
        labelLight:  { type: String, default: 'Light' },
        labelDark:   { type: String, default: 'Dark' },
        labelSystem: { type: String, default: 'System' }
    };
    static targets = ['srText'];

    static STORAGE_KEY = 'fa-theme';
    static CYCLE = ['light', 'dark', 'system'];

    connect() {
        this.syncFromStorage();
        this.updateUI();
    }

    syncFromStorage() {
        try {
            const saved = localStorage.getItem(this.constructor.STORAGE_KEY);
            this.current = this.constructor.CYCLE.includes(saved) ? saved : 'light';
        } catch (e) {
            this.current = 'light';
        }
        document.documentElement.setAttribute('data-theme', this.current);
    }

    cycle(event) {
        if (event) event.preventDefault();
        const idx = this.constructor.CYCLE.indexOf(this.current);
        this.current = this.constructor.CYCLE[(idx + 1) % this.constructor.CYCLE.length];
        try {
            localStorage.setItem(this.constructor.STORAGE_KEY, this.current);
        } catch (e) { /* quota / private-mode → in-memory only */ }
        document.documentElement.setAttribute('data-theme', this.current);
        this.updateUI();
    }

    updateUI() {
        this.element.setAttribute('data-current-mode', this.current);
        if (this.hasSrTextTarget) {
            const label = this.current === 'light' ? this.labelLightValue
                        : this.current === 'dark'  ? this.labelDarkValue
                        :                            this.labelSystemValue;
            this.srTextTarget.textContent = label;
        }
    }
}
