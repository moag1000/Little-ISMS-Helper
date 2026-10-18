/**
 * FairyAurora — shared UI strings for JavaScript.
 *
 * Controller-specific strings travel as `data-<controller>-<name>-value`
 * attributes rendered with |trans in the template. A handful of generic
 * strings (dialog buttons, network errors, …) are needed by the global
 * helpers (fa-alerts.js) and as fallbacks when a template does not pass its
 * own value. base.html.twig renders those once, translated for the current
 * locale, into window.translations.ui (JSON-encoded inline script).
 *
 * Usage:
 *   import { faT } from '../js/fa-i18n.js';
 *   faT('cancel');                          // "Abbrechen" / "Cancel"
 *   faT('error_status', { '%status%': 500 });
 *
 * The dictionary is read at call time (not import time): the inline script
 * re-runs on every Turbo visit, so a locale switch is picked up.
 */

// Last-resort English text, used only when window.translations is missing
// (unit tests, a page rendered without base.html.twig).
const FALLBACK = {
    confirm: 'Confirm',
    cancel: 'Cancel',
    apply: 'Apply',
    close: 'Close',
    are_you_sure: 'Are you sure?',
    discard_changes: 'Discard unsaved changes?',
    copied: 'Copied to clipboard',
    copy_failed: 'Copy failed',
    success: 'Done',
    unknown_error: 'Unknown error',
    network_error: 'Network error',
    error_status: 'Error (%status%)',
    loading: 'Loading…',
    required_fields_missing: '%count% required field(s) still missing – please fill in the highlighted fields.',
};

function dictionary() {
    const ui = typeof window !== 'undefined' ? window.translations?.ui : null;
    return ui && typeof ui === 'object' ? ui : {};
}

export function faT(key, params = {}) {
    let text = dictionary()[key] ?? FALLBACK[key] ?? key;
    for (const [placeholder, value] of Object.entries(params)) {
        text = text.split(placeholder).join(String(value));
    }
    return text;
}

if (typeof window !== 'undefined') {
    window.faT = faT;
}
