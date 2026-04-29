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
    Legend,
    Colors
} from 'chart.js';
import { readTokens, applyAuroraDefaults, subscribeToThemeChanges } from '../chart-theme.js';

// Register Chart.js components.
// Colors is REQUIRED in Chart.js 4 — without it datasets default to no fill +
// black stroke (we hit "alle Charts schwarz" right after the v3 → v4 bump).
// See https://www.chartjs.org/docs/latest/general/colors.html
Chart.register(
    ArcElement,
    BarElement,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Title,
    Tooltip,
    Legend,
    Colors
);

// FairyAurora v3.0: Aurora-Defaults global anwenden (liest CSS-Vars).
applyAuroraDefaults(Chart);

/**
 * FairyAurora-Tokens für chart-Controller.
 * Liest live aus CSS-Custom-Properties (reagiert auf Light/Dark/System).
 */
function getThemeColors() {
    const t = readTokens();
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark' ||
                   (document.documentElement.getAttribute('data-theme') === 'system' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches);
    return {
        textMuted: t['--fg-3'] || (isDark ? '#cbd5e1' : '#6b7280'),
        grid:      t['--border'] || (isDark ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.1)'),
        primary:   t['--primary'],
        accent:    t['--accent'],
        surface2:  t['--surface-2'],
        fg:        t['--fg'],
        isDark
    };
}

/**
 * Canvas cannot parse CSS vars. Resolve "var(--x)" strings to computed hex/rgba.
 * Recurses through strings, arrays, and plain objects (backgroundColor, borderColor, etc.).
 */
function resolveCssVars(value, rootStyle) {
    if (typeof value === 'string') {
        const m = value.match(/^var\((--[a-zA-Z0-9-]+)(?:\s*,\s*(.+))?\)$/);
        if (m) {
            const resolved = rootStyle.getPropertyValue(m[1]).trim();
            if (resolved) return resolved;
            if (m[2]) return m[2].trim();
        }
        return value;
    }
    if (Array.isArray(value)) {
        return value.map(v => resolveCssVars(v, rootStyle));
    }
    if (value && typeof value === 'object') {
        const out = {};
        for (const k in value) out[k] = resolveCssVars(value[k], rootStyle);
        return out;
    }
    return value;
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
                    bodyColor: 'rgba(255,255,255,0.85)',
                    backgroundColor: colors.isDark
                        ? 'rgba(30, 41, 59, 0.95)'
                        : 'rgba(15, 23, 42, 0.88)'
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

        const rootStyle = getComputedStyle(document.documentElement);
        const resolvedData = resolveCssVars(this.dataValue, rootStyle);
        const resolvedOptions = resolveCssVars(options, rootStyle);

        this.chart = new Chart(this.element, {
            type: chartType,
            data: resolvedData,
            options: resolvedOptions
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
