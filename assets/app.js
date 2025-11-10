import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

// External dependencies (loaded from importmap)
// Bootstrap Icons are loaded via CDN in base.html.twig due to AssetMapper issue #52620
// import 'bootstrap-icons/font/bootstrap-icons.min.css';

// Core styles
import './styles/app.css';
import './styles/components.css';

// Modern UI/UX Components - Phase 1
import './styles/ui-components.css';
import './styles/command-palette.css';
import './styles/toast.css';
import './styles/keyboard-shortcuts.css';

// Modern UI/UX Components - Phase 2
import './styles/skeleton.css';
import './styles/bulk-actions.css';

// Premium Features - Phase 5
import './styles/premium.css';
import './styles/dark-mode.css'; // Paket C - Dark Mode
import './styles/analytics.css'; // Paket D - Analytics
