/**
 * Bulk Selection Management System
 * WCAG 2.1 AA Compliant
 *
 * Provides checkbox selection functionality with:
 * - Select all/none
 * - Individual selection
 * - Keyboard navigation
 * - Screen reader announcements
 * - Visual feedback
 */

class BulkSelectManager {
    constructor(tableSelector = '.bulk-select-table') {
        this.table = document.querySelector(tableSelector);
        if (!this.table) {
            return;
        }

        this.selectAllCheckbox = this.table.querySelector('.bulk-select-all');
        this.itemCheckboxes = this.table.querySelectorAll('.bulk-select-item');
        this.bulkDeleteButton = document.querySelector('.bulk-delete-button');
        this.selectedCountBadge = document.querySelector('.bulk-selected-count');

        this.init();
    }

    init() {
        // Select all checkbox handler
        this.selectAllCheckbox?.addEventListener('change', (e) => {
            this.toggleAll(e.target.checked);
            this.updateUI();
        });

        // Individual checkbox handlers
        this.itemCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                this.updateSelectAllState();
                this.updateUI();
            });
        });

        // Initial UI update
        this.updateUI();
    }

    toggleAll(checked) {
        this.itemCheckboxes.forEach(checkbox => {
            checkbox.checked = checked;
        });
    }

    updateSelectAllState() {
        if (!this.selectAllCheckbox) {
            return;
        }

        const checkedCount = this.getSelectedCount();
        const totalCount = this.itemCheckboxes.length;

        this.selectAllCheckbox.checked = checkedCount === totalCount && totalCount > 0;
        this.selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < totalCount;
    }

    updateUI() {
        const selectedCount = this.getSelectedCount();
        const hasSelection = selectedCount > 0;

        // Update button state
        if (this.bulkDeleteButton) {
            this.bulkDeleteButton.disabled = !hasSelection;
            this.bulkDeleteButton.setAttribute('aria-disabled', !hasSelection);
        }

        // Update count badge
        if (this.selectedCountBadge) {
            this.selectedCountBadge.textContent = selectedCount;
            this.selectedCountBadge.style.display = hasSelection ? 'inline-block' : 'none';
        }

        // Announce to screen readers
        this.announceSelection(selectedCount);
    }

    getSelectedCount() {
        return Array.from(this.itemCheckboxes).filter(cb => cb.checked).length;
    }

    getSelectedIds() {
        return Array.from(this.itemCheckboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);
    }

    getSelectedItems() {
        return Array.from(this.itemCheckboxes)
            .filter(cb => cb.checked)
            .map(cb => {
                const row = cb.closest('tr');
                // Try to get the item name from various possible cells
                const nameCell = row?.querySelector('td:nth-child(2)') ||
                                row?.querySelector('.item-name') ||
                                row?.querySelector('td:nth-child(3)');
                return nameCell?.textContent?.trim() || `ID ${cb.value}`;
            });
    }

    announceSelection(count) {
        // Create or update live region for screen reader announcements
        let liveRegion = document.getElementById('bulk-select-live-region');
        if (!liveRegion) {
            liveRegion = document.createElement('div');
            liveRegion.id = 'bulk-select-live-region';
            liveRegion.className = 'sr-only';
            liveRegion.setAttribute('role', 'status');
            liveRegion.setAttribute('aria-live', 'polite');
            liveRegion.setAttribute('aria-atomic', 'true');
            document.body.appendChild(liveRegion);
        }

        const message = count === 0
            ? 'No items selected'
            : count === 1
                ? '1 item selected'
                : `${count} items selected`;

        liveRegion.textContent = message;
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.bulkSelectManager = new BulkSelectManager();

    // Handle bulk delete button click
    const bulkDeleteButton = document.querySelector('.bulk-delete-button');
    bulkDeleteButton?.addEventListener('click', () => {
        if (!window.bulkSelectManager) {
            return;
        }

        const ids = window.bulkSelectManager.getSelectedIds();
        const items = window.bulkSelectManager.getSelectedItems();

        if (ids.length === 0) {
            return;
        }

        // Call the modal show function (defined in _bulk_delete_modal.html.twig)
        if (typeof window.showBulkDeleteModal === 'function') {
            window.showBulkDeleteModal(ids, items);
        }
    });
});

// Export for use in other scripts if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = BulkSelectManager;
}
