import { describe, it, expect, vi } from 'vitest';

describe('offline.js — cached page list', () => {
    it('finds the versioned isms-dynamic-* cache without a hard-coded version', async () => {
        document.body.innerHTML = '<div id="cachedPages" style="display:none"><ul id="cachedPagesList"></ul></div>';
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
        expect(document.getElementById('cachedPages').style.display).toBe('block');
    });
});
