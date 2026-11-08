/**
 * Escaping helpers for HTML built in JavaScript (S10).
 *
 * Record text (titles, messages, names) that ends up in an innerHTML template goes
 * through escapeHtml(); a URL that ends up in href/src goes through safeUrl(), which
 * keeps relative links and http(s)/mailto and drops everything else (javascript:, data:).
 */
const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ENTITIES[ch]);
}

export function safeUrl(value) {
    const url = String(value ?? '').trim();
    if (url === '') {
        return '';
    }
    if (/^(\/(?!\/)|\.\/|\.\.\/|#|\?)/.test(url)) {
        return escapeHtml(url);
    }
    try {
        const parsed = new URL(url, window.location.origin);
        if (['http:', 'https:', 'mailto:'].includes(parsed.protocol)) {
            return escapeHtml(url);
        }
    } catch {
        // fall through: not a URL we can vouch for
    }
    return '';
}
