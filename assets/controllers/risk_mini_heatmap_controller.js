import { Controller } from '@hotwired/stimulus'

/**
 * risk-mini-heatmap
 *
 * Live 5×5 risk-matrix that highlights the cell selected by the current
 * `probability` + `impact` inputs (1-5 each). Updates the active band-label
 * + numeric score next to the grid as the user types.
 *
 * Norm-Bezug: ISO 27005 Risk-Matrix. Cell-band classification follows the PHP
 * SSoT (App\Risk\RiskMatrixThresholds) — band-boundaries are server-rendered
 * via `data-band-*-value` attributes so the JS side never duplicates the
 * thresholds.
 *
 * Audit-S5 T6.8 (UX_SCORING_2026-05-22) — Risk-Matrix erst nach Speichern
 * sichtbar; mini-heatmap-im-Form fehlt = Bewertungsfeedback verzögert.
 *
 * Usage:
 *   <div data-controller="risk-mini-heatmap"
 *        data-risk-mini-heatmap-probability-selector-value="#risk_probability"
 *        data-risk-mini-heatmap-impact-selector-value="#risk_impact">
 *     ... 25 cells with data-risk-mini-heatmap-cell-value="p_i" + data-score="N" ...
 *     <span data-risk-mini-heatmap-target="scoreLabel">—</span>
 *   </div>
 */
export default class extends Controller {
    static targets = ['cell', 'scoreLabel', 'bandLabel']
    static values = {
        probabilitySelector: String,
        impactSelector: String,
        bandCriticalMin: { type: Number, default: 20 },
        bandHighMin: { type: Number, default: 12 },
        bandMediumMin: { type: Number, default: 6 },
    }

    connect() {
        this.pInput = document.querySelector(this.probabilitySelectorValue)
        this.iInput = document.querySelector(this.impactSelectorValue)
        if (!this.pInput || !this.iInput) return

        this._handler = () => this.refresh()
        this.pInput.addEventListener('change', this._handler)
        this.pInput.addEventListener('input', this._handler)
        this.iInput.addEventListener('change', this._handler)
        this.iInput.addEventListener('input', this._handler)
        this.refresh()
    }

    disconnect() {
        if (this.pInput && this._handler) {
            this.pInput.removeEventListener('change', this._handler)
            this.pInput.removeEventListener('input', this._handler)
        }
        if (this.iInput && this._handler) {
            this.iInput.removeEventListener('change', this._handler)
            this.iInput.removeEventListener('input', this._handler)
        }
    }

    refresh() {
        const p = parseInt(this.pInput?.value || '', 10)
        const i = parseInt(this.iInput?.value || '', 10)
        const valid = Number.isFinite(p) && Number.isFinite(i) && p >= 1 && p <= 5 && i >= 1 && i <= 5

        this.cellTargets.forEach(cell => {
            cell.classList.remove('is-active')
            if (valid && cell.dataset.riskMiniHeatmapCellValue === `${p}_${i}`) {
                cell.classList.add('is-active')
            }
        })

        if (this.hasScoreLabelTarget) {
            this.scoreLabelTarget.textContent = valid ? String(p * i) : '—'
        }
        if (this.hasBandLabelTarget) {
            this.bandLabelTarget.dataset.band = valid ? this._classify(p * i) : ''
            this.bandLabelTarget.textContent = valid
                ? this.bandLabelTarget.dataset[`label${this._classify(p * i)}`] || ''
                : ''
        }
    }

    _classify(score) {
        if (score >= this.bandCriticalMinValue) return 'Critical'
        if (score >= this.bandHighMinValue) return 'High'
        if (score >= this.bandMediumMinValue) return 'Medium'
        return 'Low'
    }
}
