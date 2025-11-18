import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        risks: Array
    }

    connect() {
        this.renderMatrix();
    }

    renderMatrix() {
        const risks = this.risksValue || [];
        const matrix = this.element.querySelector('.risk-matrix');

        if (!matrix) return;

        // Clear existing risks
        matrix.querySelectorAll('.risk-item').forEach(el => el.remove());

        // Place risks in matrix
        risks.forEach(risk => {
            const probability = risk.probability || risk.inherentProbability || 1;
            const impact = risk.impact || risk.inherentImpact || 1;

            const cell = matrix.querySelector(`[data-probability="${probability}"][data-impact="${impact}"]`);
            if (cell) {
                const riskDot = document.createElement('div');
                riskDot.className = 'risk-item';
                riskDot.title = risk.name || risk.title;
                riskDot.style.backgroundColor = this.getRiskColor(probability * impact);

                // Add click handler to show risk details
                riskDot.addEventListener('click', () => {
                    // Extract locale from current URL path (e.g., /de/ or /en/)
                    const locale = window.location.pathname.match(/^\/([a-z]{2})\//)?.[1] || 'de';
                    window.location.href = `/${locale}/risk/${risk.id}`;
                });

                cell.appendChild(riskDot);
            }
        });
    }

    getRiskColor(score) {
        if (score >= 15) return '#dc2626'; // Critical (15-25) - Red
        if (score >= 8) return '#ea580c';  // High (8-14) - Orange
        if (score >= 4) return '#d97706';  // Medium (4-7) - Yellow
        return '#059669';                   // Low (1-3) - Green
    }
}
