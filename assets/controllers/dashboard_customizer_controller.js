import { Controller } from '@hotwired/stimulus';

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
    static targets = ['widget', 'settingsModal', 'toggleButton', 'widgetContainer', 'sizeSelector'];
    static values = {
        storageKey: { type: String, default: 'dashboard_widget_preferences' },
        apiUrl: { type: String, default: '' },
        useDatabaseSync: { type: Boolean, default: true }
    };

    connect() {
        this.isSyncing = false;
        this.syncQueue = [];
        this.preferences = {}; // Initialize to avoid undefined errors
        this.loadPreferences();
        this.applyPreferences();
        this.enableDragAndDrop();
    }

    disconnect() {
        // fa-modal shell controller manages its own lifecycle; no Bootstrap cleanup needed
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

    // Load preferences from API or LocalStorage
    async loadPreferences() {
        if (this.useDatabaseSyncValue && this.apiUrlValue) {
            try {
                const response = await fetch(this.apiUrlValue, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                    }
                });

                if (response.ok) {
                    const data = await response.json();
                    this.preferences = data.layout || this.getDefaultPreferences();
                    this.lastSyncedAt = data.updated_at;

                    // Also save to localStorage as backup
                    this.saveToLocalStorage();
                    return;
                }
                // non-ok (404, 401, …) — fall through to localStorage silently
            } catch (error) {
                // Network or parse error — fall through to localStorage
                console.debug('[dashboard-customizer] API unavailable, using localStorage');
            }
        }

        // Fallback to localStorage
        try {
            const stored = localStorage.getItem(this.storageKeyValue);
            this.preferences = stored ? JSON.parse(stored) : this.getDefaultPreferences();
        } catch (error) {
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
        if (!this.preferences) {
            this.preferences = {};
        }

        this.widgetTargets.forEach(widget => {
            const widgetId = widget.dataset.widgetId;
            const widgetConfig = this.preferences.widgets?.[widgetId] || this.preferences[widgetId];

            if (widgetId && widgetConfig) {
                const isVisible = widgetConfig.visible !== undefined ? widgetConfig.visible : true;
                const size = widgetConfig.size || 'default';

                // Apply visibility
                if (isVisible) {
                    widget.classList.remove('d-none');
                } else {
                    widget.classList.add('d-none');
                }

                // Apply size
                widget.classList.remove('widget-size-small', 'widget-size-medium', 'widget-size-large', 'widget-size-full');
                if (size !== 'default') {
                    widget.classList.add(`widget-size-${size}`);
                }

                // Update toggle button state in settings modal
                const toggleBtn = this.element.querySelector(`[data-widget-toggle="${widgetId}"]`);
                if (toggleBtn) {
                    const checkbox = toggleBtn.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = isVisible;
                    }
                }

                // Update size selector in settings modal
                const sizeSelector = this.element.querySelector(`[data-widget-id="${widgetId}"].widget-size-selector`);
                if (sizeSelector) {
                    sizeSelector.value = size;
                }
            }
        });

        // Apply saved widget order
        this.applyWidgetOrder();
    }

    // Save preferences to LocalStorage
    saveToLocalStorage() {
        try {
            localStorage.setItem(this.storageKeyValue, JSON.stringify(this.preferences));
        } catch (error) {
        }
    }

    // Save preferences to API and LocalStorage
    async savePreferences() {
        // Always save to localStorage immediately
        this.saveToLocalStorage();

        if (!this.useDatabaseSyncValue || !this.apiUrlValue) {
            return;
        }

        // Debounced API save to avoid too many requests
        if (this.saveTimeout) {
            clearTimeout(this.saveTimeout);
        }

        this.saveTimeout = setTimeout(async () => {
            try {
                const response = await fetch(this.apiUrlValue, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(this.preferences)
                });

                if (response.ok) {
                    const data = await response.json();
                    this.lastSyncedAt = data.updated_at;
                } else {
                }
            } catch (error) {
            }
        }, 1000); // Debounce 1 second
    }

    // Toggle widget visibility
    toggleWidget(event) {
        // Action wired to input's `change` event (template moved data-action to <input>
        // 2026-05-22 — see _dashboard_settings_modal.html.twig). Read widget id from
        // the parent wrapper carrying data-widget-toggle. Falls back to currentTarget
        // dataset for any legacy callers that still wire click on the wrapper div.
        const wrapper = event.currentTarget.closest('[data-widget-toggle]')
            ?? event.currentTarget;
        const widgetId = wrapper.dataset.widgetToggle;
        const checkbox = event.currentTarget.matches('input[type="checkbox"]')
            ? event.currentTarget
            : wrapper.querySelector('input[type="checkbox"]');

        if (!widgetId || !checkbox) return;

        const isVisible = checkbox.checked;

        // Update preferences
        if (!this.preferences.widgets) {
            this.preferences.widgets = {};
        }
        if (!this.preferences.widgets[widgetId]) {
            this.preferences.widgets[widgetId] = {};
        }
        this.preferences.widgets[widgetId].visible = isVisible;

        // Save immediately
        this.savePreferences();

        // Apply to widget
        this.applyPreferences();
    }

    // Change widget size
    changeWidgetSize(event) {
        const widgetId = event.currentTarget.dataset.widgetId;
        const size = event.currentTarget.value;

        if (!widgetId) return;

        // Update preferences
        if (!this.preferences.widgets) {
            this.preferences.widgets = {};
        }
        if (!this.preferences.widgets[widgetId]) {
            this.preferences.widgets[widgetId] = { visible: true };
        }
        this.preferences.widgets[widgetId].size = size;

        // Apply size to widget
        const widget = this.widgetTargets.find(w => w.dataset.widgetId === widgetId);
        if (widget) {
            // Remove old size classes
            widget.classList.remove('widget-size-small', 'widget-size-medium', 'widget-size-large', 'widget-size-full');

            // Add new size class
            widget.classList.add(`widget-size-${size}`);
        }

        // Save preferences
        this.savePreferences();
    }

    // Open settings modal via fa-modal shell
    openSettings() {
        if (this.hasSettingsModalTarget) {
            document.dispatchEvent(new CustomEvent('fa-modal:request-open', {
                bubbles: true,
                detail: { id: this.settingsModalTarget.id },
            }));
        }
    }

    // Reset to defaults
    async resetToDefaults() {
        if (await window.faConfirm(window.translations?.dashboard?.confirm_reset || 'Do you want to reset the dashboard to default settings?', { tone: 'warn' })) {
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

                window.faToast(window.translations?.dashboard?.settings_imported || 'Dashboard settings successfully imported!', 'success');
            } catch (error) {
                window.faToast(window.translations?.dashboard?.import_failed || 'Error importing settings. Please check the file.', 'danger');
            }
        };
        reader.readAsText(file);
    }
}
