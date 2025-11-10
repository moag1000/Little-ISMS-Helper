import { Controller } from '@hotwired/stimulus';
import * as bootstrap from 'bootstrap';

/**
 * Dashboard Customizer Controller
 *
 * Features:
 * - Widget toggle (show/hide)
 * - Widget drag & drop reordering
 * - LocalStorage persistence
 * - Reset to defaults
 */
export default class extends Controller {
    static targets = ['widget', 'settingsModal', 'toggleButton', 'widgetContainer'];
    static values = {
        storageKey: { type: String, default: 'dashboard_widget_preferences' }
    };

    connect() {
        this.loadPreferences();
        this.applyPreferences();
        this.enableDragAndDrop();
    }

    // Enable drag and drop for widgets
    enableDragAndDrop() {
        this.widgetTargets.forEach((widget, index) => {
            widget.draggable = true;
            widget.dataset.originalIndex = index;

            widget.addEventListener('dragstart', this.handleDragStart.bind(this));
            widget.addEventListener('dragend', this.handleDragEnd.bind(this));
            widget.addEventListener('dragover', this.handleDragOver.bind(this));
            widget.addEventListener('drop', this.handleDrop.bind(this));
            widget.addEventListener('dragenter', this.handleDragEnter.bind(this));
            widget.addEventListener('dragleave', this.handleDragLeave.bind(this));

            // Add drag handle styling
            widget.style.cursor = 'move';
        });
    }

    handleDragStart(event) {
        this.draggedWidget = event.currentTarget;
        event.currentTarget.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/html', event.currentTarget.innerHTML);
    }

    handleDragEnd(event) {
        event.currentTarget.classList.remove('dragging');

        // Remove all drag-over classes
        this.widgetTargets.forEach(widget => {
            widget.classList.remove('drag-over');
        });
    }

    handleDragOver(event) {
        if (event.preventDefault) {
            event.preventDefault();
        }
        event.dataTransfer.dropEffect = 'move';
        return false;
    }

    handleDragEnter(event) {
        if (event.currentTarget !== this.draggedWidget) {
            event.currentTarget.classList.add('drag-over');
        }
    }

    handleDragLeave(event) {
        event.currentTarget.classList.remove('drag-over');
    }

    handleDrop(event) {
        if (event.stopPropagation) {
            event.stopPropagation();
        }

        const dropTarget = event.currentTarget;

        if (this.draggedWidget !== dropTarget) {
            // Get parent container
            const container = this.draggedWidget.parentNode;

            // Get positions
            const draggedIndex = Array.from(container.children).indexOf(this.draggedWidget);
            const dropIndex = Array.from(container.children).indexOf(dropTarget);

            // Reorder in DOM
            if (draggedIndex < dropIndex) {
                dropTarget.parentNode.insertBefore(this.draggedWidget, dropTarget.nextSibling);
            } else {
                dropTarget.parentNode.insertBefore(this.draggedWidget, dropTarget);
            }

            // Save new order
            this.saveWidgetOrder();
        }

        return false;
    }

    saveWidgetOrder() {
        const order = [];
        this.widgetTargets.forEach(widget => {
            const widgetId = widget.dataset.widgetId;
            if (widgetId) {
                order.push(widgetId);
            }
        });

        // Save order to preferences
        this.preferences._widgetOrder = order;
        this.savePreferences();
    }

    applyWidgetOrder() {
        if (!this.preferences._widgetOrder) return;

        const container = this.widgetTargets[0]?.parentNode;
        if (!container) return;

        // Reorder widgets based on saved order
        this.preferences._widgetOrder.forEach(widgetId => {
            const widget = this.widgetTargets.find(w => w.dataset.widgetId === widgetId);
            if (widget) {
                container.appendChild(widget);
            }
        });
    }

    // Load preferences from LocalStorage
    loadPreferences() {
        try {
            const stored = localStorage.getItem(this.storageKeyValue);
            this.preferences = stored ? JSON.parse(stored) : this.getDefaultPreferences();
        } catch (error) {
            console.error('Failed to load dashboard preferences:', error);
            this.preferences = this.getDefaultPreferences();
        }
    }

    // Get default preferences (all widgets visible)
    getDefaultPreferences() {
        const defaultPrefs = {};
        this.widgetTargets.forEach(widget => {
            const widgetId = widget.dataset.widgetId;
            if (widgetId) {
                defaultPrefs[widgetId] = { visible: true };
            }
        });
        return defaultPrefs;
    }

    // Apply preferences to widgets
    applyPreferences() {
        this.widgetTargets.forEach(widget => {
            const widgetId = widget.dataset.widgetId;
            if (widgetId && this.preferences[widgetId]) {
                const isVisible = this.preferences[widgetId].visible;

                if (isVisible) {
                    widget.classList.remove('d-none');
                } else {
                    widget.classList.add('d-none');
                }

                // Update toggle button state in settings modal
                const toggleBtn = this.element.querySelector(`[data-widget-toggle="${widgetId}"]`);
                if (toggleBtn) {
                    const checkbox = toggleBtn.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = isVisible;
                    }
                }
            }
        });

        // Apply saved widget order
        this.applyWidgetOrder();
    }

    // Save preferences to LocalStorage
    savePreferences() {
        try {
            localStorage.setItem(this.storageKeyValue, JSON.stringify(this.preferences));
        } catch (error) {
            console.error('Failed to save dashboard preferences:', error);
        }
    }

    // Toggle widget visibility
    toggleWidget(event) {
        const widgetId = event.currentTarget.dataset.widgetToggle;
        const checkbox = event.currentTarget.querySelector('input[type="checkbox"]');

        if (!widgetId) return;

        const isVisible = checkbox.checked;

        // Update preferences
        if (!this.preferences[widgetId]) {
            this.preferences[widgetId] = {};
        }
        this.preferences[widgetId].visible = isVisible;

        // Save immediately
        this.savePreferences();

        // Apply to widget
        this.applyPreferences();
    }

    // Open settings modal
    openSettings() {
        if (this.hasSettingsModalTarget) {
            const modal = new bootstrap.Modal(this.settingsModalTarget);
            modal.show();
        }
    }

    // Reset to defaults
    resetToDefaults() {
        if (confirm('Möchten Sie das Dashboard auf die Standardeinstellungen zurücksetzen?')) {
            this.preferences = this.getDefaultPreferences();
            this.savePreferences();
            this.applyPreferences();

            // Update all checkboxes in settings modal
            const checkboxes = this.element.querySelectorAll('[data-widget-toggle] input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });

            // Reload page to reset widget order
            location.reload();
        }
    }

    // Export preferences
    exportPreferences() {
        const dataStr = JSON.stringify(this.preferences, null, 2);
        const dataUri = 'data:application/json;charset=utf-8,' + encodeURIComponent(dataStr);

        const exportFileDefaultName = 'dashboard_preferences.json';

        const linkElement = document.createElement('a');
        linkElement.setAttribute('href', dataUri);
        linkElement.setAttribute('download', exportFileDefaultName);
        linkElement.click();
    }

    // Import preferences
    importPreferences(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                const imported = JSON.parse(e.target.result);
                this.preferences = imported;
                this.savePreferences();
                this.applyPreferences();

                alert(window.translations?.dashboard?.settings_imported || 'Dashboard settings successfully imported!');
            } catch (error) {
                console.error('Failed to import preferences:', error);
                alert(window.translations?.dashboard?.import_failed || 'Error importing settings. Please check the file.');
            }
        };
        reader.readAsText(file);
    }
}
