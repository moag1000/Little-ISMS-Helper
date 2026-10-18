import { describe, it, expect, vi } from 'vitest';

describe('csp-nonce — Turbo bridge', () => {
    it('sends the document nonce with Turbo fetches and restores hidden nonce attributes before caching', async () => {
        document.head.innerHTML = '<meta name="csp-nonce" content="DocNonce1234567890abcdef">';
        vi.resetModules();
        await import('../../assets/js/csp-nonce.js');

        const headers = {};
        document.dispatchEvent(new CustomEvent('turbo:before-fetch-request', { detail: { fetchOptions: { headers } } }));
        expect(headers['X-CSP-Nonce']).toBe('DocNonce1234567890abcdef');

        const script = document.createElement('script');
        script.setAttribute('nonce', '');
        script.nonce = 'DocNonce1234567890abcdef';
        document.body.appendChild(script);
        document.dispatchEvent(new Event('turbo:before-cache'));
        expect(script.getAttribute('nonce')).toBe('DocNonce1234567890abcdef');
    });
});
