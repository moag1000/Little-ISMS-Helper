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

    // Bootstrap 5 colors - consistent across all charts
    // Dark mode uses lighter variants for visibility
    get colors() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark' ||
                       document.documentElement.getAttribute('data-bs-theme') === 'dark';
        return {
            success: isDark ? '#34d399' : '#28a745',
            successBg: isDark ? 'rgba(52, 211, 153, 0.3)' : 'rgba(40, 167, 69, 0.2)',
            text: isDark ? '#f1f5f9' : '#2c3e50',
            textMuted: isDark ? '#94a3b8' : '#6c757d',
            gridColor: isDark ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.1)'
        };
    }

    connect() {
        this.chart = null;
        this.loadData();

        // Re-render chart when theme changes
        this.boundHandleThemeChange = this.handleThemeChange.bind(this);
        document.addEventListener('theme:changed', this.boundHandleThemeChange);
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
        }
        document.removeEventListener('theme:changed', this.boundHandleThemeChange);
    }

    handleThemeChange() {
        this.loadData();
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
        const colors = this.colors;

        const ctx = this.canvasTarget.getContext('2d');

        this.chart = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Compliance %',
                    data: values,
                    backgroundColor: colors.successBg,
                    borderColor: colors.success,
                    borderWidth: 2,
                    pointBackgroundColor: colors.success,
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: colors.success
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
                            color: colors.textMuted,
                            callback: function(value) {
                                return value + '%';
                            }
                        },
                        pointLabels: {
                            font: {
                                size: 12,
                                weight: 'bold'
                            },
                            color: colors.text
                        },
                        grid: {
                            color: colors.gridColor
                        },
                        angleLines: {
                            color: colors.gridColor
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
