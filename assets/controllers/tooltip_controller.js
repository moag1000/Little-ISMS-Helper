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

const TOOLTIP_OPTIONS = {
    trigger: 'hover focus',
    delay: { show: 200, hide: 100 },
    container: 'body',
    boundary: 'viewport',
    fallbackPlacements: ['top', 'bottom', 'right', 'left']
};

function initAllTooltips() {
    if (!window.bootstrap || !window.bootstrap.Tooltip) {
        return;
    }
    // Dispose any existing tooltips
    const existing = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    existing.forEach(el => {
        const inst = window.bootstrap.Tooltip.getInstance(el);
        if (inst) inst.dispose();
    });
    // Initialize fresh
    existing.forEach(el => new window.bootstrap.Tooltip(el, TOOLTIP_OPTIONS));
}

// Auto-init on Turbo navigation + initial DOMContentLoaded.
document.addEventListener('turbo:load', initAllTooltips);
document.addEventListener('DOMContentLoaded', initAllTooltips);
