/**
 * F4 Evidence-Versioning — reuse / version drawer controller.
 *
 * Handles click-to-expand of the version-history drawer on the document
 * show page. Fetches the version list partial and injects it into a
 * side-sheet (fa-drawer).
 *
 * Usage:
 *   data-controller="reuse-drawer"  (on a wrapper element)
 *   data-action="click->reuse-drawer#open"  (on the trigger button/link)
 *   data-reuse-drawer-url-value="{{ path('app_document_versions', {id: doc.id}) }}"
 *
 * The drawer element must have `id="version-drawer"` and the CSS class
 * `fa-drawer` (defined in fairy-aurora-components.css).
 */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        this._drawer = null;
        this._backdrop = null;
    }

    async open(event) {
        event?.preventDefault();

        const drawerId = 'version-drawer';
        let drawer = document.getElementById(drawerId);

        if (!drawer) {
            drawer = document.createElement('div');
            drawer.id = drawerId;
            drawer.className = 'fa-drawer fa-drawer--right';
            drawer.setAttribute('role', 'dialog');
            drawer.setAttribute('aria-modal', 'true');
            document.body.appendChild(drawer);

            const backdrop = document.createElement('div');
            backdrop.className = 'fa-drawer-backdrop';
            backdrop.addEventListener('click', () => this.close());
            document.body.appendChild(backdrop);
            this._backdrop = backdrop;
        }

        this._drawer = drawer;

        // Load content
        if (this.hasUrlValue && this.urlValue) {
            try {
                const resp = await fetch(this.urlValue, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (resp.ok) {
                    drawer.innerHTML = await resp.text();
                }
            } catch {
                drawer.innerHTML = '<p class="p-4 text-muted">Failed to load version history.</p>';
            }
        }

        drawer.classList.add('is-open');
        if (this._backdrop) {
            this._backdrop.classList.add('is-open');
        }
    }

    close() {
        this._drawer?.classList.remove('is-open');
        this._backdrop?.classList.remove('is-open');
    }
}
