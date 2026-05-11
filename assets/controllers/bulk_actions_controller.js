import { Controller } from '@hotwired/stimulus';

/**
 * Bulk Actions Controller — canonical Aurora bulk-action-bar (per
 * docs/design_system/sections/feedback-systems.html §175-255).
 *
 * Usage (canonical Aurora v4 BEM — see docs/design_system/sections/generics-extra.html#bulk-action-bar):
 *   <div data-controller="bulk-actions" data-bulk-actions-endpoint-value="/asset">
 *     <table class="table-bulk-selectable">
 *       <thead><tr>
 *         <th class="bulk-select-column">
 *           <input type="checkbox" data-action="bulk-actions#selectAll"
 *                  data-bulk-actions-target="selectAllCheckbox">
 *         </th>...
 *       </tr></thead>
 *       <tbody><tr>
 *         <td class="bulk-select-column">
 *           <input type="checkbox" data-bulk-actions-target="item" value="1"
 *                  data-action="bulk-actions#selectItem">
 *         </td>...
 *       </tr></tbody>
 *     </table>
 *
 *     <div class="fa-bulk-bar" hidden role="region" aria-live="polite"
 *          data-bulk-actions-target="actionBar">
 *       <div class="fa-bulk-bar__count">
 *         <span class="fa-bulk-bar__count-num" data-bulk-actions-target="count">0</span>
 *         <span class="fa-bulk-bar__count-label">ausgewählt</span>
 *       </div>
 *       <div class="fa-bulk-bar__divider"></div>
 *       <div class="fa-bulk-bar__actions">
 *         <button class="fa-bulk-btn">…</button>
 *         <button class="fa-bulk-btn fa-bulk-btn--success">Approven</button>
 *         <button class="fa-bulk-btn fa-bulk-btn--danger"
 *                 data-action="click->bulk-actions#bulkDelete">Löschen</button>
 *       </div>
 *       <div class="fa-bulk-bar__divider"></div>
 *       <button class="fa-bulk-bar__close" aria-label="Auswahl aufheben"
 *               data-action="click->bulk-actions#deselectAll"><i class="bi bi-x-lg"></i></button>
 *     </div>
 *   </div>
 *
 *   Brand variant (hero lists: risk, document): add class `fa-bulk-bar--brand`.
 *   Loading state: add class `is-loading` to the active button.
 *
 * Targets:
 *   item                <input type="checkbox"> per row
 *   actionBar / bar     `.fa-bulk-bar` element to show/hide via [hidden] attribute
 *   count               `.fa-bulk-bar__count-num` — shows selected count
 *   selectAllCheckbox   header `<thead>` master checkbox
 *
 * Public API:
 *   ctrl.selectedIds()       → ['1','7','42']  (canonical name)
 *   ctrl.getSelectedIds()    → alias of selectedIds() for legacy callers
 *   ctrl.getSelectedItems()  → checked input nodes
 */
export default class extends Controller {
    static targets = ['item', 'actionBar', 'bar', 'count', 'selectAllCheckbox'];
    static values = {
        endpoint: String,
        // Translation strings
        entityLabel: { type: String, default: 'items' },
        deleteSuccess: { type: String, default: '%count% %entity% successfully deleted' },
        deleteError: { type: String, default: 'Error deleting: ' },
        exportSuccess: { type: String, default: 'Export successful' },
        exportError: { type: String, default: 'Error exporting' },
        tagPrompt: { type: String, default: 'Add tag:' },
        tagSuccess: { type: String, default: 'Tag added' },
        tagError: { type: String, default: 'Error adding tag' },
        statusChangeSuccess: { type: String, default: '%count% documents updated' },
        statusChangePartial: { type: String, default: '%changed% updated, %rejected% rejected' },
        statusChangeError: { type: String, default: 'Update failed' }
    };

    connect() {
        this.updateActionBar();
    }

    selectAll(event) {
        const checked = event.target.checked;
        this.itemTargets.forEach(item => {
            item.checked = checked;
        });
        this.updateActionBar();
    }

    selectItem(event) {
        this.updateActionBar();

        // Update "select all" checkbox state
        if (this.hasSelectAllCheckboxTarget) {
            const allChecked = this.itemTargets.every(item => item.checked);
            const someChecked = this.itemTargets.some(item => item.checked);

            this.selectAllCheckboxTarget.checked = allChecked;
            this.selectAllCheckboxTarget.indeterminate = someChecked && !allChecked;
        }
    }

    updateActionBar() {
        const selectedCount = this.getSelectedItems().length;

        if (selectedCount > 0) {
            this.showActionBar(selectedCount);
        } else {
            this.hideActionBar();
        }
    }

    /**
     * Resolve the bar element — supports canonical `bar` target alias and
     * legacy `actionBar` target. Returns null if neither is wired.
     */
    _barElement() {
        if (this.hasBarTarget) return this.barTarget;
        if (this.hasActionBarTarget) return this.actionBarTarget;
        return null;
    }

    showActionBar(count) {
        const bar = this._barElement();
        if (bar) {
            bar.hidden = false;

            // Update count
            if (this.hasCountTarget) {
                this.countTarget.textContent = count;
            }
        }
    }

    hideActionBar() {
        const bar = this._barElement();
        if (bar) {
            bar.hidden = true;
        }
    }

    getSelectedItems() {
        return this.itemTargets.filter(item => item.checked);
    }

    /**
     * Canonical public API — returns array of values for checked items.
     * Spec: feedback-systems.html §"Public method: selectedIds()".
     */
    selectedIds() {
        return this.getSelectedItems().map(item => item.value);
    }

    /** Alias for legacy callers — prefer selectedIds(). */
    getSelectedIds() {
        return this.selectedIds();
    }

    /** Canonical recount/visibility update — alias of updateActionBar(). */
    update() {
        this.updateActionBar();
    }

    async bulkDelete(event) {
        event.preventDefault();

        const ids = this.selectedIds();

        if (ids.length === 0) {
            return;
        }

        // Get entity label from value
        const entityLabel = this.entityLabelValue;

        // Get confirmation modal controller
        const confirmModal = document.getElementById('bulkDeleteModal');
        if (!confirmModal) {
            // Fallback to simple confirm if modal not available
            const confirmText = window.translations?.bulk_delete?.confirm_count || 'Do you really want to delete %count% %entity%?';
            const confirmed = await window.faConfirm(confirmText.replace('%count%', ids.length).replace('%entity%', entityLabel), { tone: 'danger' });
            if (!confirmed) {
                return;
            }
        } else {
            const confirmController = this.application.getControllerForElementAndIdentifier(
                confirmModal,
                'bulk-delete-confirmation'
            );

            // Show confirmation dialog with dependency check
            const confirmed = await confirmController.show({
                count: ids.length,
                entityLabel: entityLabel,
                endpoint: this.endpointValue + '/bulk-delete-check',
                ids: ids
            });

            if (!confirmed) {
                return;
            }
        }

        try {
            const response = await fetch(this.endpointValue + '/bulk-delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ ids })
            });

            if (!response.ok) {
                throw new Error('Bulk delete failed');
            }

            // Show success toast
            const successMsg = this.deleteSuccessValue
                .replace('%count%', ids.length)
                .replace('%entity%', entityLabel);
            this.showToast(successMsg, 'success');

            // Remove rows from DOM with fairy magic animation
            this.getSelectedItems().forEach(item => {
                const row = item.closest('tr');
                if (row) {
                    // Add fairy completion flash before fading out
                    row.classList.add('fairy-bulk-complete');
                    setTimeout(() => {
                        row.style.opacity = '0';
                        row.style.transition = 'opacity 0.3s ease-out';
                        setTimeout(() => row.remove(), 300);
                    }, 400);
                }
            });

            // Reset action bar
            this.hideActionBar();

            // Optionally reload page if no rows left
            const remainingRows = this.element.querySelectorAll('tbody tr').length - ids.length;
            if (remainingRows === 0) {
                setTimeout(() => window.location.reload(), 500);
            }

        } catch (error) {
            this.showToast(this.deleteErrorValue + error.message, 'error');
        }
    }

    async bulkExport(event) {
        event.preventDefault();

        const ids = this.selectedIds();

        if (ids.length === 0) {
            return;
        }

        try {
            const response = await fetch(this.endpointValue + '/bulk-export', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ ids })
            });

            if (!response.ok) {
                throw new Error('Bulk export failed');
            }

            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `export-${Date.now()}.xlsx`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            a.remove();

            this.showToast(this.exportSuccessValue, 'success');

        } catch (error) {
            this.showToast(this.exportErrorValue, 'error');
        }
    }

    async bulkTag(event) {
        event.preventDefault();

        const ids = this.selectedIds();

        if (ids.length === 0) {
            return;
        }

        const tag = prompt(this.tagPromptValue);
        if (!tag) {
            return;
        }

        try {
            const response = await fetch(this.endpointValue + '/bulk-tag', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ ids, tag })
            });

            if (!response.ok) {
                throw new Error('Bulk tag failed');
            }

            this.showToast(this.tagSuccessValue, 'success');

            // Optionally reload the page
            setTimeout(() => window.location.reload(), 1000);

        } catch (error) {
            this.showToast(this.tagErrorValue, 'error');
        }
    }

    /**
     * Bulk status-change action.
     * Button must carry: data-target-status="approved" (or another valid status).
     * Wired via: data-action="click->bulk-actions#bulkStatusChange"
     */
    async bulkStatusChange(event) {
        event.preventDefault();

        const ids = this.selectedIds();
        if (ids.length === 0) {
            return;
        }

        const newStatus = event.currentTarget.dataset.targetStatus;
        if (!newStatus) {
            this.showToast(this.statusChangeErrorValue, 'error');
            return;
        }

        try {
            const response = await fetch(this.endpointValue + '/bulk-status-change', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ ids, newStatus })
            });

            const payload = await response.json();

            if (!response.ok && !payload.ok) {
                throw new Error(payload.error || this.statusChangeErrorValue);
            }

            const changed = payload.changed ?? 0;
            const rejected = payload.rejected ?? [];

            if (rejected.length > 0) {
                // Partial success — surface non-blocking notice
                const msg = this.statusChangePartialValue
                    .replace('%changed%', changed)
                    .replace('%rejected%', rejected.length);
                this.showToast(msg, 'warning');
            } else {
                const msg = this.statusChangeSuccessValue.replace('%count%', changed);
                this.showToast(msg, 'success');
            }

            // Reload page so status pills reflect the new state
            setTimeout(() => window.location.reload(), 800);

        } catch (error) {
            this.showToast(this.statusChangeErrorValue + ': ' + error.message, 'error');
        }
    }

    deselectAll() {
        this.itemTargets.forEach(item => {
            item.checked = false;
        });

        if (this.hasSelectAllCheckboxTarget) {
            this.selectAllCheckboxTarget.checked = false;
            this.selectAllCheckboxTarget.indeterminate = false;
        }

        this.updateActionBar();
    }

    showToast(message, type) {
        // Dispatch custom event for toast
        document.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message, type }
        }));
    }
}
