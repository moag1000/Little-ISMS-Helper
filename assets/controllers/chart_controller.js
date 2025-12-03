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

export default class extends Controller {
    static values = {
        type: String,
        data: Object,
        options: Object
    };

    // Dark mode detection
    get isDarkMode() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ||
               document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    // Theme-aware colors - ensure high contrast in dark mode
    get themeColors() {
        return {
            text: this.isDarkMode ? '#e2e8f0' : '#2c3e50',           // Bright white for dark mode
            textMuted: this.isDarkMode ? '#cbd5e1' : '#6c757d',       // Lighter gray for axes
            gridColor: this.isDarkMode ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.1)'
        };
    }

    connect() {
        this.createChart();

        // Listen for theme changes
        this.boundHandleThemeChange = this.handleThemeChange.bind(this);
        document.addEventListener('theme:changed', this.boundHandleThemeChange);
    }

    createChart() {
        const colors = this.themeColors;
        const chartType = this.typeValue || 'bar';
        const isRadialChart = ['doughnut', 'pie', 'polarArea', 'radar'].includes(chartType);

        // Base options for all charts
        const baseOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: colors.text,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    backgroundColor: 'rgba(0, 0, 0, 0.8)'
                }
            }
        };

        // Only add scales for non-radial charts (bar, line, etc.)
        if (!isRadialChart) {
            baseOptions.scales = {
                x: {
                    ticks: { color: colors.textMuted },
                    grid: { color: colors.gridColor }
                },
                y: {
                    ticks: { color: colors.textMuted },
                    grid: { color: colors.gridColor }
                }
            };
        }

        // Merge user options
        const userOptions = this.optionsValue || {};
        const mergedOptions = this.deepMerge(baseOptions, userOptions);

        // Always force legend label color for dark mode compatibility
        if (!mergedOptions.plugins) mergedOptions.plugins = {};
        if (!mergedOptions.plugins.legend) mergedOptions.plugins.legend = {};
        if (!mergedOptions.plugins.legend.labels) mergedOptions.plugins.legend.labels = {};
        mergedOptions.plugins.legend.labels.color = colors.text;

        this.chart = new Chart(this.element, {
            type: chartType,
            data: this.dataValue,
            options: mergedOptions
        });
    }

    // Deep merge helper for options
    deepMerge(target, source) {
        const result = { ...target };
        for (const key in source) {
            if (source[key] instanceof Object && key in target && target[key] instanceof Object) {
                result[key] = this.deepMerge(target[key], source[key]);
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
        document.removeEventListener('theme:changed', this.boundHandleThemeChange);
    }
}
