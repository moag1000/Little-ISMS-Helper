import { Controller } from '@hotwired/stimulus';

/**
 * fa-table-sort — Client-side column sorting for fa-table (V3 W3-UX-Table)
 *
 * Pairs with the `fa-table` Aurora-table macro. Click (or Enter/Space) on a
 * `.fa-table__th--sortable` header sorts the rows in <tbody> by the column's
 * value. Tri-state cycle: none → asc → desc → none.
 *
 * Usage:
 *   <div class="fa-table-wrap" data-controller="fa-table-sort">
 *     <table class="fa-table">
 *       <thead>
 *         <tr>
 *           <th class="fa-table__th fa-table__th--sortable"
 *               data-sort-key="name"
 *               data-action="click->fa-table-sort#sort keydown.enter->fa-table-sort#sort keydown.space->fa-table-sort#sort"
 *               tabindex="0" role="columnheader" aria-sort="none">
 *             Name
 *           </th>
 *         </tr>
 *       </thead>
 *       <tbody>
 *         <tr><td data-sort-value="alpha">…</td></tr>
 *       </tbody>
 *     </table>
 *   </div>
 *
 * Cell-level sort value:
 *   - <td data-sort-value="…"> overrides text content (use for dates, numbers).
 *   - Otherwise textContent.trim() is used.
 *
 * Numeric detection: if all values parse as numbers, numeric compare is used.
 *
 * A11y: aria-sort attribute is updated on the active header
 * ("none"|"ascending"|"descending").
 */
export default class extends Controller {
    sort(event) {
        const th = event.currentTarget;
        if (!th) return;
        if (event.type === 'keydown' && event.key === ' ') event.preventDefault();

        const table = this.element.querySelector('table');
        if (!table) return;
        const headerRow = th.parentElement;
        const headerCells = Array.from(headerRow.children);
        const colIndex = headerCells.indexOf(th);
        if (colIndex < 0) return;

        // Determine new direction
        const currentDir =
            th.classList.contains('fa-table__th--sort-asc')  ? 'asc'  :
            th.classList.contains('fa-table__th--sort-desc') ? 'desc' : 'none';
        const nextDir =
            currentDir === 'none' ? 'asc'  :
            currentDir === 'asc'  ? 'desc' : 'none';

        // Reset all header cells
        headerCells.forEach(c => {
            c.classList.remove('fa-table__th--sort-asc', 'fa-table__th--sort-desc');
            if (c.hasAttribute('aria-sort')) c.setAttribute('aria-sort', 'none');
        });

        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll(':scope > tr'));

        if (nextDir === 'none') {
            // Restore original order if we cached it; otherwise leave as-is.
            if (this._origOrder && this._origOrder.length === rows.length) {
                this._origOrder.forEach(r => tbody.appendChild(r));
            }
            return;
        }

        // Cache original order on first sort.
        if (!this._origOrder || this._origOrder.length !== rows.length) {
            this._origOrder = rows.slice();
        }

        // Extract values
        const values = rows.map(r => {
            const cell = r.children[colIndex];
            if (!cell) return '';
            return cell.dataset.sortValue ?? cell.textContent.trim();
        });
        const isNumeric = values.every(v => v === '' || !Number.isNaN(parseFloat(v)));

        const dir = nextDir === 'asc' ? 1 : -1;
        const sorted = rows.map((r, i) => ({ r, v: values[i] }))
            .sort((a, b) => {
                let cmp;
                if (isNumeric) {
                    cmp = (parseFloat(a.v) || 0) - (parseFloat(b.v) || 0);
                } else {
                    cmp = a.v.localeCompare(b.v, undefined, { numeric: true, sensitivity: 'base' });
                }
                return cmp * dir;
            });

        // Re-append in sorted order (preserves event listeners).
        sorted.forEach(({ r }) => tbody.appendChild(r));

        // Mark active header
        th.classList.add(nextDir === 'asc' ? 'fa-table__th--sort-asc' : 'fa-table__th--sort-desc');
        th.setAttribute('aria-sort', nextDir === 'asc' ? 'ascending' : 'descending');
    }
}
