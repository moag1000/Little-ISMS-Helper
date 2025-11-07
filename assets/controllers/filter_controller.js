import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['searchInput', 'filterableRow', 'noResults'];
    static values = {
        filterableColumns: Array
    };

    connect() {
        this.filterRows();
    }

    filterRows() {
        const searchTerm = this.searchInputTarget.value.toLowerCase();
        let visibleCount = 0;

        this.filterableRowTargets.forEach(row => {
            const text = row.textContent.toLowerCase();
            const matches = text.includes(searchTerm);

            row.style.display = matches ? '' : 'none';
            if (matches) visibleCount++;
        });

        // Show/hide "no results" message
        if (this.hasNoResultsTarget) {
            this.noResultsTarget.style.display = visibleCount === 0 ? '' : 'none';
        }
    }

    filterByCategory(event) {
        const category = event.target.value;

        this.filterableRowTargets.forEach(row => {
            if (category === '' || row.dataset.category === category) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    filterByStatus(event) {
        const status = event.target.value;

        this.filterableRowTargets.forEach(row => {
            if (status === '' || row.dataset.status === status) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    reset() {
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
        }
        this.filterableRowTargets.forEach(row => {
            row.style.display = '';
        });
        if (this.hasNoResultsTarget) {
            this.noResultsTarget.style.display = 'none';
        }
    }
}
