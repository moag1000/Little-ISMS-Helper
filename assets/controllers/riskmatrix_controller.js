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
                    window.location.href = `/risk/${risk.id}`;
                });

                cell.appendChild(riskDot);
            }
        });
    }

    getRiskColor(score) {
        if (score >= 20) return '#dc2626'; // Critical - Red
        if (score >= 12) return '#ea580c'; // High - Orange
        if (score >= 6) return '#d97706';  // Medium - Yellow
        return '#059669'; // Low - Green
    }
}
