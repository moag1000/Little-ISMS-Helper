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

// Import tooltip initialization (must come after bootstrap is available)
// This enables the module-level event handlers for tooltip auto-initialization
import './controllers/tooltip_controller.js';

// NOTE: CSS is loaded separately via assets/styles.css (see importmap.php)
// This avoids AssetMapper issues with CSS imports from JavaScript at APP_DEBUG=0
