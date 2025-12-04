import { Controller } from '@hotwired/stimulus';
import {
    Chart,
    ArcElement,
    BarElement,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Title,
    Tooltip,
    Legend
} from 'chart.js';

// Register Chart.js components
Chart.register(
    ArcElement,
    BarElement,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Title,
    Tooltip,
    Legend
);

/**
 * Get current theme colors based on dark/light mode
 */
function getThemeColors() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark' ||
                   document.documentElement.getAttribute('data-bs-theme') === 'dark';

    return {
        textMuted: isDark ? '#cbd5e1' : '#6b7280',
        grid: isDark ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.1)',
        isDark
    };
}

export default class extends Controller {
    static values = {
        type: String,
        data: Object,
        options: Object
    };

    connect() {
        this.createChart();

        // Listen for theme changes
        this.boundHandleThemeChange = this.handleThemeChange.bind(this);
        document.addEventListener('theme:changed', this.boundHandleThemeChange);
    }

    createChart() {
        const colors = getThemeColors();
        const chartType = this.typeValue || 'bar';
        const isRadialChart = ['pie', 'doughnut', 'radar', 'polarArea'].includes(chartType);

        // Create HTML legend container
        this.createLegendContainer();

        // Build options - use HTML legend for better styling
        const baseOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false  // Hide default canvas legend
                },
                tooltip: {
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    backgroundColor: colors.isDark ? 'rgba(30, 41, 59, 0.95)' : 'rgba(0, 0, 0, 0.8)'
                }
            }
        };

        // Add scales only for non-radial charts
        if (!isRadialChart) {
            baseOptions.scales = {
                x: {
                    ticks: { color: colors.textMuted },
                    grid: { color: colors.grid }
                },
                y: {
                    ticks: { color: colors.textMuted },
                    grid: { color: colors.grid }
                }
            };
        }

        // Deep merge user options with base options
        const userOptions = this.optionsValue || {};
        const options = this.deepMerge(baseOptions, userOptions);

        // Force HTML legend (override any user option)
        if (options.plugins && options.plugins.legend) {
            options.plugins.legend.display = false;
        }

        this.chart = new Chart(this.element, {
            type: chartType,
            data: this.dataValue,
            options: options
        });

        // Render HTML legend after chart is created
        this.renderHtmlLegend();
    }

    /**
     * Create container for HTML legend
     */
    createLegendContainer() {
        // Remove existing legend if any
        if (this.legendContainer) {
            this.legendContainer.remove();
        }

        this.legendContainer = document.createElement('div');
        this.legendContainer.className = 'chart-html-legend';
        this.element.parentNode.appendChild(this.legendContainer);
    }

    /**
     * Render HTML legend with glow effect styling
     */
    renderHtmlLegend() {
        if (!this.chart || !this.legendContainer) return;

        const datasets = this.chart.data.datasets;
        const labels = this.chart.data.labels || [];

        let html = '<div class="chart-legend-items">';

        // For pie/doughnut charts, use labels array
        const chartType = this.typeValue || 'bar';
        if (['pie', 'doughnut', 'polarArea'].includes(chartType) && labels.length > 0) {
            const colors = datasets[0]?.backgroundColor || [];
            labels.forEach((label, i) => {
                const color = Array.isArray(colors) ? colors[i] : colors;
                html += this.createLegendItem(label, color, i);
            });
        } else {
            // For other charts, use dataset labels
            datasets.forEach((dataset, i) => {
                const color = dataset.borderColor || dataset.backgroundColor;
                html += this.createLegendItem(dataset.label, color, i);
            });
        }

        html += '</div>';
        this.legendContainer.innerHTML = html;

        // Add click handlers for legend items
        this.legendContainer.querySelectorAll('.chart-legend-item').forEach((item, i) => {
            item.addEventListener('click', () => this.toggleDataVisibility(i));
        });
    }

    /**
     * Create a single legend item HTML
     */
    createLegendItem(label, color, index) {
        const isHidden = this.chart.getDatasetMeta(0)?.data[index]?.hidden || false;
        return `
            <span class="chart-legend-item ${isHidden ? 'legend-hidden' : ''}" data-index="${index}">
                <span class="chart-legend-color" style="background: ${color}; box-shadow: 0 0 6px ${color};"></span>
                <span class="chart-legend-label">${label}</span>
            </span>
        `;
    }

    /**
     * Toggle visibility of data on click
     */
    toggleDataVisibility(index) {
        const chartType = this.typeValue || 'bar';

        if (['pie', 'doughnut', 'polarArea'].includes(chartType)) {
            // For radial charts, toggle the specific data point
            const meta = this.chart.getDatasetMeta(0);
            if (meta.data[index]) {
                meta.data[index].hidden = !meta.data[index].hidden;
            }
        } else {
            // For other charts, toggle the dataset
            this.chart.setDatasetVisibility(index, !this.chart.isDatasetVisible(index));
        }

        this.chart.update();
        this.renderHtmlLegend();
    }

    /**
     * Deep merge two objects
     */
    deepMerge(target, source) {
        const result = { ...target };
        for (const key in source) {
            if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
                result[key] = this.deepMerge(result[key] || {}, source[key]);
            } else {
                result[key] = source[key];
            }
        }
        return result;
    }

    handleThemeChange() {
        // Rebuild chart with new theme colors
        if (this.chart) {
            this.chart.destroy();
        }
        this.createChart();
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
        }
        if (this.legendContainer) {
            this.legendContainer.remove();
        }
        document.removeEventListener('theme:changed', this.boundHandleThemeChange);
    }
}
