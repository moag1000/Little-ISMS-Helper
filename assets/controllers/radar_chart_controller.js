import { Controller } from '@hotwired/stimulus';

/**
 * Radar Chart Controller - Compliance Radar Visualization
 *
 * Features:
 * - Spider/Radar chart using Chart.js
 * - Shows compliance % per ISO 27001 Annex
 * - Overall compliance score
 * - Details table
 */
export default class extends Controller {
    static targets = ['loading', 'container', 'canvas', 'overall', 'overallValue', 'detailsTable'];
    static values = {
        url: String
    };

    connect() {
        this.chart = null;
        this.loadData();
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
        }
    }

    async loadData() {
        this.showLoading();

        try {
            const response = await fetch(this.urlValue);
            const data = await response.json();

            this.renderRadarChart(data.data);
            this.renderDetailsTable(data.data);
            this.updateOverallCompliance(data.overall_compliance);
            this.hideLoading();
        } catch (error) {
            console.error('Failed to load compliance radar data:', error);
            this.showError();
        }
    }

    renderRadarChart(data) {
        if (!this.hasCanvasTarget) return;

        // Destroy existing chart
        if (this.chart) {
            this.chart.destroy();
        }

        const labels = data.map(item => item.label);
        const values = data.map(item => item.value);

        const ctx = this.canvasTarget.getContext('2d');

        this.chart = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Compliance %',
                    data: values,
                    backgroundColor: 'rgba(46, 204, 113, 0.2)',
                    borderColor: 'rgba(46, 204, 113, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: 'rgba(46, 204, 113, 1)',
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: 'rgba(46, 204, 113, 1)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 20,
                            callback: function(value) {
                                return value + '%';
                            }
                        },
                        pointLabels: {
                            font: {
                                size: 12,
                                weight: 'bold'
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.r + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    renderDetailsTable(data) {
        if (!this.hasDetailsTableTarget) return;

        let html = '';
        data.forEach(item => {
            const percentage = item.value;
            const badgeClass = percentage >= 80 ? 'success' : percentage >= 50 ? 'warning' : 'danger';

            html += `
                <tr>
                    <td><strong>${item.label}</strong></td>
                    <td>${item.implemented}</td>
                    <td>${item.total}</td>
                    <td>
                        <span class="badge bg-${badgeClass}">${percentage}%</span>
                    </td>
                </tr>
            `;
        });

        this.detailsTableTarget.innerHTML = html;
    }

    updateOverallCompliance(compliance) {
        if (!this.hasOverallValueTarget) return;

        const valueSpan = this.overallValueTarget.querySelector('.value');
        if (valueSpan) {
            valueSpan.textContent = compliance;
        }

        // Add color class
        this.overallValueTarget.classList.remove('text-success', 'text-warning', 'text-danger');
        if (compliance >= 80) {
            this.overallValueTarget.classList.add('text-success');
        } else if (compliance >= 50) {
            this.overallValueTarget.classList.add('text-warning');
        } else {
            this.overallValueTarget.classList.add('text-danger');
        }
    }

    refresh() {
        this.loadData();
    }

    exportImage() {
        if (this.chart) {
            const url = this.chart.toBase64Image();
            const link = document.createElement('a');
            link.href = url;
            link.download = 'compliance-radar.png';
            link.click();
        }
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
        if (this.hasCanvasTarget) {
            this.canvasTarget.parentElement.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi-exclamation-triangle"></i>
                    Failed to load compliance radar data. Please try again.
                </div>
            `;
        }
    }
}
