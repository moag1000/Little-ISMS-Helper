/**
 * Global fetch interceptor — reauth modal trigger.
 *
 * Wraps `window.fetch` to detect 403 responses whose JSON body contains
 * `{ "reauth": true, ... }`. On detection it dispatches the `fa-reauth:open`
 * custom event with the payload detail so the `reauth-modal` Stimulus
 * controller can open the challenge modal in-place.
 *
 * After the user re-authenticates successfully, the reauth-modal controller
 * reloads the page at `return_to` — the original request is not retried
 * automatically (a full reload is simpler and avoids state-management complexity
 * on complex POST flows).
 *
 * Activation: imported once in assets/app.js so it runs on every page.
 *
 * Design constraints:
 *  - Only intercepts JSON responses (Content-Type includes application/json)
 *  - Does NOT swallow the original response; clones it so downstream code
 *    still receives it (though with status 403 → callers should check).
 *  - Uses `response.clone()` to avoid "body already read" errors.
 *  - No-ops for non-fetch navigation (form submits, Turbo Drive visits handled
 *    by AccessDeniedHandler returning the full reauth_page.html.twig instead).
 */

const _originalFetch = window.fetch.bind(window);

window.fetch = async function interceptedFetch(input, init) {
    const response = await _originalFetch(input, init);

    if (response.status !== 403) {
        return response;
    }

    const contentType = response.headers.get('Content-Type') || '';
    if (!contentType.includes('application/json')) {
        return response;
    }

    // Clone before reading so the original response body remains available.
    const clone = response.clone();
    let data = null;
    try {
        data = await clone.json();
    } catch (_) {
        // Not JSON — not our 403
        return response;
    }

    if (data && data.reauth === true) {
        // Dispatch the reauth open event with full payload.
        document.dispatchEvent(new CustomEvent('fa-reauth:open', {
            bubbles: true,
            detail: {
                provider:        data.provider ?? 'password',
                user_identifier: data.user_identifier ?? '',
                return_to:       data.return_to ?? window.location.pathname,
                sso_slug:        data.sso_slug ?? '',
            },
        }));
    }

    return response;
};
