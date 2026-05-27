/**
 * Wave 5 — Density Toggle Controller
 * Manages sidebar density: basic | standard | expert
 *
 * Wave 5 interface (sb-density radiogroup in _mega_menu.html.twig):
 *   data-density-toggle-target="option"  — each radio button
 *   data-action="click->density-toggle#set"
 *   data-density="basic|standard|expert"
 *
 * Legacy interface (Welle 3, fa-density-toggle__opt):
 *   data-action="change->density-toggle#submit"
 *   endpointValue  — optional fetch endpoint
 *
 * Effect:
 *   - Sets document.body.dataset.density (drives CSS density gates in fairy-aurora-components.css)
 *   - Adds .is-active + aria-checked="true" to selected option, removes from others
 *   - Persists to localStorage under key "isms-density" (survives page navigation)
 */
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['option']
    static values  = { endpoint: String }

    connect() {
        // Restore persisted density; sync button states
        const saved = localStorage.getItem('isms-density') ?? 'standard';
        this._apply(saved, false);
    }

    // ── Wave 5 action (click->density-toggle#set) ──────────────────────────

    /**
     * Wave 5 sb-density radiogroup click handler.
     * @param {Event} event
     */
    set(event) {
        const density = event.currentTarget.dataset.density;
        if (density) this._apply(density, true);
    }

    // ── Legacy action (change->density-toggle#submit) ──────────────────────

    async submit(event) {
        const radio   = event.target;
        const density = radio.value;

        this._apply(density, true);

        // Optional server-side persist (legacy callers provide endpointValue)
        if (this.endpointValue) {
            try {
                await fetch(this.endpointValue, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new URLSearchParams({ density }),
                });
            } catch {
                // Non-critical — body attribute drives CSS immediately
            }
        }
    }

    // ── Internal ───────────────────────────────────────────────────────────

    /**
     * Apply density to <body>, sync all toggle buttons, optionally persist.
     * @param {string}  density  'basic' | 'standard' | 'expert'
     * @param {boolean} persist  write to localStorage
     */
    _apply(density, persist) {
        if (!['basic', 'standard', 'expert'].includes(density)) return;

        document.body.dataset.density = density;

        // Wave 5 targets (sb-density__opt buttons)
        if (this.hasOptionTarget) {
            this.optionTargets.forEach(opt => {
                const active = opt.dataset.density === density;
                opt.classList.toggle('is-active', active);
                opt.setAttribute('aria-checked', active ? 'true' : 'false');
            });
        }

        // Legacy targets (fa-density-toggle__opt labels wrapping radio inputs)
        this.element.querySelectorAll('.fa-density-toggle__opt').forEach(label => {
            label.classList.remove('is-active');
        });
        this.element.querySelector(`.fa-density-toggle__opt[data-density="${density}"]`)
            ?.classList.add('is-active');

        if (persist) {
            localStorage.setItem('isms-density', density);
        }
    }
}
