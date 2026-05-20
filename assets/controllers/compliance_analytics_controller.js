import { Controller } from '@hotwired/stimulus';

/**
 * compliance-analytics — structural hook for the Compliance-Frameworks dashboard.
 *
 * Charts render via inline Chart.js scripts (window.Chart from app.js).
 *
 * @todo H-12 — wire framework filter + per-framework drilldown.
 */
export default class extends Controller {
    connect() {
        // No-op.
    }
}
