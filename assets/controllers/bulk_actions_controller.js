import { Controller } from '@hotwired/stimulus';

/**
 * Bulk Actions Controller
 * Enables bulk operations on table rows
 *
 * Usage:
 * <div data-controller="bulk-actions">
 *   <table>
 *     <thead>
 *       <tr>
 *         <th><input type="checkbox" data-action="bulk-actions#selectAll"></th>
 *       </tr>
 *     </thead>
 *     <tbody>
 *       <tr>
 *         <td><input type="checkbox" data-bulk-actions-target="item" value="1"></td>
 *       </tr>
 *     </tbody>
 *   </table>
 *   <div data-bulk-actions-target="actionBar" hidden>...</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['item', 'actionBar', 'count', 'selectAllCheckbox'];
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
        tagError: { type: String, default: 'Error adding tag' }
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

    showActionBar(count) {
        if (this.hasActionBarTarget) {
            this.actionBarTarget.hidden = false;

            // Animate in
            requestAnimationFrame(() => {
                this.actionBarTarget.style.transform = 'translateY(0)';
                this.actionBarTarget.style.opacity = '1';
            });

            // Update count
            if (this.hasCountTarget) {
                this.countTarget.textContent = count;
            }
        }
    }

    hideActionBar() {
        if (this.hasActionBarTarget) {
            this.actionBarTarget.style.transform = 'translateY(20px)';
            this.actionBarTarget.style.opacity = '0';

            setTimeout(() => {
                this.actionBarTarget.hidden = true;
            }, 200);
        }
    }

    getSelectedItems() {
        return this.itemTargets.filter(item => item.checked);
    }

    getSelectedIds() {
        return this.getSelectedItems().map(item => item.value);
    }

    async bulkDelete(event) {
        event.preventDefault();

        const ids = this.getSelectedIds();

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
            const confirmed = confirm(confirmText.replace('%count%', ids.length).replace('%entity%', entityLabel));
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

            // Remove rows from DOM with animation
            this.getSelectedItems().forEach(item => {
                const row = item.closest('tr');
                if (row) {
                    row.style.opacity = '0';
                    row.style.transition = 'opacity 0.2s ease-out';
                    setTimeout(() => row.remove(), 200);
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

        const ids = this.getSelectedIds();

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

        const ids = this.getSelectedIds();

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
