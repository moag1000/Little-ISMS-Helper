import { Controller } from '@hotwired/stimulus';

/**
 * Analytics Controller - Main Analytics Dashboard
 *
 * Features:
 * - Period filtering
 * - Print dashboard
 * - Coordinate child controllers
 */
export default class extends Controller {
    static targets = ['periodFilter'];

    connect() {
        // Controller connected
    }

    changePeriod(event) {
        const period = event.target.value;

        // Dispatch event to update all trend charts
        this.dispatch('period-changed', { detail: { period } });

        // Optionally show toast
        this.showToast(`Loading data for last ${period} months...`);
    }

    printDashboard() {
        window.print();
    }

    showToast(message) {
        window.dispatchEvent(new CustomEvent('show-toast', {
            detail: {
                type: 'info',
                message: message
            }
        }));
    }
}
