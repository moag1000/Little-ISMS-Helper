import { Controller } from '@hotwired/stimulus';

/**
 * Trend Chart Controller - Trend Analysis Visualization
 *
 * Features:
 * - Multiple line charts (Risks, Assets, Incidents)
 * - Time-based trends
 * - Period filtering
 * - Export functionality
 */
export default class extends Controller {
    static targets = [
        'loading', 'container',
        'riskTab', 'assetTab', 'incidentTab',
        'riskChart', 'assetChart', 'incidentChart',
        'riskCanvas', 'assetCanvas', 'incidentCanvas',
        'riskTotal', 'riskHigh', 'riskTrend',
        'assetTotal', 'assetGrowth',
        'incidentTotal', 'incidentCritical', 'incidentAvg'
    ];
    static values = {
        url: String
    };

    connect() {
        this.charts = {
            risk: null,
            asset: null,
            incident: null
        };
        this.currentChart = 'risk';
        this.period = 12; // default

        this.loadData();

        // Listen for period changes from parent analytics controller
        this.boundHandlePeriodChange = this.handlePeriodChange.bind(this);
        document.addEventListener('analytics:period-changed', this.boundHandlePeriodChange);
    }

    disconnect() {
        Object.values(this.charts).forEach(chart => {
            if (chart) chart.destroy();
        });
        document.removeEventListener('analytics:period-changed', this.boundHandlePeriodChange);
    }

    handlePeriodChange(event) {
        this.period = event.detail.period;
        this.loadData();
    }

    async loadData() {
        this.showLoading();

        try {
            const url = `${this.urlValue}?period=${this.period}`;
            const response = await fetch(url);
            const data = await response.json();

            this.renderRiskTrendChart(data.risks);
            this.renderAssetTrendChart(data.assets);
            this.renderIncidentTrendChart(data.incidents);

            this.updateStats(data);
            this.hideLoading();
        } catch (error) {
            console.error('Failed to load trend data:', error);
            this.showError();
        }
    }

    renderRiskTrendChart(data) {
        if (!this.hasRiskCanvasTarget) return;

        if (this.charts.risk) {
            this.charts.risk.destroy();
        }

        const labels = data.map(d => d.month);
        const low = data.map(d => d.low);
        const medium = data.map(d => d.medium);
        const high = data.map(d => d.high);

        const ctx = this.riskCanvasTarget.getContext('2d');

        this.charts.risk = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'High',
                        data: high,
                        borderColor: '#e74c3c',
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Medium',
                        data: medium,
                        borderColor: '#f39c12',
                        backgroundColor: 'rgba(243, 156, 18, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Low',
                        data: low,
                        borderColor: '#2ecc71',
                        backgroundColor: 'rgba(46, 204, 113, 0.1)',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    renderAssetTrendChart(data) {
        if (!this.hasAssetCanvasTarget) return;

        if (this.charts.asset) {
            this.charts.asset.destroy();
        }

        const labels = data.map(d => d.month);
        const counts = data.map(d => d.count);

        const ctx = this.assetCanvasTarget.getContext('2d');

        this.charts.asset = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Assets',
                    data: counts,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    renderIncidentTrendChart(data) {
        if (!this.hasIncidentCanvasTarget) return;

        if (this.charts.incident) {
            this.charts.incident.destroy();
        }

        const labels = data.map(d => d.month);
        const critical = data.map(d => d.critical);
        const high = data.map(d => d.high);
        const medium = data.map(d => d.medium);
        const low = data.map(d => d.low);

        const ctx = this.incidentCanvasTarget.getContext('2d');

        this.charts.incident = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Critical',
                        data: critical,
                        backgroundColor: '#c0392b',
                        stack: 'Stack 0'
                    },
                    {
                        label: 'High',
                        data: high,
                        backgroundColor: '#e74c3c',
                        stack: 'Stack 0'
                    },
                    {
                        label: 'Medium',
                        data: medium,
                        backgroundColor: '#f39c12',
                        stack: 'Stack 0'
                    },
                    {
                        label: 'Low',
                        data: low,
                        backgroundColor: '#2ecc71',
                        stack: 'Stack 0'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true
                    }
                }
            }
        });
    }

    updateStats(data) {
        // Risk stats
        if (this.hasRiskTotalTarget && data.risks.length > 0) {
            const latest = data.risks[data.risks.length - 1];
            this.riskTotalTarget.textContent = latest.total;
            this.riskHighTarget.textContent = latest.high;

            // Calculate trend
            if (data.risks.length >= 2) {
                const previous = data.risks[data.risks.length - 2];
                const change = latest.total - previous.total;
                const icon = change > 0 ? '↑' : change < 0 ? '↓' : '→';
                const color = change > 0 ? 'text-danger' : change < 0 ? 'text-success' : 'text-muted';
                this.riskTrendTarget.innerHTML = `<span class="${color}">${icon} ${Math.abs(change)}</span>`;
            }
        }

        // Asset stats
        if (this.hasAssetTotalTarget && data.assets.length > 0) {
            const latest = data.assets[data.assets.length - 1];
            this.assetTotalTarget.textContent = latest.count;

            // Calculate growth
            if (data.assets.length >= 2) {
                const first = data.assets[0];
                const growth = latest.count - first.count;
                const percentage = first.count > 0 ? ((growth / first.count) * 100).toFixed(1) : 0;
                this.assetGrowthTarget.innerHTML = `+${growth} (+${percentage}%)`;
            }
        }

        // Incident stats
        if (this.hasIncidentTotalTarget && data.incidents.length > 0) {
            const totalIncidents = data.incidents.reduce((sum, d) => sum + d.total, 0);
            const totalCritical = data.incidents.reduce((sum, d) => sum + d.critical, 0);
            const avgPerMonth = (totalIncidents / data.incidents.length).toFixed(1);

            this.incidentTotalTarget.textContent = totalIncidents;
            this.incidentCriticalTarget.textContent = totalCritical;
            this.incidentAvgTarget.textContent = avgPerMonth;
        }
    }

    showRiskTrend() {
        this.switchChart('risk');
    }

    showAssetTrend() {
        this.switchChart('asset');
    }

    showIncidentTrend() {
        this.switchChart('incident');
    }

    switchChart(chart) {
        this.currentChart = chart;

        // Update tabs
        [this.riskTabTarget, this.assetTabTarget, this.incidentTabTarget].forEach(tab => {
            tab.classList.remove('active');
        });

        // Update chart visibility
        [this.riskChartTarget, this.assetChartTarget, this.incidentChartTarget].forEach(c => {
            c.classList.add('d-none');
        });

        switch (chart) {
            case 'risk':
                this.riskTabTarget.classList.add('active');
                this.riskChartTarget.classList.remove('d-none');
                break;
            case 'asset':
                this.assetTabTarget.classList.add('active');
                this.assetChartTarget.classList.remove('d-none');
                break;
            case 'incident':
                this.incidentTabTarget.classList.add('active');
                this.incidentChartTarget.classList.remove('d-none');
                break;
        }
    }

    refresh() {
        this.loadData();
    }

    exportImage() {
        const currentChartObj = this.charts[this.currentChart];
        if (currentChartObj) {
            const url = currentChartObj.toBase64Image();
            const link = document.createElement('a');
            link.href = url;
            link.download = `${this.currentChart}-trend.png`;
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
        console.error('Failed to load trend data');
    }
}
