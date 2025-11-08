import { Controller } from '@hotwired/stimulus';

/**
 * Theme Controller - Dark Mode Management
 *
 * Features:
 * - Toggle between light and dark mode
 * - Persist preference in localStorage
 * - Auto-detect system preference
 * - Smooth transitions
 */
export default class extends Controller {
    static values = {
        storageKey: { type: String, default: 'theme-preference' }
    };

    connect() {
        // Initialize theme from localStorage or system preference
        this.initializeTheme();

        // Listen for system theme changes
        this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        this.boundHandleSystemThemeChange = this.handleSystemThemeChange.bind(this);
        this.mediaQuery.addEventListener('change', this.boundHandleSystemThemeChange);

        // Initialize debounce timestamp
        this.lastToggleTime = 0;
    }

    disconnect() {
        if (this.mediaQuery) {
            this.mediaQuery.removeEventListener('change', this.boundHandleSystemThemeChange);
        }
    }

    initializeTheme() {
        const savedTheme = localStorage.getItem(this.storageKeyValue);

        if (savedTheme) {
            // Use saved preference
            this.setTheme(savedTheme);
        } else {
            // Auto-detect system preference
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            this.setTheme(prefersDark ? 'dark' : 'light');
        }
    }

    toggle(event) {
        // Prevent event propagation and default behavior
        if (event) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
        }

        // Debounce: prevent multiple rapid calls (within 300ms)
        const now = Date.now();
        if (now - this.lastToggleTime < 300) {
            return;
        }
        this.lastToggleTime = now;

        const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        this.setTheme(newTheme);
        this.savePreference(newTheme);

        // Dispatch custom event
        this.dispatch('changed', { detail: { theme: newTheme } });
    }

    setTheme(theme) {
        // Remove existing theme
        document.documentElement.removeAttribute('data-theme');

        // Add new theme
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }

        // Update meta theme-color for mobile browsers
        this.updateMetaThemeColor(theme);

        // Update toggle button icon
        this.updateToggleIcon(theme);
    }

    savePreference(theme) {
        localStorage.setItem(this.storageKeyValue, theme);
    }

    handleSystemThemeChange(event) {
        // Only apply if user hasn't set a manual preference
        const savedTheme = localStorage.getItem(this.storageKeyValue);

        if (!savedTheme) {
            const newTheme = event.matches ? 'dark' : 'light';
            this.setTheme(newTheme);
        }
    }

    updateMetaThemeColor(theme) {
        let metaThemeColor = document.querySelector('meta[name="theme-color"]');

        if (!metaThemeColor) {
            metaThemeColor = document.createElement('meta');
            metaThemeColor.name = 'theme-color';
            document.head.appendChild(metaThemeColor);
        }

        // Set color based on theme
        metaThemeColor.content = theme === 'dark' ? '#1a1d23' : '#ffffff';
    }

    updateToggleIcon(theme) {
        const toggleBtn = document.querySelector('.theme-toggle-btn');

        if (toggleBtn) {
            const icon = toggleBtn.querySelector('i');
            if (icon) {
                // Switch icon
                if (theme === 'dark') {
                    icon.className = 'bi-sun-fill';
                } else {
                    icon.className = 'bi-moon-fill';
                }
            }
        }
    }

    // Getter for current theme
    get currentTheme() {
        return document.documentElement.getAttribute('data-theme') || 'light';
    }

    // Manual theme setters for external use
    setLight() {
        this.setTheme('light');
        this.savePreference('light');
    }

    setDark() {
        this.setTheme('dark');
        this.savePreference('dark');
    }

    setAuto() {
        // Remove saved preference and use system
        localStorage.removeItem(this.storageKeyValue);
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        this.setTheme(prefersDark ? 'dark' : 'light');
    }
}
