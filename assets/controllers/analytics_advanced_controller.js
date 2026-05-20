import { Controller } from '@hotwired/stimulus';

/**
 * analytics-advanced — structural hook for the Advanced Analytics dashboard.
 *
 * The dashboard renders charts via inline scripts (Chart.js is exposed
 * globally on `window.Chart` from app.js). This controller exists as a
 * connection point for future filter / drilldown wiring.
 *
 * @todo H-12 — wire filter chips + drilldown handlers; until then this
 *              stub keeps `data-controller="analytics-advanced"` from
 *              breaking the page when Stimulus tries to resolve it.
 */
export default class extends Controller {
    connect() {
        // No-op. See JSDoc above.
    }
}
