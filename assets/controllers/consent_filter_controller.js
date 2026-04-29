import { Controller } from '@hotwired/stimulus';

/**
 * Combined search + status + verified filter for consent index page.
 * Replaces inline vanilla JS with Stimulus lifecycle management.
 */
export default class extends Controller {
    static targets = ['search', 'statusFilter', 'verifiedFilter', 'row'];

    filter() {
        const query = this.hasSearchTarget ? this.searchTarget.value.toLowerCase() : '';
        const status = this.hasStatusFilterTarget ? this.statusFilterTarget.value : '';
        const verified = this.hasVerifiedFilterTarget ? this.verifiedFilterTarget.value : '';

        this.rowTargets.forEach(row => {
            const text = row.textContent.toLowerCase();
            const matchesSearch = !query || text.includes(query);
            const matchesStatus = !status || row.dataset.consentStatus === status;
            const matchesVerified = !verified || row.dataset.consentVerified === verified;

            row.style.display = (matchesSearch && matchesStatus && matchesVerified) ? '' : 'none';
        });
    }
}
