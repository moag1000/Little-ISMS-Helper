import { Controller } from '@hotwired/stimulus';

/**
 * WS-6 Budget Simulator Controller
 *
 * Binds a Bootstrap <input type="range"> to the Gap-Report requirement rows.
 * When the user drags the slider, every row with
 *     data-budget-simulator-target="row"
 *     data-effort="<days>"
 * is evaluated against the running cumulative sum (cheapest first). Rows
 * that fit into the budget get the `budget-affordable` class and
 * aria-checked="true", the rest get `budget-unaffordable`.
 *
 * The accessible label / live region reads e.g. "25 of 60 person-days,
 * covers 8 of 40 requirements".
 *
 * Usage:
 * <div data-controller="budget-simulator" data-budget-simulator-total-value="60">
 *   <input type="range"
 *          data-budget-simulator-target="slider"
 *          data-action="input->budget-simulator#update"
 *          min="0" max="60" step="1" value="0"
 *          aria-valuemin="0" aria-valuemax="60" aria-valuenow="0">
 *   <output data-budget-simulator-target="display" aria-live="polite"></output>
 *   <tbody>
 *     <tr data-budget-simulator-target="row" data-effort="3.5">…</tr>
 *     <tr data-budget-simulator-target="row" data-effort="7.0">…</tr>
 *   </tbody>
 * </div>
 */
export default class extends Controller {
    static targets = ['slider', 'display', 'row', 'count', 'cumulative'];

    static values = {
        total: { type: Number, default: 0 },
        labelFormat: { type: String, default: '{budget} of {total} person-days · covers {covered} of {count} requirements' },
    };

    connect() {
        // Sort the affordability queue once — cheapest first — and remember the
        // original DOM order so we never mutate it.
        this.orderedRows = this.rowTargets
            .map((el) => ({ el, effort: Number.parseFloat(el.dataset.effort || '0') }))
            .filter((row) => Number.isFinite(row.effort) && row.effort > 0)
            .sort((a, b) => a.effort - b.effort);

        this.update();
    }

    update() {
        if (!this.hasSliderTarget) {
            return;
        }

        const budget = Number.parseFloat(this.sliderTarget.value) || 0;
        let cumulative = 0;
        let covered = 0;

        // Pass 1: clear all markers on EVERY row (including unestimated ones).
        this.rowTargets.forEach((row) => {
            row.classList.remove('budget-affordable', 'budget-unaffordable');
            row.removeAttribute('aria-checked');
        });

        // Pass 2: greedily fit rows into the budget, cheapest first.
        this.orderedRows.forEach(({ el, effort }) => {
            if (cumulative + effort <= budget) {
                cumulative += effort;
                covered += 1;
                el.classList.add('budget-affordable');
                el.setAttribute('aria-checked', 'true');
            } else {
                el.classList.add('budget-unaffordable');
                el.setAttribute('aria-checked', 'false');
            }
        });

        // Sync ARIA + visible label
        this.sliderTarget.setAttribute('aria-valuenow', String(budget));

        const label = this.labelFormatValue
            .replace('{budget}', this.formatNumber(budget))
            .replace('{total}', this.formatNumber(this.totalValue))
            .replace('{covered}', String(covered))
            .replace('{count}', String(this.orderedRows.length));

        this.sliderTarget.setAttribute('aria-valuetext', label);

        if (this.hasDisplayTarget) {
            this.displayTarget.textContent = label;
        }

        if (this.hasCountTarget) {
            this.countTarget.textContent = String(covered);
        }

        if (this.hasCumulativeTarget) {
            this.cumulativeTarget.textContent = this.formatNumber(cumulative);
        }
    }

    formatNumber(value) {
        if (!Number.isFinite(value)) {
            return '0';
        }
        // Trim .0 but keep one decimal when needed
        return Number.isInteger(value) ? String(value) : value.toFixed(1);
    }
}
