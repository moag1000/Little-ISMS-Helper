import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

// Extension Error Handler - Suppress browser extension errors
import './extension_error_handler.js';

// CSRF Protection - Global script (not a Stimulus controller)
import './csrf_protection.js';

// Import Bootstrap and expose globally for inline scripts (avoids Turbo/importmap conflicts)
import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

// Initialize Bootstrap tooltips on page load and Turbo navigation
function initTooltips() {
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');

    tooltipTriggerList.forEach((el) => {
        // Dispose existing tooltip if any
        const existing = bootstrap.Tooltip.getInstance(el);
        if (existing) existing.dispose();

        // Create new tooltip
        new bootstrap.Tooltip(el, {
            trigger: 'hover focus',
            html: true,
            container: 'body'
        });
    });
}

// Run on Turbo navigation (primary for SPA-like behavior)
document.addEventListener('turbo:load', initTooltips);

// Fallback for initial page load without Turbo
document.addEventListener('DOMContentLoaded', initTooltips);

// NOTE: CSS is loaded separately via assets/styles.css (see importmap.php)
// This avoids AssetMapper issues with CSS imports from JavaScript at APP_DEBUG=0
