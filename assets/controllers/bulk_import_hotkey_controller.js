import { Controller } from '@hotwired/stimulus';

/**
 * BulkImportHotkey Controller — Power-User Shortcut (F2.9 + F2.12)
 *
 * Listens for the `I` key (case-insensitive) and navigates to the
 * bulk-import upload route for the current entity type.
 *
 * Place this controller on the <body> or app-shell element that has
 * `data-current-entity` set to the entity-type slug (asset, supplier, control).
 *
 * The current locale is read from the `<html lang="…">` attribute.
 *
 * Usage:
 *   <body data-controller="bulk-import-hotkey"
 *         data-current-entity="asset">
 *
 * Discoverability:
 *   TODO (F40.4): Register `I → Bulk-Import` in the keyboard-shortcuts overlay
 *   (keyboard_shortcuts_controller.js / design-system help panel) once that
 *   overlay's registration API is available.
 */
export default class extends Controller {
    connect() {
        console.log('[bulk-import-hotkey] controller connected');
        this._onKeyDown = this._handleKeyDown.bind(this);
        document.addEventListener('keydown', this._onKeyDown);
    }

    disconnect() {
        document.removeEventListener('keydown', this._onKeyDown);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    _handleKeyDown(event) {
        // Skip when focus is inside a text field
        const tag = (document.activeElement?.tagName || '').toLowerCase();
        if (['input', 'textarea', 'select'].includes(tag)) return;
        if (document.activeElement?.isContentEditable) return;

        // Skip modifier combinations (Ctrl+I, Alt+I, …)
        if (event.ctrlKey || event.altKey || event.metaKey) return;

        if (event.key.toLowerCase() !== 'i') return;

        event.preventDefault();

        const entityType = document.body.dataset.currentEntity || 'asset';
        const locale = document.documentElement.lang || 'de';

        window.location.href = `/${locale}/import/${entityType}/upload`;
    }
}
