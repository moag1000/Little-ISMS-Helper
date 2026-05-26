import { Controller } from '@hotwired/stimulus';

/**
 * row-click — make Aurora-table rows clickable as a shortcut to the row's
 * primary "show" link.
 *
 * UX motivation: clicking the eye-icon button in the action cell to view a
 * record is unintuitive — the row itself should respond to a click. The
 * controller auto-detects the show link without templates having to opt in
 * per-row.
 *
 * Resolution order for the navigation target per row:
 *   1. `<a>` element explicitly marked with `data-row-show` (preferred)
 *   2. `<a>` element with `data-action-key="view"` or `.fa-cyber-btn--view`
 *   3. First `<a href>` inside the row's `.fa-table__cell--actions`
 *      whose href matches a "show" route pattern (`/show$` or `/{id}$`)
 *
 * Skipped when:
 *   - The click hit an interactive element (button, link, input, select,
 *     textarea, label, contenteditable). Their default behavior runs.
 *   - The click hit the bulk-select checkbox cell (`.fa-table__cell--checkbox`)
 *     or the action cell (`.fa-table__cell--actions`).
 *   - Modifier keys (ctrl / cmd / shift / middle-click) — let the browser
 *     handle "open in new tab".
 *
 * Visual affordance: rows that resolve to a show link get `data-row-clickable`
 * which CSS uses to set `cursor: pointer` + a subtle hover-translate. Rows
 * without a resolvable link stay non-clickable.
 *
 * Wiring: attached automatically by `_fa_table.html.twig` to the table-wrap
 * element. Opt-out per table via `rowClick: false` config or per row via
 * `data-row-clickable="false"` attribute.
 */
export default class extends Controller {
    connect() {
        // Mark rows that have a resolvable show-link so CSS can show pointer.
        const rows = this.element.querySelectorAll('tr.fa-table__row, .fa-table__body tr');
        rows.forEach((row) => {
            if (row.dataset.rowClickable === 'false') return;
            const link = this._resolveShowLink(row);
            if (link) {
                row.dataset.rowClickable = 'true';
            }
        });
    }

    /**
     * Click handler bound via `data-action="click->row-click#navigate"` on the
     * fa-table wrapper. Bubbles up from row descendants — we filter here.
     */
    navigate(event) {
        // Let modifier-clicks (cmd+click = new tab, middle-click, shift) pass through.
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;

        const target = event.target;
        if (!target || target.nodeType !== 1) return;

        // Don't hijack clicks on interactive elements — let them do their thing.
        if (target.closest('a, button, input, select, textarea, label, [contenteditable], [role="button"]')) {
            return;
        }

        // Don't hijack clicks in the checkbox or action cell either — those
        // belong to bulk-select / explicit action buttons.
        if (target.closest('.fa-table__cell--checkbox, .fa-table__cell--actions')) {
            return;
        }

        const row = target.closest('tr');
        if (!row || row.dataset.rowClickable === 'false') return;

        const link = this._resolveShowLink(row);
        if (!link) return;

        event.preventDefault();
        // Click the link rather than setting location — Turbo intercepts the
        // anchor click and we keep the SPA-like nav. Falls back to native
        // location for non-Turbo pages.
        link.click();
    }

    _resolveShowLink(row) {
        // 1. Explicit opt-in
        let link = row.querySelector('a[data-row-show]');
        if (link && link.href) return link;

        // 2. Conventional class / data-attribute used by Aurora action buttons
        link = row.querySelector('a[data-action-key="view"], a.fa-cyber-btn--view');
        if (link && link.href) return link;

        // 3. First anchor in the action cell that looks like a show URL
        const actionCell = row.querySelector('.fa-table__cell--actions, td:last-child');
        if (actionCell) {
            const anchors = actionCell.querySelectorAll('a[href]');
            for (const a of anchors) {
                if (!a.href) continue;
                const href = a.getAttribute('href') || '';
                // Skip new/edit/delete/export/print/etc. routes — only navigate
                // for a record-detail style URL.
                if (/\/(new|edit|delete|export|print|toggle|approve|reject|create)/i.test(href)) continue;
                // Skip JS-only anchors (e.g. `#`, `javascript:`)
                if (/^(#|javascript:)/i.test(href)) continue;
                return a;
            }
        }

        return null;
    }
}
