import { Controller } from '@hotwired/stimulus';

/**
 * asset-analytics — structural hook for the Asset-Criticality dashboard.
 *
 * Charts render via inline Chart.js scripts (window.Chart from app.js).
 *
 * @todo H-12 — wire filter chips + criticality drilldown.
 */
export default class extends Controller {
    connect() {
        // No-op.
    }
}
