import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

// CSRF Protection - Global script (not a Stimulus controller)
import './csrf_protection.js';

// NOTE: CSS is loaded separately via assets/styles.css (see importmap.php)
// This avoids AssetMapper issues with CSS imports from JavaScript at APP_DEBUG=0
