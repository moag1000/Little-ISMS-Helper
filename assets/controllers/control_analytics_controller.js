import { Controller } from '@hotwired/stimulus';

/**
 * control-analytics — structural hook for the Control-Effectiveness dashboard.
 *
 * Charts render via inline Chart.js scripts (window.Chart from app.js).
 *
 * @todo H-12 — wire effectiveness filter + per-control drilldown.
 */
export default class extends Controller {
    connect() {
        // No-op.
    }
}
