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
        url: String,
        locale: { type: String, default: 'de' }
    };

    // Translated labels for chart datasets
    get labels() {
        const isGerman = this.localeValue === 'de';
        return {
            high: isGerman ? 'Hoch' : 'High',
            medium: isGerman ? 'Mittel' : 'Medium',
            low: isGerman ? 'Niedrig' : 'Low',
            critical: isGerman ? 'Kritisch' : 'Critical',
            totalAssets: isGerman ? 'Gesamt Assets' : 'Total Assets'
        };
    }

    // Bootstrap 5 colors - consistent across all charts
    // Light mode uses standard Bootstrap colors
    // Dark mode uses lighter variants for visibility
    get colors() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark' ||
                       document.documentElement.getAttribute('data-bs-theme') === 'dark';
        return {
            danger: isDark ? '#f87171' : '#dc3545',      // Critical/High risk
            dangerDark: isDark ? '#b91c1c' : '#721c24',  // Critical severity
            warning: isDark ? '#fbbf24' : '#ffc107',     // Medium risk
            success: isDark ? '#34d399' : '#28a745',     // Low risk
            info: isDark ? '#22d3ee' : '#17a2b8',        // Assets/Info
            secondary: isDark ? '#94a3b8' : '#6c757d',   // Neutral
            // RGBA versions for backgrounds
            dangerBg: isDark ? 'rgba(248, 113, 113, 0.2)' : 'rgba(220, 53, 69, 0.1)',
            warningBg: isDark ? 'rgba(251, 191, 36, 0.2)' : 'rgba(255, 193, 7, 0.1)',
            successBg: isDark ? 'rgba(52, 211, 153, 0.2)' : 'rgba(40, 167, 69, 0.1)',
            infoBg: isDark ? 'rgba(34, 211, 238, 0.2)' : 'rgba(23, 162, 184, 0.1)',
            // Text colors for chart labels - high contrast in dark mode
            text: isDark ? '#e2e8f0' : '#2c3e50',
            textMuted: isDark ? '#cbd5e1' : '#6c757d',
            gridColor: isDark ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.1)'
        };
    }

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

        // Re-render charts when theme changes
        this.boundHandleThemeChange = this.handleThemeChange.bind(this);
        document.addEventListener('theme:changed', this.boundHandleThemeChange);
    }

    disconnect() {
        Object.values(this.charts).forEach(chart => {
            if (chart) chart.destroy();
        });
        document.removeEventListener('analytics:period-changed', this.boundHandlePeriodChange);
        document.removeEventListener('theme:changed', this.boundHandleThemeChange);
    }

    handleThemeChange() {
        // Re-render all charts with new theme colors
        this.loadData();
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

        const monthLabels = data.map(d => d.month);
        const low = data.map(d => d.low);
        const medium = data.map(d => d.medium);
        const high = data.map(d => d.high);
        const colors = this.colors;
        const labels = this.labels;

        const ctx = this.riskCanvasTarget.getContext('2d');

        this.charts.risk = new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [
                    {
                        label: labels.high,
                        data: high,
                        borderColor: colors.danger,
                        backgroundColor: colors.dangerBg,
                        tension: 0.4
                    },
                    {
                        label: labels.medium,
                        data: medium,
                        borderColor: colors.warning,
                        backgroundColor: colors.warningBg,
                        tension: 0.4
                    },
                    {
                        label: labels.low,
                        data: low,
                        borderColor: colors.success,
                        backgroundColor: colors.successBg,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { color: colors.text }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, color: colors.textMuted },
                        grid: { color: colors.gridColor }
                    },
                    x: {
                        ticks: { color: colors.textMuted },
                        grid: { color: colors.gridColor }
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

        const monthLabels = data.map(d => d.month);
        const counts = data.map(d => d.count);
        const colors = this.colors;
        const labels = this.labels;

        const ctx = this.assetCanvasTarget.getContext('2d');

        this.charts.asset = new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: labels.totalAssets,
                    data: counts,
                    borderColor: colors.info,
                    backgroundColor: colors.infoBg,
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
                        ticks: { stepSize: 1, color: colors.textMuted },
                        grid: { color: colors.gridColor }
                    },
                    x: {
                        ticks: { color: colors.textMuted },
                        grid: { color: colors.gridColor }
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

        const monthLabels = data.map(d => d.month);
        const critical = data.map(d => d.critical);
        const high = data.map(d => d.high);
        const medium = data.map(d => d.medium);
        const low = data.map(d => d.low);
        const colors = this.colors;
        const labels = this.labels;

        const ctx = this.incidentCanvasTarget.getContext('2d');

        this.charts.incident = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: monthLabels,
                datasets: [
                    {
                        label: labels.critical,
                        data: critical,
                        backgroundColor: colors.dangerDark,
                        stack: 'Stack 0'
                    },
                    {
                        label: labels.high,
                        data: high,
                        backgroundColor: colors.danger,
                        stack: 'Stack 0'
                    },
                    {
                        label: labels.medium,
                        data: medium,
                        backgroundColor: colors.warning,
                        stack: 'Stack 0'
                    },
                    {
                        label: labels.low,
                        data: low,
                        backgroundColor: colors.success,
                        stack: 'Stack 0'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { color: colors.text }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        ticks: { color: colors.textMuted },
                        grid: { color: colors.gridColor }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { color: colors.textMuted },
                        grid: { color: colors.gridColor }
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
