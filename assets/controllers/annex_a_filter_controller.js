import { Controller } from '@hotwired/stimulus';

/**
 * Junior-Helps client-side filter + counter for the ISO 27001:2022
 * Annex-A applicability table on Step 4 (Risk-Classification).
 *
 * Targets:
 *  - search    : free-text input (control-ref or keyword)
 *  - theme     : <select> with values "" | a5 | a6 | a7 | a8
 *  - table     : one per Annex theme (data-theme="a5" …)
 *  - row       : per-control <tr> with data-search-blob lower-cased
 *  - checkbox  : per-control applicability checkbox
 *  - counter   : <span> with the live "selected" number
 *  - empty     : hidden hint when no row matches
 *
 * Values:
 *  - total : total number of controls (93)
 */
export default class extends Controller {
    static targets = ['search', 'theme', 'table', 'row', 'checkbox', 'counter', 'empty'];
    static values = { total: Number };

    connect() {
        this.updateCounter();
    }

    filter() {
        const query = ((this.hasSearchTarget && this.searchTarget.value) || '')
            .trim()
            .toLowerCase();
        const theme = (this.hasThemeTarget && this.themeTarget.value) || '';

        let visibleRows = 0;

        this.tableTargets.forEach((table) => {
            const tableTheme = table.dataset.theme || '';
            const themeMatches = theme === '' || theme === tableTheme;
            let visibleInTable = 0;

            table.querySelectorAll('[data-annex-a-filter-target="row"]').forEach((row) => {
                const blob = row.dataset.searchBlob || '';
                const queryMatches = query === '' || blob.includes(query);
                const isVisible = themeMatches && queryMatches;
                row.hidden = !isVisible;
                if (isVisible) {
                    visibleInTable += 1;
                }
            });

            // Hide entire group section when no row in it matches.
            const section = table.closest('section, .fa-section, .card');
            if (section) {
                section.hidden = visibleInTable === 0;
            }
            visibleRows += visibleInTable;
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.toggle('d-none', visibleRows !== 0);
        }
    }

    updateCounter() {
        if (!this.hasCounterTarget) {
            return;
        }
        const selected = this.checkboxTargets.filter((cb) => cb.checked).length;
        this.counterTarget.textContent = String(selected);
    }

    clear() {
        if (this.hasSearchTarget) {
            this.searchTarget.value = '';
        }
        if (this.hasThemeTarget) {
            this.themeTarget.value = '';
        }
        this.filter();
    }
}
