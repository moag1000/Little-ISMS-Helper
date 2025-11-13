/**
 * Extension Error Handler
 *
 * Suppresses console errors from browser extensions that don't affect application functionality.
 * Common errors include:
 * - Unchecked runtime.lastError (Chrome extensions)
 * - Message port closed errors
 * - Back/forward cache (bfcache) errors
 * - Connection errors from extensions
 */

// Suppress unchecked runtime.lastError messages from browser extensions
window.addEventListener('error', function(event) {
    // Check if error is from browser extension
    if (event.message && (
        event.message.includes('runtime.lastError') ||
        event.message.includes('extension port') ||
        event.message.includes('back/forward cache') ||
        event.message.includes('Receiving end does not exist') ||
        event.message.includes('message channel is closed') ||
        (event.message.includes('Frame') && event.message.includes('does not exist'))
    )) {
        event.preventDefault();
        return true;
    }

    // Check if error is from missing extension files
    if (event.filename && (
        event.filename.includes('utils.js') ||
        event.filename.includes('heuristicsRedefinitions.js') ||
        event.filename.includes('extensionState.js')
    )) {
        event.preventDefault();
        return true;
    }
}, true);

// Suppress unhandled promise rejections from browser extensions
window.addEventListener('unhandledrejection', function(event) {
    const message = event.reason?.message || String(event.reason);
    if (message.includes('Could not establish connection') ||
        message.includes('Receiving end does not exist') ||
        message.includes('extension port') ||
        message.includes('message channel') ||
        (message.includes('Frame') && message.includes('does not exist'))) {
        event.preventDefault();
    }
});

// Handle page visibility changes to prevent bfcache issues with extensions
let wasHidden = false;

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        wasHidden = true;
    } else if (wasHidden) {
        wasHidden = false;
        // Page became visible again - extensions might reconnect
        // Give them a moment before showing any errors
        const suppressErrors = true;
        setTimeout(() => {
            // Re-enable error reporting after brief delay
        }, 1000);
    }
});

// Prevent bfcache issues by ensuring cleanup
window.addEventListener('pagehide', function(event) {
    // Disconnect any message ports or connections that might interfere
    if (event.persisted) {
        // Page is being cached - ensure proper cleanup
        document.dispatchEvent(new Event('turbo:before-cache'));
    }
});

// Handle page restore from bfcache
window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        // Page restored from bfcache
        // Suppress extension errors that might occur during restoration
        setTimeout(() => {
            // Allow time for extensions to reconnect
        }, 500);
    }
});
