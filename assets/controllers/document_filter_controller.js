import { Controller } from '@hotwired/stimulus';

/**
 * Document Filter Controller
 *
 * Provides combined category + full-text filtering for the document list table.
 * Both filters apply simultaneously (AND logic): a row must match the selected
 * category AND contain the search string to be visible.
 *
 * Targets:
 *   - categoryFilter  — <select> element for category filtering
 *   - searchInput     — <input type="search"> for name filtering
 *   - row             — each <tr class="document-row"> in the table body
 *   - tableBody       — the <tbody> element (hidden when zero results)
 *   - noResults       — element shown when zero rows match
 */
export default class extends Controller {
    static targets = ['categoryFilter', 'searchInput', 'row', 'tableBody', 'noResults'];

    connect() {
        // No-op: event listeners are wired via data-action in the template.
    }

    filter() {
        const category = this.hasCategoryFilterTarget
            ? this.categoryFilterTarget.value.toLowerCase()
            : '';
        const search = this.hasSearchInputTarget
            ? this.searchInputTarget.value.toLowerCase()
            : '';

        let visibleCount = 0;

        this.rowTargets.forEach(row => {
            const matchesCategory = !category || row.dataset.category === category;
            const matchesSearch = !search || row.dataset.name.includes(search);

            if (matchesCategory && matchesSearch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        if (this.hasTableBodyTarget && this.hasNoResultsTarget) {
            const hasRows = this.rowTargets.length > 0;
            this.tableBodyTarget.style.display = (visibleCount === 0 && hasRows) ? 'none' : '';
            this.noResultsTarget.style.display = (visibleCount === 0 && hasRows) ? 'block' : 'none';
        }
    }
}
