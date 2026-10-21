import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// vitest runs from the repository root.
const offlineHtml = readFileSync(resolve(process.cwd(), 'public/offline.html'), 'utf8');

/** The real offline page body, so the test exercises the shipped markup. */
function mountOfflinePage() {
    const body = offlineHtml.match(/<body>([\s\S]*)<\/body>/)[1].replace(/<script[\s\S]*?<\/script>/g, '');
    document.body.innerHTML = body;
}

describe('offline.js — cached page list', () => {
    it('finds the versioned isms-dynamic-* cache without a hard-coded version', async () => {
        mountOfflinePage();
        const opened = [];
        globalThis.caches = {
            keys: async () => ['isms-static-v3.0.0-fa', 'isms-dynamic-v3.0.0-fa'],
            open: async (name) => {
                opened.push(name);
                return { keys: async () => [{ url: 'https://x.test/de/dashboard' }, { url: 'https://x.test/api/foo' }] };
            },
        };
        vi.resetModules();
        await import('../../public/offline.js');
        await vi.waitFor(() => expect(document.querySelectorAll('#cachedPagesList li').length).toBe(1));
        expect(opened).toEqual(['isms-dynamic-v3.0.0-fa']);
        expect(document.getElementById('cachedPages').hidden).toBe(false);
    });

    it('wires the retry button from the external script (no inline handler)', async () => {
        mountOfflinePage();
        globalThis.caches = { keys: async () => [] };
        const reload = vi.fn();
        const original = window.location;
        Object.defineProperty(window, 'location', { configurable: true, value: { ...original, reload } });
        try {
            vi.resetModules();
            await import('../../public/offline.js');
            document.getElementById('offlineRetry').click();
            expect(reload).toHaveBeenCalledOnce();
        } finally {
            Object.defineProperty(window, 'location', { configurable: true, value: original });
        }
    });
});

describe('offline.html — needs no CSP nonce', () => {
    it('has no inline script, event handler or style', () => {
        expect(offlineHtml).not.toMatch(/<script(?![^>]*\ssrc=)[^>]*>/i);
        expect(offlineHtml).not.toMatch(/\son[a-z]+\s*=/i);
        expect(offlineHtml).not.toMatch(/<style[\s>]/i);
        expect(offlineHtml).not.toMatch(/\sstyle\s*=/i);
        expect(offlineHtml).not.toMatch(/nonce=/i);
    });
});
