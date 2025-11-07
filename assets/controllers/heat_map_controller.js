import { Controller } from '@hotwired/stimulus';

/**
 * Heat Map Controller - Risk Heat Map Visualization
 *
 * Features:
 * - 5x5 Matrix rendering
 * - Color-coded cells
 * - Interactive tooltips
 * - Click to view risks in cell
 * - Export as image
 */
export default class extends Controller {
    static targets = ['loading', 'container', 'grid', 'details', 'detailsTitle', 'detailsList'];
    static values = {
        url: String
    };

    connect() {
        this.loadData();
    }

    async loadData() {
        this.showLoading();

        try {
            const response = await fetch(this.urlValue);
            const data = await response.json();

            this.renderHeatMap(data.matrix);
            this.hideLoading();
        } catch (error) {
            console.error('Failed to load heat map data:', error);
            this.showError();
        }
    }

    renderHeatMap(matrix) {
        if (!this.hasGridTarget) return;

        let html = '<div class="heat-map-matrix">';

        // Y-axis labels (Impact: 5 -> 1)
        html += '<div class="heat-map-y-labels">';
        for (let i = 5; i >= 1; i--) {
            html += `<div class="y-label">${i}</div>`;
        }
        html += '</div>';

        // Grid cells
        html += '<div class="heat-map-cells">';

        // Render cells from top-left to bottom-right
        for (let impact = 5; impact >= 1; impact--) {
            for (let probability = 1; probability <= 5; probability++) {
                const cell = matrix.find(c => c.x === probability && c.y === impact);

                if (cell) {
                    html += `
                        <div class="heat-map-cell"
                             data-action="click->heat-map#showDetails"
                             data-x="${cell.x}"
                             data-y="${cell.y}"
                             data-count="${cell.count}"
                             data-risks='${JSON.stringify(cell.risks)}'
                             style="background-color: ${cell.color};"
                             title="${cell.count} risk(s) - Score: ${cell.score}">
                            <span class="cell-count">${cell.count > 0 ? cell.count : ''}</span>
                        </div>
                    `;
                }
            }
        }

        html += '</div>'; // .heat-map-cells

        // X-axis labels (Probability: 1 -> 5)
        html += '<div class="heat-map-x-labels">';
        for (let i = 1; i <= 5; i++) {
            html += `<div class="x-label">${i}</div>`;
        }
        html += '</div>';

        html += '</div>'; // .heat-map-matrix

        this.gridTarget.innerHTML = html;
    }

    showDetails(event) {
        const cell = event.currentTarget;
        const x = cell.dataset.x;
        const y = cell.dataset.y;
        const count = cell.dataset.count;
        const risks = JSON.parse(cell.dataset.risks);

        if (count == 0) return;

        // Update title
        this.detailsTitleTarget.textContent = `Risks: Probability ${x}, Impact ${y} (${count})`;

        // Render risk list
        let html = '<ul class="risk-list">';
        risks.forEach(risk => {
            html += `
                <li class="risk-item">
                    <a href="/risk/${risk.id}">
                        <strong>${risk.title}</strong>
                        <span class="risk-level badge bg-${this.getLevelClass(risk.level)}">
                            Level: ${risk.level}
                        </span>
                    </a>
                </li>
            `;
        });
        html += '</ul>';

        this.detailsListTarget.innerHTML = html;

        // Show details panel
        this.detailsTarget.classList.remove('d-none');
    }

    closeDetails() {
        this.detailsTarget.classList.add('d-none');
    }

    getLevelClass(level) {
        if (level <= 6) return 'success';
        if (level <= 14) return 'warning';
        return 'danger';
    }

    refresh() {
        this.loadData();
    }

    exportImage() {
        // Simple export using html2canvas or similar
        // For now, just trigger print
        window.print();
    }

    showLoading() {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.remove('d-none');
        }
        if (this.hasContainerTarget) {
            this.containerTarget.style.opacity = '0.3';
        }
    }

    hideLoading() {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.add('d-none');
        }
        if (this.hasContainerTarget) {
            this.containerTarget.style.opacity = '1';
        }
    }

    showError() {
        this.hideLoading();
        if (this.hasGridTarget) {
            this.gridTarget.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi-exclamation-triangle"></i>
                    Failed to load heat map data. Please try again.
                </div>
            `;
        }
    }
}
