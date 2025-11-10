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

    connect() {
        this.chart = new Chart(this.element, {
            type: this.typeValue || 'bar',
            data: this.dataValue,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                },
                ...this.optionsValue
            }
        });
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
        }
    }
}
