import { Controller } from '@hotwired/stimulus';

/**
 * Dashboard Customizer Controller
 *
 * Features:
 * - Widget toggle (show/hide)
 * - LocalStorage persistence
 * - Reset to defaults
 */
export default class extends Controller {
    static targets = ['widget', 'settingsModal', 'toggleButton'];
    static values = {
        storageKey: { type: String, default: 'dashboard_widget_preferences' }
    };

    connect() {
        this.loadPreferences();
        this.applyPreferences();
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

                alert('Dashboard-Einstellungen erfolgreich importiert!');
            } catch (error) {
                console.error('Failed to import preferences:', error);
                alert('Fehler beim Importieren der Einstellungen. Bitte überprüfen Sie die Datei.');
            }
        };
        reader.readAsText(file);
    }
}
