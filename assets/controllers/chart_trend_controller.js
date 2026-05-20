import { Controller } from '@hotwired/stimulus';

/**
 * chart-trend — structural hook for compliance-wizard history trend chart.
 *
 * Chart rendering is currently done by inline scripts using window.Chart.
 *
 * @todo H-12 — wire trend chart instantiation here (currently no-op).
 */
export default class extends Controller {
    connect() {
        // No-op.
    }
}
