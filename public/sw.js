/**
 * Little ISMS Helper - Service Worker
 * Phase 8A: Progressive Web App Support
 *
 * Caching Strategy:
 * - Static Assets: Cache First (CSS, JS, Images, Fonts)
 * - API Calls: Network First with Cache Fallback
 * - HTML Pages: Network First with Offline Fallback
 */

const CACHE_VERSION = 'v1.0.0';
const STATIC_CACHE = `isms-static-${CACHE_VERSION}`;
const DYNAMIC_CACHE = `isms-dynamic-${CACHE_VERSION}`;
const API_CACHE = `isms-api-${CACHE_VERSION}`;

// Assets to cache immediately on install
const STATIC_ASSETS = [
    '/',
    '/offline.html',
    '/manifest.json',
    '/favicon.svg',
    '/logo.svg',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/vendor/bootstrap/css/bootstrap.min.css',
    '/vendor/bootstrap-icons/font/bootstrap-icons.min.css'
];

// Cache duration in milliseconds
const CACHE_DURATION = {
    api: 5 * 60 * 1000,      // 5 minutes for API responses
    page: 60 * 60 * 1000,    // 1 hour for HTML pages
    static: 7 * 24 * 60 * 60 * 1000  // 7 days for static assets
};

/**
 * Install Event - Cache static assets
 */
self.addEventListener('install', (event) => {
    console.log('[SW] Installing Service Worker...');

    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                console.log('[SW] Static assets cached');
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[SW] Failed to cache static assets:', error);
            })
    );
});

/**
 * Activate Event - Clean up old caches
 */
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating Service Worker...');

    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((cacheName) => {
                            return cacheName.startsWith('isms-') &&
                                   !cacheName.includes(CACHE_VERSION);
                        })
                        .map((cacheName) => {
                            console.log('[SW] Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        })
                );
            })
            .then(() => {
                console.log('[SW] Old caches cleared');
                return self.clients.claim();
            })
    );
});

/**
 * Fetch Event - Handle all network requests
 */
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }

    // Skip browser extensions and external URLs
    if (!url.origin.includes(self.location.origin)) {
        return;
    }

    // Skip hot module replacement and webpack dev server
    if (url.pathname.includes('hot-update') || url.pathname.includes('__webpack')) {
        return;
    }

    // API requests - Network First with Cache Fallback
    if (url.pathname.startsWith('/api/') || url.pathname.includes('/analytics/api/')) {
        event.respondWith(networkFirstWithCache(request, API_CACHE));
        return;
    }

    // Static assets - Cache First
    if (isStaticAsset(url.pathname)) {
        event.respondWith(cacheFirstWithNetwork(request, STATIC_CACHE));
        return;
    }

    // HTML pages - Network First with Offline Fallback
    if (request.headers.get('Accept')?.includes('text/html')) {
        event.respondWith(networkFirstWithOffline(request));
        return;
    }

    // Default - Network First
    event.respondWith(networkFirstWithCache(request, DYNAMIC_CACHE));
});

/**
 * Check if request is for a static asset
 */
function isStaticAsset(pathname) {
    const staticExtensions = [
        '.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico',
        '.woff', '.woff2', '.ttf', '.eot', '.json'
    ];
    return staticExtensions.some(ext => pathname.endsWith(ext));
}

/**
 * Cache First Strategy - Try cache, fallback to network
 */
async function cacheFirstWithNetwork(request, cacheName) {
    const cachedResponse = await caches.match(request);

    if (cachedResponse) {
        // Return cached response and update cache in background
        updateCache(request, cacheName);
        return cachedResponse;
    }

    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (error) {
        console.error('[SW] Network request failed:', error);
        return new Response('Offline - Asset not cached', { status: 503 });
    }
}

/**
 * Network First Strategy - Try network, fallback to cache
 */
async function networkFirstWithCache(request, cacheName) {
    try {
        const networkResponse = await fetch(request);

        if (networkResponse.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        console.log('[SW] Network failed, trying cache...');
        const cachedResponse = await caches.match(request);

        if (cachedResponse) {
            return cachedResponse;
        }

        return new Response(JSON.stringify({
            error: 'Offline',
            message: 'No cached data available'
        }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

/**
 * Network First with Offline Page Fallback
 */
async function networkFirstWithOffline(request) {
    try {
        const networkResponse = await fetch(request);

        if (networkResponse.ok) {
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        console.log('[SW] Network failed for page, checking cache...');

        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }

        // Return offline page
        const offlinePage = await caches.match('/offline.html');
        if (offlinePage) {
            return offlinePage;
        }

        return new Response('Offline', { status: 503 });
    }
}

/**
 * Update cache in background (stale-while-revalidate)
 */
async function updateCache(request, cacheName) {
    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, networkResponse);
        }
    } catch (error) {
        // Silently fail - we already have cached version
    }
}

/**
 * Push Notification Event
 */
self.addEventListener('push', (event) => {
    console.log('[SW] Push notification received');

    let data = {
        title: 'ISMS Helper',
        body: 'You have a new notification',
        icon: '/icons/icon-192x192.png',
        badge: '/icons/icon-72x72.png',
        tag: 'isms-notification'
    };

    if (event.data) {
        try {
            data = { ...data, ...event.data.json() };
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        tag: data.tag,
        vibrate: [100, 50, 100],
        data: {
            url: data.url || '/',
            dateOfArrival: Date.now()
        },
        actions: data.actions || [
            { action: 'open', title: 'Open' },
            { action: 'close', title: 'Dismiss' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

/**
 * Notification Click Event
 */
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] Notification clicked');

    event.notification.close();

    if (event.action === 'close') {
        return;
    }

    const urlToOpen = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                // Check if there's already a window open
                for (const client of clientList) {
                    if (client.url.includes(self.location.origin) && 'focus' in client) {
                        client.navigate(urlToOpen);
                        return client.focus();
                    }
                }
                // Open new window
                if (clients.openWindow) {
                    return clients.openWindow(urlToOpen);
                }
            })
    );
});

/**
 * Background Sync Event (for offline form submissions)
 */
self.addEventListener('sync', (event) => {
    console.log('[SW] Background sync:', event.tag);

    if (event.tag === 'sync-incidents') {
        event.waitUntil(syncIncidents());
    }

    if (event.tag === 'sync-risk-updates') {
        event.waitUntil(syncRiskUpdates());
    }
});

/**
 * Sync pending incidents (placeholder for future implementation)
 */
async function syncIncidents() {
    // TODO: Implement offline incident sync
    console.log('[SW] Syncing incidents...');
}

/**
 * Sync pending risk updates (placeholder for future implementation)
 */
async function syncRiskUpdates() {
    // TODO: Implement offline risk update sync
    console.log('[SW] Syncing risk updates...');
}

console.log('[SW] Service Worker loaded');
