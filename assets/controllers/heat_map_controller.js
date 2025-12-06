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
    static targets = ['loading', 'container', 'grid', 'details', 'detailsTitle', 'detailsList', 'tooltip', 'filters', 'statusFilter', 'categoryFilter', 'timeFilter'];
    static values = {
        url: String,
        locale: { type: String, default: 'de' }
    };

    connect() {
        this.loadData();
        this.createTooltip();
        this.boundHideTooltip = this.hideTooltip.bind(this);
        this.boundHandleKeyboard = this.handleKeyboard.bind(this);
        this.rawMatrixData = null;
        this.currentFilters = { status: '', category: '', time: '' };
        this.focusedCellIndex = -1;

        // Enable keyboard navigation
        document.addEventListener('keydown', this.boundHandleKeyboard);
    }

    disconnect() {
        if (this.tooltipElement) {
            this.tooltipElement.remove();
        }
        document.removeEventListener('keydown', this.boundHandleKeyboard);
    }

    async loadData() {
        this.showLoading();

        try {
            const response = await fetch(this.urlValue);
            const data = await response.json();

            this.renderHeatMap(data.matrix);
            this.hideLoading();
        } catch (error) {
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
                    const scoreMin = probability * impact;
                    const riskListHtml = cell.risks.slice(0, 3).map(r =>
                        `<li><strong>${this.escapeHtml(r.title)}</strong> (${this.getLevelLabel(r.level)})</li>`
                    ).join('');

                    html += `
                        <div class="heat-map-cell ${cell.count > 0 ? 'has-risks' : ''}"
                             data-action="click->heat-map#showDetails mouseenter->heat-map#showTooltip mouseleave->heat-map#hideTooltip"
                             data-x="${cell.x}"
                             data-y="${cell.y}"
                             data-count="${cell.count}"
                             data-score="${scoreMin}"
                             data-risks='${JSON.stringify(cell.risks)}'
                             data-tooltip-content='<div class="tooltip-header"><strong>${cell.count} Risiko${cell.count !== 1 ? 's' : ''}</strong><span class="badge bg-${this.getLevelClass(scoreMin)}">Score: ${scoreMin}</span></div><ul class="tooltip-risk-list">${riskListHtml}${cell.count > 3 ? '<li class="text-muted">+ ' + (cell.count - 3) + ' weitere...</li>' : ''}</ul>'
                             style="background-color: ${cell.color};"
                             role="button"
                             tabindex="0"
                             aria-label="${cell.count} risk(s) at probability ${probability}, impact ${impact}">
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
        const score = cell.dataset.score;
        const risks = JSON.parse(cell.dataset.risks);

        if (count == 0) return;

        // Update title with more context
        const probabilityLabel = this.getProbabilityLabel(parseInt(x));
        const impactLabel = this.getImpactLabel(parseInt(y));

        this.detailsTitleTarget.innerHTML = `
            <div class="details-title-content">
                <h6>${count} Risiko${count !== 1 ? 's' : ''} in dieser Zelle</h6>
                <div class="cell-context">
                    <span class="badge bg-info">Wahrscheinlichkeit: ${probabilityLabel}</span>
                    <span class="badge bg-warning">Auswirkung: ${impactLabel}</span>
                    <span class="badge bg-${this.getLevelClass(parseInt(score))}">Score: ${score}</span>
                </div>
            </div>
        `;

        // Render enhanced risk list
        let html = '<ul class="risk-list-enhanced">';
        risks.forEach(risk => {
            const locale = this.localeValue || 'de';
            html += `
                <li class="risk-item-enhanced">
                    <div class="risk-header">
                        <a href="/${locale}/risk/${risk.id}" class="risk-title">
                            <i class="bi-exclamation-triangle-fill"></i>
                            <strong>${this.escapeHtml(risk.title)}</strong>
                        </a>
                        <span class="risk-level badge bg-${this.getLevelClass(risk.level)}">
                            ${this.getLevelLabel(risk.level)}
                        </span>
                    </div>
                    <div class="risk-meta">
                        ${risk.status ? `<span class="badge bg-secondary">${risk.status}</span>` : ''}
                        ${risk.category ? `<span class="badge bg-light text-dark">${risk.category}</span>` : ''}
                        ${risk.owner ? `<span class="text-muted"><i class="bi-person"></i> ${this.escapeHtml(risk.owner)}</span>` : ''}
                    </div>
                    ${risk.description ? `<p class="risk-description">${this.escapeHtml(risk.description.substring(0, 120))}${risk.description.length > 120 ? '...' : ''}</p>` : ''}
                </li>
            `;
        });
        html += '</ul>';

        this.detailsListTarget.innerHTML = html;

        // Show details panel with animation
        this.detailsTarget.classList.remove('d-none');
        requestAnimationFrame(() => {
            this.detailsTarget.classList.add('details-visible');
        });
    }

    closeDetails() {
        this.detailsTarget.classList.add('d-none');
    }

    getLevelClass(level) {
        if (level < 4) return 'success';      // Low (1-3)
        if (level < 8) return 'info';         // Medium (4-7)
        if (level < 15) return 'warning';     // High (8-14)
        return 'danger';                       // Critical (15-25)
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

    // ========== Enhanced Tooltip Functionality ==========

    createTooltip() {
        this.tooltipElement = document.createElement('div');
        this.tooltipElement.className = 'heat-map-tooltip';
        this.tooltipElement.setAttribute('role', 'tooltip');
        this.tooltipElement.style.display = 'none';
        document.body.appendChild(this.tooltipElement);
    }

    showTooltip(event) {
        const cell = event.currentTarget;
        const count = parseInt(cell.dataset.count);

        if (count === 0) return;

        const content = cell.dataset.tooltipContent;
        this.tooltipElement.innerHTML = content;

        // Position tooltip
        const rect = cell.getBoundingClientRect();
        const tooltipRect = this.tooltipElement.getBoundingClientRect();

        // Default: show above the cell
        let top = rect.top + window.scrollY - tooltipRect.height - 10;
        let left = rect.left + window.scrollX + (rect.width / 2) - (tooltipRect.width / 2);

        // If tooltip would go off top of screen, show below instead
        if (top < window.scrollY) {
            top = rect.bottom + window.scrollY + 10;
            this.tooltipElement.classList.add('tooltip-below');
        } else {
            this.tooltipElement.classList.remove('tooltip-below');
        }

        // Prevent tooltip from going off left/right edges
        if (left < 10) {
            left = 10;
        } else if (left + tooltipRect.width > window.innerWidth - 10) {
            left = window.innerWidth - tooltipRect.width - 10;
        }

        this.tooltipElement.style.top = `${top}px`;
        this.tooltipElement.style.left = `${left}px`;
        this.tooltipElement.style.display = 'block';

        // Trigger animation
        requestAnimationFrame(() => {
            this.tooltipElement.classList.add('tooltip-visible');
        });
    }

    hideTooltip() {
        if (this.tooltipElement) {
            this.tooltipElement.classList.remove('tooltip-visible');
            setTimeout(() => {
                this.tooltipElement.style.display = 'none';
            }, 200);
        }
    }

    getLevelLabel(level) {
        if (level < 4) return 'Niedrig';
        if (level < 8) return 'Mittel';
        if (level < 15) return 'Hoch';
        return 'Kritisch';
    }

    getProbabilityLabel(value) {
        const labels = {
            1: 'Sehr selten',
            2: 'Selten',
            3: 'Gelegentlich',
            4: 'Wahrscheinlich',
            5: 'Sehr wahrscheinlich'
        };
        return labels[value] || value;
    }

    getImpactLabel(value) {
        const labels = {
            1: 'Unbedeutend',
            2: 'Gering',
            3: 'Moderat',
            4: 'Hoch',
            5: 'Kritisch'
        };
        return labels[value] || value;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ========== Filter Functionality ==========

    toggleFilters() {
        if (this.hasFiltersTarget) {
            this.filtersTarget.classList.toggle('d-none');
        }
    }

    applyFilters() {
        if (!this.hasStatusFilterTarget) return;

        this.currentFilters = {
            status: this.statusFilterTarget.value,
            category: this.categoryFilterTarget.value,
            time: this.timeFilterTarget.value
        };

        // Reload data with filters
        const url = new URL(this.urlValue, window.location.origin);
        Object.entries(this.currentFilters).forEach(([key, value]) => {
            if (value) url.searchParams.append(key, value);
        });

        fetch(url)
            .then(response => response.json())
            .then(data => {
                this.rawMatrixData = data.matrix;
                this.renderHeatMap(data.matrix);
            })
            .catch(() => {});
    }

    clearFilters() {
        if (this.hasStatusFilterTarget) this.statusFilterTarget.value = '';
        if (this.hasCategoryFilterTarget) this.categoryFilterTarget.value = '';
        if (this.hasTimeFilterTarget) this.timeFilterTarget.value = '';
        this.currentFilters = { status: '', category: '', time: '' };
        this.loadData();
    }

    // ========== Keyboard Navigation ==========

    handleKeyboard(event) {
        // Only handle keyboard if heat map is visible and no modal is open
        if (!this.hasGridTarget || this.detailsTarget?.classList.contains('d-none') === false) {
            return;
        }

        const cells = Array.from(this.gridTarget.querySelectorAll('.heat-map-cell.has-risks'));
        if (cells.length === 0) return;

        switch(event.key) {
            case 'ArrowRight':
                event.preventDefault();
                this.navigateCells(cells, 1);
                break;
            case 'ArrowLeft':
                event.preventDefault();
                this.navigateCells(cells, -1);
                break;
            case 'ArrowDown':
                event.preventDefault();
                this.navigateCells(cells, 5); // Move down one row (5 cells per row)
                break;
            case 'ArrowUp':
                event.preventDefault();
                this.navigateCells(cells, -5); // Move up one row
                break;
            case 'Enter':
            case ' ':
                event.preventDefault();
                if (this.focusedCellIndex >= 0 && cells[this.focusedCellIndex]) {
                    cells[this.focusedCellIndex].click();
                }
                break;
            case 'Escape':
                event.preventDefault();
                this.closeDetails();
                break;
        }
    }

    navigateCells(cells, direction) {
        // Initialize focus if not set
        if (this.focusedCellIndex === -1) {
            this.focusedCellIndex = 0;
        } else {
            this.focusedCellIndex += direction;
        }

        // Wrap around
        if (this.focusedCellIndex < 0) {
            this.focusedCellIndex = cells.length - 1;
        } else if (this.focusedCellIndex >= cells.length) {
            this.focusedCellIndex = 0;
        }

        // Focus the cell
        cells[this.focusedCellIndex].focus();
        cells[this.focusedCellIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}
