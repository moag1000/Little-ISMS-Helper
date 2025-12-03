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

    // Theme-aware colors
    get themeColors() {
        return {
            text: this.isDarkMode ? '#f1f5f9' : '#2c3e50',
            textMuted: this.isDarkMode ? '#94a3b8' : '#6c757d',
            gridColor: this.isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'
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

        this.chart = new Chart(this.element, {
            type: this.typeValue || 'bar',
            data: this.dataValue,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: colors.text
                        }
                    },
                },
                ...this.optionsValue
            }
        });
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
