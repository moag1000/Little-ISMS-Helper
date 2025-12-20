import { Controller } from '@hotwired/stimulus';

/**
 * Bootstrap 5 Tooltip Controller
 *
 * Automatically initializes Bootstrap tooltips on the page.
 * Handles Turbo navigation by re-initializing on turbo:load.
 *
 * Usage: Add data-controller="tooltip" to a parent element (e.g., body or form)
 *        or use the auto-initialization on turbo:load.
 */
export default class extends Controller {
    static tooltipInstances = [];

    connect() {
        this.initTooltips();
    }

    disconnect() {
        this.disposeTooltips();
    }

    initTooltips() {
        // Dispose existing tooltips first
        this.disposeTooltips();

        // Find all elements with data-bs-toggle="tooltip"
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');

        if (window.bootstrap && window.bootstrap.Tooltip) {
            this.constructor.tooltipInstances = [...tooltipTriggerList].map(tooltipTriggerEl => {
                return new window.bootstrap.Tooltip(tooltipTriggerEl, {
                    trigger: 'hover focus',  // Show on hover and focus
                    delay: { show: 200, hide: 100 },  // Small delay to avoid flickering
                    container: 'body',  // Append to body to avoid overflow issues
                    boundary: 'viewport',  // Keep within viewport
                    fallbackPlacements: ['top', 'bottom', 'right', 'left']  // Fallback positions
                });
            });
        }
    }

    disposeTooltips() {
        this.constructor.tooltipInstances.forEach(tooltip => {
            if (tooltip && typeof tooltip.dispose === 'function') {
                tooltip.dispose();
            }
        });
        this.constructor.tooltipInstances = [];
    }
}

// Auto-initialize tooltips on turbo:load for pages without explicit controller
document.addEventListener('turbo:load', () => {
    if (window.bootstrap && window.bootstrap.Tooltip) {
        // Dispose any existing tooltips
        const existingTooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        existingTooltips.forEach(el => {
            const existingTooltip = window.bootstrap.Tooltip.getInstance(el);
            if (existingTooltip) {
                existingTooltip.dispose();
            }
        });

        // Initialize new tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(tooltipTriggerEl => {
            new window.bootstrap.Tooltip(tooltipTriggerEl, {
                trigger: 'hover focus',
                delay: { show: 200, hide: 100 },
                container: 'body',
                boundary: 'viewport',
                fallbackPlacements: ['top', 'bottom', 'right', 'left']
            });
        });
    }
});

// Also handle DOMContentLoaded for initial page load
document.addEventListener('DOMContentLoaded', () => {
    if (window.bootstrap && window.bootstrap.Tooltip) {
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(tooltipTriggerEl => {
            new window.bootstrap.Tooltip(tooltipTriggerEl, {
                trigger: 'hover focus',
                delay: { show: 200, hide: 100 },
                container: 'body',
                boundary: 'viewport',
                fallbackPlacements: ['top', 'bottom', 'right', 'left']
            });
        });
    }
});
