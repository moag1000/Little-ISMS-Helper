import { Controller } from '@hotwired/stimulus';

/**
 * ColumnMapping Controller — Bulk-Import Wizard Step 2 (F2.9)
 *
 * Manages confidence badge coloring for column mapping dropdowns.
 * When the user overrides an auto-mapping, the confidence badge dims to
 * neutral to signal the suggestion is no longer active.
 * "Reset to auto-mapping" restores the original suggestions.
 *
 * Targets:
 *   dropdown        — each <select> for a column mapping row (collection)
 *   confidenceBadge — each confidence badge span (collection, same order as dropdowns)
 *   resetButton     — the "Reset to auto-mapping" button
 *
 * Values:
 *   autoMapping (Object) — JSON map of { sourceColumn: { target, confidence } }
 *                          set via data-column-mapping-auto-mapping-value='{"Col A": {...}}'
 *
 * Usage:
 *   <div data-controller="column-mapping"
 *        data-column-mapping-auto-mapping-value="{{ autoMappings|json_encode }}">
 *     <select data-column-mapping-target="dropdown"
 *             data-action="change->column-mapping#onSelect"
 *             data-source-column="Col A">…</select>
 *     <span data-column-mapping-target="confidenceBadge"
 *           data-auto-confidence="0.92">…</span>
 *     <button data-column-mapping-target="resetButton"
 *             data-action="click->column-mapping#resetAll">Reset</button>
 *   </div>
 */
export default class extends Controller {
    static targets = ['dropdown', 'confidenceBadge', 'resetButton'];
    static values = {
        autoMapping: { type: Object, default: {} },
    };

    connect() {
        // Store initial values for reset
        this._initialValues = this.dropdownTargets.map((sel) => sel.value);
    }

    // ── Public actions ─────────────────────────────────────────────────────

    onSelect(event) {
        const dropdown = event.currentTarget;
        const idx = this.dropdownTargets.indexOf(dropdown);
        if (idx === -1) return;

        const badge = this.confidenceBadgeTargets[idx];
        if (!badge) return;

        const initialValue = this._initialValues[idx];
        const currentValue = dropdown.value;

        if (currentValue !== initialValue) {
            // User has overridden — dim badge to neutral
            badge.dataset.overridden = 'true';
            badge.classList.remove('fa-filter-chip--success', 'fa-filter-chip--warning', 'fa-filter-chip--danger');
            badge.classList.add('fa-filter-chip--neutral');
            badge.style.opacity = '0.5';
        } else {
            // Restored to auto-suggested value — re-apply confidence color
            this._applyConfidenceStyle(badge);
        }
    }

    resetAll() {
        this.dropdownTargets.forEach((dropdown, idx) => {
            const autoVal = this._initialValues[idx];
            if (autoVal !== undefined) {
                dropdown.value = autoVal;
            }

            const badge = this.confidenceBadgeTargets[idx];
            if (badge) {
                delete badge.dataset.overridden;
                badge.style.opacity = '';
                this._applyConfidenceStyle(badge);
            }
        });
    }

    // ── Private helpers ────────────────────────────────────────────────────

    _applyConfidenceStyle(badge) {
        const conf = parseFloat(badge.dataset.autoConfidence || '0');
        badge.classList.remove('fa-filter-chip--success', 'fa-filter-chip--warning',
            'fa-filter-chip--danger', 'fa-filter-chip--neutral');

        if (conf >= 0.9) {
            badge.classList.add('fa-filter-chip--success');
        } else if (conf >= 0.6) {
            badge.classList.add('fa-filter-chip--warning');
        } else {
            badge.classList.add('fa-filter-chip--danger');
        }
    }
}
