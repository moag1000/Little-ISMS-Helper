/* Offline fallback page behaviour. Static file so offline.html needs no inline
   script or event-handler attribute — and therefore no per-request CSP nonce,
   which a page served from the service-worker cache could only carry stale. */
window.addEventListener('online', () => {
    window.location.reload();
});

document.getElementById('offlineRetry')?.addEventListener('click', () => {
    window.location.reload();
});

// No list of cached pages: since S16 the service worker stores no page of the
// application (a cached page would outlive the logout on a shared device).
