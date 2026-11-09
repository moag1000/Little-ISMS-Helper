/**
 * Little ISMS Helper - Service Worker
 * Phase 8A: Progressive Web App Support
 *
 * Caching strategy (S16, 2026-11):
 * - Only fixed public files are cached: the build assets (/assets/, /vendor/, /fonts/,
 *   /icons/, /js/) and the offline page with its files (STATIC_ASSETS). Cache first.
 * - Nothing else is ever stored: no HTML page, no API or .json answer, no download.
 *   A cached page or report would outlive the logout and be shown to the next person
 *   on a shared device (Art. 33 report, risk register). Pages go to the network; when
 *   it is down, the offline page is shown.
 * - Even a static path is not stored when the answer says Cache-Control no-store or
 *   private, or is not a plain 200.
 * - Logout sends Clear-Site-Data ("cache", "storage"), which also drops this cache;
 *   activate removes every cache of an older CACHE_VERSION (including the page caches
 *   of versions before S16). tests/js/service_worker.test.js keeps these rules.
 */

const CACHE_VERSION = 'v3.1.0-s16';
const STATIC_CACHE = `isms-static-${CACHE_VERSION}`;

// Assets to cache immediately on install. No page of the application (not even "/"):
// the start page redirects into the logged-in area.
const STATIC_ASSETS = [
    // The offline fallback is fully static (no inline script/style), so it
    // never depends on a per-request CSP nonce that a cached copy would carry
    // stale. Its assets are cached with it.
    '/offline.html',
    '/offline.css',
    '/offline.js',
    '/manifest.json',
    '/favicon.svg',
    '/favicon.ico',
    '/logo.svg',
    '/apple-touch-icon.png',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/fonts/inter-400.woff2',
    '/fonts/inter-500.woff2',
    '/fonts/inter-600.woff2',
    '/fonts/inter-700.woff2',
    '/fonts/jetbrains-mono-400.woff2',
    '/fonts/jetbrains-mono-500.woff2',
    '/fonts/jetbrains-mono-600.woff2',
    '/vendor/bootstrap/css/bootstrap.min.css',
    '/vendor/bootstrap-icons/font/bootstrap-icons.min.css'
];

// Path prefixes of public build files. Everything else is personal or per-session.
const STATIC_PREFIXES = ['/assets/', '/vendor/', '/fonts/', '/icons/', '/js/'];
const STATIC_EXTENSIONS = [
    '.css', '.js', '.mjs', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.webp',
    '.woff', '.woff2', '.ttf', '.eot'
];

/**
 * Install Event - Cache static assets
 */
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
            .catch((error) => {
                console.error('[SW] Failed to cache static assets:', error);
            })
    );
});

/**
 * Activate Event - drop every cache that is not this version's static cache
 * (older versions kept HTML pages and API answers in isms-dynamic-* / isms-api-*).
 */
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => Promise.all(
                cacheNames
                    .filter((cacheName) => cacheName.startsWith('isms-') && cacheName !== STATIC_CACHE)
                    .map((cacheName) => caches.delete(cacheName))
            ))
            .then(() => self.clients.claim())
    );
});

/**
 * Fetch Event - static files from the cache, everything else from the network.
 */
self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(cacheFirstWithNetwork(request));
        return;
    }

    // Pages: network only, offline page when the network is down. Never stored.
    if (request.mode === 'navigate' || request.headers.get('Accept')?.includes('text/html')) {
        event.respondWith(networkWithOfflinePage(request));
    }

    // Everything else (API, .json, downloads, uploads): the browser handles it, no cache.
});

/**
 * Public build file? Fixed list or a static prefix with a static extension, no query
 * string. .json is never static (API answers, exports, reports).
 */
function isStaticAsset(url) {
    if (STATIC_ASSETS.includes(url.pathname)) {
        return url.search === '';
    }
    if (url.search !== '' && !url.pathname.startsWith('/assets/')) {
        return false;
    }
    return STATIC_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))
        && STATIC_EXTENSIONS.some((ext) => url.pathname.endsWith(ext));
}

/** May this answer be stored? Plain 200, same origin, no no-store/private. */
function isStorable(response) {
    if (!response || response.status !== 200 || response.type !== 'basic') {
        return false;
    }
    const cacheControl = (response.headers.get('Cache-Control') || '').toLowerCase();
    return !cacheControl.includes('no-store') && !cacheControl.includes('private');
}

/**
 * Cache First Strategy - Try cache, fallback to network
 */
async function cacheFirstWithNetwork(request) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
        // Unhashed files (/vendor/, offline page) refresh in the background.
        refreshInBackground(request);
        return cachedResponse;
    }

    try {
        const networkResponse = await fetch(request);
        if (isStorable(networkResponse)) {
            const cache = await caches.open(STATIC_CACHE);
            await cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (error) {
        return new Response('Offline - Asset not cached', { status: 503 });
    }
}

/** Stale-while-revalidate for a cached static file; failures keep the cached copy. */
async function refreshInBackground(request) {
    try {
        const networkResponse = await fetch(request);
        if (isStorable(networkResponse)) {
            const cache = await caches.open(STATIC_CACHE);
            await cache.put(request, networkResponse);
        }
    } catch (error) {
        // offline: the cached copy stays
    }
}

/**
 * Network only for pages; the offline page when the network is unreachable.
 */
async function networkWithOfflinePage(request) {
    try {
        return await fetch(request);
    } catch (error) {
        const offlinePage = await caches.match('/offline.html');
        return offlinePage || new Response('Offline', { status: 503 });
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

console.log('[SW] Service Worker loaded with Background Sync support');
