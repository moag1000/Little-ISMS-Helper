import { Controller } from '@hotwired/stimulus';

/**
 * Preferences Controller - User Preferences Management
 *
 * Features:
 * - View density (compact/comfortable)
 * - Animation preferences
 * - Keyboard shortcuts on/off
 * - Persist all preferences in localStorage
 */
export default class extends Controller {
    static targets = [
        'modal',
        'viewDensity',
        'animations',
        'keyboardShortcuts',
        'language'
    ];

    static values = {
        storageKey: { type: String, default: 'user-preferences' }
    };

    previouslyFocusedElement = null;

    connect() {
        // Load saved preferences
        this.loadPreferences();

        // Apply preferences
        this.applyPreferences();

        // ESC to close modal
        this.handleKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.handleKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.handleKeydown);
    }

    handleKeydown(event) {
        if (!this.hasModalTarget || this.modalTarget.classList.contains('d-none')) {
            return;
        }

        if (event.key === 'Escape') {
            this.close();
        } else if (event.key === 'Tab') {
            // Focus trap - keep focus within modal
            this.handleTabKey(event);
        }
    }

    handleTabKey(event) {
        const focusableElements = this.getFocusableElements();
        if (focusableElements.length === 0) return;

        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (event.shiftKey) {
            // Shift+Tab: if on first element, go to last
            if (document.activeElement === firstElement) {
                event.preventDefault();
                lastElement.focus();
            }
        } else {
            // Tab: if on last element, go to first
            if (document.activeElement === lastElement) {
                event.preventDefault();
                firstElement.focus();
            }
        }
    }

    getFocusableElements() {
        const selector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
        return Array.from(this.modalTarget.querySelectorAll(selector)).filter(
            el => !el.disabled && el.offsetParent !== null
        );
    }

    open() {
        if (this.hasModalTarget) {
            // Save currently focused element to restore on close
            this.previouslyFocusedElement = document.activeElement;

            // Remove inline style that modal manager might have set
            this.modalTarget.style.display = '';
            this.modalTarget.classList.remove('d-none');
            this.modalTarget.classList.add('show');
            document.body.style.overflow = 'hidden';

            // Focus first focusable element
            requestAnimationFrame(() => {
                const focusable = this.getFocusableElements();
                if (focusable.length > 0) {
                    focusable[0].focus();
                }
            });
        }
    }

    close() {
        if (this.hasModalTarget) {
            this.modalTarget.classList.add('d-none');
            this.modalTarget.classList.remove('show');
            document.body.style.overflow = '';

            // Restore focus to previously focused element
            if (this.previouslyFocusedElement && typeof this.previouslyFocusedElement.focus === 'function') {
                this.previouslyFocusedElement.focus();
            }
            this.previouslyFocusedElement = null;
        }
    }

    handleBackdropClick(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    loadPreferences() {
        const savedPrefs = localStorage.getItem(this.storageKeyValue);

        if (savedPrefs) {
            try {
                this.preferences = JSON.parse(savedPrefs);
            } catch (e) {
                this.preferences = this.getDefaultPreferences();
            }
        } else {
            this.preferences = this.getDefaultPreferences();
        }
    }

    getDefaultPreferences() {
        return {
            viewDensity: 'comfortable', // compact, comfortable
            animations: true,
            keyboardShortcuts: true,
            language: 'de'
        };
    }

    savePreferences() {
        localStorage.setItem(this.storageKeyValue, JSON.stringify(this.preferences));

        // Dispatch event
        this.dispatch('changed', { detail: { preferences: this.preferences } });
    }

    applyPreferences() {
        // Apply view density
        this.applyViewDensity(this.preferences.viewDensity);

        // Apply animations preference
        this.applyAnimations(this.preferences.animations);

        // Apply keyboard shortcuts
        this.applyKeyboardShortcuts(this.preferences.keyboardShortcuts);
    }

    applyViewDensity(density) {
        document.documentElement.setAttribute('data-density', density);
    }

    applyAnimations(enabled) {
        if (enabled) {
            document.documentElement.removeAttribute('data-animations');
        } else {
            document.documentElement.setAttribute('data-animations', 'disabled');
        }
    }

    applyKeyboardShortcuts(enabled) {
        document.documentElement.setAttribute('data-keyboard-shortcuts', enabled ? 'enabled' : 'disabled');
    }

    // User actions
    setViewDensity(event) {
        const density = event.target.value;
        this.preferences.viewDensity = density;
        this.savePreferences();
        this.applyViewDensity(density);
    }

    toggleAnimations(event) {
        const enabled = event.target.checked;
        this.preferences.animations = enabled;
        this.savePreferences();
        this.applyAnimations(enabled);
    }

    toggleKeyboardShortcuts(event) {
        const enabled = event.target.checked;
        this.preferences.keyboardShortcuts = enabled;
        this.savePreferences();
        this.applyKeyboardShortcuts(enabled);
    }

    setLanguage(event) {
        const language = event.target.value;
        this.preferences.language = language;
        this.savePreferences();

        // Reload page to apply language change
        // In a real app, you might want to handle this more gracefully
        window.location.reload();
    }

    resetToDefaults() {
        if (confirm(window.translations?.preferences?.confirm_reset || 'Do you want to reset all settings?')) {
            this.preferences = this.getDefaultPreferences();
            this.savePreferences();
            this.applyPreferences();

            // Update form fields
            this.updateFormFields();

            // Show success message
            this.showSuccessMessage(window.translations?.preferences?.reset_success || 'Settings have been reset');
        }
    }

    updateFormFields() {
        if (this.hasViewDensityTarget) {
            this.viewDensityTarget.value = this.preferences.viewDensity;
        }
        if (this.hasAnimationsTarget) {
            this.animationsTarget.checked = this.preferences.animations;
        }
        if (this.hasKeyboardShortcutsTarget) {
            this.keyboardShortcutsTarget.checked = this.preferences.keyboardShortcuts;
        }
        if (this.hasLanguageTarget) {
            this.languageTarget.value = this.preferences.language;
        }
    }

    showSuccessMessage(message) {
        // Dispatch event for toast notification
        window.dispatchEvent(new CustomEvent('show-toast', {
            detail: {
                type: 'success',
                message: message
            }
        }));
    }

    exportPreferences() {
        const dataStr = JSON.stringify(this.preferences, null, 2);
        const dataBlob = new Blob([dataStr], { type: 'application/json' });
        const url = URL.createObjectURL(dataBlob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'isms-preferences.json';
        link.click();
        URL.revokeObjectURL(url);
    }

    importPreferences(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            try {
                const imported = JSON.parse(e.target.result);
                this.preferences = { ...this.getDefaultPreferences(), ...imported };
                this.savePreferences();
                this.applyPreferences();
                this.updateFormFields();
                this.showSuccessMessage(window.translations?.preferences?.imported || 'Settings successfully imported');
            } catch (err) {
                alert(window.translations?.preferences?.import_failed || 'Error importing settings');
            }
        };
        reader.readAsText(file);
    }
}
