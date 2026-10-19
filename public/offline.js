/* Offline fallback page behaviour. Static file so offline.html needs no inline script (CSP-safe). */
window.addEventListener('online', () => {
    window.location.reload();
});

// List cached pages if available. The dynamic cache name carries the SW's
// CACHE_VERSION (public/sw.js), so discover it by prefix instead of
// hard-coding the version here.
if ('caches' in window) {
    caches.keys().then(names => {
        const dynamicNames = names.filter(n => n.startsWith('isms-dynamic-'));
        return Promise.all(dynamicNames.map(n => caches.open(n).then(c => c.keys())));
    }).then(lists => {
        const seen = new Set();
        const htmlRequests = lists.flat().filter(req => {
            if (seen.has(req.url)) return false;
            seen.add(req.url);
            return req.url.includes('/de/') || req.url.includes('/en/');
        });

        if (htmlRequests.length > 0) {
            const container = document.getElementById('cachedPages');
            const list = document.getElementById('cachedPagesList');
            if (!container || !list) return;
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
    }).catch(() => {});
}
