/**
 * CSP nonce bridge for Turbo Drive.
 *
 * script-src carries a per-request nonce (SecurityHeadersSubscriber). A Turbo
 * visit keeps the current document — and with it the CSP of the initial full
 * page load — so the inline scripts of every page Turbo fetches must carry
 * THAT nonce:
 *
 *  1. Every Turbo fetch (visit, prefetch, frame, form) sends the document's
 *     nonce as `X-CSP-Nonce`; the server renders the page with it
 *     (App\Security\CspNonce). Turbo copies the scripts' nonce attribute when
 *     it re-creates them, so they run.
 *  2. Browsers hide nonce attributes in the live DOM (`nonce=""`), and Turbo
 *     caches page snapshots as DOM clones. Before a snapshot is taken, the
 *     attribute is restored from the `nonce` property, so scripts of a page
 *     restored from the cache (back button) still carry the nonce.
 */
const nonce = document.querySelector('meta[name="csp-nonce"]')?.content || '';

if (nonce) {
    document.addEventListener('turbo:before-fetch-request', (event) => {
        event.detail.fetchOptions.headers['X-CSP-Nonce'] = nonce;
    });

    document.addEventListener('turbo:before-cache', () => {
        document.querySelectorAll('script[nonce]').forEach((script) => {
            if (script.nonce) {
                script.setAttribute('nonce', script.nonce);
            }
        });
    });
}
