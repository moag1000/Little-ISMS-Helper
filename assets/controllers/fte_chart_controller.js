import { Controller } from '@hotwired/stimulus';

/**
 * F11 FTE-Tracking — Chart.js trend chart with period toggle.
 *
 * Reads all 12-month data from data-attributes, then slices to the
 * selected period (3m / 6m / 12m) on toggle. No additional HTTP request.
 *
 * data-fte-chart-labels-value: JSON array of 'YYYY-MM' strings
 * data-fte-chart-data-value:   JSON array of integer savings values
 */
export default class extends Controller {
    static targets = ['canvas'];
    static values  = {
        labels: Array,
        data:   Array,
    };

    connect() {
        this._chart = null;
        this._allLabels = this.labelsValue;
        this._allData   = this.dataValue;
        this._render(12);
    }

    disconnect() {
        if (this._chart) {
            this._chart.destroy();
            this._chart = null;
        }
    }

    show12m() { this._render(12); }
    show6m()  { this._render(6);  }
    show3m()  { this._render(3);  }

    _render(months) {
        const labels = this._allLabels.slice(-months);
        const data   = this._allData.slice(-months);

        if (this._chart) {
            this._chart.data.labels       = labels;
            this._chart.data.datasets[0].data = data;
            this._chart.update();
            return;
        }

        // Lazy-load Chart.js (already bundled in importmap)
        import('chart.js/auto').then(({ default: Chart }) => {
            const ctx = this.canvasTarget.getContext('2d');
            this._chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Savings (min)',
                        data,
                        backgroundColor: 'rgba(13, 110, 253, 0.25)',
                        borderColor:     'rgba(13, 110, 253, 0.85)',
                        borderWidth: 2,
                        borderRadius: 4,
                    }],
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => {
                                    const min = ctx.parsed.y;
                                    const h   = (min / 60).toFixed(1);
                                    return `${min} min (${h} h)`;
                                },
                            },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Minutes saved' },
                        },
                    },
                },
            });
        });
    }
}
