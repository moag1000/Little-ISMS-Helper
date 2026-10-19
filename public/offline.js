/* Offline fallback page behaviour. Static file so offline.html needs no inline script (CSP-safe). */
// Check for online status changes
window.addEventListener('online', () => {
    window.location.reload();
});

// List cached pages if available
if ('caches' in window) {
    caches.open('isms-dynamic-v1.0.0').then(cache => {
        cache.keys().then(requests => {
            const htmlRequests = requests.filter(req =>
                req.url.includes('/de/') || req.url.includes('/en/')
            );

            if (htmlRequests.length > 0) {
                const container = document.getElementById('cachedPages');
                const list = document.getElementById('cachedPagesList');
                container.style.display = 'block';

                htmlRequests.slice(0, 5).forEach(req => {
                    const url = new URL(req.url);
                    const li = document.createElement('li');
                    const a = document.createElement('a');
                    a.href = url.pathname;
                    a.textContent = url.pathname;
                    li.appendChild(a);
                    list.appendChild(li);
                });
            }
        });
    });
}
