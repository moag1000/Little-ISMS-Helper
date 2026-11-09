import { describe, it, expect, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// S16: the service worker must never store a page, API answer or .json of the
// application — a cached copy outlives the logout and is shown to the next person on
// a shared device. Only fixed public build files are cached.
const source = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf8');
const ORIGIN = 'https://isms.test';

function response({ status = 200, cacheControl = '', type = 'basic', body = 'x' } = {}) {
    const headers = new Map([['cache-control', cacheControl]]);
    const res = {
        status,
        type,
        ok: status >= 200 && status < 300,
        body,
        headers: { get: (name) => headers.get(name.toLowerCase()) ?? null },
    };
    res.clone = () => ({ ...res });
    return res;
}

/** Loads sw.js into a fake worker scope and returns its handlers + the cache store. */
function bootWorker({ network = () => response(), existingCaches = [] } = {}) {
    const handlers = {};
    const stores = new Map(existingCaches.map((name) => [name, new Map()]));
    const cacheOf = (name) => {
        if (!stores.has(name)) stores.set(name, new Map());
        const entries = stores.get(name);
        return {
            put: async (req, res) => { entries.set(typeof req === 'string' ? req : req.url, res); },
            addAll: async (list) => { list.forEach((path) => entries.set(ORIGIN + path, response())); },
            keys: async () => [...entries.keys()].map((url) => ({ url })),
        };
    };
    const caches = {
        open: async (name) => cacheOf(name),
        keys: async () => [...stores.keys()],
        delete: async (name) => stores.delete(name),
        match: async (req) => {
            const url = typeof req === 'string' ? ORIGIN + req : req.url;
            for (const entries of stores.values()) {
                if (entries.has(url)) return entries.get(url);
            }
            return undefined;
        },
    };
    const self = {
        location: { origin: ORIGIN },
        addEventListener: (type, fn) => { handlers[type] = fn; },
        skipWaiting: async () => {},
        clients: { claim: async () => {}, matchAll: async () => [] },
        registration: { showNotification: async () => {} },
    };
    const fetchCalls = [];
    const fetch = async (req) => { fetchCalls.push(req.url ?? req); return network(req); };
    // eslint-disable-next-line no-new-func
    new Function('self', 'caches', 'fetch', 'clients', 'console', source)(
        self, caches, fetch, self.clients, { log() {}, error() {} },
    );

    return { handlers, stores, fetchCalls };
}

function request(path, { mode = 'cors', accept = '*/*', method = 'GET' } = {}) {
    return { url: ORIGIN + path, method, mode, headers: { get: (n) => (n === 'Accept' ? accept : null) } };
}

/** Runs the fetch handler; resolves to the response, or null when the worker passed. */
async function dispatchFetch(worker, req) {
    let answered = null;
    worker.handlers.fetch({ request: req, respondWith: (p) => { answered = p; } });
    return answered === null ? null : await answered;
}

function storedUrls(stores) {
    return [...stores.values()].flatMap((entries) => [...entries.keys()]);
}

describe('sw.js — no personal data in the cache (S16)', () => {
    let worker;
    beforeEach(() => { worker = bootWorker(); });

    it('never stores a logged-in page', async () => {
        const res = await dispatchFetch(worker, request('/de/incident/12', { mode: 'navigate', accept: 'text/html' }));
        expect(res.status).toBe(200);
        expect(storedUrls(worker.stores)).not.toContain(ORIGIN + '/de/incident/12');
    });

    it.each([
        '/de/authority-notification/7/export.json',
        '/api/incidents/recent',
        '/de/analytics/api/heatmap',
        '/de/document/5/download',
        '/uploads/documents/a.pdf',
        '/manifest.json?x=1',
    ])('leaves %s to the network without caching', async (path) => {
        const res = await dispatchFetch(worker, request(path));
        expect(res).toBeNull();
        expect(storedUrls(worker.stores)).not.toContain(ORIGIN + path);
    });

    it('caches public build files', async () => {
        await dispatchFetch(worker, request('/assets/app-3f2a1b.js'));
        expect(storedUrls(worker.stores)).toContain(ORIGIN + '/assets/app-3f2a1b.js');
    });

    it('does not store a static path answered with no-store or private', async () => {
        const w = bootWorker({ network: () => response({ cacheControl: 'private, max-age=0' }) });
        await dispatchFetch(w, request('/assets/report-chart.js'));
        expect(storedUrls(w.stores)).not.toContain(ORIGIN + '/assets/report-chart.js');
    });

    it('shows the offline page, not a cached copy, when the network is down', async () => {
        const w = bootWorker({ network: () => { throw new TypeError('offline'); } });
        await w.handlers.install({ waitUntil: (p) => p });
        const res = await dispatchFetch(w, request('/de/dashboard', { mode: 'navigate', accept: 'text/html' }));
        expect(res).toBe(await (async () => {
            for (const entries of w.stores.values()) {
                if (entries.has(ORIGIN + '/offline.html')) return entries.get(ORIGIN + '/offline.html');
            }
            return undefined;
        })());
    });

    it('caches no application page on install', async () => {
        await worker.handlers.install({ waitUntil: (p) => p });
        const pages = storedUrls(worker.stores).filter((url) => !/\.[a-z0-9]+$/.test(new URL(url).pathname));
        expect(pages).toEqual([]);
    });

    it('drops the page and API caches of older versions on activate', async () => {
        const w = bootWorker({ existingCaches: ['isms-dynamic-v3.0.1-fa', 'isms-api-v3.0.1-fa', 'isms-static-v3.0.1-fa'] });
        let done;
        w.handlers.activate({ waitUntil: (p) => { done = p; } });
        await done;
        expect([...w.stores.keys()].filter((n) => n.startsWith('isms-') && !n.startsWith('isms-static-v3.1'))).toEqual([]);
    });
});
