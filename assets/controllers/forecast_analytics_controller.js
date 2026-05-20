import { Controller } from '@hotwired/stimulus';

/**
 * forecast-analytics — structural hook for the Risk-Forecast dashboard.
 *
 * Charts render via inline Chart.js scripts (window.Chart from app.js).
 *
 * @todo H-12 — wire forecast horizon picker + scenario toggle.
 */
export default class extends Controller {
    connect() {
        // No-op.
    }
}
