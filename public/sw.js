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

    if (event.tag === 'sync-offline-requests') {
        event.waitUntil(syncOfflineRequests());
    }

    if (event.tag === 'sync-incidents') {
        event.waitUntil(syncIncidents());
    }

    if (event.tag === 'sync-risk-updates') {
        event.waitUntil(syncRiskUpdates());
    }
});

/**
 * IndexedDB helper for offline request storage
 */
const DB_NAME = 'isms-offline-db';
const DB_VERSION = 1;
const STORE_NAME = 'offline-requests';

function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);

        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                const store = db.createObjectStore(STORE_NAME, {
                    keyPath: 'id',
                    autoIncrement: true
                });
                store.createIndex('timestamp', 'timestamp');
                store.createIndex('type', 'type');
            }
        };
    });
}

/**
 * Store a request for later sync
 */
async function storeOfflineRequest(requestData) {
    const db = await openDatabase();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);

        const request = store.add({
            ...requestData,
            timestamp: Date.now(),
            retries: 0
        });

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/**
 * Get all pending offline requests
 */
async function getOfflineRequests() {
    const db = await openDatabase();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readonly');
        const store = tx.objectStore(STORE_NAME);
        const request = store.getAll();

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/**
 * Delete a synced request
 */
async function deleteOfflineRequest(id) {
    const db = await openDatabase();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const request = store.delete(id);

        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
    });
}

/**
 * Sync all pending offline requests
 */
async function syncOfflineRequests() {
    console.log('[SW] Syncing offline requests...');

    const requests = await getOfflineRequests();
    console.log(`[SW] Found ${requests.length} pending requests`);

    for (const req of requests) {
        try {
            const response = await fetch(req.url, {
                method: req.method,
                headers: req.headers,
                body: req.body
            });

            if (response.ok) {
                await deleteOfflineRequest(req.id);
                console.log(`[SW] Synced request ${req.id}`);

                // Notify clients of successful sync
                const clients = await self.clients.matchAll();
                clients.forEach(client => {
                    client.postMessage({
                        type: 'SYNC_SUCCESS',
                        requestId: req.id,
                        requestType: req.type
                    });
                });
            } else if (response.status >= 400 && response.status < 500) {
                // Client error - don't retry
                await deleteOfflineRequest(req.id);
                console.log(`[SW] Request ${req.id} failed with client error, removing`);
            }
        } catch (error) {
            console.error(`[SW] Failed to sync request ${req.id}:`, error);
            // Will retry on next sync
        }
    }
}

/**
 * Sync pending incidents
 */
async function syncIncidents() {
    console.log('[SW] Syncing incidents...');
    const requests = await getOfflineRequests();
    const incidentRequests = requests.filter(r => r.type === 'incident');

    for (const req of incidentRequests) {
        try {
            const response = await fetch(req.url, {
                method: req.method,
                headers: req.headers,
                body: req.body
            });

            if (response.ok) {
                await deleteOfflineRequest(req.id);

                // Show success notification
                self.registration.showNotification('Incident Synced', {
                    body: 'Your offline incident report has been submitted.',
                    icon: '/icons/icon-192x192.png',
                    badge: '/icons/icon-72x72.png',
                    tag: 'sync-incident'
                });
            }
        } catch (error) {
            console.error('[SW] Failed to sync incident:', error);
        }
    }
}

/**
 * Sync pending risk updates
 */
async function syncRiskUpdates() {
    console.log('[SW] Syncing risk updates...');
    const requests = await getOfflineRequests();
    const riskRequests = requests.filter(r => r.type === 'risk');

    for (const req of riskRequests) {
        try {
            const response = await fetch(req.url, {
                method: req.method,
                headers: req.headers,
                body: req.body
            });

            if (response.ok) {
                await deleteOfflineRequest(req.id);
            }
        } catch (error) {
            console.error('[SW] Failed to sync risk update:', error);
        }
    }
}

/**
 * Handle messages from clients
 */
self.addEventListener('message', (event) => {
    console.log('[SW] Message received:', event.data);

    if (event.data.type === 'STORE_OFFLINE_REQUEST') {
        storeOfflineRequest(event.data.request)
            .then(id => {
                event.ports[0].postMessage({ success: true, id });
            })
            .catch(error => {
                event.ports[0].postMessage({ success: false, error: error.message });
            });
    }

    if (event.data.type === 'GET_OFFLINE_COUNT') {
        getOfflineRequests()
            .then(requests => {
                event.ports[0].postMessage({ count: requests.length });
            })
            .catch(() => {
                event.ports[0].postMessage({ count: 0 });
            });
    }

    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

/**
 * Periodic Background Sync (if supported)
 */
self.addEventListener('periodicsync', (event) => {
    console.log('[SW] Periodic sync:', event.tag);

    if (event.tag === 'sync-dashboard-data') {
        event.waitUntil(prefetchDashboardData());
    }
});

/**
 * Prefetch dashboard data for offline access
 */
async function prefetchDashboardData() {
    console.log('[SW] Prefetching dashboard data...');

    const endpoints = [
        '/api/dashboard/stats',
        '/api/risks/summary',
        '/api/incidents/recent'
    ];

    const cache = await caches.open(API_CACHE);

    for (const endpoint of endpoints) {
        try {
            const response = await fetch(endpoint);
            if (response.ok) {
                cache.put(endpoint, response);
            }
        } catch (error) {
            console.error(`[SW] Failed to prefetch ${endpoint}:`, error);
        }
    }
}

console.log('[SW] Service Worker loaded with Background Sync support');
