import { Controller } from '@hotwired/stimulus';

/**
 * Renders an Aurora ISMS-Risk-Matrix (5x5).
 *
 * Reads the risks-Array from data-riskmatrix-risks-value, places one dot per
 * risk in the corresponding cell (probability x impact), and wires a click
 * handler that navigates to the risk detail page.
 *
 * DOM-Convention (must match the Aurora pattern in fairy-aurora-components.css):
 *   .isms-risk-matrix__grid > .isms-risk-cell[data-probability][data-impact]
 *
 * The cell-level (1..5) is set server-side by the Twig template; this
 * controller only adds the per-risk dots, not the cell colour.
 */
export default class extends Controller {
    static values = {
        risks: Array
    }

    connect() {
        this.renderMatrix();
    }

    renderMatrix() {
        const risks = this.risksValue || [];
        const grid = this.element.querySelector('.isms-risk-matrix__grid');
        if (!grid) return;

        // Clear existing dots before re-render.
        grid.querySelectorAll('.isms-risk-dot').forEach(el => el.remove());

        risks.forEach(risk => {
            const probability = risk.probability || risk.inherentProbability || 1;
            const impact = risk.impact || risk.inherentImpact || 1;

            const cell = grid.querySelector(
                `.isms-risk-cell[data-probability="${probability}"][data-impact="${impact}"]`
            );
            if (!cell) return;

            const dot = document.createElement('span');
            dot.className = 'isms-risk-dot';
            dot.title = risk.name || risk.title || '';

            dot.addEventListener('click', (e) => {
                e.stopPropagation();
                const locale = window.location.pathname.match(/^\/([a-z]{2})\//)?.[1] || 'de';
                window.location.href = `/${locale}/risk/${risk.id}`;
            });

            cell.appendChild(dot);
        });
    }
}
