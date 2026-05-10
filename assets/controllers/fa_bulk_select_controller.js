import { Controller } from '@hotwired/stimulus';

/**
 * fa-bulk-select — Aurora Multi-Select for fa-table + fa-bulk-action-bar
 * (V3 W3-UX-Bulk)
 *
 * Lightweight selection-tracker: shows the bulk-action-bar when ≥1 row is
 * selected, updates the count, exposes selected ids via `selectedIds()`.
 *
 * It does NOT implement the actual bulk operations (delete/export/...) —
 * those are app-specific and live in the legacy `bulk-actions` controller
 * or a new app-specific controller. This controller only manages the
 * selection-state + UI-bar visibility.
 *
 * Usage:
 *   <div class="fa-table-wrap" data-controller="fa-bulk-select">
 *     <table class="fa-table">
 *       <thead>
 *         <tr>
 *           <th>
 *             <input type="checkbox" data-action="change->fa-bulk-select#selectAll">
 *           </th>
 *           ...
 *         </tr>
 *       </thead>
 *       <tbody>
 *         <tr class="fa-table__row" data-fa-bulk-select-target="row">
 *           <td><input type="checkbox" value="42"
 *                      data-fa-bulk-select-target="item"
 *                      data-action="change->fa-bulk-select#updateBar"></td>
 *           ...
 *         </tr>
 *       </tbody>
 *     </table>
 *     {{ _fa_bulk_bar.render({ actions: [...] }) }}
 *   </div>
 *
 * Targets:
 *   item       <input type="checkbox"> per row
 *   row        <tr> per data-row (gets `.fa-table__row--selected`)
 *   actionBar  the .fa-bulk-action-bar element to show/hide
 *   count      <span> showing the selected count
 *   selectAll  the master <input type="checkbox"> in <thead>
 *
 * Public API:
 *   ctrl.selectedIds()        → ['1','7','42']
 *   ctrl.selectedCount        → number
 */
export default class extends Controller {
    static targets = ['item', 'row', 'actionBar', 'count', 'selectAll'];

    connect() {
        this._update();
    }

    selectAll(event) {
        const checked = event.target.checked;
        this.itemTargets.forEach(item => { item.checked = checked; });
        this._update();
    }

    updateBar() {
        this._update();
    }

    deselectAll() {
        this.itemTargets.forEach(i => { i.checked = false; });
        if (this.hasSelectAllTarget) {
            this.selectAllTarget.checked = false;
            this.selectAllTarget.indeterminate = false;
        }
        this._update();
    }

    _update() {
        const selected = this._selectedItems();
        const n = selected.length;

        // Row visual selection
        this.rowTargets.forEach(r => {
            const cb = r.querySelector('input[type="checkbox"][data-fa-bulk-select-target="item"]');
            r.classList.toggle('fa-table__row--selected', !!(cb && cb.checked));
        });

        // Action-bar visibility
        if (this.hasActionBarTarget) {
            if (n > 0) {
                this.actionBarTarget.hidden = false;
            } else {
                this.actionBarTarget.hidden = true;
            }
        }

        // Count
        if (this.hasCountTarget) {
            this.countTarget.textContent = String(n);
        }

        // Master-checkbox indeterminate state
        if (this.hasSelectAllTarget) {
            const total = this.itemTargets.length;
            this.selectAllTarget.checked       = total > 0 && n === total;
            this.selectAllTarget.indeterminate = n > 0 && n < total;
        }
    }

    _selectedItems() {
        return this.itemTargets.filter(i => i.checked);
    }

    /* ----------------------------------------------------------------- Public API */
    selectedIds() {
        return this._selectedItems().map(i => i.value);
    }

    get selectedCount() {
        return this._selectedItems().length;
    }
}
