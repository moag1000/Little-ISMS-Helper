import './stimulus_bootstrap.js';

// FairyAurora v4.0 — Alva Companion Event Bus (singleton, exposes window.alvaBus)
import './js/alva-bus.js';
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

// Chart.js global für Template-Inline-Scripts (report_builder, analytics, compliance)
// Registriert alle Komponenten einmalig; Stimulus-Chart-Controller nutzt denselben Global.
import {
    Chart,
    ArcElement, BarElement, LineElement, PointElement,
    CategoryScale, LinearScale, RadialLinearScale, TimeScale,
    Title, Tooltip, Legend, Filler, SubTitle
} from 'chart.js';

Chart.register(
    ArcElement, BarElement, LineElement, PointElement,
    CategoryScale, LinearScale, RadialLinearScale, TimeScale,
    Title, Tooltip, Legend, Filler, SubTitle
);
window.Chart = Chart;

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

// FairyAurora v4.0 — Alva empty-state integration
// Templates that render an empty state can dispatch the custom event 'alva:empty'
// (e.g. document.dispatchEvent(new CustomEvent('alva:empty'))) to signal Alva.
document.addEventListener('alva:empty', () => {
    window.alvaBus?.emit({ mood: 'curious', reason: 'empty-state' });
});
